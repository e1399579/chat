<?php
namespace server;

class MasterProcess extends AProcess implements IService {
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    //0x0附加数据帧 0x1文本数据帧 0x2二进制数据帧 0x3-7无定义，保留 0x8连接关闭 0x9ping 0xApong 0xB-F无定义，保留
    // see https://www.rfc-editor.org/rfc/rfc6455.html#section-5.2
    const FRAME_OPCODE_CONTINUATION = 0x0;
    const FRAME_OPCODE_TEXT = 0x1;
    const FRAME_OPCODE_BINARY = 0x2;
    const FRAME_OPCODE_CLOSE = 0x8;
    const FRAME_OPCODE_PING = 0x9;
    const FRAME_OPCODE_PONG = 0xA;
    const FRAME_FIN_FINAL = 0b10000000;
    const FRAME_STATUS_CODE_NORMAL = 1000;
    const FRAME_STATUS_CODE_PROTOCOL_ERROR = 1002;

    const TASK_TIMEOUT_SPARE = 1;
    const TASK_TIMEOUT_BUSY = 5;

    /**
     * @var \EventListener
     */
    protected $listener; // 监听服务
    /**
     * @var \EventBufferEvent[]
     */
    protected $established_connections = []; // 已握手的服务
    /**
     * @var \EventBufferEvent[]
     */
    protected $ready_connections = []; // 准备握手的服务
    protected $wait_for_close = []; // 等待关闭的索引列表
    protected $bad_request = []; // 非WebSocket请求
    protected $address_list = []; // IP列表
    protected $max_index = 0; // 连接索引
    
    /**
     * @var \EventSslContext|null
     */
    protected $ctx; // SSL上下文

    protected $target; // 服务监听地址

    protected $frame_meta_pool = []; // 帧结构元数据，列表
    protected $buffers = []; // 数据缓冲，列表
    protected $all_messages = []; // 将要发送的全部消息列表
    /**
     * @var \Event
     */
    protected $timer; // 定时器
    protected $child_pids = []; // 子进程PID
    protected $max_per_sent_bytes; // 每秒最大传送字节数
    protected $packet_header_size = 20 + 20; // IP+TCP
    /**
     * @var \Event
     */
    protected $signal; // 信号处理

    /**
     * @param int $port
     * @param array $ssl
     * @param int $bandwidth // 网络带宽 Mbps
     */
    public function __construct(int $port, array $ssl = [], int $bandwidth = 100) {
        parent::__construct();

        $this->max_per_sent_bytes = ($bandwidth / 8 * 1024 ** 2) | 0;
        ini_set('memory_limit', '8G');

        $this->ctx = null;
        if (!empty($ssl)) {
            if (!\EventUtil::sslRandPoll()) {
                die("EventUtil::sslRandPoll failed\n");
            }

            $this->ctx = new \EventSslContext(
                \EventSslContext::TLS_SERVER_METHOD,
                [
                    \EventSslContext::OPT_LOCAL_CERT => $ssl['local_cert'],
                    \EventSslContext::OPT_LOCAL_PK => $ssl['local_pk'],
                    \EventSslContext::OPT_CA_FILE => $ssl['ca_file'],
//                    \EventSslContext::OPT_PASSPHRASE => '',
                    \EventSslContext::OPT_VERIFY_PEER => false,
//                    \EventSslContext::OPT_CIPHERS => '',
                ]
            );
        }

        $this->target = "0.0.0.0:{$port}";
    }

    /**
     * 开始运行
     * @param int $num
     * @param IClient $worker
     * @return void
     */
    public function run(int $num, IClient $worker): void {
        $this->setEventBase(); // 主进程，设置一次base，子进程每个都要重新设置
        for ($i = 0;$i < $num;++$i) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $index = $i;
            $arg = compact('index');
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \RuntimeException('could not fork');
            }
            if ($pid) {
                $this->child_pids[] = $pid;
                // 这里不调用dispatch 避免阻塞影响后续fork
                $this->listen($sockets, $arg);
            } else {
                $childProcess = new ChildProcess();
                $childProcess->attach($worker);
                $childProcess->listen($sockets, $arg);
                $childProcess->dispatch();
                die();
            }
        }

        // 主进程作用域，最终有1个主进程+n个子进程
        $this->dispatch();
        die();
    }

    public function listen(array $sockets, array $arg): void {
        cli_set_process_title('php: master process ' . $this->target);

        // 主进程：管理全部通道，传递消息，并接收子进程消息（调用）。此处在主进程中执行N次
        // 注意：需要保留socket和bev，否则将立即停止
        fclose($sockets[1]);
        $this->sockets[] = $sockets[0];

        $this->setChannels($sockets[0], $arg);
        $this->setSignal();
    }

    public function dispatch(): void {
        $this->debug('Listening on: ' . $this->target);

        $this->listener = new \EventListener(
            $this->base,
            $this->ctx ? [$this, "acceptConnectSSL"] : [$this, "acceptConnect"],
            null,
            \EventListener::OPT_CLOSE_ON_FREE | \EventListener::OPT_REUSEABLE,
            -1,
            $this->target
        );
        $this->listener->setErrorCallback([$this, "acceptError"]);

        // 定时器
        $this->timer = \Event::timer($this->base, [$this, "smoothlySendToAll"], null);
        $this->timer->add(self::TASK_TIMEOUT_SPARE);

        $this->base->dispatch();
    }

    public function acceptError(\EventListener $listener, $arg): void {
        $error_no = \EventUtil::getLastSocketErrno();
        $error_msg = \EventUtil::getLastSocketError();
        $this->logger->error('lister error:', compact('error_no', 'error_msg'));

        $this->base->exit();
    }

    /**
     * 接受新连接回调
     * @param \EventListener $listener
     * @param int $fd
     * @param array $address
     * @return void
     */
    public function acceptConnect(\EventListener $listener, int $fd, array $address): void {
        // 使用文件描述符创建一个新的缓冲事件服务，并设置为释放时关闭底层socket
        $bev = new \EventBufferEvent(
            $this->base,
            $fd
//            \EventBufferEvent::OPT_CLOSE_ON_FREE
        );
        $this->prepareConnect($bev, $fd, $address);
    }

    public function acceptConnectSSL(\EventListener $listener, int $fd, array $address): void {
        $bev = \EventBufferEvent::sslSocket(
            $this->base,
            $fd,
            $this->ctx,
            \EventBufferEvent::SSL_ACCEPTING
//            \EventBufferEvent::OPT_CLOSE_ON_FREE
        );
        $this->prepareConnect($bev, $fd, $address);
    }

    protected function prepareConnect(\EventBufferEvent $bev, $fd, $address): void {
        $index = $this->max_index++;
        $this->ready_connections[$index] = $bev;
        $arg = compact('index', 'address', 'fd');

        $this->setEventOption($bev, 0, 2, 0); // 根据WebSocket协议，最小数据是2字节
        $bev->setCallbacks(
            [$this, "handShake"],
            [$this, "writeCallback"],
            [$this, "messageEventCallback"],
            $arg
        );
    }

    public function handShake(\EventBufferEvent $bev, $arg): void {
        $index = $arg['index'];
        $buffer = $bev->input->read($bev->input->length);
        $headers = $this->getHeaders($buffer);
        $headers['REMOTE_ADDR'] = $arg['address'][0];
        $this->address_list[$index] = $arg['address'];

        $this->debug($index . '# Requesting handshake...' . PHP_EOL . $buffer);

        $handshake = $this->doHandShake($bev, $headers, $index, $buffer);
        if ($handshake) {
            $this->established_connections[$index] = $bev;
            // 握手成功，设置读取回调
            $bev->setCallbacks(
                [$this, "messageReadCallback"],
                function() {},
                [$this, "messageEventCallback"],
                $arg
            );

            //握手成功，通知客户端连接已经建立
            $data = json_encode($headers);
            $this->sendToChannel($this->chooseChannel($index), self::NOTIFY_TYPE_ON_OPEN, $index, $data, 0, self::FRAME_OPCODE_TEXT);

            // 移出等待区，释放内存
            unset($this->ready_connections[$index]);
        } else {
            // 未成功的连接在write callback中释放
            $this->bad_request[$index] = 1;
        }
    }

    /**
     * 消息读取回调
     * @param \EventBufferEvent $bev
     * @param mixed $arg
     */
    public function messageReadCallback(\EventBufferEvent $bev, $arg): void {
        $index = $arg['index'];

        // 可能有好几条消息合并，一直读到没有数据或出现特殊标志为止
        do {
            $params = [];
            $msg = $this->prepareData($index, $bev, $params);
            if ($params['is_pending']) {
                return;
            }

            if ($params['is_ping']) {
                $this->logger->info('ping: ' . $msg);
                $pong = $this->frame($msg, self::FRAME_OPCODE_PONG);
                $bev->write($pong);
                return;
            }

            if ($params['is_pong']) {
                $this->logger->info('pong: ' . $msg);
                return;
            }

            $channel = $this->chooseChannel($index);
            if ($params['is_closed']) {
                if (isset($this->wait_for_close[$index])) {
                    $this->disConnect($index);
                    unset($this->wait_for_close[$index]);
                } else {
                    $this->confirmClose($index);

                    // 通知事件
                    $data = '';
                    $this->sendToChannel($channel, self::NOTIFY_TYPE_ON_CLOSE, $index, $data, 0, self::FRAME_OPCODE_TEXT);
                }

                return;
            }

            if ($params['is_exception']) {
                $this->prepareClose($index, self::FRAME_STATUS_CODE_PROTOCOL_ERROR, 'Data parse error');
                return;
            }

            if (!isset($msg[0])) {
                $this->debug('空消息，不作处理');
                return;
            }

            // 通知子进程
            $this->sendToChannel($channel, self::NOTIFY_TYPE_ON_MESSAGE, $index, $msg, 0, $params['opcode']);
        } while ($params['remain_length'] > 0);
    }

    protected function chooseChannel(int $index): \EventBufferEvent {
        $num = count($this->channels);
        $channel_index = $index % $num;
        return $this->channels[$channel_index];
    }

    /**
     * 事件回调：如连接关闭，有错误发生
     * @param $bev
     * @param $events
     * @param $arg
     */
    public function messageEventCallback($bev, $events, $arg): void {
        $index = $arg['index'];
        if ($events & \EventBufferEvent::ERROR) {
            // Fetch errors from the SSL error stack
            while ($err = $bev->sslError()) {
                $this->logger->error("{$index}# {$err}", $this->address_list[$index] ?? []);
            }
        }

        if ($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR)) {
            $this->disConnect($index);
            $data = '';
            $this->sendToChannel($this->chooseChannel($index), self::NOTIFY_TYPE_ON_CLOSE, $index, $data, 0, self::FRAME_OPCODE_TEXT);
        }
    }

    public function channelReadCallback(\EventBufferEvent $bev, $arg): void {
        // IPC消息
        $data_list = $this->receiveFromChannel($bev, $opcode);
        foreach ($data_list as list($priority, $notify_type, $index, $data)) {
            $this->debug('master callback:' . sprintf('%08b', $notify_type));
//            call_user_func_array([$this, $this->call_map[$notify_type]], [$index, $data]);
            switch ($notify_type) {
                case self::CALL_TYPE_SEND_TO:
                    $this->sendTo($index, $data, $opcode);
                    break;
                case self::CALL_TYPE_SEND_TO_ALL:
                    $this->sendToAll($data, $priority, $opcode);
                    break;
                case self::CALL_TYPE_PREPARE_CLOSE:
                    $this->prepareClose($index);
                    break;
                case self::CALL_TYPE_SEND_TO_MULTIPLY:
                    list($keys, $msg) = json_decode($data, true);
                    $this->sendToMultiple($keys, $msg, $opcode);
                    break;
            }
        }
    }

    protected function sendTo(int $index, string $msg, int $opcode): void {
        if (!isset($this->established_connections[$index])) {
            return;
        }

        $bev = $this->established_connections[$index];
        $msg = $this->frame($msg, $opcode);
        $this->doSend($bev, $index, $msg, $opcode);
    }

    protected function doSend(\EventBufferEvent $bev, int $index, string $msg, int $opcode): void {
        $len = strlen($msg);
        $res = $bev->write($msg);
        if (false === $res) {
            $error = $this->getError($bev->fd);
            $this->logger->error("{$index}# write error:" . $error);

            $this->disConnect($index);
            $this->sendToChannel($this->chooseChannel($index), self::NOTIFY_TYPE_ON_ERROR, $index, $error, 0, $opcode);
        } else {
            $this->debug($index . '# ' . $len . ' bytes sent');
        }
    }

    /**
     * 群发消息
     * @param string $msg
     * @param int $priority
     * @param int $opcode
     */
    protected function sendToAll(string $msg, int $priority = 10, int $opcode = self::FRAME_OPCODE_TEXT): void {
        if ($priority > 0) {
            $this->all_messages[] = [$opcode, $msg];
        } else {
            // 0 高优先级 实时发送
            $msg = $this->frame($msg, $opcode);
            $this->doSendToAll($msg, $opcode);
        }
    }

    protected function sendToMultiple(array $keys, string $msg, int $opcode): void {
        foreach ($keys as $key) {
            $this->sendTo($key, $msg, $opcode);
        }
    }

    /**
     * 断开连接
     * @param int $index 服务下标
     */
    protected function disConnect(int $index): void {
        // 主动调用free容易导致报错 free(): double free detected in tcache 2
        // Usually there is no need to call this method, since normally it is donewithin internal object destructors.
//        if (isset($this->established_connections[$index])) {
//            $this->established_connections[$index]->free();
//        }

        $this->debug($index . '# disconnecting...');
        unset($this->established_connections[$index]);
        unset($this->address_list[$index]);
    }

    protected function prepareClose(int $index, int $status_code = self::FRAME_STATUS_CODE_NORMAL, string $reason = ''): void {
        $bev = $this->established_connections[$index];
        $bev and $bev->write($this->frame(pack('n', $status_code) . $reason, self::FRAME_OPCODE_CLOSE));
        $this->wait_for_close[$index] = 1;
    }

    protected function confirmClose($index): void {
        // 发送关闭帧
        $close = $this->frame(pack('n', self::FRAME_STATUS_CODE_NORMAL), self::FRAME_OPCODE_CLOSE);
        $bev = $this->established_connections[$index];
        $bev->write($close);

        // 关闭TCP连接
        $this->disConnect($index);
    }

    /**
     * 服务端与客户端握手
     * @param \EventBufferEvent $bev 客户端服务
     * @param array $headers
     * @param int $index
     * @param string $buffer
     * @return bool
     */
    protected function doHandShake(\EventBufferEvent $bev, array $headers, int $index, string $buffer): bool {
        // TODO 请求信息校验
        $version = $headers['Sec-WebSocket-Version'] ?? '';
        $versions = explode(',', $version);
        if (!(in_array(13, $versions)
            && isset($headers['Sec-WebSocket-Key']))) {
            /*
            HTTP/1.1 400 Bad Request
            Server: nginx/1.25.4
            Date: Tue, 16 Apr 2024 23:32:17 GMT
            Content-Type: text/html; charset=utf-8
            Content-Length: 157
            Connection: close
            */
            $date_utc = (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::RFC7231);
            $str = "HTTP/1.1 400 Bad Request\r\n" .
                "Server: nginx\r\n" .
                "Date: " . $date_utc . "\r\n" .
                "Content-Type: text/plain\r\n" .
                "Content-Length: 0\r\n" .
                "Connection: close\r\n\r\n";
            $bev->write($str);
            // 记录日志，便于分析可能的攻击者
            $this->logger->warning($index . '# Bad Request: ' . PHP_EOL . $buffer, $this->address_list[$index]);
            return false;
        }

        $key = $headers['Sec-WebSocket-Key'];
        $upgrade =
            "HTTP/1.1 101 Switching Protocol\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: {$this->calcKey($key)}\r\n\r\n";  //必须以两个空行结尾
        $res = $bev->write($upgrade);
        if (false === $res) {
            $this->logger->error($index . '# Handshake failed!' . $this->getError($bev->fd));
            return false;
        } else {
            $this->debug($index . '# Handshake complete!' . PHP_EOL . $upgrade);
            return true;
        }
    }

    /**
     * 获取协议头部信息
     * @param string $request 请求数据
     * @return array
     */
    protected function getHeaders(string $request): array {
        /*请求示例
        GET ws://socket.mf.com:443/ HTTP/1.1
        Host: socket.mf.com:443
        Connection: Upgrade
        Pragma: no-cache
        Cache-Control: no-cache
        Upgrade: websocket
        Origin: http://socket.mf.com
        Sec-WebSocket-Version: 13
        User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/52.0.2743.116 Safari/537.36
        Accept-Encoding: gzip, deflate, sdch
        Accept-Language: zh-CN,zh;q=0.8
        Sec-WebSocket-Key: v7RrPTGmP/iCW7pTbKcFMg==
        Sec-WebSocket-Extensions: permessage-deflate; client_max_window_bits
        */
        $headers = explode("\r\n", $request);
        unset($headers[0]);
        array_pop($headers);
        array_pop($headers);
        $res = [];
        foreach ($headers as $row) {
            $arr = explode(':', $row, 2);
            isset($arr[1]) and $res[trim($arr[0])] = trim($arr[1]);
        }
        // 只提取必要信息
        return [
            'Sec-WebSocket-Key' => $res['Sec-WebSocket-Key'] ?? '',
            'Sec-WebSocket-Version' => $res['Sec-WebSocket-Version'] ?? '',
            'User-Agent' => $res['User-Agent'] ?? '',
        ];
    }

    /**
     * 生成服务端key
     * @param string $key 客户端的key
     * @return string
     */
    protected function calcKey(string $key): string {
        //基于websocket version 13
        return base64_encode(sha1($key . self::GUID, true));
    }

    /**
     * 预处理数据，接收数据并处理粘包
     * @param int $index
     * @param \EventBufferEvent $bev
     * @param array $params
     * @return string
     */
    protected function prepareData(int $index, \EventBufferEvent $bev, array &$params): string {
        $params = [
            'is_closed' => false,
            'is_ping' => false,
            'is_pong' => false,
            'is_exception' => false,
            'is_pending' => false,
            'remain_length' => 0,
        ];
//        echo 'IN:', PHP_EOL;

        // 使用缓冲解决粘包问题
        $this->buffers[$index] = $this->buffers[$index] ?? '';
        $input = $bev->input;
        $this->buffers[$index] .= $input->length ? $input->read($input->length) : ''; // 读取全部数据

        if (isset($this->frame_meta_pool[$index])) {
            list($offset, $frame_struct_list) = $this->frame_meta_pool[$index];
        } else {
            $offset = 0;
            $frame_struct_list = [];
        }

        // 一帧的结构：
        /*
             0                   1                   2                   3
             0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
            +-+-+-+-+-------+-+-------------+-------------------------------+
            |F|R|R|R| opcode|M| Payload len |    Extended payload length    |
            |I|S|S|S|  (4)  |A|     (7)     |             (16/64)           |
            |N|V|V|V|       |S|             |   (if payload len==126/127)   |
            | |1|2|3|       |K|             |                               |
            +-+-+-+-+-------+-+-------------+ - - - - - - - - - - - - - - - +
            |     Extended payload length continued, if payload len == 127  |
            + - - - - - - - - - - - - - - - +-------------------------------+
            |                               |Masking-key, if MASK set to 1  |
            +-------------------------------+-------------------------------+
            | Masking-key (continued)       |          Payload Data         |
            +-------------------------------- - - - - - - - - - - - - - - - +
            :                     Payload Data continued ...                :
            + - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - +
            |                     Payload Data continued ...                |
            +---------------------------------------------------------------+
        */
        $total_length = strlen($this->buffers[$index]);
        do {
            if ($offset + 2 > $total_length) {
                // 数据未接收完成，下次再处理，本次返回空
                $params['is_pending'] = true;
                return '';
            }

            $buffer = substr($this->buffers[$index], $offset, 2);
            $offset += 2;
            $first_byte_ord = ord($buffer[0]);
            $FIN = $first_byte_ord >> 7; // 1表示最后一帧

//			echo 'BUFFER[0]:', sprintf('%08b', ord($buffer[0])), PHP_EOL;
//			echo 'FIN:', $FIN, PHP_EOL;

            $second_byte_ord = ord($buffer[1]);
            // TODO 校验MASK
//			$MASK = $second_byte_ord >> 7; // mask 1bit
//			echo 'MASK:', $MASK, PHP_EOL;

            $payload_len = $second_byte_ord & 0b1111111; // payload 7bit

//			echo 'BUFFER[1]:', sprintf('%08b', ord($buffer[1])), PHP_EOL;
//			echo 'PAYLOAD:', $payload_len, PHP_EOL;

            //网络字节序--大端序 big endian byte order
            //一次读取payload length和Masking-key，方便计算
            switch ($payload_len) {
                case 126: //<2^16 65536
                    $read_length = 6; // 16bit+4byte=2+4
                    $data_offset = $offset + $read_length;
                    if ($data_offset > $total_length) {
                        $params['is_pending'] = true;
                        return '';
                    }
                    $buffer = substr($this->buffers[$index], $offset, $read_length);
                    $payload_length_data = substr($buffer, 0, 2);
                    $unpack = unpack('n', $payload_length_data); //16bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 2, 4);
                    break;
                case 127: //<2^64
                    $read_length = 12; // 64bit+4byte=8+4
                    $data_offset = $offset + $read_length;
                    if ($data_offset > $total_length) {
                        $params['is_pending'] = true;
                        return '';
                    }
                    $buffer = substr($this->buffers[$index], $offset, $read_length);
                    $payload_length_data = substr($buffer, 0, 8);
                    $unpack = unpack('J', $payload_length_data); //64bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 8, 4);
                    break;
                default: //<=125
                    $read_length = 4; // 4byte
                    $data_offset = $offset + $read_length;
                    if ($data_offset > $total_length) {
                        $params['is_pending'] = true;
                        return '';
                    }
                    $buffer = substr($this->buffers[$index], $offset, $read_length);
                    $length = $payload_len;
                    $masks = $buffer;
                    break;
            }

            if (($length > $this->frame_max_length) || ($length < 0)) {
                // 异常情况：1.计算的长度大于最大内存限制的 2.数据错误导致unpack计算的长度为负
                $params['is_exception'] = true;
                unset($this->frame_meta_pool[$index], $this->buffers[$index]);
                return '';
            }

            // 偏移量是否越界
            $end = $data_offset + $length;
            if ($end > $total_length) {
                $params['is_pending'] = true;
                return '';
            }

            $offset = $end; // 更新为合法值
            $frame_struct_list[] = [
                'offset' => $data_offset, // 当前帧承载数据的起始位置
                'length' => $length, // 当前帧承载数据的长度
                'masks' => $masks, // 掩码
            ];

            // 保存帧结构信息，方便下次快速处理
            $this->frame_meta_pool[$index] = [$offset, $frame_struct_list];
        } while (0 === $FIN); //连续帧
//        echo 'END.', PHP_EOL, PHP_EOL;

        // 检查控制帧（Control Frames）操作码（当FIN=1时，opcode可能是0 Continuation Frame，此处以首帧为准）
        // 发送文件时，BUFFER[0]可能的结构 00000010 00000010 ... 00000000 00000000 ... 10000000 10000000 ... 10000000
        $opcode = ord($this->buffers[$index][0]) & 0b1111;
        $params['opcode'] = $opcode;
        switch ($opcode) {
            case self::FRAME_OPCODE_PING:
                $params['is_ping'] = true;
                break;
            case self::FRAME_OPCODE_PONG:
                $params['is_pong'] = true;
                break;
            case self::FRAME_OPCODE_CLOSE:
                $params['is_closed'] = true;
                break;
        }

        //根据掩码解析数据，处理每个字节，比较费时
        $decoded = '';
        foreach ($frame_struct_list as $item) {
            $data = substr($this->buffers[$index], $item['offset'], $item['length']);
            $masks = $item['masks'];
            for ($i = 0, $n = strlen($data); $i < $n; ++$i) {
                // 4是2的次幂，i % 4 = i & (4 - 1)，位运算更快
//                $decoded .= $data[$i] ^ $masks[$i % 4];
                $decoded .= $data[$i] ^ $masks[$i & 3];
            }
        }

        // 清除buffer、结构数据
        $this->buffers[$index] = substr($this->buffers[$index], $offset);
        unset($this->frame_meta_pool[$index]);

        // 剩余字节
        $params['remain_length'] = strlen($this->buffers[$index]);

        return $decoded;
    }

    /**
     * 计算数据帧，一帧
     * @param string $str
     * @param int $opcode
     * @return string
     */
    protected function frame(string $str, int $opcode = self::FRAME_OPCODE_BINARY): string {
        $protocol = chr(self::FRAME_FIN_FINAL | $opcode);
        $len = strlen($str);
        if ($len <= 125)
            return $protocol . chr($len) . $str; //8+7位
        else if ($len <= 65535) //最大2^16-1字节
            return $protocol . chr(126) . pack('n', $len) . $str; //8+7+16位
        else //最大2^64-1字节
            return $protocol . chr(127) . pack('J', $len) . $str; //8+7+64位
    }

    /**
     * 计算数据帧，多帧(每帧最多125字节)，参照ASCII编码(数据量多时效率低，不推荐)
     * @param string $str
     * @return string
     */
    protected function frameContinued(string $str): string {
        //参照协议，FIN(1)+RSV1(0)+RSV2(0)+RSV3(0)+opcode(0001)
        //opcode:0001表示文本数据帧，也可以直接写成十六进制"\x81"或者八进制"\201"
        $protocol = chr(0b10000001);
        $arr = str_split($str, 125);
        //只有一帧，即结束帧
        if (1 == count($arr))
            return $protocol . chr(strlen($arr[0])) . $arr[0];

        //多帧=起始帧+附加帧+结束帧
        $start = chr(0b00000001);//起始帧
        $additional = chr(0b00000000);//附加帧
        $end = chr(0b10000000);//结束帧
        $frame = $start . chr(125) . $arr[0];//第一帧
        array_shift($arr);
        $last = array_pop($arr);//最后的数据
        //移除开头和结尾，剩余附加帧，遍历连接
        foreach ($arr as $val) {
            $frame .= $additional . chr(strlen($val)) . $val;
        }
        $frame .= $end . chr(strlen($last)) . $last;//连接最后一帧
        return $frame;
    }

    public function smoothlySendToAll(): void {
        // $load = sys_getloadavg();
        $percent = (new GetProcessUsage)($this->child_pids[0]);
        if ($percent <= 5) {
            // 空闲时，执行发送
            $last_bev = end($this->established_connections);
            $is_finished = $last_bev && (0 == $last_bev->output->length); // 最后一个是否发送完毕
            if ($is_finished) {
                // 限流，libevent会把消息全部写入内存，防止消息多时内存瞬间溢出
                $bytes = 0;
                $num = count($this->established_connections);
                foreach ($this->all_messages as $i => list($opcode, $msg)) {
                    $msg = $this->frame($msg, $opcode);
                    $msg_len = strlen($msg);
                    $next_guess = ($msg_len + $this->packet_header_size) * $num;
                    if ($bytes + $next_guess > $this->max_per_sent_bytes) break;

                    unset($this->all_messages[$i]);
                    $sent_num = $this->doSendToAll($msg, $opcode);
                    $bytes += ($msg_len + $this->packet_header_size) * $sent_num;
                }
            }
            
            $timeout = self::TASK_TIMEOUT_SPARE;
        } else {
            $timeout = self::TASK_TIMEOUT_BUSY;
        }

        $this->timer->add($timeout);
    }

    /**
     * 给所有完成握手的客户端发送消息
     * @param string $msg
     * @param int $opcode
     * @return int sent num
     */
    protected function doSendToAll(string $msg, int $opcode): int {
        $num = 0;
        foreach ($this->established_connections as $index => $bev) {
            if (isset($this->wait_for_close[$index])) continue; // 即将关闭的忽略

            $this->doSend($bev, $index, $msg, $opcode);
            ++$num;
        }
        return $num;
    }

    protected function setSignal() {
        $this->signal = \Event::signal($this->base, SIGTERM, [$this, 'signalCallback'], null);
        $this->signal->add();
    }

    public function signalCallback(int $signal_no, $arg): void {
        $this->debug('master caught signal ' . $signal_no);
        $this->base->exit();
    }

    public function writeCallback(\EventBufferEvent $bev, $arg) {
        $index = $arg['index'];
        // 清除bad request
        if (isset($this->bad_request[$index])) {
            $this->debug($index . '# clearing bad request... ');
            unset($this->ready_connections[$index]);
            unset($this->bad_request[$index]);
            unset($this->address_list[$index]);
        }
    }
}