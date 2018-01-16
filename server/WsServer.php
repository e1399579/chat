<?php
namespace server;

error_reporting(E_ALL);//调试时为E_ALL
set_time_limit(0);
ob_implicit_flush();//调试时打开

class WsServer implements IServer {
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    const FRAME_TYPE_BINARY = 0b10000010;
    const FRAME_TYPE_TEXT = 0b10000001;

    protected $master;//主机
    protected $handshake = array();//服务握手标志
    protected $backlog = 0;//最大的积压连接数
    protected $storage;//业务处理对象存储容器
    protected $timeout = 0; //读取/发送超时
    protected $max_log_length = 1024; //消息记录在日志的最大长度
    protected $headers = array(); //请求头
    protected $memory_limit; //最大内存限制:byte
    protected $debug = false;
    protected $listener;
    protected $base;
    protected $event_buffer_events = array();
    protected $max_index = 0;
    /**
     * @var Logger
     */
    protected $logger;
    protected $slave;
    protected $pid;
    protected $ctx;
    protected $local_socket;

    public function __construct($port, $ssl = array()) {
        $this->checkEnvironment();

        $path = './logs/socket';
        $this->logger = Logger::getInstance($path);
        $this->memory_limit = intval(ini_get('memory_limit')) * 1024 * 1024;
        $this->storage = new \SplObjectStorage();

        $this->ctx = null;
        if (!empty($ssl)) {
            if (!\EventUtil::sslRandPoll()) {
                die("EventUtil::sslRandPoll failed\n");
            }

            $this->ctx = new \EventSslContext(
                \EventSslContext::TLS_SERVER_METHOD ,
                array (
                    \EventSslContext::OPT_LOCAL_CERT  => $ssl['local_cert'],
                    \EventSslContext::OPT_LOCAL_PK    => $ssl['local_pk'],
                    \EventSslContext::OPT_PASSPHRASE  => "",
                    \EventSslContext::OPT_VERIFY_PEER => false,
                )
            );
        }

        stream_context_set_default(array(
            'ssl' => $ssl,
            'socket' => array(
                'so_reuseport' => 1,
                'backlog' => $this->backlog,
            ),
        ));
        $wrapper = empty($ssl) ? 'tcp' : 'tlsv1.2';
        $this->local_socket = "{$wrapper}://0.0.0.0:{$port}";
    }

    /**
     * 设置socket选项
     * @param resource $socket
     * @return void
     */
    protected function setSocketOption($socket) {
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_REUSEADDR, 1); //重用本地地址
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_KEEPALIVE, 1); //保持连接
        /*\EventUtil::setSocketOption($socket, SOL_SOCKET, SO_LINGER, array(
            'l_onoff' => 1,
            'l_linger' => 1,
        ));*/
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_SNDBUF, PHP_INT_MAX); //发送缓冲
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_RCVBUF, PHP_INT_MAX); //接收缓冲
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_DONTROUTE, 0); // 报告传出消息是否绕过标准路由设施：1只能在本机IP使用，0无限制
        $timeout = array('sec' => $this->timeout, 'usec' => 0);
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout); //发送超时
        \EventUtil::setSocketOption($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout); //接收超时
        \EventUtil::setSocketOption($socket, SOL_SOCKET, TCP_NODELAY, 1); //取消Nagle算法
    }

    public function checkEnvironment() {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Only run in command line');
        }

        if (!extension_loaded('libevent')) {
            throw new \RuntimeException("Please install libevent extension firstly");
        }
    }

    protected function setEventOption($event_buffer_event) {
        $event_buffer_event->enable(\Event::READ);
        $event_buffer_event->setWatermark(\Event::READ, 0, 0);
        $event_buffer_event->setPriority(0);
    }

    public function acceptError($listener, $arg) {
        $error_no = \EventUtil::getLastSocketErrno();
        $error_msg = \EventUtil::getLastSocketError();
        $this->logger->error('lister error:', compact('error_no', 'error_msg'));

        $this->base->exit(NULL);
    }

    /**
     * 接受新连接回调
     * @param $listener
     * @param $fd
     * @param $address
     * @param $ctx
     */
    public function acceptConnect($listener, $fd, $address, $ctx) {
        $this->setSocketOption($fd);
        if ($ctx) {
            $event_buffer_event = \EventBufferEvent::sslSocket(
                $this->base,
                $fd,
                $ctx,
                \EventBufferEvent::SSL_ACCEPTING,
                \EventBufferEvent::OPT_CLOSE_ON_FREE
            );
        } else {
            $event_buffer_event = new \EventBufferEvent(
                $this->base,
                $fd,
                \EventBufferEvent::OPT_CLOSE_ON_FREE
            );
        }

        if (!$event_buffer_event) {
            $this->base->exit(NULL);
            throw new \RuntimeException('Failed creating ssl buffer');
        }

        $index = $this->max_index++;
        $this->event_buffer_events[$index] = $event_buffer_event;
        $arg = compact('index', 'address', 'fd');

        $this->setEventOption($event_buffer_event);
        $event_buffer_event->setCallbacks(
            array($this, "readCallback"),
            NULL,
            array($this, "eventCallback"),
            $arg
        );
    }

    /**
     * 读取数据回调
     * @param $bev
     * @param $arg
     */
    public function readCallback($bev, $arg) {
        $index = $arg['index'];

        if (isset($this->handshake[$index])) {
            $params = array();
            $msg = $this->prepareData($bev, $params);
            if ($params['is_exception']) {
                return;
            }

            if ($params['is_ping']) {
                $protocol = chr(0b10001010);//0x0A pong帧--1010
                $bev->write($protocol);
                return;
            }

            if ($params['is_closed']) {
                $this->disConnect($index);

                $this->notify('onClose', array($this->encodeKey($index)));
                return;
            }

            if (!isset($msg[0])) {
                if (\EventUtil::getLastSocketErrno()) {
                    $error = $this->getError();
                    $this->logger->error("{$index}#socket ERROR:" . $error);

                    $this->disConnect($index);
                    $this->notify('onError', array(
                        $this->encodeKey($index),
                        $error,
                    ));
                }
                return;
            }

            isset($msg[$this->max_log_length]) or $this->logger->info('<' . $msg);//数据太大时不写日志
            if ($msg == 'PING') return;//Firefox

            $this->notify('onMessage', array($this->encodeKey($index), $msg));

            unset($msg);//图片数据很大，清空临时数据，释放内存
        } else {
            $buffer = $bev->read(2048);
            $this->headers['REMOTE_ADDR'] = $arg['address'][0];
            $handshake = $this->doHandShake($bev, $buffer, $index);
            if ($handshake) {
                $this->notify('onOpen', array($this->encodeKey($index), $this->headers)); //握手成功，通知客户端连接已经建立
            } else {
                $this->disConnect($index); //关闭连接
            }

            $this->headers = array();
        }
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
            $this->notify('onClose', array($this->encodeKey($index)));
        }
    }

    /**
     * 观察对象
     * @param IClient $client
     */
    public function attach(IClient $client) {
        $this->storage->attach($client);
    }

    /**
     * 解除对象
     * @param IClient $client
     */
    public function detach(IClient $client) {
        $this->storage->detach($client);
    }

    /**
     * 通知对象
     * @param string $method
     * @param array $params
     */
    protected function notify($method, $params) {
        foreach ($this->storage as $client) {
            call_user_func_array(array($client, $method), $params);
        }
    }

    /**
     * 开始运行
     * @param int $pid
     * @param resource $socket
     */
    public function run($pid, $socket) {
        $this->pid = $pid;
        $this->master = stream_socket_server($this->local_socket, $errno, $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        stream_socket_enable_crypto($this->master, false);

        $this->setSocketOption($this->master);

        $this->debug('Server Started : ' . date('Y-m-d H:i:s'));
        $this->debug('Listening on   : '. $this->local_socket);
        $this->debug('Master socket  : ' . $this->master);

        $this->base = new \EventBase();
        if (!$this->base) {
            die("Couldn't open event base\n");
        }
        $this->listener = new \EventListener(
            $this->base,
            array($this, "acceptConnect"),
            $this->ctx,
            \EventListener::OPT_CLOSE_ON_FREE | \EventListener::OPT_REUSEABLE,
            $this->backlog,
            $this->master
        );
        if (!$this->listener) {
            die("Couldn't create listener\n");
        }
        $this->listener->setErrorCallback(array($this, "acceptError"));

        if (!is_null($socket)) {
            $this->slave = $socket;
            $event_buffer_event = new \EventBufferEvent(
                $this->base,
                $socket,
                \EventBufferEvent::OPT_CLOSE_ON_FREE
            );

            if (!$event_buffer_event) {
                $this->base->exit(NULL);
                throw new \RuntimeException('Failed creating buffer');
            }

            $index = $this->max_index++;
            $this->event_buffer_events[$index] = $event_buffer_event;
            $arg = compact('index');

            $this->setEventOption($event_buffer_event);
            $event_buffer_event->setCallbacks(
                array($this, 'slaveReadCallback'),
                NULL,
                array($this, 'slaveEventCallback'),
                $arg
            );
        }

        $this->base->dispatch();
    }

    public function slaveReadCallback($bev, $arg) {
        // IPC消息
        $arr = $this->unSerializeIPC($bev);
        $this->logger->info('IPC回调：' . $arr['callback']);

        call_user_func_array(array($this, $arr['callback']), $arr['params']);
    }

    public function slaveEventCallback($bev, $events, $arg) {
        $index = $arg['index'];
        if ($events & \EventBufferEvent::ERROR) {
            $this->logger->error("Sub process " . $this->getError($bev));
        }

        if ($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR)) {
            $bev->free();
            unset($this->event_buffer_events[$index]);
        }
    }

    /**
     * 发送消息
     * @param int $key socket index
     * @param string $msg 消息
     */
    public function send($key, $msg) {
        list($pid, $index) = $this->decodeKey($key);
        if ($pid != $this->pid) {
            $this->logger->info('IPC转发', array(
                'pid' => $this->pid,
                'key' => $key,
            ));
            // 转发
            $data = [
                'callback' => 'sendToOther',
                'params' => [$pid, $index, $msg],
            ];
            fwrite($this->slave, $this->serializeIPC($data, $pid));
        } else {
            $this->doSend($index, $msg);
        }
    }

    protected function sendToOther($pid, $index, $msg) {
        if ($pid == $this->pid) {
            $this->doSend($index, $msg);
        }
    }

    protected function doSend($index, &$msg) {
        if (!isset($this->event_buffer_events[$index])) {
            return;
        }

        isset($msg[$this->max_log_length]) or $this->logger->info('>' . $msg);
        $event_buffer_event = $this->event_buffer_events[$index];
        $msg = $this->frame($msg);
        $len = strlen($msg);
        $res = $event_buffer_event->write($msg);
        if (false === $res) {
            $error = $this->getError();
            $this->logger->error("{$index}# write error:" . $error);

            $this->disConnect($index);
            $this->notify('onError', array(
                $this->encodeKey($index),
                $error,
            ));
        } else {
            $this->logger->info('!' . $len . ' bytes sent');
        }
    }

    public function sendAll($msg) {
        if (is_null($this->slave)) {
            $this->doSendAll($msg);
        } else {
            $data = [
                'callback' => 'doSendAll',
                'params' => [$msg],
            ];
            fwrite($this->slave, $this->serializeIPC($data, 0));
        }
    }

    /**
     * 给所有完成握手的客户端发送消息
     * @param string $msg
     */
    public function doSendAll($msg) {
        isset($msg[$this->max_log_length]) or $this->logger->info('*>' . $msg);
        $msg = $this->frame($msg);
        foreach ($this->event_buffer_events as $index => $event_buffer_event) {
            if (isset($this->handshake[$index])) {
                $len = strlen($msg);
                $res = $event_buffer_event->write($msg);
                if (false === $res) {
                    $error = $this->getError();
                    $this->logger->error("{$index}# write error:" . $error);

                    $this->disConnect($index);
                    $this->notify('onError', array(
                        $this->encodeKey($index),
                        $error,
                    ));
                } else {
                    $this->logger->info('*!' . $len . ' bytes sent');
                }
            }
        }
    }

    /**
     * 断开连接
     * @param int $index 服务下标
     */
    protected function disConnect($index) {
        isset($this->event_buffer_events[$index]) and ($this->event_buffer_events[$index])->free();
        $this->debug($index . ' DISCONNECTED!');
        unset($this->event_buffer_events[$index]);
        unset($this->handshake[$index]);
    }

    public function close($key) {
        list($pid, $index) = $this->decodeKey($key);
        if ($pid != $this->pid) {
            $this->logger->info('IPC转发', array(
                'pid' => $this->pid,
                'key' => $key,
            ));
            // 转发
            $data = [
                'callback' => 'closeOther',
                'params' => [$pid, $index],
            ];
            fwrite($this->slave, $this->serializeIPC($data, $pid));
        } else {
            $this->disConnect($index);
        }
    }

    protected function closeOther($pid, $index) {
        if ($pid == $this->pid) {
            $this->disConnect($index);
        }
    }

    /**
     * 服务端与客户端握手
     * @param \EventBufferEvent $event_buffer_event 客户端服务
     * @param string $buffer 二进制数据
     * @param int $index 服务下标
     * @return bool
     */
    protected function doHandShake($event_buffer_event , $buffer, $index) {
        $this->debug('Requesting handshake...');
        $this->debug($buffer);
        $this->headers += $this->getHeaders($buffer);


        // TODO 请求信息校验
        $key = isset($this->headers['Sec-WebSocket-Key']) ? $this->headers['Sec-WebSocket-Key'] : '';
        $this->debug("Handshaking...");
        $upgrade =
            "HTTP/1.1 101 Switching Protocol\r\n" .
            "Upgrade: websocket\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept: " . $this->calcKey($key) . "\r\n\r\n";  //必须以两个空行结尾
        $this->debug($upgrade);
        $res = $event_buffer_event->write($upgrade);
        if (false === $res) {
            $this->logger->error('Handshake failed!' . $this->getError($event_buffer_event));
            return false;
        } else {
            $this->handshake[$index] = true;
            $this->debug('Done handshaking...');
            return true;
        }
    }

    /**
     * 获取协议头部信息
     * @param string $request 请求数据
     * @return array
     */
    protected function getHeaders($request) {
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
        $res = array();
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
    public function calcKey($key) {
        //基于websocket version 13
        return base64_encode(sha1($key . self::GUID, true));
    }

    /**
     * 解码二进制流，所有数据接收完成时使用
     * @param string $buffer 二进制数据
     * @return null|string
     */
    protected function decode(&$buffer) {
        $decoded = '';
        do {
            if (!isset($buffer[1]))
                break;
            //$this->debug('===START DECODE===');
            //$fin = ord($buffer[0]) & 128;//例：00000001 & 10000000 = 00000000 = 0 连续帧，10000001 & 10000000 = 10000000 = 128 一帧
            $len = ord($buffer[1]) & 127;
            /*for ($i = 0, $n = min(14, strlen($buffer)); $i < $n; ++$i) {
                $this->debug("Byte[{$i}]:" . sprintf('%08b', ord($buffer[$i])));
            }*/
            if ($len == 126) {
                $unpack = unpack('n', substr($buffer, 2, 2));
                $length = current($unpack);
                $masks = substr($buffer, 4, 4);
                $data = substr($buffer, 8, $length);
                $full = strlen($buffer) - 8;
                $start = $length + 8;
            } else if ($len == 127) {
                $length = current(unpack('J', substr($buffer, 2, 8)));
                $masks = substr($buffer, 10, 4);
                $data = substr($buffer, 14, $length);
                $full = strlen($buffer) - 14;
                $start = $length + 14;
            } else {
                $length = $len;
                $masks = substr($buffer, 2, 4);
                $data = substr($buffer, 6, $length);
                $full = strlen($buffer) - 6;
                $start = $length + 6;
            }
            //$this->debug('------------------receive:' . $length);
            for ($index = 0, $n = strlen($data); $index < $n; ++$index) {
                $decoded .= $data[$index] ^ $masks[$index % 4];
            }

            $buffer = substr($buffer, $start);
            usleep(100);//防止可能死循环时占用CPU过高
        } while (($full > $length) && ($length > 0));//0 == $fin也可以根据数据长度$full > $length判断是否为连续帧
        return $decoded;
    }

    /**
     * 预处理数据，接收数据并且计算
     * @param \EventBufferEvent $event_buffer_event
     * @param array $params
     * @return string
     */
    protected function prepareData($event_buffer_event, array &$params) {
        $decoded = '';
        $params = array(
            'is_closed' => false,
            'is_ping' => false,
            'is_exception' => false,
        );
        $is_first = true;
//        echo 'IN:', PHP_EOL;

        do {
            $buffer = $event_buffer_event->read(2);
            $len = strlen($buffer);
            if ($is_first) {
                if (!isset($buffer[0])) {
                    $params['is_closed'] = true;
                    break;
                }

                //0x09 ping帧--1001
                if (chr(0b10001001) == $buffer[0]) {
                    $params['is_ping'] = true;
                    break;
                }

                //浏览器关闭时，发送关闭连接控制帧[一帧，opcode 0x8=1000] 10001000 = 十六进制\x88 = 八进制\210
                if (chr(0b10001000) == $buffer[0]) {
                    $params['is_closed'] = true;
                    break;
                }
            }
            if ($len < 2) {
                $params['is_exception'] = true;
                break;
            }

            $is_first = false;

            //一帧的结构：
            // |FIN:1bit RSV:3bit opcode:4bit | MASK:1bit Payload len:7bit | Extended payload length:16/64bit | Masking-key:4byte | Payload Data:...
            $FIN = ord($buffer[0]) >> 7; //0 连续帧，1 一帧

//			echo 'BUFFER[0]:', sprintf('%08b', ord($buffer[0])), PHP_EOL;
//			echo 'FIN:', $FIN, PHP_EOL;

            //0x0附加数据帧 0x1文本数据帧 0x2二进制数据帧 0x3-7无定义，保留 0x8连接关闭 0x9ping 0xApong 0xB-F无定义，保留
            $opcode = ord($buffer[0]) & 0b1111;

//			echo 'OPCODE:', sprintf('%04b', $opcode), ',', $opcode, PHP_EOL;

			$MASK = ord($buffer[1]) >> 7; // 1掩码
//			echo 'MASK:', $MASK, PHP_EOL;

            $payload_len = ord($buffer[1]) & 0b1111111; //127

//			echo 'BUFFER[1]:', sprintf('%08b', ord($buffer[1])), PHP_EOL;
//			echo 'PAYLOAD:', $payload_len, PHP_EOL;

            //数据长度：
            //Payload length:7bit/7+16bit(2 bytes)/7+64bit(8 bytes)
            //Payload len(7bit)<=125 Payload Data Length=Payload len(7bit)<=125
            //Payload len(7bit)==126 Payload Data Length=Extend payload length(16bit)<2^16 65535
            //Payload len(7bit)==127 Payload Data Length=Extend payload length(64bit)<2^64 PHP_INT_MAX(64位系统)
            //网络字节序--大端序 big endian byte order
            //一次读取payload length和Masking-key，方便计算
            switch ($payload_len) {
                case 126: //<2^16 65536
                    $read_length = 6; // 16bit+4byte=2+4
                    $buffer = $event_buffer_event->read($read_length);
                    $len = strlen($buffer);
                    $payload_length_data = substr($buffer, 0, 2);
                    $unpack = unpack('n', $payload_length_data); //16bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 2, 4);
                    break;
                case 127: //<2^64
                    $read_length = 12; // 64bit+4byte=8+4
                    $buffer = $event_buffer_event->read($read_length);
                    $len = strlen($buffer);
                    $payload_length_data = substr($buffer, 0, 8);
                    $unpack = unpack('J', $payload_length_data); //64bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 8, 4);
                    break;
                default: //<=125
                    $read_length = 4; // 4byte
                    $buffer = $event_buffer_event->read($read_length);
                    $len = strlen($buffer);
                    $payload_length_data = '';
                    $unpack = array();
                    $length = $payload_len;
                    $masks = $buffer;
                    break;
            }

            if (($len < $read_length) || ($length > $this->memory_limit) || ($length < 0)) {
                //异常情况：1.接收的长度小于计算的 2.计算的长度大于最大内存限制的 3.数据错误导致unpack计算的长度为负
                $params['is_exception'] = true;
                break;
            }

//			echo 'PAYLOAD_LENGTH_DATA:', $payload_length_data, PHP_EOL;
//			for ($i = 0, $n = strlen($payload_length_data); $i < $n; ++$i) {
//				echo sprintf('%08b', ord($payload_length_data[$i])), PHP_EOL;
//			}
//			echo 'LENGTH:', $length, PHP_EOL;
//			var_dump($unpack);

            //$finished_len = socket_recv($socket, $data, $length, MSG_WAITALL); //这里用阻塞模式，接收指定长度的数据
            $data = '';
            $finished_len = 0;
            do {
                // 每次都尝试以最大长度来读取，实际长度以接收到的为准
                $buff = $event_buffer_event->read($length);
                $bytes = strlen($buff);
                if (false === $bytes) {
                    $params['is_exception'] = true;
                    break 2;
                }
                $data .= $buff;
                $length -= $bytes;
                $finished_len += $bytes;
            } while ($length > 0);
            unset($buff);

//            echo 'FINISHED LENGTH:', $finished_len, PHP_EOL;

            //根据掩码解析数据，处理每个字节，比较费时
            for ($index = 0, $n = strlen($data); $index < $n; ++$index) {
                $decoded .= $data[$index] ^ $masks[$index % 4];
            }
            unset($buffer, $data); //销毁临时变量(可能很大)，释放内存
        } while (0 === $FIN); //连续帧
//        echo 'END.', PHP_EOL, PHP_EOL;
        return $decoded;
    }

    /**
     * 计算数据帧，多帧(每帧最多125字节)，参照ASCII编码(数据量多时效率低，不推荐)
     * @param $str
     * @return string
     */
    protected function frame2(&$str) {
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


    /**
     * 计算数据帧，一帧
     * @param $str
     * @param $type
     * @return string
     */
    protected function frame(&$str, $type = self::FRAME_TYPE_BINARY) {
        $protocol = chr($type);
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
     * @param resource $socket
     * @return string
     */
    protected function getError($socket = null) {
        return \EventUtil::getLastSocketError($socket);
    }

    public function debug($content) {
        if (!$this->debug) return true;
        return $this->logger->info($content);
    }

    public function forwardMessage($pid_list, $socket_list) {
        $resource = array();
        foreach ($socket_list as $key => $socket) {
            $resource[$pid_list[$key]] = $socket;
        }

        while (true) {
            // 主进程：阻塞接收消息
            $read = $socket_list;
            stream_select($read, $write, $except, null);

            foreach ($read as $item) {
                $protocol = fread($item, 10);
                $pid = current(unpack('n', substr($protocol, 0, 2)));
                $head = $this->getSerializeIPCHead(substr($protocol, 2, 8));
                $message = $head['string'] . stream_get_contents($item, $head['len']);

                // 通知子进程
                if (isset($resource[$pid])) {
                    fwrite($resource[$pid], $message);
                } else {
                    foreach ($socket_list as $socket) {
                        fwrite($socket, $message);
                    }
                }

                unset($message);
            }
        }
    }

    protected function serializeIPC(&$data, $pid) {
        $data = serialize($data);
        $len = strlen($data);
        $pid_head = pack('n', $pid);
        $head = $pid_head . pack('J', $len);

        return $head . $data;
    }

    protected function getSerializeIPCHead($head) {
        if (strlen($head) < 8) {
            return array(
                'len' => 0,
                'string' => '',
            );
        }

        $len = current(unpack('J', $head));
        return array(
            'len' => $len,
            'string' => $head,
        );
    }

    protected function unSerializeIPC(&$event_buffer_event) {
        $head = $event_buffer_event->read(8);
        $head = $this->getSerializeIPCHead($head);
        $len = $head['len'];

        return unserialize($event_buffer_event->read($len));
    }

    protected function encodeKey($key) {
        return $this->pid . ':' . $key;
    }

    protected function decodeKey($key) {
        return explode(':', $key, 2);
    }
}


