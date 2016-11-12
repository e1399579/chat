<?php
define('APP_PATH', realpath('.'));
function autoload($class)
{
	require APP_PATH . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
}

spl_autoload_register('autoload');

use server\IClient;
use server\WsServer;
use server\User;

class Client implements IClient
{
	private $server;//WebSocket
	private $user;//用户
	public $userService = array();//user_id=>socket key
	public $serviceUser = array();//socket key=>user_id
	public $serviceAgent = array();//socket key=>agent
	public $serviceIp = array();//socket key=>ip
	public $login = '欢迎%USERNAME%进入聊天室';
	public $logout = '%USERNAME%退出聊天室';
	public $remove = '用户%USERNAME%被管理员移除聊天室';
	private $debug = false;//调试开关
	public $upload = 'upload';//上传目录

	const COMMON = 0;//公共消息
	const WELCOME = 1;//欢迎消息
	const QUIT = -1;//退出消息
	const SELF = 2;//本人消息
	const OTHER = 3;//他人消息
	const PERSONAL = 4;//私信
	const ONLINE = 10;//在线用户
	const REGISTER = 99;//用户注册
	const LOGIN = 100;//用户登录
	const ERROR = -2;//错误消息
	const WARNING = -3;//警告消息
	const REMOVE = -100;//移除用户
	const SYSTEM = 11;//系统消息
	const FORBIDDEN = -99;//禁用
	const DOWNLINE = -10;//下线
	const INCORRECT = -4;//用户名/密码错误
	const COMMON_IMAGE = 20;//公共图片
	const SELF_IMAGE = 22;//本人图片
	const OTHER_IMAGE = 23;//他人图片
	const PERSONAL_IMAGE = 24;//私信
	const COMMON_EMOTION = 30;//公共表情
	const SELF_EMOTION = 32;//本人表情
	const OTHER_EMOTION = 33;//他人图片
	const PERSONAL_EMOTION = 34;//私信表情
	const AVATAR_UPLOAD = 42;//上传头像
	const AVATAR_SUCCESS = 43;//上传成功
	const AVATAR_FAIL = -43;//上传失败
	const HISTORY_COMMON_MESSAGE = 50; //历史公共消息
	const HISTORY_PERSONAL_MESSAGE = 51; //历史个人消息
	const COMMON_MUSIC = 60; //公共音乐
	const SELF_MUSIC = 62; //本人音乐
	const OTHER_MUSIC = 63; //他人音乐
	const PERSONAL_MUSIC = 64; //私信音乐
	const PERSONAL_VIDEO_REQUEST = 70; //私信视频请求
	const PERSONAL_VIDEO_OFFLINE = -70; //离线
	const PERSONAL_VIDEO_ALLOW = 71; //请求通过
	const PERSONAL_VIDEO_DENY = -71; //请求拒绝
	const PERSONAL_VIDEO_OPEN = 72; //打开摄像头
	const PERSONAL_VIDEO_CLOSE = -72; //关闭摄像头
	const PERSONAL_VIDEO_END = 79; //传输结束

	const PERSONAL_VIDEO_OFFER_DESC = 80;
	const PERSONAL_VIDEO_ANSWER_DESC = 81;
	const PERSONAL_VIDEO_CANDIDATE = 82;

	const COMMON_VIDEO_REQUEST = 90;
	const COMMON_VIDEO_NOTIFY = 91;
	const PERSONAL_VIDEO_NOTIFY = 92;

	public function __construct($address, $port)
	{
		$this->server = new WsServer($address, $port);
		$this->server->attach($this);
		$this->user = new User();
	}

	public function onOpen($key, $headers)
	{
		$this->serviceAgent[$key] = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
		$this->serviceIp[$key] = isset($headers['X-Real-IP']) ? $headers['X-Real-IP'] :
			($headers['Host'] ? $headers['Host'] : '');
		$this->debug(var_export($headers, true));
	}

	public function onMessage($key, $message)
	{
		//发送消息-处理业务
		$arr = $this->decode($message);
		if (!isset($arr['type'])) {
			$this->log(sprintf('数据不完整，共 %s bytes', strlen($message)), 'ERROR');
			$this->warning($key, array('type' => self::ERROR, 'mess' => '读取消息出错'));
			unset($message);
			return;
		}

		unset($message);
		$type = $arr['type'] + 0;

		if (!in_array($type, array(self::PERSONAL_VIDEO_OFFER_DESC, self::PERSONAL_VIDEO_ANSWER_DESC, self::PERSONAL_VIDEO_CANDIDATE)))
			array_walk_recursive($arr, function (&$item, $key) {
				$item = addslashes(htmlspecialchars(trim($item)));
			});

		$user_id = 0;
		$sender = array('user_id' => 0, 'username' => '');
		if ($type != self::REGISTER) {
			//除了注册，所有操作都需要进行验证
			$user_id = $arr['sender_id'];
			$sender = $this->auth($key, $user_id);
			if (false == $sender)
				return;
		}

		$receiver_id = 0;
		$receiver = array('user_id' => 0, 'username' => '');
		if (!empty($arr['receiver_id'])) {
			$receiver_id = $arr['receiver_id'];
			$receiver = $this->user->getUserById($receiver_id);
		}

		$mess = isset($arr['mess']) ? $arr['mess'] : '';

		$timestamp = microtime(true);
		switch ($type) {
			case self::REGISTER: //注册
				$username = $arr['username'];
				$password = $arr['password'];
				if (empty($username)) {
					$this->register($key);
					break;
				}
				$info = array('user_id', 'username', 'role_id', 'is_active', 'password');
				$user = $this->user->getUserByName($username, $info);

				$headers = array('ip' => $this->serviceIp[$key], 'agent' => $this->serviceAgent[$key]);
				empty($user['user_id']) and $user = $this->user->register($username, $password, $headers);
				if (empty($user['user_id'])) {
					$this->warning($key, array(
						'type' => self::ERROR,
						'mess' => '注册用户失败！',
					));
					break;
				}
				if ($password != $user['password']) {
					$this->warning($key, array(
						'type' => self::INCORRECT,
						'mess' => '用户名或密码错误',
					));
					break;
				}
				unset($user['password']);
				$this->login($key, $user);

				break;
			case self::LOGIN:
				$headers = array('ip' => $this->serviceIp[$key], 'agent' => $this->serviceAgent[$key]);
				$this->user->update($user_id, $headers);
				$this->login($key, $sender);

				unset($headers, $this->serviceIp[$key], $this->serviceAgent[$key]);
				break;
			case self::COMMON: //公共消息
				$this->sendCommonMessage($key, $type, $sender, $mess, $timestamp);
				break;
			case self::COMMON_IMAGE: //公共图片
				$mess = $this->getUniqueFile($mess);//图片直接存为文件，节省编码时间
				$this->sendCommonMessage($key, $type, $sender, $mess, $timestamp);
				break;
			case self::COMMON_EMOTION: //公共表情
				$content = explode('_', $mess);
				$mess = "/images/emotion/{$content[0]}/{$content[1]}";
				$this->sendCommonMessage($key, $type, $sender, $mess, $timestamp);
				break;
			case self::PERSONAL: //私信
				$types = array($type, self::SELF, self::OTHER);
				$this->sendPersonalMessage($key, $types, $sender, $receiver, $mess, $timestamp);
				break;
			case self::PERSONAL_IMAGE: //私信图片
				$types = array($type, self::SELF_IMAGE, self::OTHER_IMAGE);
				$mess = $this->getUniqueFile($mess);
				$this->sendPersonalMessage($key, $types, $sender, $receiver, $mess, $timestamp);
				break;
			case self::PERSONAL_EMOTION: //私信表情
				$types = array($type, self::SELF_EMOTION, self::OTHER_EMOTION);
				$content = explode('_', $mess);
				$mess = "/images/emotion/{$content[0]}/{$content[1]}";
				$this->sendPersonalMessage($key, $types, $sender, $receiver, $mess, $timestamp);
				break;
			case self::COMMON_MUSIC:
				$data = $mess['data'];
				$name = $mess['name'];
				$mess = $this->getUniqueFile($data, 'music');
				$extra = array(
					'name' => $name,
				);
				$this->sendCommonMessage($key, $type, $sender, $mess, $timestamp, $extra);
				break;
			case self::PERSONAL_MUSIC:
				$data = $mess['data'];
				$name = $mess['name'];
				$mess = $this->getUniqueFile($data, 'music');

				$types = array($type, self::SELF_MUSIC, self::OTHER_MUSIC);
				$extra = array(
					'name' => $name,
				);
				$this->sendPersonalMessage($key, $types, $sender, $receiver, $mess, $timestamp, $extra);
				break;
			case self::PERSONAL_VIDEO_REQUEST:
			case self::PERSONAL_VIDEO_ALLOW:
				$mess = '';
				$this->sendPersonalVideoMessage($key, $type, $sender, $receiver, $mess, $timestamp);
				break;
			case self::PERSONAL_VIDEO_DENY:
				$mess = '拒绝了视频请求';
				$this->sendPersonalVideoMessage($key, $type, $sender, $receiver, $mess, $timestamp);
				break;
			case self::PERSONAL_VIDEO_OPEN:
			case self::PERSONAL_VIDEO_CLOSE:
			case self::PERSONAL_VIDEO_OFFER_DESC:
			case self::PERSONAL_VIDEO_ANSWER_DESC:
			case self::PERSONAL_VIDEO_CANDIDATE:
			case self::COMMON_VIDEO_NOTIFY:
			case self::PERSONAL_VIDEO_NOTIFY:
				$this->sendPersonalVideoMessage($key, $type, $sender, $receiver, $mess, $timestamp);
				break;
			case self::PERSONAL_VIDEO_END:
				$mess = '视频聊天结束';
				$this->sendPersonalVideoMessage($key, $type, $sender, $receiver, $mess, $timestamp);
				break;
			case self::COMMON_VIDEO_REQUEST:
				$this->sendCommonVideoMessage($key, $type, $sender, $mess, $timestamp);
				break;
			case self::REMOVE: //移除，由管理员发起
				$receiver_id = $arr['receiver_id'];
				$user = $this->user->getUserById($receiver_id);
				if (empty($user['user_id']) || empty($sender['role_id'])) {
					$this->warning($key, array(
						'type' => self::WARNING,
						'mess' => '移除失败！用户不存在或者非法操作',
					));
					break;
				}
				if ($sender['role_id'] <= $user['role_id']) {
					$this->warning($key, array(
						'type' => self::WARNING,
						'mess' => '移除失败！您没有该权限',
					));
					break;
				}
				$info = array('is_active' => 0);
				$res = $this->user->update($receiver_id, $info);
				if (false == $res) {
					$this->warning($key, array(
						'type' => self::WARNING,
						'mess' => '移除失败！服务器出错',
					));
					break;
				}

				$key = $this->userService[$receiver_id];//用户的服务索引
				$mess = $this->encode(array(
					'type' => self::REMOVE,
					'user_id' => $receiver_id,
					'mess' => '你已被移除聊天室',
				));
				$this->server->send($key, $mess);//发给禁用的用户
				$this->server->disConnect($key);//断开连接

				$this->tearDown($receiver_id, $key);//注销用户服务

				//系统通知
				$mess = $this->encode(array(
					'type' => self::SYSTEM,
					'user_id' => $receiver_id,
					'mess' => str_replace('%USERNAME%', $user['username'], $this->remove),
				));
				$this->server->sendAll($mess);

				$this->flushUsers();//刷新在线用户列表
				break;
			case self::AVATAR_UPLOAD:
				//删除原来图片
				if (!empty($sender['avatar'])) {
					unlink(__DIR__ . '/' . $sender['avatar']);
				}

				$info['avatar'] = $path = $this->getUniqueFile($mess, 'avatar');
				$res = $this->user->update($user_id, $info);
				if (false == $res) {
					$mess = $this->encode(array(
						'type' => self::AVATAR_FAIL,
						'user_id' => $user_id,
						'mess' => '头像上传失败！',
					));
					$this->server->send($key, $mess);
					break;
				}
				$mess = $this->encode(array(
					'type' => self::AVATAR_SUCCESS,
					'user_id' => $user_id,
					'mess' => $path,
				));
				$this->server->send($key, $mess);
				break;
			case self::HISTORY_COMMON_MESSAGE:
				empty($arr['query_time']) or $timestamp = $arr['query_time'];
				$mess = $this->user->getPrevCommonMessage($timestamp);
				$mess = $this->encode(array(
					'type' => $type,
					'mess' => $mess,
				));
				$this->server->send($key, $mess);
				break;
			case self::HISTORY_PERSONAL_MESSAGE:
				empty($arr['query_time']) or $timestamp = $arr['query_time'];
				$users = array($arr['sender_id'], $arr['receiver_id']);
				$mess = $this->user->getPrevPersonalMessage($users, $timestamp);
				$mess = $this->encode(array(
					'type' => $type,
					'mess' => $mess,
					'receiver_id' => $arr['receiver_id'], //标识是哪个联系人的，防止多次请求混淆
				));
				$this->server->send($key, $mess);
				break;
			default:
				$this->warning($key, array(
					'type' => self::ERROR,
					'mess' => '未知的消息类型',
				));
		}

		unset($arr, $mess, $sender);//销毁临时变量
	}

	public function sendCommonMessage($key, $type, $sender, &$mess, $timestamp, $extra = array())
	{
		if (empty($mess)) {
			$this->warning($key, array(
				'type' => self::WARNING,
				'mess' => '请不要发空消息！',
			));
			return;
		}

		$mess = $this->encode(array_merge(array(
			'type' => $type,
			'time' => date('H:i:s'),
			'timestamp' => $timestamp,
			'sender' => $sender,
			'mess' => $mess,
		), $extra));
		$this->user->addCommonMessage($timestamp, $mess);
		$this->server->sendAll($mess);
		unset($mess);
	}

	public function sendPersonalMessage($key, $types, $sender, $receiver, &$mess, $timestamp, $extra = array())
	{
		$user_id = $sender['user_id'];
		$receiver_id = $receiver['user_id'];
		$arr = array(
			'type' => $types[0],
			'time' => date('H:i:s'),
			'timestamp' => $timestamp,
			'sender' => $sender,
			'receiver' => $receiver,
			'mess' => $mess,
		);
		$arr = array_merge($arr, $extra);
		$this->user->addPersonalMessage(array($user_id, $receiver_id), $timestamp, $this->encode($arr));

		if (isset($this->userService[$receiver_id])) {
			$arr['type'] = $types[1];//转换成本人
			$mess = $this->encode($arr);
			$this->server->send($key, $mess);//给当前客户端发送消息

			$arr['type'] = $types[2];//转换成他人
			$mess = $this->encode($arr);
			$index = $this->userService[$receiver_id];
			$this->server->send($index, $mess);//给接收者发送消息
		} else {
			//用户已经离线
			$arr['type'] = self::SELF;
			$arr['mess'] = '已经离线...';
			$mess = $this->encode($arr);
			$this->server->send($key, $mess);
		}
		unset($arr, $mess);
	}

	public function sendCommonVideoMessage($key, $type, $sender, &$mess, $timestamp, $extra = array())
	{
		$mess = $this->encode(array_merge(array(
			'type' => $type,
			'time' => date('H:i:s'),
			'timestamp' => $timestamp,
			'sender' => $sender,
			'mess' => $mess,
		), $extra));
		$this->server->sendAll($mess);
		unset($mess);
	}

	public function sendPersonalVideoMessage($key, $type, $sender, $receiver, &$mess, $timestamp, $extra = array()) {
		$user_id = $sender['user_id'];
		$receiver_id = $receiver['user_id'];
		$arr = array(
			'type' => $type,
			'time' => date('H:i:s'),
			'timestamp' => $timestamp,
			'sender' => $sender,
			'receiver' => $receiver,
			'mess' => $mess,
		);
		if (isset($this->userService[$receiver_id])) {
			$index = $this->userService[$receiver_id];
			$mess = $this->encode($arr);
			$this->server->send($index, $mess);
		} else {
			$arr['type'] = self::PERSONAL_VIDEO_OFFLINE;
			$arr['mess'] = $receiver['username'] . '已经离线...';
			$mess = $this->encode($arr);
			$this->server->send($key, $mess);
		}
	}

	public function getUniqueFile(&$base64, $flag = 'message')
	{
		$md5 = md5($base64);
		$path = $this->user->getFileByMd5($md5);
		if (!empty($path))
			return $path;
		$path = $this->base64ToFile($base64, $flag);
		$this->user->addFile($md5, $path);
		return $path;
	}

	public function onError($key, $err)
	{
		$this->onClose($key);
		if (isset($this->serviceUser[$key])) {
			$user_id = $this->serviceUser[$key];
			$this->log('user_id:' . $user_id . ' service error:' . $err, 'ERROR');
		} else {
			$this->log("service#{$key} ERROR:$err");
		}
	}

	public function onClose($key)
	{
		//用户退出-处理业务
		if (isset($this->serviceUser[$key])) {
			$user_id = $this->serviceUser[$key];
			$this->tearDown($user_id, $key);//注销用户服务
			$this->flushUsers();//刷新在线用户列表

			$user = $this->user->getUserById($user_id);
			$this->user->logout($user_id);
			$mess = $this->encode(array(
				'type' => self::QUIT,
				'time' => date('H:i:s'),
				'timestamp' => time(),
				'user' => $user,
			));
			$this->server->sendAll($mess);//通知所有人
		}
	}

	public function auth($key, $user_id)
	{
		$user = $this->user->getUserById($user_id);
		if (empty($user['user_id'])) {
			$this->register($key);
			return false;
		}
		if (0 == $user['is_active']) {
			$this->forbidden($key, $user);
			return false;
		}
		return $user;
	}

	public function login($key, $user)
	{
		$user_id = $user['user_id'];
		if (isset($this->userService[$user_id])) {
			//多点登录，让他下线
			$index = $this->userService[$user_id];
			$mess = $this->encode(array(
				'type' => self::DOWNLINE,
				'mess' => '您已下线',
			));
			$this->server->send($index, $mess);//给他发送通知
			$this->server->disConnect($index);//断开连接
			$this->tearDown($user_id, $index);//注销用户服务
		}

		$mess = $this->encode(array(
			'type' => self::LOGIN,
			'user' => $user,
		));
		$this->server->send($key, $mess);//通知当前用户，已经登录
		//绑定用户ID与SOCKET
		$this->userService[$user_id] = $key;//user_id=>socket key，通过用户ID找到服务索引
		$this->serviceUser[$key] = $user_id;//socket key=>user_id，通过服务索引找到用户ID

		$this->user->login($user_id);
		$mess = $this->encode(array(
			'type' => self::WELCOME,
			'time' => date('H:i:s'),
			'timestamp' => time(),
			'user' => $user,
		));
		$this->server->sendAll($mess);//欢迎消息

		$this->flushUsers();//刷新在线用户列表
	}

	public function register($key)
	{
		$mess = $this->encode(array(
			'type' => self::REGISTER,
		));
		$this->server->send($key, $mess);
	}

	public function forbidden($key, $user)
	{
		$mess = $this->encode(array(
			'type' => self::FORBIDDEN,
			'user_id' => $user['user_id'],
			'mess' => '你已被管理员禁用，不能发送消息',
		));
		$this->server->send($key, $mess);
		$this->server->disConnect($key);//断开连接

		$user_id = $user['user_id'];
		$this->tearDown($user_id, $key);//注销用户服务
	}

	public function warning($key, $arr)
	{
		$mess = $this->encode($arr);
		$this->server->send($key, $mess);
	}

	/**
	 * 刷新用户列表-业务处理
	 */
	public function flushUsers()
	{
		$users = array();
		foreach ($this->serviceUser as $user_id) {
			$users[] = $this->user->getUserById($user_id);
		}
		$mess = $this->encode(array(
			'type' => self::ONLINE,
			'users' => $users,
		));
		$this->server->sendAll($mess);//刷新在线用户列表
	}

	/**
	 * 注销用户服务-业务处理
	 * @param $user_id
	 * @param $key
	 */
	public function tearDown($user_id, $key)
	{
		unset($this->userService[$user_id]);//注销服务
		unset($this->serviceUser[$key]);//注销用户
	}

	public function base64ToFile(&$base64, $flag = 'message')
	{
		$pos = strpos($base64, ',') + 1;
		$image = base64_decode(substr($base64, $pos));
		$pos2 = strpos($base64, '/');
		$pos3 = strpos($base64, ';');
		$suffix = '.' . substr($base64, $pos2 + 1, $pos3 - $pos2 - 1);
		$path = $this->getFilePath($suffix, $flag);
		file_put_contents($path, $image);
		unset($image);
		return '/' . ltrim($path, __DIR__);
	}

	public function getFilePath($suffix, $flag = 'message')
	{
		$path = sprintf('%s/%s/%s/%s/%s/', __DIR__, $this->upload, $flag, date('Ymd'), date('H'));
		is_dir($path) or mkdir($path, 0777, true);
		return $path . uniqid() . $suffix;
	}

	/**
	 * 编码数据
	 * @param string $str
	 * @return mixed
	 * @link http://github.com/msgpack/msgpack-php
	 */
	public function decode($str)
	{
		/*if (function_exists('msgpack_unpack')) {
			return msgpack_unpack($str);
		}*/
		return json_decode($str, true);
	}

	/**
	 * 解码数据
	 * @param mixed $data
	 * @return string
	 * @link http://github.com/msgpack/msgpack-php
	 */
	public function encode($data)
	{
		/*if (function_exists('msgpack_pack')) {
			return msgpack_pack($data);
		}*/
		return json_encode($data, JSON_UNESCAPED_UNICODE);
	}

	/**
	 * 记录日志
	 * @param string $content
	 * @param string $flag
	 * @return int
	 */
	public function log($content, $flag = 'INFO')
	{
		$dir = sprintf('%s/logs/%s', __DIR__, date('Y-m-d'));
		if (!is_dir($dir)) {
			mkdir($dir, 0777, true);
		}
		$filename = sprintf('%s/client.%s@%s.log', $dir, $flag, date('H'));
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

	public function daemon()
	{
		if (($pid1 = pcntl_fork()) === 0) {
			posix_setsid();
			if (($pid2 = pcntl_fork()) === 0) {
				$this->server->run();
			} else {
				die();
			}
		} else {
			pcntl_wait($status);//等待子进程中断，防止子进程成为僵尸进程。
		}
	}

	public function start()
	{
		if (PHP_OS == 'WINNT')
			$this->server->run();
		else
			$this->daemon();
	}
}

try {
	//php Client.php 81
	$port = isset($argv[1]) ? $argv[1] + 0 : 81; //默认81(nginx)，如果是apache，则直接监听访问端口
	$client = new Client('127.0.0.1', $port);
	$client->start();
} catch (\Exception $e) {
	die($e);
}