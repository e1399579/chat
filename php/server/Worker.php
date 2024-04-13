<?php
namespace server;

class Worker implements IClient {
    /**
     * @var IServer
     */
    protected $server;
    /**
     * @var User
     */
    protected $user;//用户
    protected $serviceAgent = [];//socket key=>agent
    protected $serviceIp = [];//socket key=>ip
    protected $remove = '用户%USERNAME%被管理员移除聊天室';
    protected $debug = false;//调试开关
    protected $upload = 'upload';//上传目录
    protected $request = [];
    protected $response = [];
    protected $timestamp;
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
    const USER_ONLINE_TOTAL = 213; // 用户在线数量

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

    // 文件
    const FILE_COMMON = 1000;
    const FILE_SELF = 1001;
    const FILE_OTHER = 1002;
    const FILE_PERSONAL = 1003;

    // 群聊
    const GROUP_CREATE = 1100;
    const GROUP_QUERY_LIST = 1101;
    const GROUP_QUERY_MEMBER = 1102;
    const GROUP_QUERY_INFO = 1103;
    const GROUP_JOIN = 1104;
    const GROUP_EXIT = 1105;
    const GROUP_DEL = 1106;

    // Web Real-Time Communication
    const RTC_CREATE = 600;
    const RTC_JOIN = 601;
    const RTC_MESSAGE = 602;
    const RTC_OFFLINE = 603;
    const RTC_CLOSE = 604;
    const RTC_EXIT = 605;

    const HISTORY_MESSAGE_COMMON = 800; //历史公共消息
    const HISTORY_MESSAGE_PERSONAL = 801; //历史个人消息

    const ERROR = 900;//错误消息
    const WARNING = 901;//警告消息
    const SYSTEM = 902;//系统消息

    const FILE_UPLOAD_SUCCESS = 903; // 文件上传成功
    const FILE_UPLOAD_FAIL = 904; // 文件上传失败

    protected $types = [
        self::MESSAGE_PERSONAL => [self::MESSAGE_SELF, self::MESSAGE_OTHER],
        self::IMAGE_PERSONAL => [self::IMAGE_SELF, self::IMAGE_OTHER],
        self::EMOTION_PERSONAL => [self::EMOTION_SELF, self::EMOTION_OTHER],
        self::MUSIC_PERSONAL => [self::MUSIC_SELF, self::MUSIC_OTHER],
        self::FILE_PERSONAL => [self::FILE_SELF, self::FILE_OTHER],
    ];

    public function __construct() {
        $this->user = new User();

        $path = './logs/client';
        $this->logger = Logger::getInstance($path);

        set_error_handler([$this, 'errorHandler']);
    }

    public function setServer(IServer $server): void {
        $this->server = $server;
    }

    public function onOpen(int $key, array $headers): void {
        $this->serviceAgent[$key] = $headers['User-Agent'] ?? '';
        $this->serviceIp[$key] = $headers['REMOTE_ADDR'];
        $this->debug(var_export($headers, true));
    }

    public function onMessage(int $key, string $message, string $data_type = 'TEXT'): void {
        $this->timestamp = microtime(true);
        // 检查消息类型
        if ($data_type === 'BINARY') {
            $finfo = new \finfo(\FILEINFO_MIME);
            $mime = $finfo->buffer($message);
            $this->debug(var_export(compact('key', 'mime'), true));
            // image/png; charset=binary
            // audio/mpeg; charset=binary
            // text/plain; charset=us-ascii
            // application/vnd.openxmlformats-officedocument.wordprocessingml.document; charset=binary
            // application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; charset=binary
            $match = preg_match('#image/|audio/|text/|application/vnd\.openxmlformats#', $mime);
            if ($match) {
                // 处理文件
                $data = $this->getHashFile($message, $mime);
                $this->response = [
                    'type' => self::FILE_UPLOAD_SUCCESS,
                    'mess' => $data,
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                $this->clearData();
            } else {
                $this->response = [
                    'type' => self::FILE_UPLOAD_FAIL,
                    'mess' => '不支持的文件类型：' . $mime,
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                $this->clearData();
            }
            return;
        }

        //发送消息-处理业务
        $this->request = $this->decode($message);
        if (!isset($this->request['type'])) {
            $this->logger->error(sprintf('数据不完整，共 %s bytes', strlen($message)));
            $this->response = [
                'type' => self::ERROR,
                'mess' => '读取消息出错',
                'timestamp' => $this->timestamp,
            ];
            $this->sendMessage($key);
            $this->clearData();

            return;
        }

        unset($message);
        $request_type = $this->request['type'] + 0;

        $this->filterRequest();

        if ($request_type != self::USER_REGISTER) {
            //除了注册，所有操作都需要进行验证
            $user_id = $this->request['sender_id'];
            $res = $this->auth($key, $user_id);
            if (false === $res) {
                $this->logger->error('非法操作', compact('key', 'user_id'));
                return;
            }
        }

        switch ($request_type) {
            case self::USER_REGISTER: //注册，若已有账户则登录
                $username = $this->request['username'];
                $password = $this->request['password'];
                if (empty($username)) {
                    $this->register($key);
                    break;
                }
                $info = ['user_id', 'username', 'role_id', 'is_active', 'password', 'avatar'];
                $user = $this->user->getUserByName($username, $info);

                $headers = ['ip' => $this->serviceIp[$key], 'agent' => $this->serviceAgent[$key]];
                empty($user['user_id']) and $user = $this->user->register($username, $password, $headers);
                if (empty($user['user_id'])) {
                    $this->response = [
                        'type' => self::ERROR,
                        'mess' => '注册用户失败！',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }
                if (!password_verify($password, $user['password'])) {
                    $this->response = [
                        'type' => self::USER_INCORRECT,
                        'mess' => '用户名或密码错误',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }
                unset($user['password']);
                $this->login($key, $user);

                break;
            case self::USER_LOGIN:
                $headers = ['ip' => $this->serviceIp[$key], 'agent' => $this->serviceAgent[$key]];
                $this->user->update($this->request['sender_id'], $headers);
                $user = $this->user->getUserById($this->request['sender_id']);
                $this->login($key, $user);

                unset($headers, $this->serviceIp[$key], $this->serviceAgent[$key]);
                break;
            case self::USER_REMOVE: //移除，由管理员发起
                $admin = $this->user->getUserById($this->request['sender_id'], ['role_id']);
                $receiver_id = $this->request['receiver_id'];
                $user = $this->user->getUserById($receiver_id, ['user_id', 'role_id', 'username']);
                if (empty($user['user_id']) || empty($admin['role_id'])) {
                    $this->response = [
                        'type' => self::WARNING,
                        'mess' => '移除失败！用户不存在或者非法操作',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }
                if ($admin['role_id'] <= $user['role_id']) {
                    $this->response = [
                        'type' => self::WARNING,
                        'mess' => '移除失败！您没有该权限',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }

                $info = ['is_active' => 0];
                $res = $this->user->update($receiver_id, $info);
                if (false === $res) {
                    $this->response = [
                        'type' => self::WARNING,
                        'mess' => '移除失败！服务器出错',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }

                $receiver_key = $this->user->getServiceKeyByUserId($receiver_id); // 用户的服务索引
                if (false === $receiver_key) {
                    $this->response = [
                        'type' => self::SYSTEM,
                        'mess' => "移除成功！",
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }
                $this->response = [
                    'type' => self::USER_REMOVE,
                    'mess' => '你已被移除聊天室',
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($receiver_key);//发给禁用的用户
                $this->server->close($receiver_key);//断开连接

                $this->tearDown($receiver_id, $receiver_key);//注销用户服务

                //系统通知
                $this->response = [
                    'type' => self::SYSTEM,
                    'mess' => str_replace('%USERNAME%', $user['username'], $this->remove),
                    'timestamp' => $this->timestamp,
                ];
                $this->sendAllMessage(0);

                break;
            case self::USER_AVATAR_UPLOAD:
                //删除原来图片
                $user = $this->user->getUserById($this->request['sender_id'], ['avatar']);
                if (!empty($user['avatar'])) {
                    unlink(APP_PATH . '/' . $user['avatar']);
                }

//                $info['avatar'] = $path = $this->getUniqueFile($this->request['mess'], 'avatar');
                $info['avatar'] = $path = $this->request['mess']['path'];
                $res = $this->user->update($this->request['sender_id'], $info);
                if (false === $res) {
                    $this->response = [
                        'type' => self::USER_AVATAR_FAIL,
                        'mess' => '头像上传失败！',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                    break;
                }
                $this->response = [
                    'type' => self::USER_AVATAR_SUCCESS,
                    'mess' => $path,
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                break;
            case self::USER_LIST:
                $this->flushUsers($key);
                break;
            case self::USER_QUERY:
                $user = $this->request['receiver_id'] ? $this->user->getUserById($this->request['receiver_id']) : [];
                $is_online = $this->user->isOnline($this->request['receiver_id']);
                $this->response = [
                    'type' => self::USER_QUERY,
                    'user' => $user,
                    'timestamp' => $this->timestamp,
                    'is_online' => $is_online,
                ];
                $this->sendMessage($key);
                break;
            case self::USER_ONLINE_TOTAL:
                $total = $this->user->getOnlineTotal();
                $this->response = [
                    'type' => self::USER_ONLINE_TOTAL,
                    'mess' => $total,
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                break;
            case self::MESSAGE_COMMON: //公共消息
                $this->response['mess'] = $this->request['mess'];
                $this->sendCommonMessage($request_type, $this->request['receiver_id'], $key);
                break;
            case self::IMAGE_COMMON: //公共图片
            case self::FILE_COMMON:
            case self::MUSIC_COMMON:
//                $this->response['mess'] = $this->getUniqueFile($this->request['mess']);//图片直接存为文件，节省编码时间
                $this->response['mess'] = $this->request['mess'];
                $this->sendCommonMessage($request_type, $this->request['receiver_id'], $key);
                break;
            case self::EMOTION_COMMON: //公共表情
                $content = explode('_', $this->request['mess']);
                $this->response['mess'] = "/images/emotion/{$content[0]}/{$content[1]}";
                $this->sendCommonMessage($request_type, $this->request['receiver_id'], $key);
                break;
            case self::MESSAGE_PERSONAL: //私信
                $this->response['mess'] = $this->request['mess'];
                $this->sendPersonalMessage($request_type, $key);
                break;
            case self::IMAGE_PERSONAL: //私信图片
            case self::FILE_PERSONAL:
            case self::MUSIC_PERSONAL:
//                $this->response['mess'] = $this->getUniqueFile($this->request['mess']);
                $this->response['mess'] = $this->request['mess'];
                $this->sendPersonalMessage($request_type, $key);
                break;
            case self::EMOTION_PERSONAL: //私信表情
                $content = explode('_', $this->request['mess']);
                $this->response['mess'] = "/images/emotion/{$content[0]}/{$content[1]}";
                $this->sendPersonalMessage($request_type, $key);
                break;
//            case self::MUSIC_COMMON:
//                $data = $this->request['mess']['data'];
//                $name = $this->request['mess']['name'];
//                $this->response['mess'] = $this->getUniqueFile($data, 'music');
//                $extra = [
//                    'name' => $name,
//                ];
//                $this->sendCommonMessage($key, $extra);
//                break;
//            case self::MUSIC_PERSONAL:
//                $data = $this->request['mess']['data'];
//                $name = $this->request['mess']['name'];
//                $this->response['mess'] = $this->getUniqueFile($data, 'music');
//
//                $extra = [
//                    'name' => $name,
//                ];
//                $this->sendPersonalMessage($key, $extra);
//                break;
            case self::GROUP_CREATE:
                $admin_id = $this->request['sender_id'];
                $name = $this->request['mess']['name'];
                $members = $this->request['mess']['members'];
                $avatar = $this->request['mess']['avatar'] ?? '';
                $extra = $avatar ? compact('avatar') : [];
                if (count($members) < 2) {
                    $this->response = [
                        'type' => self::WARNING,
                        'mess' => '创建失败！群聊人数不能少于2人',
                        'timestamp' => $this->timestamp,
                    ];
                    $this->sendMessage($key);
                } else {
                    $group = $this->user->addGroup($admin_id, $name, $members, $extra);
                    $this->response = [
                        'type' => $request_type,
                        'sender_id' => $this->request['sender_id'],
                        'receiver_id' => $group['id'],
                        'mess' => $group,
                        'timestamp' => $this->timestamp,
                    ];

                    // 添加一条记录
                    $this->user->addCommonMessage($group['id'], $this->timestamp, $this->encode($this->response));

                    // 给所有成员发消息
                    $keys = $this->user->getServiceKeys(array_merge([$admin_id], $members));
                    $this->sendMultiMessage($keys);
                }
                break;
            case self::GROUP_QUERY_LIST:
                $user_id = $this->request['sender_id'];
                $data = $this->user->getUserGroupsInfo($user_id);
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => $data,
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                break;
            case self::GROUP_QUERY_MEMBER:
                $group_id = $this->request['mess'];
                $info = ['user_id', 'username', 'role_id', 'is_active', 'avatar'];
                $members = $this->user->getGroupMembersInfo($group_id, $info);
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => compact('group_id', 'members'),
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                break;
            case self::GROUP_QUERY_INFO:
                $group_id = $this->request['mess'];
                $info = $this->user->getGroup($group_id);
                $info and $info['id'] = $group_id;
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => $info,
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                break;
            case self::GROUP_JOIN:
                $group_id = $this->request['mess']['group_id'];
                $members = $this->request['mess']['members'];
                $this->user->joinGroup($group_id, $members);
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => '加入成功',
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                // TODO 给其他成员发消息
                break;
            case self::GROUP_EXIT:
                $user_id = $this->request['sender_id'];
                $group_id = $this->request['mess'];
                $this->user->exitGroup($user_id, $group_id);
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => '退出成功',
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
                // TODO 给其他成员发消息
                break;
            case self::GROUP_DEL:
                $user_id = $this->request['sender_id'];
                $group_id = $this->request['mess'];
                $is_admin = $this->user->isGroupAdmin($group_id, $user_id);
                if ($is_admin) {
                    $this->user->delGroup($group_id);
                    $this->response = [
                        'type' => $request_type,
                        'sender_id' => $this->request['sender_id'],
                        'receiver_id' => $this->request['receiver_id'],
                        'mess' => '解散成功',
                        'timestamp' => $this->timestamp,
                    ];
                } else {
                    $this->response = [
                        'type' => self::WARNING,
                        'mess' => '解散失败！您没有该权限',
                        'timestamp' => $this->timestamp,
                    ];
                }
                $this->sendMessage($key);
                // TODO 给其他成员发消息
                break;
            case self::RTC_CREATE:
                $data = $this->request['mess'];
                $is_group = $data['is_group'];
                // 创建房间
                $user_id = $this->request['sender_id'];
                $room_id = $user_id; // 暂时以房主id创建
                $this->user->addRTCRoom($room_id, $user_id);

                $data['room_id'] = $room_id;
                $this->response['mess'] = $data;
                if ($is_group) {
                    $this->sendCommonMessage($request_type, $this->request['receiver_id'], $key, [], false);
                } else {
                    $this->sendRTCMessage($request_type, $key, [$user_id, $this->request['receiver_id']]);
                }
                break;
            case self::RTC_JOIN:
                // 新用户加入，转发消息给其他人（不包括房主，因为创建时已经联系过了）
                $data = $this->request['mess'];
                $user_id = $this->request['sender_id'];
                $room_id = $data['room_id'];
                $members = $this->user->getRTCRoom($room_id); // 未加入之前的成员
                $this->user->joinRTCRoom($room_id, $user_id);
                array_shift($members); // 排除房主
                if (!empty($members)) {
                    $this->response['mess'] = $this->request['mess'];
                    $this->sendRTCMessage($request_type, $key, $members);
                }
                break;
            case self::RTC_MESSAGE:
                // 转发消息
                $receiver_id = $this->request['receiver_id'];
                $this->response['mess'] = $this->request['mess'];
                $this->sendRTCMessage($request_type, $key, [$receiver_id]);
                break;
            case self::RTC_CLOSE:
                $data = $this->request['mess'];
                $room_id = $data['room_id'];
                $is_group = $data['is_group'];
                // 查询成员
//                $members = $this->user->getRTCRoom($room_id);
                $user_id = $this->request['sender_id'];
                if ($room_id === $user_id) {
                    // 房主退出，解散
                    $this->user->delRTCRoom($room_id);
                    $type = self::RTC_EXIT;
                } else {
                    // 移除成员
                    $this->user->exitRTCRoom($room_id, $user_id);
                    $type = self::RTC_CLOSE;
                }
                $this->response['mess'] = $data;
                // 通知其他人
//                $members = array_diff($members, [$user_id]);
//                $members and $this->sendRTCMessage($type, $key, $members);
                // 通知所有群成员（可能有人未接听，关闭弹窗）
                if ($is_group) {
                    $this->sendCommonMessage($type, $this->request['receiver_id'], $key, [], false);
                } else {
                    $this->sendRTCMessage($type, $key, [$this->request['receiver_id']]);
                }
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
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'],
                    'mess' => $mess,
                    'timestamp' => $this->timestamp,
                ];
                unset($mess);
                $this->sendMessage($key);
                break;
            case self::HISTORY_MESSAGE_PERSONAL:
                $timestamp = empty($this->request['mess']) ? $this->timestamp : $this->request['mess'];
                $users = [$this->request['sender_id'], $this->request['receiver_id']];
                $mess = $this->user->getPrevPersonalMessage($users, $timestamp);
                foreach ($mess as &$row) {
                    $row = $this->decode($row);
                    $type = $row['type'];
                    if (!isset($this->types[$type])) continue;
                    $types = $this->types[$type];
                    $row['type'] = $this->request['sender_id'] == $row['sender_id'] ? $types[0] : $types[1];
                }
                unset($row);
                $this->response = [
                    'type' => $request_type,
                    'sender_id' => $this->request['sender_id'],
                    'receiver_id' => $this->request['receiver_id'], //标识是哪个联系人的，防止多个窗口混淆
                    'mess' => $mess,
                    'timestamp' => $this->timestamp,
                ];
                unset($mess);
                $this->sendMessage($key);
                break;
            default:
                $this->response = [
                    'type' => self::ERROR,
                    'mess' => '未知的消息类型',
                    'timestamp' => $this->timestamp,
                ];
                $this->sendMessage($key);
        }

        $this->clearData();
    }

    public function clearData() {
        //释放内存
        $this->request = [];
        $this->response = [];
    }

    public function filterRequest() {
//        if (!in_array($this->request_type,
//            [
//                self::IMAGE_COMMON,
//                self::IMAGE_PERSONAL,
//                self::VIDEO_PERSONAL_OFFER_DESC,
//                self::VIDEO_PERSONAL_ANSWER_DESC,
//                self::VIDEO_PERSONAL_CANDIDATE,
//            ])
//        ) {
//            array_walk_recursive($this->request, function (&$item, $key) {
//                $item = addslashes(htmlspecialchars(trim($item)));
//            });
//        }
    }

    public function sendCommonMessage($type, $receiver_id, $key, $extra = [], $is_store = true) {
        if (empty($this->request['mess'])) {
            $this->response = [
                'type' => self::WARNING,
                'mess' => '请不要发空消息！',
                'timestamp' => $this->timestamp,
            ];
            $this->sendMessage($key);

            return;
        }

        $this->response = array_merge([
            'type' => $type,
            'sender_id' => $this->request['sender_id'],
            'receiver_id' => $this->request['receiver_id'],
            'mess' => $this->response['mess'],
            'timestamp' => $this->timestamp,
        ], $extra);
        if (!empty($this->request['id'])) $this->response['id'] = $this->request['id'];
        $is_store and $this->user->addCommonMessage($this->request['receiver_id'], $this->timestamp,
            $this->encode($this->response), $this->response['id'] ?? '');

        if (empty($receiver_id)) {
            // receiver_id=0 大厅
            $this->sendAllMessage(0);
        } else {
            // 群聊
            $members = $this->user->getGroupMembers($receiver_id);
            $keys = $this->user->getServiceKeys($members);
            $this->sendMultiMessage($keys);
        }
    }

    public function sendPersonalMessage($type, $key, $extra = []) {
        $this->response = array_merge([
            'type' => $type,
            'sender_id' => $this->request['sender_id'],
            'receiver_id' => $this->request['receiver_id'],
            'mess' => $this->response['mess'],
            'timestamp' => $this->timestamp,
        ], $extra);
        if (!empty($this->request['id'])) $this->response['id'] = $this->request['id'];
        $users = [$this->request['sender_id'], $this->request['receiver_id']];
        $this->user->addPersonalMessage($users, $this->timestamp,
            $this->encode($this->response), $this->response['id'] ?? '');

        $receiver_id = $this->request['receiver_id'];
        $receiver_key = $this->user->getServiceKeyByUserId($receiver_id);
        if (false !== $receiver_key) {
            $this->response['type'] = $this->types[$type][0];//转换成本人
            $this->sendMessage($key);//给当前客户端发送消息

            $this->response['type'] = $this->types[$type][1];//转换成他人
            $this->sendMessage($receiver_key);//给接收者发送消息
        } else {
            //用户已经离线
            $this->response['type'] = self::MESSAGE_SELF;
            $this->response['mess'] = '对方已经离线...';
            $this->sendMessage($key);
        }
    }

    public function sendRTCMessage($type, $key, $members) {
        $this->response = [
            'type' => $type,
            'sender_id' => $this->request['sender_id'],
            'receiver_id' => $this->request['receiver_id'],
            'mess' => $this->response['mess'],
            'timestamp' => $this->timestamp,
        ];

        $keys = $this->user->getServiceKeys($members);
        $this->sendMultiMessage($keys);
        $online = array_keys($keys);
        $offline = array_diff($members, $online);
        if (count($offline) > 0) {
            $this->response['type'] = self::RTC_OFFLINE;
            $this->response['mess'] = $offline;
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

    public function getHashFile(&$data, $mime) {
        $hash = hash('sha256', $data);
        $path = $this->user->getFilePath($hash);
        if (empty($path)) {
            $path = $this->saveFile($data, $mime);
            $this->user->addFilePath($hash, $path);
        }
        $size = strlen($data);

        return compact('hash', 'path', 'size');
    }

    public function onError(int $key, string $err): void {
        $this->onClose($key);
        $user_id = $this->user->getUserIdByServiceKey($key);
        if (false !== $user_id) {
            $this->logger->error('user_id:' . $user_id . ' service error:' . $err);
        } else {
            $this->logger->error("service#{$key} ERROR:$err");
        }
    }

    public function onClose(int $key): void {
        //用户退出-处理业务
        $user_id = $this->user->getUserIdByServiceKey($key);
        if (false !== $user_id) {
            $this->tearDown($user_id, $key);//注销用户服务

            $user = $this->user->getUserById($user_id, ['user_id', 'username']);
            $this->response = [
                'type' => self::USER_QUIT,
                'user' => $user,
                'timestamp' => $this->timestamp,
            ];
            $this->sendAllMessage(); //通知所有人
        }
    }

    public function auth($key, $user_id) {
        $user = $this->user->getUserById($user_id, ['user_id', 'is_active']);
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
        $index = $this->user->getServiceKeyByUserId($user_id);
        if (false !== $index) {
            // 多点登录，让他下线
            $this->response = [
                'type' => self::USER_DOWNLINE,
                'mess' => '您已下线',
                'timestamp' => $this->timestamp,
            ];
            $this->sendMessage($index); //给他发送通知
            $this->server->close($index);//断开连接
            $this->tearDown($user_id, $index);//注销用户服务
        }

        $this->response = [
            'type' => self::USER_LOGIN,
            'user' => $user,
            'timestamp' => $this->timestamp,
        ];
        $this->sendMessage($key); //通知当前用户，已经登录
        // 绑定用户ID与SOCKET
        $this->user->addUserServiceRelation($user_id, $key);

        $this->user->login($user_id);
        $this->response = [
            'type' => self::USER_ONLINE,
            'user' => [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
            ],
            'timestamp' => $this->timestamp,
        ];
        $this->sendAllMessage(); //欢迎消息

//            $this->flushUsers($key); //刷新在线用户列表
    }

    public function register($key) {
        $this->response = [
            'type' => self::USER_REGISTER,
            'timestamp' => $this->timestamp,
        ];
        $this->sendMessage($key);
    }

    public function forbidden($key, $user) {
        $this->response = [
            'type' => self::USER_DISABLED,
            'mess' => '你已被管理员禁用，不能发送消息',
            'timestamp' => $this->timestamp,
        ];
        $this->sendMessage($key);
        $this->server->close($key);//断开连接

        $user_id = $user['user_id'];
        $this->tearDown($user_id, $key);//注销用户服务
    }

    public function sendMessage($key) {
        $this->response['trace_id'] = $this->request['trace_id'] ?? '';
        $this->server->send($key, $this->encode($this->response));
    }

    public function sendAllMessage($priority = 10) {
        $this->response['trace_id'] = $this->request['trace_id'] ?? '';
        $this->server->sendAll($this->encode($this->response), $priority);
    }

    public function sendMultiMessage($keys) {
        $this->response['trace_id'] = $this->request['trace_id'] ?? '';
        $this->server->sendMultiple($keys, $this->encode($this->response));
    }

    /**
     * 刷新用户列表-业务处理
     * @param $key
     */
    public function flushUsers($key) {
        $users = [];
        foreach ($this->user->getOnlineUsers() as $user_id) {
            $users[] = $this->user->getUserById($user_id);
        }
        $this->response = [
            'type' => self::USER_LIST,
            'users' => $users,
            'timestamp' => $this->timestamp,
        ];
        $this->sendMessage($key); //刷新在线用户列表
    }

    /**
     * 注销用户服务-业务处理
     *
     * @param $user_id
     * @param $key
     */
    public function tearDown($user_id, $key) {
        $this->user->delUserServiceRelation($user_id, $key);
        $this->user->logout($user_id);
    }

    public function base64ToFile(&$base64, $flag = 'message') {
        $pos = strpos($base64, ',') + 1;
        $image = base64_decode(substr($base64, $pos));
        $pos2 = strpos($base64, '/');
        $pos3 = strpos($base64, ';');
        $suffix = '.' . substr($base64, $pos2 + 1, $pos3 - $pos2 - 1);
        $path = $this->getFilePath($suffix, $flag);
        file_put_contents($path['real'], $image);
        unset($image);

        return $path['relative'];
    }

    public function getFilePath($suffix, $flag = 'message') {
        $dir = sprintf('/%s/%s/%s/%s/', $this->upload, $flag, date('Ymd'), date('H'));
        $real_dir = APP_PATH . DIRECTORY_SEPARATOR . $dir;
        is_dir($real_dir) or mkdir($real_dir, 0777, true);

        $filename = uniqid() . $suffix;
        return [
            'real' => $real_dir . $filename,
            'relative' => $dir . $filename,
        ];
    }

    public function saveFile(&$data, $mime) {
        list($name, ) = explode(';', $mime, 2);
        $suffix_map = [
            'image/png' => '.png',
            'image/jpeg' => '.jpg',
            'image/gif' => '.gif',
            'audio/mpeg' => '.mp3',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
        ];
        $flag_map = [
            'image/png' => 'image',
            'image/jpeg' => 'image',
            'image/gif' => 'image',
            'audio/mpeg' => 'music',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document',
        ];
        $suffix = $suffix_map[$name];
        $flag = $flag_map[$name];
        $path = $this->getFilePath($suffix, $flag);
        file_put_contents($path['real'], $data);

        return $path['relative'];
    }

    /**
     * 编码数据
     *
     * @param string $str
     * @return mixed
     * @link http://github.com/msgpack/msgpack-php
     */
    public function decode(&$str) {
        return json_decode($str, true);
//        return msgpack_unpack($str);
    }

    /**
     * 解码数据
     *
     * @param mixed $data
     *
     * @return string
     * @link http://github.com/msgpack/msgpack-php
     */
    public function encode(&$data) {
        return json_encode($data, JSON_UNESCAPED_UNICODE);
//        return msgpack_pack($data);
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

            case E_USER_WARNING:
                $content = "WARNING [$errno] $errstr";
                $this->logger->error($content);
                break;

            case E_USER_NOTICE:
                $content = "NOTICE [$errno] $errstr";
                $this->logger->info($content);
                break;

            default:
                $content = "Unknown error type: [$errno] $errstr";
                $this->logger->error($content, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
                break;
        }

        /* Don't execute PHP internal error handler */
        return true;
    }

    public function onStart(): void {
        $this->user->connect();
    }

    public function reset() {
        $this->user->connect();
        $this->user->flushOnline();
        $this->user->flushUserServiceRelation();
        $this->user->flushRTCRoom();
        $this->user->close();
    }
}