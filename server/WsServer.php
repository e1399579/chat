<?php
namespace server;

error_reporting(E_ALL);//调试时为E_ALL
set_time_limit(0);
ob_implicit_flush();//调试时打开

class WsServer implements IServer {
    const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    protected $master;//主机
    protected $sockets = array();//所有的服务数组
    protected $handshake = array();//服务握手标志
    protected $backlog = 0;//最大的积压连接数
    protected $storage;//业务处理对象存储容器
    protected $timeout = 3; //读取/发送超时
    protected $max_log_length = 1024; //消息记录在日志的最大长度
    protected $invalid_sockets = array(); //无效的socket:key => error
    protected $headers = array(); //请求头
    protected $memory_limit; //最大内存限制:byte
    protected $debug = false;
    /**
     * @var Logger
     */
    protected $logger;
    protected $master_queue;
    protected $worker_queues;
    protected $shared_memory_key;
    protected $shared_memory_var;

    public function __construct($port, $ssl = array()) {
        $this->checkEnvironment();

        $path = './logs/socket';
        $this->logger = Logger::getInstance($path);
        $this->memory_limit = intval(ini_get('memory_limit')) * 1024 * 1024;
        $this->storage = new \SplObjectStorage();

        stream_context_set_default(array(
            'ssl' => $ssl,
            'socket' => array(
                'so_reuseport' => 1,
                'backlog' => $this->backlog,
            ),
        ));
        $wrapper = empty($ssl) ? 'tcp' : 'tlsv1.2';
        $local_socket = "{$wrapper}://0.0.0.0:{$port}";
        $this->master = stream_socket_server($local_socket, $errno, $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);

        $this->setSocketOption(socket_import_stream($this->master));

        $this->sockets[] = $this->master;

        $key = ftok(__FILE__, 'm');
        $this->master_queue = msg_get_queue($key, 0666);
        $this->shared_memory_key = ftok(__FILE__, 'k');
        $this->shared_memory_var = ftok(__FILE__, 'v');

        $this->debug('Server Started : ' . date('Y-m-d H:i:s'));
        $this->debug('Listening on   : '. $local_socket);
        $this->debug('Master socket  : ' . $this->master);
    }

    public function checkEnvironment() {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Only run in command line');
        }

        if (!extension_loaded('libevent')) {
            throw new \RuntimeException("Please install libevent extension firstly");
        }
    }

    /**
     * 设置socket选项
     * @param resource $socket
     * @return void
     */
    protected function setSocketOption($socket) {
        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1); //使用本地地址
        socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1); //保持连接
        /*socket_set_option($socket, SOL_SOCKET, SO_LINGER, array(
            'l_onoff' => 1,
            'l_linger' => 1,
        ));*/
        socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, PHP_INT_MAX); //发送缓冲
        socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, PHP_INT_MAX); //接收缓冲
        socket_set_option($socket, SOL_SOCKET, SO_DONTROUTE, 0); // 报告传出消息是否绕过标准路由设施：1只能在本机IP使用，0无限制
        $timeout = array('sec' => $this->timeout, 'usec' => 0);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $timeout); //发送超时
        socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, $timeout); //接收超时
        socket_set_option($socket, SOL_SOCKET, TCP_NODELAY, 1); //取消Nagle算法
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
     */
    public function run() {
        $scheduler = new Scheduler();

        $scheduler->newTask($this->ioPoll());
        $scheduler->newTask($this->waitMessage());

        $scheduler->run();
    }

    /**
     * 监听事件
     * @return \Generator
     */
    protected function ioPoll() {
        do {
            $read = $this->sockets;
            /**
             * 多路选择
             * a.刚开始运行时，阻塞在这里(第四个参数)，等待客户端加入
             * b1.当有客户端新连接加入时，第一个参数会变化，包含主机socket(下标为0)
             * b2.当有客户端发送消息时，第一个参数会变化，不包含主机socket(注意：并发量大时，会有主机、客户端混杂的情况)
             * c.新连接会由主机接受连接，产生一个客户端服务，加入sockets数组，为下次循环作准备
             * d.遍历每个客户端，如果是新连接则服务器与客户端握手(每个连接只执行一次)
             * e.读取二进制数据，解码(如果关闭了浏览器，则读取不到数据，从数组中删除客户端socket)
             * f.编码为二进制(网络字节序)消息，返回给客户端文本(opcode=0001)/二进制(opcode=0010)数据
             * g.重复执行d-f步，当所有客户端都关闭时，则会进入a步
             */
            stream_select($read, $write, $except, NULL);

            if (isset($read[0])) {
                //有主机socket，意味着有新的连接
                $this->connect();//连接，加入客户端服务

                unset($read[0]);
            }
            //遍历客户端服务
            foreach ($read as $key => $socket) {
                $params = array();
                $msg = $this->prepareData($socket, $params);
                if ($params['is_exception']) {
                    continue;
                }

                if ($params['is_ping']) {
                    $protocol = chr(0b10001010);//0x0A pong帧--1010
                    fwrite($socket, $protocol);
                    continue;
                }

                if ($params['is_closed']) {
                    $this->disConnect($key);

                    $this->notify('onClose', array($key));
                    continue;
                }

                if (!isset($msg[0])) {
                    if (($no = socket_last_error(socket_import_stream($socket))) > 0) {
                        $this->logger->error("$key#socket ERROR:" . socket_strerror($no));
                        $this->disConnect($key);

                        $this->notify('onClose', array($key));
                    }
                    continue;
                }

                isset($msg[$this->max_log_length]) or $this->logger->info('<' . $msg);//数据太大时不写日志
                if ($msg == 'PING') continue;//Firefox

                $this->notify('onMessage', array($key, $msg));

                unset($msg);//图片数据很大，清空临时数据，释放内存
            }

            //出错的统一通知
            foreach ($this->invalid_sockets as $key => $err) {
                $this->logger->error("socket#{$key} ERROR:{$err}");
                $this->notify('onError', array($key, $err));
            }

            $this->invalid_sockets = array(); //释放内存

            yield;
        } while (true);
    }


    /**
     * 发送消息
     * @param int $index socket index
     * @param string $msg 消息
     */
    public function send($index, $msg) {
        if (!isset($this->sockets[$index])) return;
        isset($msg[$this->max_log_length]) or $this->logger->info('>' . $msg);
        $socket = $this->sockets[$index];
        $msg = $this->frame($msg);
        $len = strlen($msg);
        $res = fwrite($socket, $msg);
        if (false === $res) {
            $this->disConnect($index);

            $this->invalid_sockets[$index] = 'socket_write() fail!Broken pipe';
        } else {
            $this->logger->info('!' . $len . ' bytes sent');
        }
    }

    public function sendAll($msg) {
        $shm_id = shm_attach($this->shared_memory_key, strlen($msg) + 100, 0666);

        if (false !== $shm_id) {
            $sem_id = sem_get(ftok(__FILE__, 'a'));
            sem_acquire($sem_id);
            shm_put_var($shm_id, $this->shared_memory_var, [$msg]);
            sem_release($sem_id);

            msg_send($this->master_queue, 1, 'doSendAll');
        }
    }

    /**
     * 给所有完成握手的客户端发送消息
     * @param string $msg
     */
    public function doSendAll($msg) {
        isset($msg[$this->max_log_length]) or $this->logger->info('*' . $msg);
        $msg = $this->frame($msg);
        reset($this->sockets); //将数组的内部指针指向第一个单元
        isset($this->sockets[0]) and next($this->sockets);
        while (list($key, $socket) = each($this->sockets)) {
            if (isset($this->handshake[$key])) {
                $len = strlen($msg);
                $res = fwrite($socket, $msg);
                if (false === $res) {
                    $this->disConnect($key);

                    $this->invalid_sockets[$key] = 'socket_write() fail!Broken pipe';
                } else {
                    $this->logger->info('*' . $len . ' bytes sent');
                }
            }
        }
    }

    /**
     * 新的连接
     */
    protected function connect() {
        $client = stream_socket_accept($this->master);
        if (false === $client) {
            $this->logger->error('socket_accept() failed:' . $this->getError($this->master));
            return false;
        }
        $this->setSocketOption(socket_import_stream($client));

        $this->debug($client . ' CONNECTED!');

        array_push($this->sockets, $client);

        end($this->sockets); //指向最后一个单位
        $key = key($this->sockets); //新的服务索引
        reset($this->sockets); //复位
        //服务端与客户端握手，无阻塞读取
        $buffer = fread($client, 2048); //如果header头信息较多，可以调大此值
        $len = strlen($buffer);
        if (0 == $len) {
            unset($this->sockets[$key]); //未完成握手，不用通知客户端
            $handshake = false;
        } else {
            $handshake = $this->doHandShake($client, $buffer, $key);
            unset($buffer);

            if ($handshake) {
                $this->notify('onOpen', array($key, $this->headers)); //握手成功，通知客户端连接已经建立
            } else {
                $this->disConnect($key); //关闭连接
            }

            $this->headers = array();
        }
        return $handshake;
    }

    /**
     * 断开连接
     * @param int $index 服务下标
     */
    public function disConnect($index) {
        isset($this->sockets[$index]) and fclose($this->sockets[$index]);
        $this->debug($index . ' DISCONNECTED!');
        $this->tearDown($index);
    }

    /**
     * 注销服务
     * @param $index
     */
    protected function tearDown($index) {
        unset($this->sockets[$index]);
        unset($this->handshake[$index]);
    }

    /**
     * 服务端与客户端握手
     * @param resource $socket 客户端服务
     * @param string $buffer 二进制数据
     * @param int $index 服务下标
     * @return bool
     */
    protected function doHandShake($socket, $buffer, $index) {
        $this->debug('Requesting handshake...');
        $this->debug($buffer);
        $this->headers = $this->getHeaders($buffer);
        $peer_name = stream_socket_get_name($socket, true);
        $peer_info = explode(':', $peer_name, 2);
        $this->headers['REMOTE_ADDR'] = $peer_info[0];
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
        $bytes = fwrite($socket, $upgrade);
        if (false === $bytes) {
            $this->logger->error('Handshake failed!' . $this->getError($socket));
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
     * @param resource $socket
     * @param array $params
     * @return string
     */
    protected function prepareData($socket, array &$params) {
        $decoded = '';
        $params = array(
            'is_closed' => false,
            'is_ping' => false,
            'is_exception' => false,
        );
        $is_first = true;
//        echo 'IN:', PHP_EOL;

        do {
            $buffer = fread($socket, 2);
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
                    $buffer = fread($socket, $read_length);
                    $len = strlen($buffer);
                    $payload_length_data = substr($buffer, 0, 2);
                    $unpack = unpack('n', $payload_length_data); //16bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 2, 4);
                    break;
                case 127: //<2^64
                    $read_length = 12; // 64bit+4byte=8+4
                    $buffer = fread($socket, $read_length);
                    $len = strlen($buffer);
                    $payload_length_data = substr($buffer, 0, 8);
                    $unpack = unpack('J', $payload_length_data); //64bit 字节序
                    $length = current($unpack);
                    $masks = substr($buffer, 8, 4);
                    break;
                default: //<=125
                    $read_length = 4; // 4byte
                    $buffer = fread($socket, $read_length);
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
                $buff = fread($socket, $length);
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
     * @return string
     */
    protected function frame(&$str) {
        $protocol = chr(0b10000010);
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
    protected function getError($socket) {
        return socket_strerror(socket_last_error(socket_import_stream($socket)));
    }

    public function debug($content) {
        if (!$this->debug) return true;
        return $this->logger->info($content);
    }

    public function attachMessage() {
        while (true) {
            // 主进程：阻塞接收消息
            msg_receive($this->master_queue, 0, $msgtype, 65535, $message);

            $this->notifyWorks($msgtype, $message);
        }
    }

    protected function notifyWorks($msgtype, $message) {
        foreach ($this->worker_queues as $worker_queue) {
            msg_send($worker_queue, $msgtype, $message);
        }
    }

    /**
     * 等待队列消息
     * @return \Generator
     */
    protected function waitMessage() {
        $pid = posix_getpid();
        $queue = msg_get_queue($pid, 0666);
        while (true) {
            // 子进程：无阻塞接收消息
            msg_receive($queue, 1, $msgtype, 65535, $message, true, MSG_IPC_NOWAIT);

            $shm_id = shm_attach($this->shared_memory_key);
            if (false !== $shm_id) {
                $params = shm_get_var($shm_id, $this->shared_memory_var);
                call_user_func_array(array($this, $message), $params);
                shm_remove($shm_id);
            }

            yield;
        }
    }

    public function initWorks($num) {
        $pid = posix_getpid();
        for ($i = 1; $i <= $num; ++ $i) {
            $this->worker_queues[] = msg_get_queue($pid + $i, 0666);
        }
    }
}


