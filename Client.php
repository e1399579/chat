<?php
define('APP_PATH', realpath('.'));
function autoload($class) {
    require APP_PATH . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
}

spl_autoload_register('autoload');

use server\IClient;
use server\IServer;
use server\WsServer;
use server\User;
use server\DaemonCommand;
use server\Logger;

class Client implements IClient {
    private $server;//WebSocket
    /**
     * @var User
     */
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
    private $request = array();
    private $response = array();
    private $timestamp;
    private $request_type;
    /**
     * @var Logger
     */
    protected $logger;

    const MESSAGE_COMMON = 100;//公共消息
    const MESSAGE_SELF = 101;//本人消息
    const MESSAGE_OTHER = 102;//他人消息
    const MESSAGE_PERSONAL = 103;//私信

    const USER_ONLINE = 200;//用户上线
    const USER_QUIT = 201;//用户退出
    const USER_LIST = 202;//用户列表
    const USER_QUERY = 203; //用户查询
    const USER_REGISTER = 204;//用户注册
    const USER_LOGIN = 205;//用户登录
    const USER_DISABLED = 206;//用户禁用
    const USER_DOWNLINE = 207;//用户下线
    const USER_INCORRECT = 208;//用户名/密码错误
    const USER_REMOVE = 209;//用户移除

    const USER_AVATAR_UPLOAD = 210;//上传头像
    const USER_AVATAR_SUCCESS = 211;//上传成功
    const USER_AVATAR_FAIL = 212;//上传失败

    const IMAGE_COMMON = 300;//公共图片
    const IMAGE_SELF = 301;//本人图片
    const IMAGE_OTHER = 302;//他人图片
    const IMAGE_PERSONAL = 303;//私信图片

    const EMOTION_COMMON = 400;//公共表情
    const EMOTION_SELF = 401;//本人表情
    const EMOTION_OTHER = 402;//他人图片
    const EMOTION_PERSONAL = 403;//私信表情

    const MUSIC_COMMON = 500; //公共音乐
    const MUSIC_SELF = 501; //本人音乐
    const MUSIC_OTHER = 502; //他人音乐
    const MUSIC_PERSONAL = 503; //私信音乐

    const VIDEO_PERSONAL_REQUEST = 600; //私信视频请求
    const VIDEO_PERSONAL_OFFLINE = 601; //离线
    const VIDEO_PERSONAL_ALLOW = 602; //请求通过
    const VIDEO_PERSONAL_DENY = 603; //请求拒绝
    const VIDEO_PERSONAL_OPEN = 604; //打开摄像头
    const VIDEO_PERSONAL_CLOSE = 605; //关闭摄像头
    const VIDEO_PERSONAL_END = 606; //传输结束

    const VIDEO_PERSONAL_OFFER_DESC = 607;
    const VIDEO_PERSONAL_ANSWER_DESC = 608;
    const VIDEO_PERSONAL_CANDIDATE = 609;

    const VIDEO_COMMON_REQUEST = 700;
    const VIDEO_COMMON_NOTIFY = 701;
    const VIDEO_PERSONAL_NOTIFY = 702;

    const HISTORY_MESSAGE_COMMON = 800; //历史公共消息
    const HISTORY_MESSAGE_PERSONAL = 801; //历史个人消息

    const ERROR = 900;//错误消息
    const WARNING = 901;//警告消息
    const SYSTEM = 902;//系统消息

    protected $types = array(
        self::MESSAGE_PERSONAL => array(self::MESSAGE_SELF, self::MESSAGE_OTHER),
        self::IMAGE_PERSONAL => array(self::IMAGE_SELF, self::IMAGE_OTHER),
        self::EMOTION_PERSONAL => array(self::EMOTION_SELF, self::EMOTION_OTHER),
        self::MUSIC_PERSONAL => array(self::MUSIC_SELF, self::MUSIC_OTHER),
    );

    public function __construct(IServer $server) {
        $this->server = $server;
        $this->server->attach($this);

        $path = './logs/client';
        $this->logger = Logger::getInstance($path);

        set_error_handler(array($this, 'errorHandler'));
    }

    public function onOpen($key, $headers) {
        $this->serviceAgent[$key] = isset($headers['User-Agent']) ? $headers['User-Agent'] : '';
        $this->serviceIp[$key] = $headers['REMOTE_ADDR'];
        $this->debug(var_export($headers, true));
    }

    public function onMessage($key, $message) {
        //发送消息-处理业务
        $this->request = $this->decode($message);
        $this->timestamp = microtime(true);
        if (!isset($this->request['type'])) {
            $this->logger->error(sprintf('数据不完整，共 %s bytes', strlen($message)));
            $this->response = array(
                'type' => self::ERROR,
                'mess' => '读取消息出错',
                'timestamp' => $this->timestamp,
            );
            $this->sendMessage($key);
            unset($message);
            $this->clearData();

            return;
        }

        unset($message);
        $this->request_type = $this->request['type'] + 0;

        $this->filterRequest();

        if ($this->request_type != self::USER_REGISTER) {
            //除了注册，所有操作都需要进行验证
            $user_id = $this->request['sender_id'];
            $res = $this->auth($key, $user_id);
            if (false === $res) {
                return;
            }
        }

        switch ($this->request_type) {
            case self::USER_REGISTER: //注册
                $username = $this->request['username'];
                $password = $this->request['password'];
                if (empty($username)) {
                    $this->register($key);
                    break;
                }
                $info = array('user_id', 'username', 'role_id', 'is_active', 'password');
                $user = $this->user->getUserByName($username, $info);

                $headers = array('ip' => $this->serviceIp[$key], 'agent' => $this->serviceAgent[$key]);
                empty($user['user_id']) and $user = $this->user->register($username, $password, $headers);
                if (empty($user['user_id'])) {
                    $this->response = array(
                        'type' => self::ERROR,
                        'mess' => '注册用户失败！',
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }
                if (!password_verify($password, $user['password'])) {
                    $this->response = array(
                        'type' => self::USER_INCORRECT,
                        'mess' => '用户名或密码错误',
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }
                unset($user['password']);
                $this->login($key, $user);

                break;
            case self::USER_LOGIN:
                $headers = array('ip' => $this->serviceIp[$key], 'agent' => $this->serviceAgent[$key]);
                $this->user->update($this->request['sender_id'], $headers);
                $user = $this->user->getUserById($this->request['sender_id']);
                $this->login($key, $user);

                unset($headers, $this->serviceIp[$key], $this->serviceAgent[$key]);
                break;
            case self::USER_REMOVE: //移除，由管理员发起
                $admin = $this->user->getUserById($this->request['sender_id'], array('role_id'));
                $receiver_id = $this->request['receiver_id'];
                $user = $this->user->getUserById($receiver_id, array('user_id', 'role_id', 'username'));
                if (empty($user['user_id']) || empty($admin['role_id'])) {
                    $this->response = array(
                        'type' => self::WARNING,
                        'mess' => '移除失败！用户不存在或者非法操作',
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }
                if ($admin['role_id'] <= $user['role_id']) {
                    $this->response = array(
                        'type' => self::WARNING,
                        'mess' => '移除失败！您没有该权限',
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }

                $info = array('is_active' => 0);
                $res = $this->user->update($receiver_id, $info);
                if (false == $res) {
                    $this->response = array(
                        'type' => self::WARNING,
                        'mess' => '移除失败！服务器出错',
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }

                if (!isset($this->userService[$receiver_id])) {
                    $this->response = array(
                        'type' => self::SYSTEM,
                        'mess' => "移除成功！",
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }
                $key = $this->userService[$receiver_id];//用户的服务索引
                $this->response = array(
                    'type' => self::USER_REMOVE,
                    'mess' => '你已被移除聊天室',
                    'timestamp' => $this->timestamp,
                );
                $this->sendMessage($key);//发给禁用的用户
                $this->server->disConnect($key);//断开连接

                $this->tearDown($receiver_id, $key);//注销用户服务

                //系统通知
                $this->response = array(
                    'type' => self::SYSTEM,
                    'mess' => str_replace('%USERNAME%', $user['username'], $this->remove),
                    'timestamp' => $this->timestamp,
                );
                $this->sendAllMessage();

                break;
            case self::USER_AVATAR_UPLOAD:
                //删除原来图片
                $user = $this->user->getUserById($this->request['sender_id'], array('avatar'));
                if (!empty($user['avatar'])) {
                    unlink(__DIR__ . '/' . $user['avatar']);
                }

                $info['avatar'] = $path = $this->getUniqueFile($this->request['mess'], 'avatar');
                $res = $this->user->update($this->request['sender_id'], $info);
                if (false == $res) {
                    $this->response = array(
                        'type' => self::USER_AVATAR_FAIL,
                        'mess' => '头像上传失败！',
                        'timestamp' => $this->timestamp,
                    );
                    $this->sendMessage($key);
                    break;
                }
                $this->response = array(
                    'type' => self::USER_AVATAR_SUCCESS,
                    'mess' => $path,
                    'timestamp' => $this->timestamp,
                );
                $this->sendMessage($key);
                break;
            case self::USER_QUERY:
                $user = $this->request['receiver_id'] ? $this->user->getUserById($this->request['receiver_id']) : array();
                $this->response = array(
                    'type' => self::USER_QUERY,
                    'user' => $user,
                    'timestamp' => $this->timestamp,
                );
                $this->sendMessage($key);
                break;
            case self::MESSAGE_COMMON: //公共消息
                $this->sendCommonMessage($key);
                break;
            case self::IMAGE_COMMON: //公共图片
                $this->response['mess'] = $this->getUniqueFile($this->request['mess']);//图片直接存为文件，节省编码时间
                $this->sendCommonMessage($key);
                break;
            case self::EMOTION_COMMON: //公共表情
                $content = explode('_', $this->request['mess']);
                $this->response['mess'] = "/images/emotion/{$content[0]}/{$content[1]}";
                $this->sendCommonMessage($key);
                break;
            case self::MESSAGE_PERSONAL: //私信
                $this->sendPersonalMessage($key);
                break;
            case self::IMAGE_PERSONAL: //私信图片
                $this->response['mess'] = $this->getUniqueFile($this->request['mess']);
                $this->sendPersonalMessage($key);
                break;
            case self::EMOTION_PERSONAL: //私信表情
                $content = explode('_', $this->request['mess']);
                $this->response['mess'] = "/images/emotion/{$content[0]}/{$content[1]}";
                $this->sendPersonalMessage($key);
                break;
            case self::MUSIC_COMMON:
                $data = $this->request['mess']['data'];
                $name = $this->request['mess']['name'];
                $this->response['mess'] = $this->getUniqueFile($data, 'music');
                $extra = array(
                    'name' => $name,
                );
                $this->sendCommonMessage($key, $extra);
                break;
            case self::MUSIC_PERSONAL:
                $data = $this->request['mess']['data'];
                $name = $this->request['mess']['name'];
                $this->response['mess'] = $this->getUniqueFile($data, 'music');

                $extra = array(
                    'name' => $name,
                );
                $this->sendPersonalMessage($key, $extra);
                break;
            case self::VIDEO_PERSONAL_REQUEST:
            case self::VIDEO_PERSONAL_ALLOW:
                $this->sendPersonalVideoMessage($key);
                break;
            case self::VIDEO_PERSONAL_DENY:
                $this->sendPersonalVideoMessage($key, '拒绝了视频请求');
                break;
            case self::VIDEO_PERSONAL_OPEN:
            case self::VIDEO_PERSONAL_CLOSE:
            case self::VIDEO_PERSONAL_OFFER_DESC:
            case self::VIDEO_PERSONAL_ANSWER_DESC:
            case self::VIDEO_PERSONAL_CANDIDATE:
            case self::VIDEO_COMMON_NOTIFY:
            case self::VIDEO_PERSONAL_NOTIFY:
                $this->sendPersonalVideoMessage($key);
                break;
            case self::VIDEO_PERSONAL_END:
                $this->sendPersonalVideoMessage($key, '视频聊天结束');
                break;
            case self::VIDEO_COMMON_REQUEST:
                $this->sendCommonMessage($key, array(), false);
                break;
            case self::HISTORY_MESSAGE_COMMON:
                $timestamp = empty($this->request['mess']) ? $this->timestamp : $this->request['mess'];
                //TODO 验证用户是否在群组内
                $common_id = $this->request['receiver_id'];
                $mess = $this->user->getPrevCommonMessage($common_id, $timestamp);
                foreach ($mess as &$row) {
                    $row = $this->decode($row);
                }
                unset($row);
                $this->response = array(
                    'type' => $this->request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => $mess,
                    'timestamp' => $this->timestamp,
                );
                unset($mess);
                $this->sendMessage($key);
                break;
            case self::HISTORY_MESSAGE_PERSONAL:
                $timestamp = empty($this->request['mess']) ? $this->timestamp : $this->request['mess'];
                $users = array($this->request['sender_id'], $this->request['receiver_id']);
                $mess = $this->user->getPrevPersonalMessage($users, $timestamp);
                foreach ($mess as &$row) {
                    $row = $this->decode($row);
                    $type = $row['type'];
                    if (!isset($this->types[$type])) continue;
                    $types = $this->types[$type];
                    $row['type'] = $this->request['sender_id'] == $row['sender_id'] ? $types[0] : $types[1];
                }
                unset($row);
                $this->response = array(
                    'type' => $this->request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'], //标识是哪个联系人的，防止多个窗口混淆
                    'mess' => $mess,
                    'timestamp' => $this->timestamp,
                );
                unset($mess);
                $this->sendMessage($key);
                break;
            default:
                $this->response = array(
                    'type' => self::ERROR,
                    'mess' => '未知的消息类型',
                    'timestamp' => $this->timestamp,
                );
                $this->sendMessage($key);
        }

        $this->clearData();
    }

    public function clearData() {
        //释放内存
        $this->request = array();
        $this->response = array();
    }

    public function filterRequest() {
        if (!in_array($this->request_type,
            array(self::VIDEO_PERSONAL_OFFER_DESC, self::VIDEO_PERSONAL_ANSWER_DESC, self::VIDEO_PERSONAL_CANDIDATE))
        ) {
            array_walk_recursive($this->request, function (&$item, $key) {
                $item = addslashes(htmlspecialchars(trim($item)));
            });
        }
    }

    public function sendCommonMessage($key, $extra = array(), $is_store = true) {
        if (empty($this->request['mess'])) {
            $this->response = array(
                'type' => self::WARNING,
                'mess' => '请不要发空消息！',
                'timestamp' => $this->timestamp,
            );
            $this->sendMessage($key);

            return;
        }

        $this->response = array_merge(array(
            'type' => $this->request_type,
            'sender_id' => $this->request['sender_id'],
            'receiver_id' => $this->request['receiver_id'],
            'mess' => isset($this->response['mess']) ? $this->response['mess'] : $this->request['mess'],
            'timestamp' => $this->timestamp,
        ), $extra);
        $is_store and $this->user->addCommonMessage($this->request['receiver_id'], $this->timestamp, $this->encode($this->response));

        $this->sendAllMessage();
    }

    public function sendPersonalMessage($key, $extra = array()) {
        $this->response = array_merge(array(
            'type' => $this->request_type,
            'sender_id' => $this->request['sender_id'],
            'receiver_id' => $this->request['receiver_id'],
            'mess' => isset($this->response['mess']) ? $this->response['mess'] : $this->request['mess'],
            'timestamp' => $this->timestamp,
        ), $extra);
        $users = array($this->request['sender_id'], $this->request['receiver_id']);
        $this->user->addPersonalMessage($users, $this->timestamp, $this->encode($this->response));

        $receiver_id = $this->request['receiver_id'];
        if (isset($this->userService[$receiver_id])) {
            $this->response['type'] = $this->types[$this->request_type][0];//转换成本人
            $this->sendMessage($key);//给当前客户端发送消息

            $this->response['type'] = $this->types[$this->request_type][1];//转换成他人
            $index = $this->userService[$receiver_id];
            $this->sendMessage($index);//给接收者发送消息
        } else {
            //用户已经离线
            $this->response['type'] = self::MESSAGE_SELF;
            $this->response['mess'] = '对方已经离线...';
            $this->sendMessage($key);
        }
    }

    public function sendPersonalVideoMessage($key, $mess = '') {
        $this->response = array(
            'type' => $this->request_type,
            'sender_id' => $this->request['sender_id'],
            'receiver_id' => $this->request['receiver_id'],
            'mess' => $mess ? $mess : $this->request['mess'],
            'timestamp' => $this->timestamp,
        );

        $receiver_id = $this->request['receiver_id'];
        if (isset($this->userService[$receiver_id])) {
            $index = $this->userService[$receiver_id];
            $this->sendMessage($index);
        } else {
            $this->response['type'] = self::VIDEO_PERSONAL_OFFLINE;
            $this->response['mess'] = '对方已经离线...';
            $this->sendMessage($key);
        }
    }

    public function getUniqueFile(&$base64, $flag = 'message') {
        $md5 = md5($base64);
        $path = $this->user->getFileByMd5($md5);
        if (!empty($path)) {
            return $path;
        }
        $path = $this->base64ToFile($base64, $flag);
        $this->user->addFile($md5, $path);

        return $path;
    }

    public function onError($key, $err) {
        $this->onClose($key);
        if (isset($this->serviceUser[$key])) {
            $user_id = $this->serviceUser[$key];
            $this->logger->error('user_id:' . $user_id . ' service error:' . $err);
        } else {
            $this->logger->error("service#{$key} ERROR:$err");
        }
    }

    public function onClose($key) {
        //用户退出-处理业务
        if (isset($this->serviceUser[$key])) {
            $user_id = $this->serviceUser[$key];
            $this->tearDown($user_id, $key);//注销用户服务

            $user = $this->user->getUserById($user_id, array('user_id', 'username'));
            $this->user->logout($user_id);
            $this->response = array(
                'type' => self::USER_QUIT,
                'user' => $user,
                'timestamp' => $this->timestamp,
            );
            $this->sendAllMessage(); //通知所有人
        }
    }

    public function auth($key, $user_id) {
        $user = $this->user->getUserById($user_id, array('user_id', 'is_active'));
        if (empty($user['user_id'])) {
            $this->register($key);

            return false;
        }
        if (0 == $user['is_active']) {
            $this->forbidden($key, $user);

            return false;
        }

        return true;
    }

    public function login($key, $user) {
        $user_id = $user['user_id'];
        if (isset($this->userService[$user_id])) {
            //多点登录，让他下线
            $index = $this->userService[$user_id];
            $this->response = array(
                'type' => self::USER_DOWNLINE,
                'mess' => '您已下线',
                'timestamp' => $this->timestamp,
            );
            $this->sendMessage($index); //给他发送通知
            $this->server->disConnect($index);//断开连接
            $this->tearDown($user_id, $index);//注销用户服务
        }

        $this->response = array(
            'type' => self::USER_LOGIN,
            'user' => $user,
            'timestamp' => $this->timestamp,
        );
        $this->sendMessage($key); //通知当前用户，已经登录
        //绑定用户ID与SOCKET
        $this->userService[$user_id] = $key;//user_id=>socket key，通过用户ID找到服务索引
        $this->serviceUser[$key] = $user_id;//socket key=>user_id，通过服务索引找到用户ID

        $this->user->login($user_id);
        $this->response = array(
            'type' => self::USER_ONLINE,
            'user' => $user,
            'timestamp' => $this->timestamp,
        );
        $this->sendAllMessage(); //欢迎消息

        $this->flushUsers($key); //刷新在线用户列表
    }

    public function register($key) {
        $this->response = array(
            'type' => self::USER_REGISTER,
            'timestamp' => $this->timestamp,
        );
        $this->sendMessage($key);
    }

    public function forbidden($key, $user) {
        $this->response = array(
            'type' => self::USER_DISABLED,
            'mess' => '你已被管理员禁用，不能发送消息',
            'timestamp' => $this->timestamp,
        );
        $this->sendMessage($key);
        $this->server->disConnect($key);//断开连接

        $user_id = $user['user_id'];
        $this->tearDown($user_id, $key);//注销用户服务
    }

    public function sendMessage($key) {
        $this->server->send($key, $this->encode($this->response));
    }

    public function sendAllMessage() {
        $this->server->sendAll($this->encode($this->response));
    }

    /**
     * 刷新用户列表-业务处理
     * @param $key
     */
    public function flushUsers($key) {
        $users = array();
        foreach ($this->user->getOnlineUsers() as $user_id) {
            $users[] = $this->user->getUserById($user_id);
        }
        $this->response = array(
            'type' => self::USER_LIST,
            'users' => $users,
            'timestamp' => $this->timestamp,
        );
        $this->sendMessage($key); //刷新在线用户列表
    }

    /**
     * 注销用户服务-业务处理
     *
     * @param $user_id
     * @param $key
     */
    public function tearDown($user_id, $key) {
        unset($this->userService[$user_id]);//注销服务
        unset($this->serviceUser[$key]);//注销用户
    }

    public function base64ToFile(&$base64, $flag = 'message') {
        $pos = strpos($base64, ',') + 1;
        $image = base64_decode(substr($base64, $pos));
        $pos2 = strpos($base64, '/');
        $pos3 = strpos($base64, ';');
        $suffix = '.' . substr($base64, $pos2 + 1, $pos3 - $pos2 - 1);
        $path = $this->getFilePath($suffix, $flag);
        file_put_contents(__DIR__ . $path, $image);
        unset($image);

        return $path;
    }

    public function getFilePath($suffix, $flag = 'message') {
        $dir = sprintf('/%s/%s/%s/%s/', $this->upload, $flag, date('Ymd'), date('H'));
        $real_path = __DIR__ . $dir;
        is_dir($real_path) or mkdir($real_path, 0777, true);

        return $dir . uniqid() . $suffix;
    }

    /**
     * 编码数据
     *
     * @param string $str
     *
     * @return mixed
     * @link http://github.com/msgpack/msgpack-php
     */
    public function decode($str) {
        //return json_decode($str, true);
        return msgpack_unpack($str);
    }

    /**
     * 解码数据
     *
     * @param mixed $data
     *
     * @return string
     * @link http://github.com/msgpack/msgpack-php
     */
    public function encode($data) {
        //return json_encode($data, JSON_UNESCAPED_UNICODE);
        return msgpack_pack($data);
    }

    public function debug($content) {
        if (!$this->debug) {
            return true;
        }

        return $this->logger->info($content);
    }

    /**
     * 自定义错误处理
     * @param $errno
     * @param $errstr
     * @param $errfile
     * @param $errline
     * @return bool|void
     */
    public function errorHandler($errno, $errstr, $errfile, $errline) {
        if (!(error_reporting() & $errno)) {
            // This error code is not included in error_reporting
            return;
        }

        switch ($errno) {
            case E_USER_ERROR:
                $content = "[$errno] $errstr" . PHP_EOL . " Fatal error on line $errline in file $errfile";
                $this->logger->error($content);
                exit(1);
                break;

            case E_USER_WARNING:
                $content = "WARNING [$errno] $errstr";
                $this->logger->info($content);
                break;

            case E_USER_NOTICE:
                $content = "NOTICE [$errno] $errstr";
                $this->logger->info($content);
                break;

            default:
                $content = "Unknown error type: [$errno] $errstr";
                $this->logger->info($content);
                break;
        }

        /* Don't execute PHP internal error handler */
        return true;
    }

    public function run($num) {
        if (PHP_OS == 'WINNT') {
            $this->server->run();
        } else {
            $daemon = new DaemonCommand(true, 'root', __DIR__ . '/DaemonCommand.log');
            $daemon->daemonize();

            $daemon->addJob(function () {
                $this->user = new User();
                $this->server->run();
            });
            $daemon->addMasterJob(function() use ($num) {
                (new User())->flushOnline();
                $this->server->initWorks($num);
                $this->server->attachMessage();
            });

            $daemon->start($num);
        }
    }
}

try {
    // nginx需要通过反向代理将开放端口8080映射到php监听端口81
    // apache不需要代理，直接监听开放端口8080即可
    // windows+apache cmd:php Client.php 8080
    // linux+nginx bash:nohup php Client.php
    $port = isset($argv[1]) ? $argv[1] + 0 : 8080;
    $ssl = array(
        'local_cert'  => '/usr/local/nginx/conf/1_chat.ridersam.cn_cert.crt',
        'local_pk'    => '/usr/local/nginx/conf/2_chat.ridersam.cn.key',
        'verify_peer' => false,
    );
    $server = new WsServer($port, $ssl);
    $client = new Client($server);
    $client->run(4);
} catch (\Exception $e) {
    die($e);
}