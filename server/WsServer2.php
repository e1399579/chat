<?php
namespace server;

error_reporting(E_ALL);//调试时为E_ALL
set_time_limit(0);
ob_implicit_flush();//调试时打开

class WsServer
{
	const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	public $master;//主机
	public $sockets = array();//所有的服务数组
	public $handshake = array();//服务握手标志
	public $backlog = 0;//最大的积压连接数
	public $storage;//业务处理对象存储容器
	public $debug = false;
	public $bytes = 1448;//每次读的字节，算法待确认(MTU,1500-20-20-12=1448 ?)
	public $usleep = 10;//每次读取间隔时间

	public function __construct($address, $port)
	{
		$this->storage = new \SplObjectStorage();
		//产生一个socket
		$this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)
		or die('socket_create() failed:' . $this->getError($this->master));

		//设置socket选项
		$this->setSocketOption($this->master);

		//把socket绑定在一个IP地址和端口上
		socket_bind($this->master, $address, $port)
		or die('socket_bind() failed:' . $this->getError($this->master));

		//监听由指定socket的所有连接
		socket_listen($this->master, $this->backlog)
		or die('socket_listen() failed:' . $this->getError($this->master));

		$this->sockets[] = $this->master;
		$this->debug('Server Started : ' . date('Y-m-d H:i:s'));
		$this->debug('Listening on   : ' . $address . ' port ' . $port);
		$this->debug('Master socket  : ' . $this->master);
	}

	/**
	 * 设置socket选项
	 * @param resource $socket
	 * @return void
	 */
	public function setSocketOption($socket)
	{
		socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
		socket_set_option($socket, SOL_SOCKET, SO_LINGER, array(
			'l_onoff' => 1,
			'l_linger' => 1,
		));
		socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, PHP_INT_MAX);
		socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, PHP_INT_MAX);
		socket_set_option($socket, SOL_SOCKET, SO_DONTROUTE, 1);
		socket_set_option($socket, SOL_SOCKET, TCP_NODELAY, 1);
	}

	/**
	 * 观察对象
	 * @param IClient $client
	 */
	public function attach(IClient $client)
	{
		$this->storage->attach($client);
	}

	/**
	 * 解除对象
	 * @param IClient $client
	 */
	public function detach(IClient $client)
	{
		$this->storage->detach($client);
	}

	/**
	 * 通知对象
	 * @param string $method
	 * @param array $params
	 */
	public function notify($method, $params)
	{
		foreach ($this->storage as $client) {
			call_user_func_array(array($client, $method), $params);
		}
	}

	/**
	 * 开始运行
	 */
	public function run()
	{
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
			socket_select($read, $write, $except, NULL);

			if (isset($read[0])) {//有主机socket
				$this->connect();//连接，加入客户端服务
				unset($read[0]);
			}
			//遍历客户端服务
			foreach ($read as $key => $socket) {
				if (!isset($this->handshake[$key])) {
					//服务端与客户端握手
					socket_recv($socket, $buffer, $this->bytes, 0);
					$headers = $this->doHandShake($socket, $buffer, $key);
					unset($buffer);

					$this->notify('onOpen', array($key, $headers));
					unset($headers);

					continue;
				}

				/*var_dump(socket_get_option($socket, SOL_SOCKET, TCP_NODELAY));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_BROADCAST));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_REUSEADDR));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_KEEPALIVE));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_LINGER));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_OOBINLINE));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_SNDBUF));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_RCVBUF));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_ERROR));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_TYPE));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_DONTROUTE));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_RCVLOWAT));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_RCVTIMEO));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_SNDTIMEO));
				var_dump(socket_get_option($socket, SOL_SOCKET, SO_SNDLOWAT));*/

				$buff = '';
				do {
					$buffer = '';
					//从socket里结束数据到缓存，强制进行计算，防止接收的数据正好是$this->bytes倍数时阻塞
					$bytes = 0 + socket_recv($socket, $buffer, $this->bytes, 0);
					$buff .= $buffer;
					usleep($this->usleep);
				} while ($bytes == $this->bytes);//当读取的字节不等于指定值时，说明读取完毕，结束循环

				if (!isset($buff[0])) {
					$this->log(sprintf('index=%s socket DATA EMPTY!msg:%s', $key, $this->getError($socket)), 'ERROR');
					$this->disConnect($key);

					$this->notify('onClose', array($key));

					continue;
				}

				if ((chr('10001001') == $buff[0])) {//0x09 ping帧--1001
					$protocol = chr('10001010');//0x0A pong帧--1010
					socket_write($socket, $protocol, strlen($protocol));
					continue;
				}

				//浏览器关闭时，发送关闭连接控制帧10001000，opcode为0x8(后四位1000)，十进制136，十六进制\x88，八进制\210
				if ("\210" == $buff[0]) {
					$this->disConnect($key);

					$this->notify('onClose', array($key));

					continue;
				}

				$msg = $this->decode($buff);//解码客户端数据
				if (!isset($msg{0})) continue;//空字符
				isset($msg{10240}) or $this->log('<' . $msg);//数据太大时不写日志
				if ($msg == 'PING') continue;//Firefox

				$this->notify('onMessage', array($key, $msg));

				unset($buff, $buffer, $msg);//图片数据很大，清空临时数据，释放内存
			}
			unset($read);
		} while (true);
	}


	/**
	 * 发送消息
	 * @param int $index socket index
	 * @param string $msg 消息
	 */
	public function send($index, &$msg)
	{
		isset($msg{10240}) or $this->log('>' . $msg);
		$msg = $this->frame($msg);
		socket_write($this->sockets[$index], $msg, strlen($msg));
		$this->log('!' . strlen($msg) . ' bytes sent');
	}

	/**
	 * 给所有完成握手的客户端发送消息
	 * @param string $msg
	 */
	public function sendAll(&$msg)
	{
		isset($msg{10240}) or $this->log('*' . $msg);
		$msg = $this->frame($msg);
		reset($this->sockets);// 将数组的内部指针指向第一个单元
		isset($this->sockets[0]) and next($this->sockets);
		while (list($key, $socket) = each($this->sockets)) {
			if (isset($this->handshake[$key])) {
				$res = socket_write($socket, $msg, strlen($msg));
				if (false === $res) {
					$this->disConnect($key);
					$err = $this->getError($socket);

					foreach ($this->storage as $client) {
						$client->onError($key, $err);
					}

					$this->log('socket_write() fail!' . $err, 'ERROR');
				}
				$this->log('*' . strlen($msg) . ' bytes sent');
			}
		}
	}

	/**
	 * 新的连接
	 */
	public function connect()
	{
		$client = socket_accept($this->master);
		if (false === $client) {
			$this->log('socket_accept() failed:' . $this->getError($this->master), 'ERROR');
			return;
		}
		$this->setSocketOption($client);

		array_push($this->sockets, $client);
		$this->debug($client . ' CONNECTED!');
	}

	/**
	 * 断开连接
	 * @param int $index 服务下标
	 */
	public function disConnect($index)
	{
		socket_close($this->sockets[$index]);
		$this->debug($index . ' DISCONNECTED!');
		unset($this->sockets[$index]);
		unset($this->handshake[$index]);
	}

	/**
	 * 服务端与客户端握手
	 * @param resource $socket 客户端服务
	 * @param string $buffer 二进制数据
	 * @param int $index 服务下标
	 * @return array
	 */
	public function doHandShake($socket, $buffer, $index)
	{
		$this->debug('Requesting handshake...');
		$this->debug($buffer);
		$headers = $this->getHeaders($buffer);
		$key = isset($headers['Sec-WebSocket-Key']) ? $headers['Sec-WebSocket-Key'] : '';
		$this->debug("Handshaking...");
		$upgrade =
			"HTTP/1.1 101 Switching Protocol\r\n" .
			"Upgrade: websocket\r\n" .
			"Sec-WebSocket-Version: 13\r\n" .
			"Connection: Upgrade\r\n" .
			"Sec-WebSocket-Accept: " . $this->calcKey($key) . "\r\n\r\n";  //必须以两个空行结尾
		$this->debug($upgrade);
		$bytes = socket_write($socket, $upgrade, strlen($upgrade));
		if (false === $bytes) {
			$this->log('Handshake failed!' . $this->getError($socket), 'ERROR');
		} else {
			$this->handshake[$index] = true;
			$this->debug('Done handshaking...');
		}
		return $headers;
	}

	/**
	 * 获取协议头部信息
	 * @param string $request 请求数据
	 * @return array
	 */
	public function getHeaders($request)
	{
		$headers = explode("\r\n", $request);
		unset($headers[0]);
		$res = array();
		foreach ($headers as $row) {
			$arr = explode(':', $row);
			isset($arr[1]) and $res[trim($arr[0])] = trim($arr[1]);
		}
		return $res;
		/*$r = $h = $o = $key = $agent = '';
		if (preg_match("/GET (.*) HTTP/", $request, $match)) {
			$r = $match[1];
		}
		if (preg_match("/Host: (.*)\r\n/", $request, $match)) {
			$h = $match[1];
		}
		if (preg_match("/Origin: (.*)\r\n/", $request, $match)) {
			$o = $match[1];
		}
		if (preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $request, $match)) {
			$key = $match[1];
		}
		if (preg_match("/User-Agent: (.*)\r\n/", $request, $match)) {
			$agent = $match[1];
		}
		return array($r, $h, $o, $key, $agent);*/
	}

	/**
	 * 生成服务端key
	 * @param string $key 客户端的key
	 * @return string
	 */
	public function calcKey($key)
	{
		//基于websocket version 13
		return base64_encode(sha1($key . self::GUID, true));
	}

	/**
	 * 解码二进制流
	 * @param string $buffer 二进制数据
	 * @return null|string
	 */
	public function decode(&$buffer)
	{
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
	 * 计算数据帧，多帧(每帧最多125字节)，参照ASCII编码(数据量多时效率低，不推荐)
	 * @param $str
	 * @return string
	 */
	public function frame2(&$str)
	{
		//参照协议，FIN(1)+RSV1(0)+RSV2(0)+RSV3(0)+opcode(0001)
		//opcode:0001表示文本数据帧，也可以直接写成十六进制"\x81"或者八进制"\201"
		$protocol = chr('10000001');
		$arr = str_split($str, 125);
		//只有一帧，即结束帧
		if (1 == count($arr))
			return $protocol . chr(strlen($arr[0])) . $arr[0];

		//多帧=起始帧+附加帧+结束帧
		$start = chr('00000001');//起始帧
		$additional = chr('00000000');//附加帧
		$end = chr('10000000');//结束帧
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
	public function frame(&$str)
	{
		$protocol = chr('10000001');
		$len = strlen($str);
		if ($len <= 125)
			return $protocol . chr($len) . $str;//8+7位
		else if ($len <= 65535)//最大2^16字节
			return $protocol . chr(126) . pack('n', $len) . $str;//8+7+16位
		else//最大2^64字节
			return $protocol . chr(127) . pack('J', $len) . $str;//8+7+64位
		/*$b1 = 0x80 | (0x1 & 0x0f);
		$length = strlen($str);
		if ($length <= 125) $header = pack('CC', $b1, $length);
		elseif ($length > 125 && $length < 65536) $header = pack('CCn', $b1, 126, $length);
		else $header = pack('CCNN', $b1, 127, $length);
		return $header . $str;*/
	}

	/**
	 * 获取错误信息
	 * @param resource $socket
	 * @return string
	 */
	public function getError($socket)
	{
		return socket_strerror(socket_last_error($socket));
	}

	/**
	 * 记录日志
	 * @param string $content
	 * @param string $flag
	 * @return int
	 */
	public function log($content, $flag = 'INFO')
	{
		$dir = sprintf('%s/../logs/%s', __DIR__, date('Y-m-d'));
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		$filename = sprintf('%s/socket.%s@%s.log', $dir, $flag, date('H'));
		is_array($content) and $content = json_encode($content, JSON_UNESCAPED_UNICODE);
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$content = '[' . date('Y-m-d H:i:s') . '] ' . $content . PHP_EOL . json_encode($trace, JSON_UNESCAPED_UNICODE) . PHP_EOL . PHP_EOL;
		$fp = fopen($filename, 'a');
		$res = fwrite($fp, $content);
		fclose($fp);
		return $res;
	}

	public function debug($content, $flag = 'INFO')
	{
		if (!$this->debug) return true;
		return $this->log($content, $flag);
	}
}


