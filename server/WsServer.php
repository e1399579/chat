<?php
namespace server;

error_reporting(E_ALL);//调试时为E_ALL
set_time_limit(0);
ob_implicit_flush();//调试时打开

class WsServer implements IServer {
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    //0x0附加数据帧 0x1文本数据帧 0x2二进制数据帧 0x3-7无定义，保留 0x8连接关闭 0x9ping 0xApong 0xB-F无定义，保留
    const FRAME_OPCODE_CONTINUATION = 0x0;
    const FRAME_OPCODE_TEXT = 0x1;
    const FRAME_OPCODE_BINARY = 0x2;
    const FRAME_OPCODE_CLOSE = 0x8;
    const FRAME_OPCODE_PING = 0x9;
    const FRAME_OPCODE_PONG = 0xA;
    const FRAME_FIN_FINAL = 0b10000000;

    const NOTIFY_TYPE_ON_OPEN = 0b1;
    const NOTIFY_TYPE_ON_CLOSE = 0b10;
    const NOTIFY_TYPE_ON_MESSAGE = 0b100;
    const NOTIFY_TYPE_ON_ERROR = 0b1000;
    const NOTIFY_TYPE_SEND_TO = 0b10000;
    const NOTIFY_TYPE_SEND_TO_ALL = 0b100000;
    const NOTIFY_TYPE_NORMAL_CLOSE = 0b1000000;

    protected $method_map = [
        self::NOTIFY_TYPE_ON_OPEN => 'onOpen',
        self::NOTIFY_TYPE_ON_CLOSE => 'onClose',
        self::NOTIFY_TYPE_ON_MESSAGE => 'onMessage',
        self::NOTIFY_TYPE_ON_ERROR => 'onError',
        self::NOTIFY_TYPE_SEND_TO => 'sendTo',
        self::NOTIFY_TYPE_SEND_TO_ALL => 'sendToAll',
        self::NOTIFY_TYPE_NORMAL_CLOSE => 'normalClose',
    ];

    protected $debug = false; // 调试
    /**
     * @var Logger
     */
    protected $logger; // 日志

    protected $storage; // 业务处理对象存储容器
    protected $frame_max_length; // 一帧最大长度，判断是否出现异常

    /**
     * @var \EventListener
     */
    protected $listener; // 监听服务
    /**
     * @var \EventBase
     */
    protected $base; // 基础服务
    /**
     * @var \EventBufferEvent[]
     */
    protected $established_connections = []; // 已握手的服务
    /**
     * @var \EventBufferEvent[]
     */
    protected $ready_connections = []; // 准备握手的服务
    protected $max_index = 0; // 连接索引
    /**
     * @var \EventBufferEvent
     */
    protected $work; // 子进程管道服务
    protected $work_socket; // 子进程管道socket
    /**
     * @var \EventSslContext|null
     */
    protected $ctx; // SSL上下文
    protected $target; // 服务监听地址
    /**
     * @var \EventBufferEvent[]
     */
    protected $channels = []; // 主进程管道服务
    protected $sockets = []; // 主进程管道socket
    protected $frame_meta_pool = []; // 帧结构元数据，列表
    protected $buffers = []; // 数据缓冲，列表

    public function __construct($port, array $ssl = []) {
        $this->checkEnvironment();

        $path = './logs/socket';
        $this->logger = Logger::getInstance($path);
        $this->frame_max_length = 2 ** 32;
        ini_set('memory_limit', '2G');
        $this->storage = new \SplObjectStorage();

        $this->ctx = null;
        if (!empty($ssl)) {
            if (!\EventUtil::sslRandPoll()) {
                die("EventUtil::sslRandPoll failed\n");
            }

            $this->ctx = new \EventSslContext(
                \EventSslContext::TLS_SERVER_METHOD,
                [
                    \EventSslContext::OPT_LOCAL_CERT  => $ssl['local_cert'],
                    \EventSslContext::OPT_LOCAL_PK    => $ssl['local_pk'],
                    \EventSslContext::OPT_PASSPHRASE  => "",
                    \EventSslContext::OPT_VERIFY_PEER => false,
                    \EventSslContext::OPT_CIPHERS => 'ECDHE-RSA-AES128-GCM-SHA256:ECDHE:ECDH:AES:HIGH:!NULL:!aNULL:!MD5:!ADH:!RC4',
                ]
            );
        }

        $this->target = "0.0.0.0:{$port}";
    }

    public function checkEnvironment() {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Only run in command line');
        }

        if (!extension_loaded('event')) {
            throw new \RuntimeException("Please install event extension firstly");
        }
    }

    protected function setEventOption(\EventBufferEvent $bev, $priority = 100, $low = 2): void {
        $bev->enable(\Event::READ | \Event::WRITE | \Event::PERSIST);
        $bev->setWatermark(\Event::READ, $low, 0);
        $bev->setWatermark(\Event::WRITE, 0, 0);
        $bev->setPriority($priority);
    }

    public function acceptError(\EventListener $listener, $arg): void {
        $error_no = \EventUtil::getLastSocketErrno();
        $error_msg = \EventUtil::getLastSocketError();
        $this->logger->error('lister error:', compact('error_no', 'error_msg'));

        $this->base->exit(NULL);
    }

    /**
     * 接受新连接回调
     * @param \EventListener $listener
     * @param int $fd
     * @param array $address
     * @return void
     */
    public function acceptConnect(\EventListener $listener, int $fd, array $address) {
        // 使用文件描述符创建一个新的缓冲事件服务，并设置为释放时关闭底层socket
        $bev = new \EventBufferEvent(
            $this->base,
            $fd,
            \EventBufferEvent::OPT_CLOSE_ON_FREE
        );
        $this->prepareConnect($bev, $fd, $address);
    }

    public function acceptConnectSSL(\EventListener $listener, int $fd, array $address) {
        $bev = \EventBufferEvent::sslSocket(
            $this->base,
            $fd,
            $this->ctx,
            \EventBufferEvent::SSL_ACCEPTING,
            \EventBufferEvent::OPT_CLOSE_ON_FREE
        );
        $this->prepareConnect($bev, $fd, $address);
    }

    public function prepareConnect(\EventBufferEvent $bev, $fd, $address) {
        $index = $this->max_index++;
        $this->ready_connections[$index] = $bev;
        $arg = compact('index', 'address', 'fd');

        $this->setEventOption($bev, 0);
        $bev->setCallbacks(
            [$this, "handShake"],
            NULL,
            [$this, "eventCallback"],
            $arg
        );
    }

    public function handShake(\EventBufferEvent $bev, $arg) {
        $index = $arg['index'];
        $buffer = $bev->read($bev->input->length);
        $headers = $this->getHeaders($buffer);
        $headers['REMOTE_ADDR'] = $arg['address'][0];

        $this->debug('Requesting handshake...');
        $this->debug($buffer);

        $handshake = $this->doHandShake($bev, $headers);
        if ($handshake) {
            $this->established_connections[$index] = $bev;
            // 握手成功，设置读取回调
            $bev->setCallbacks(
                [$this, "readCallback"],
                NULL,
                [$this, "eventCallback"],
                $arg
            );

            //握手成功，通知客户端连接已经建立
            $data = json_encode($headers);
            $this->sendToChannel($this->chooseChannel($index), self::NOTIFY_TYPE_ON_OPEN, $index, $data);
        } else {
            $this->disConnect($index); //关闭连接
        }

        // 移出等待区，释放内存
        unset($this->ready_connections[$index]);
    }

    /**
     * 读取数据回调
     * @param \EventBufferEvent $bev
     * @param $arg
     */
    public function readCallback(\EventBufferEvent $bev, $arg) {
        $index = $arg['index'];

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
            // 发送关闭帧
            $close = $this->frame('', self::FRAME_OPCODE_CLOSE);
            $bev->write($close);

            // 关闭TCP连接
            $this->disConnect($index);

            // 通知事件
            $data = '';
            $this->sendToChannel($channel, self::NOTIFY_TYPE_ON_CLOSE, $index, $data);
            return;
        }

        if ($params['is_exception']) {
            $close = $this->frame(pack('n', 1002) . 'Data parse error', self::FRAME_OPCODE_CLOSE);
            $bev->write($close);
            return;
        }

        if (!isset($msg[0])) {
            $this->debug('空消息，不作处理');
            return;
        }

        // 通知子进程
        $this->sendToChannel($channel, self::NOTIFY_TYPE_ON_MESSAGE, $index, $msg);
    }

    protected function chooseChannel($index) {
        $num = count($this->channels);
        $channel_index = $index % $num;
        $channel = $this->channels[$channel_index];
        return $channel;
    }

    protected function sendToChannel(\EventBufferEvent $bev, int $notify_type, int $index, string &$data) {
        // IPC消息格式：| 数据长度 4 byte | 通知类型 1 byte | 服务标识 2 byte | 数据 |
        $len = strlen($data);
        $payload = pack('N', $len) . chr($notify_type) . pack('n', $index);
        $bev->write($payload . $data);
    }

    protected function receiveFromChannel(\EventBufferEvent $bev) {
        $data_list = [];
        // 可能写入的数据有多条，但是都在一个缓存里面，故延迟处理
        $input = $bev->input;
        $payload_length = 7;
        while ($input->length) {
            $payload = $input->substr(0, $payload_length);
            if (strlen($payload) < $payload_length) {
                break;
            }

            $data_length = current(unpack('N', substr($payload, 0, 4)));
            if (($data_length > $this->frame_max_length) || ($data_length < 0)) {
                $input->drain($input->length);
                $this->logger->error('Receive length error:' . $data_length);
                break;
            }
            $notify_type = ord(substr($payload, 4, 1));
            $index = current(unpack('n', substr($payload, 5, 2)));

            if ($payload_length + $data_length > $input->length) {
                // 未接收完，下次回调再作处理
                break;
            }

            $input->drain($payload_length);
            $data = $bev->read($data_length);
            $data_list[] = [$notify_type, $index, $data];
        }

        return $data_list;
    }

    /**
     * 事件回调：如连接关闭，有错误发生
     * @param $bev
     * @param $events
     * @param $arg
     */
    public function eventCallback($bev, $events, $arg) {
        $index = $arg['index'];
        if ($events & \EventBufferEvent::ERROR) {
            // Fetch errors from the SSL error stack
            while ($err = $bev->sslError()) {
                $this->logger->error("{$index}# {$err}");
            }
        }

        if ($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR)) {
            $this->disConnect($index);
            $data = '';
            $this->sendToChannel($this->chooseChannel($index), self::NOTIFY_TYPE_ON_CLOSE, $index, $data);
        }
    }

    /**
     * 观察对象
     * @param IClient $client
     */
    public function attach(IClient $client): void {
        $this->storage->attach($client);
    }

    /**
     * 解除对象
     * @param IClient $client
     */
    public function detach(IClient $client): void {
        $this->storage->detach($client);
    }

    /**
     * 通知对象
     * @param string $method
     * @param array $params
     */
    protected function notify($method, $params) {
        foreach ($this->storage as $client) {
            call_user_func_array([$client, $method], $params);
        }
    }

    /**
     * 开始运行
     * @param int $num
     * @param ?callable $callback
     */
    public function run(int $num, ?callable $callback = null): void {
        $cfg = new \EventConfig();
        $cfg->requireFeatures(\EventConfig::FEATURE_O1 | \EventConfig::FEATURE_ET);
        $this->base = new \EventBase($cfg);
        for ($i = 0;$i < $num;++$i) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $index = $i;
            $arg = compact('index');
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \RuntimeException('could not fork');
            }
            if ($pid) {
                // 主进程：管理全部通道，传递消息，并接收子进程消息（调用）。此处在主进程中执行N次
                // 注意：需要保留socket和bev，否则将立即停止
                fclose($sockets[1]);
                $this->sockets[] = $sockets[0];

                $bev = new \EventBufferEvent(
                    $this->base,
                    $sockets[0],
                    \EventBufferEvent::OPT_CLOSE_ON_FREE
                );

                $this->setEventOption($bev);
                $bev->setCallbacks(
                    [$this, 'masterReadCallback'],
                    NULL,
                    [$this, 'channelEventCallback'],
                    $arg
                );
                $this->channels[] = $bev;
            } else {
                if (!is_null($callback)) {
                    $callback();
                }

                // 子进程：接收主进程程消息，处理业务。此处在每个不同的新子进程中执行1次
                fclose($sockets[0]);
                $this->work_socket = $sockets[1];

                $this->base = new \EventBase($cfg);
                $bev = new \EventBufferEvent(
                    $this->base,
                    $sockets[1],
                    \EventBufferEvent::OPT_CLOSE_ON_FREE
                );

                $this->setEventOption($bev);
                $bev->setCallbacks(
                    [$this, 'workReadCallback'],
                    NULL,
                    [$this, 'channelEventCallback'],
                    $arg
                );
                $this->work = $bev;

                $this->base->dispatch();
                die();
            }
        }

        // 主进程作用域，最终有1个主进程+n个子进程
        $this->debug('Server Started : ' . date('Y-m-d H:i:s'));
        $this->debug('Listening on   : '. $this->target);

        $this->listener = new \EventListener(
            $this->base,
            $this->ctx ? [$this, "acceptConnectSSL"] : [$this, "acceptConnect"],
            null,
            \EventListener::OPT_CLOSE_ON_FREE | \EventListener::OPT_REUSEABLE,
            -1,
            $this->target
        );
        $this->listener->setErrorCallback([$this, "acceptError"]);

        $this->base->dispatch();
    }

    public function masterReadCallback(\EventBufferEvent $bev, $arg): void {
        // IPC消息
        $data_list = $this->receiveFromChannel($bev);
        foreach ($data_list as list($notify_type, $index, $data)) {
            $this->debug('master callback:' . sprintf('%08b', $notify_type));
//            call_user_func_array([$this, $this->method_map[$notify_type]], [$index, $data]);
            switch ($notify_type) {
                case self::NOTIFY_TYPE_SEND_TO:
                    $this->sendTo($index, $data);
                    break;
                case self::NOTIFY_TYPE_SEND_TO_ALL:
                    $this->sendToAll($data);
                    break;
                case self::NOTIFY_TYPE_NORMAL_CLOSE:
                    $bev->write($this->frame('', self::FRAME_OPCODE_CLOSE));
                    $this->disConnect($index);
                    break;
            }
        }
    }

    public function channelEventCallback(\EventBufferEvent $bev, int $events, $arg): void {
        $index = $arg['index'];
        if ($events & \EventBufferEvent::ERROR) {
            $this->logger->error("Sub process " . $this->getError($bev->fd));
        }

        if ($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR)) {
            $bev->free();
            unset($this->channels[$index]);
        }
    }

    public function workReadCallback(\EventBufferEvent $bev, $arg): void {
        $data_list = $this->receiveFromChannel($bev);
        foreach ($data_list as list($notify_type, $index, $data)) {
            $this->debug('work callback:' . sprintf('%08b', $notify_type));
            if ($notify_type === self::NOTIFY_TYPE_ON_OPEN) {
                $data = json_decode($data, true);
            }
            $this->notify($this->method_map[$notify_type], [$index, $data]);
        }
    }

    /**
     * 发送消息
     * @param int $key socket index
     * @param string $msg 消息
     */
    public function send(int $key, string $msg): void {
        // 发给主进程
        $this->sendToChannel($this->work, self::NOTIFY_TYPE_SEND_TO, $key, $msg);
    }

    protected function sendTo(int $index, string $msg): void {
        if (!isset($this->established_connections[$index])) {
            return;
        }

        $bev = $this->established_connections[$index];
        $msg = $this->frame($msg);
        $this->doSend($bev, $index, $msg);
    }

    protected function doSend(\EventBufferEvent $bev, int $index, string $msg): void {
        $len = strlen($msg);
        $res = $bev->write($msg);
        if (false === $res) {
            $error = $this->getError($bev->fd);
            $this->logger->error("{$index}# write error:" . $error);

            $this->disConnect($index);
            $this->sendToChannel($this->chooseChannel($index), self::NOTIFY_TYPE_ON_ERROR, $index, $error);
        } else {
            $this->debug('!' . $len . ' bytes sent');
        }
    }

    public function sendAll(string $msg): void {
        // 发给主进程
        $this->sendToChannel($this->work, self::NOTIFY_TYPE_SEND_TO_ALL, 0, $msg);
    }

    /**
     * 给所有完成握手的客户端发送消息
     * @param string $msg
     */
    public function sendToAll(string $msg): void {
        $msg = $this->frame($msg);
        foreach ($this->established_connections as $index => $bev) {
            $this->doSend($bev, $index, $msg);
        }
    }

    /**
     * 断开连接
     * @param int $index 服务下标
     */
    protected function disConnect(int $index): void {
        if (isset($this->established_connections[$index])) {
//            $this->established_connections[$index]->close();
            $this->established_connections[$index]->free();
        }

        $this->debug($index . ' DISCONNECTED!');
        unset($this->established_connections[$index]);
    }

    public function close(int $key): void {
        $data = '';
        $this->sendToChannel($this->work, self::NOTIFY_TYPE_NORMAL_CLOSE, $key, $data);
    }

    /**
     * 服务端与客户端握手
     * @param \EventBufferEvent $bev 客户端服务
     * @param array $headers
     * @return bool
     */
    protected function doHandShake(\EventBufferEvent $bev, array $headers): bool {
        // TODO 请求信息校验
        if (!isset($headers['Sec-WebSocket-Key'])) {
            $this->logger->error('Handshake failed:none Sec-WebSocket-Key');
            return false;
        }

        $key = $headers['Sec-WebSocket-Key'];
        $this->debug("Handshaking...");
        $upgrade =
            "HTTP/1.1 101 Switching Protocol\r\n" .
            "Upgrade: websocket\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: " . $this->calcKey($key) . "\r\n\r\n";  //必须以两个空行结尾
        $this->debug($upgrade);
        $res = $bev->write($upgrade);
        if (false === $res) {
            $this->logger->error('Handshake failed!' . $this->getError($bev->fd));
            return false;
        } else {
            $this->debug('Done handshaking...');
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
        return $res;
    }

    /**
     * 生成服务端key
     * @param string $key 客户端的key
     * @return string
     */
    public function calcKey(string $key): string {
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
        ];
//        echo 'IN:', PHP_EOL;

        // 使用缓冲解决粘包问题
        $this->buffers[$index] = $this->buffers[$index] ?? '';
        $input = $bev->input;
        $this->buffers[$index] .= $input->read($input->length); // 读取全部数据

        if (isset($this->frame_meta_pool[$index])) {
            list($cursor, $frame_struct_list) = $this->frame_meta_pool[$index];
        } else {
            $cursor = 0;
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
        $first_byte_ord = 0;
        do {
            $buffer = substr($this->buffers[$index], $cursor, 2);
            if (strlen($buffer) < 2) {
                $params['is_pending'] = true;
                break;
            }

            $cursor += 2;
            $first_byte_ord = ord($buffer[0]);
            $FIN = $first_byte_ord >> 7; // 1表示最后一帧

//			echo 'BUFFER[0]:', sprintf('%08b', ord($buffer[0])), PHP_EOL;
//			echo 'FIN:', $FIN, PHP_EOL;

            $second_byte_ord = ord($buffer[1]);
            // TODO 校验MASK
			$MASK = $second_byte_ord >> 7; // mask 1bit
//			echo 'MASK:', $MASK, PHP_EOL;

            $payload_len = $second_byte_ord & 0b1111111; // payload 7bit

//			echo 'BUFFER[1]:', sprintf('%08b', ord($buffer[1])), PHP_EOL;
//			echo 'PAYLOAD:', $payload_len, PHP_EOL;

            //网络字节序--大端序 big endian byte order
            //一次读取payload length和Masking-key，方便计算
            switch ($payload_len) {
                case 126: //<2^16 65536
                    $read_length = 6; // 16bit+4byte=2+4
                    if ($cursor + $read_length >= $total_length) {
                        $params['is_pending'] = true;
                        break 2;
                    }
                    $buffer = substr($this->buffers[$index], $cursor, $read_length);
                    $payload_length_data = substr($buffer, 0, 2);
                    $unpack = unpack('n', $payload_length_data); //16bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 2, 4);
                    break;
                case 127: //<2^64
                    $read_length = 12; // 64bit+4byte=8+4
                    if ($cursor + $read_length >= $total_length) {
                        $params['is_pending'] = true;
                        break 2;
                    }
                    $buffer = substr($this->buffers[$index], $cursor, $read_length);
                    $payload_length_data = substr($buffer, 0, 8);
                    $unpack = unpack('J', $payload_length_data); //64bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 8, 4);
                    break;
                default: //<=125
                    $read_length = 4; // 4byte
                    if ($cursor + $read_length >= $total_length) {
                        $params['is_pending'] = true;
                        break 2;
                    }
                    $buffer = substr($this->buffers[$index], $cursor, $read_length);
                    $length = $payload_len;
                    $masks = $buffer;
                    break;
            }

            if (($length > $this->frame_max_length) || ($length < 0)) {
                //异常情况：1.接收的长度小于计算的 2.计算的长度大于最大内存限制的 3.数据错误导致unpack计算的长度为负
                $params['is_exception'] = true;
                unset($this->frame_meta_pool[$index]);
                return '';
            }

            $cursor += $read_length;
            $frame_struct_list[] = [
                'offset' => $cursor, // 当前帧承载数据的起始位置
                'length' => $length, // 当前帧承载数据的长度
                'masks' => $masks, // 掩码
            ];
            $cursor += $length;

            // 保存帧结构信息，方便下次快速处理
            $this->frame_meta_pool[$index] = [$cursor, $frame_struct_list];

            // 游标是否越界
            if ($cursor > $total_length) {
                $params['is_pending'] = true;
                break;
            }
        } while (0 === $FIN); //连续帧
//        echo 'END.', PHP_EOL, PHP_EOL;

        // 数据未接收完成，下次再处理，本次返回空
        if ($params['is_pending']) {
            return '';
        }

        // 最后一帧，检查控制帧（Control Frames）操作码
        $opcode = $first_byte_ord & 0b1111;
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
                $decoded .= $data[$i] ^ $masks[$i % 4];
            }
        }

        // 清除buffer、结构数据
        $this->buffers[$index] = substr($this->buffers[$index], $cursor);
        unset($this->frame_meta_pool[$index]);

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
     * 获取错误信息
     * @param int|null $fd
     * @return string
     */
    protected function getError($fd = null): string {
        return (string) \EventUtil::getLastSocketError($fd);
    }

    public function debug(string $content, array $context = []) {
        if (!$this->debug) return true;
        return $this->logger->info($content, $context);
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
}


