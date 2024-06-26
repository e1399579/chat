<?php
namespace server;

class User {
    /**
     * @var \Redis
     */
    protected $redis;
    protected $userSet = 'user';//在线用户ID集合
    protected $fileStore = 'file:md5'; //文件库:md5 => path
    protected $fileStore2 = 'file:hash';
    protected $userService = 'user_service'; // user_id=>socket key
    protected $serviceUser = 'service_user'; // socket key=>user_id
    protected $groupsMap = 'groups'; // 群组[id => info]

    protected $dbIndex; // 库序号

    protected $indexNum; // 消息索引数量

    public function __construct($dbIndex = 15, $indexNum = 5) {
        $this->dbIndex = 15;
        $this->indexNum = 5;
    }

    public function connect() {
        $this->redis = new \Redis();
        $res = $this->redis->pconnect('redis', 6379);
        if (false === $res)
            throw new \RuntimeException('连接REDIS失败！');
        $this->redis->select($this->dbIndex);
    }

    public function close() {
        $this->redis->close();
    }

    /**
     * 注册
     * @param string $name 昵称
     * @param string $password
     * @param array $headers
     * @return array|bool
     */
    public function register($name, $password, $headers = []) {
        $user_id = uniqid();
        $user = [
            'user_id' => $user_id,
            'username' => $name,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'reg_time' => date('Y-m-d H:i:s'),
            'role_id' => 0,
            'is_active' => 1,
            'avatar' => '',
        ];
        $user += $headers;
        $hKey = 'user_id:' . $user_id;
        $res = $this->redis->hMset($hKey, $user);
        $key = 'username:' . $name;
        $this->redis->set($key, $user_id);
        return $res ? $user : $res;
    }

    /**
     * 通过用户ID获取用户信息
     * @param $user_id
     * @param array $info
     * @return array
     */
    public function getUserById($user_id, $info = ['user_id', 'username', 'role_id', 'is_active', 'avatar']) {
        $hKey = 'user_id:' . $user_id;
        return $this->redis->hMGet($hKey, $info);
    }

    /**
     * 通过用户昵称获取用户信息
     * @param $name
     * @param array $info
     * @return array|bool
     */
    public function getUserByName($name, $info = ['user_id', 'username', 'role_id', 'is_active']) {
        $key = 'username:' . $name;
        $user_id = $this->redis->get($key);
        if (!$user_id)
            return false;
        return $this->getUserById($user_id, $info);
    }

    /**
     * 在线用户ID列表
     * @return array
     */
    public function getOnlineUsers() {
        return $this->redis->sMembers($this->userSet);
    }

    /**
     * 在线用户数量
     * @return int
     */
    public function getOnlineTotal() {
        return $this->redis->sCard($this->userSet);
    }

    /**
     * 登录/添加用户
     * @param $user_id
     * @param $info
     * @return int
     */
    public function login($user_id, $info = []) {
        $this->update($user_id, $info);
        return $this->redis->sAdd($this->userSet, $user_id);
    }

    /**
     * 退出/删除用户
     * @param $user_id
     * @param $info
     * @return int
     */
    public function logout($user_id, $info = []) {
        $this->update($user_id, $info);
        return $this->redis->sRem($this->userSet, $user_id);
    }

    /**
     * 清空在线用户
     * @return int
     */
    public function flushOnline() {
        return $this->redis->del($this->userSet);
    }

    public function isOnline($user_id) {
        return $this->redis->sIsMember($this->userSet, $user_id);
    }

    /**
     * 更新信息
     * @param $user_id
     * @param array $info
     * @return bool
     */
    public function update($user_id, array $info) {
        $hKey = 'user_id:' . $user_id;
        return $this->redis->hMset($hKey, $info);
    }

    public function getMessageIndexKey($message_id) {
        $number = crc32($message_id);
        $choose = $number % $this->indexNum;
        return 'message:index:' . $choose;
    }

    public function addMessageIndex($message_id, $data) {
        // Limits: Every hash can store up to 4,294,967,295 (2^32 - 1) field-value pairs
        $key = $this->getMessageIndexKey($message_id);
        $value = json_encode($data);
        return $this->redis->hSet($key, $message_id, $value);
    }

    public function getMessageIndex($message_id) {
        $key = $this->getMessageIndexKey($message_id);
        return $this->redis->hGet($key, $message_id);
    }

    public function deleteMessage($message_id) {
        $index_data = $this->getMessageIndex($message_id);
        $key = $index_data['key'];
        $timestamp = $index_data['timestamp'];
        return $this->redis->zRemRangeByScore($key, $timestamp, $timestamp);
    }

    public function getCommonMessageKey($common_id) {
        return 'message:common_id:' . $common_id;
    }

    public function addCommonMessage($common_id, $timestamp, $message, $message_id = '') {
        $key = $this->getCommonMessageKey($common_id);
        if (!empty($message_id)) {
            $this->addMessageIndex($message_id, compact('key', 'timestamp'));
        }
        return $this->redis->zAdd($key, $timestamp, $message);
    }

    public function getPersonalMessageKey($users) {
        sort($users);
        return 'message:users:[' . implode(',', $users) . ']';
    }

    public function addPersonalMessage(array $users, $timestamp, $message, $message_id = '') {
        $key = $this->getPersonalMessageKey($users);
        if (!empty($message_id)) {
            $this->addMessageIndex($message_id, compact('key', 'timestamp'));
        }
        return $this->redis->zAdd($key, $timestamp, $message);
    }

    public function getPrevMessage($key, $timestamp, $size = 10) {
        //score之前的数量
        $end = $this->redis->zCount($key, '-inf', $timestamp) - 1;
        if ($end < 0)
            return [];
        $start = $end - $size + 1; //包含start，所有要少一个下标
        $start = max(0, $start);
        return $this->redis->zRange($key, $start, $end);
    }

    public function getPrevCommonMessage($common_id, $timestamp, $size = 10) {
        $key = $this->getCommonMessageKey($common_id);
        return $this->getPrevMessage($key, $timestamp, $size);
    }

    public function getPrevPersonalMessage(array $users, $timestamp, $size = 10) {
        $key = $this->getPersonalMessageKey($users);
        return $this->getPrevMessage($key, $timestamp, $size);
    }

    public function addFile($md5, $path) {
        return $this->redis->hSet($this->fileStore, $md5, $path);
    }

    public function getFileByMd5($md5) {
        return $this->redis->hGet($this->fileStore, $md5);
    }

    public function addFilePath($hash, $path) {
        return $this->redis->hSet($this->fileStore2, $hash, $path);
    }

    public function getFilePath($hash) {
        return $this->redis->hGet($this->fileStore2, $hash);
    }

    public function addUserServiceRelation($user_id, $key) {
        $this->redis->hSet($this->userService, $user_id, $key);
        $this->redis->hSet($this->serviceUser, $key, $user_id);
    }

    public function getServiceKeyByUserId($user_id) {
        return $this->redis->hGet($this->userService, $user_id);
    }

    public function getServiceKeys($user_id_list) {
        return $this->redis->hMGet($this->userService, $user_id_list);
    }

    public function getUserIdByServiceKey($key) {
        return $this->redis->hGet($this->serviceUser, $key);
    }

    public function delUserServiceRelation($user_id, $key) {
        $this->redis->hDel($this->userService, $user_id);
        $this->redis->hDel($this->serviceUser, $key);
    }

    public function flushUserServiceRelation() {
        $this->redis->del([$this->userService, $this->serviceUser]);
    }

    public function getUserContactsKey($user_id) {
        return 'user_contacts:user_id:' . $user_id;
    }

    public function addUserContact($user_id, $friend_id) {
        $user_contacts_key = $this->getUserContactsKey($user_id);
        $timestamp = microtime(true);
        return $this->redis->zAdd($user_contacts_key, $timestamp, $friend_id);
    }

    public function delUserContact($user_id, $friend_id) {
        $user_contacts_key = $this->getUserContactsKey($user_id);
        return $this->redis->zRem($user_contacts_key, $friend_id);
    }

    public function getUserContacts($user_id, $with_score = false) {
        $user_contacts_key = $this->getUserContactsKey($user_id);
        return $this->redis->zRange($user_contacts_key, 0, -1, $with_score);
    }

    public function getUserContactsInfo($user_id, $info) {
        $user_contacts = $this->getUserContacts($user_id, true);
        $data = [];
        foreach ($user_contacts as $friend_id => $timestamp) {
            $user = $this->getUserById($friend_id, $info);
            $user and $user['join_timestamp'] = $timestamp;
            $data[] = $user;
        }
        return $data;
    }

    public function getGroupMembersKey($group_id) {
        return 'group_members:group_id:' . $group_id;
    }

    public function getUserGroupKey($user_id) {
        return 'user_groups:user_id:' . $user_id;
    }

    public function addGroup($admin_id, $name, $members, $extra = []) {
        // 创建群组基本信息
        $group_id = uniqid();
        $create_time = microtime(true);
        $data = compact('name', 'admin_id', 'create_time') + $extra;
        $this->redis->hSet($this->groupsMap, $group_id, json_encode($data, JSON_UNESCAPED_UNICODE));

        $group_members_key = $this->getGroupMembersKey($group_id);
        $this->redis->zAdd($this->getUserGroupKey($admin_id), $create_time, $group_id);
        $args = [$group_members_key, $create_time, $admin_id]; // 合并操作
        foreach ($members as $member) {
            $timestamp = microtime(true);
            // 添加群组成员
            $args[] = $timestamp;
            $args[] = $member;

            // 添加成员关联
            $this->addUserGroup($member, $group_id, $timestamp);
        }
        call_user_func_array([$this->redis, 'zAdd'], $args);

        $data['id'] = $group_id;
        return $data;
    }

    public function updateGroup($group_id, $info) {
        $data = $this->getGroup($group_id);
        $merged = array_merge($data, $info);
        return $this->redis->hSet($this->groupsMap, $group_id, json_encode($merged, JSON_UNESCAPED_UNICODE));
    }

    public function delGroup($group_id) {
        // 查询群组成员
        $members = $this->getGroupMembers($group_id);
        $group_members_key = $this->getGroupMembersKey($group_id);
        $args = [$group_members_key];
        foreach ($members as $member) {
            // 删除群组成员
            $args[] = $member;

            // 删除成员关联
            $this->delUserGroup($member, $group_id);
        }
        call_user_func_array([$this->redis, 'zRem'], $args);

        // 删除群组
        return $this->redis->hDel($this->groupsMap, $group_id);
    }

    public function joinGroup($group_id, $members) {
        $group_members_key = $this->getGroupMembersKey($group_id);
        $args = [$group_members_key];
        foreach ($members as $member) {
            $timestamp = microtime(true);
            // 添加群组成员
            $args[] = $timestamp;
            $args[] = $member;

            // 添加成员关联
            $this->addUserGroup($member, $group_id, $timestamp);
        }
        return call_user_func_array([$this->redis, 'zAdd'], $args);
    }

    public function exitGroup($user_id, $group_id) {
        $group_members_key = $this->getGroupMembersKey($group_id);
        $this->redis->zRem($group_members_key, $user_id); // 删除群组成员
        return $this->delUserGroup($user_id, $group_id); // 删除成员关联
    }

    public function addUserGroup($user_id, $group_id, $timestamp) {
        $user_groups_key = $this->getUserGroupKey($user_id);
        return $this->redis->zAdd($user_groups_key, $timestamp, $group_id);
    }

    public function delUserGroup($user_id, $group_id) {
        $user_groups_key = $this->getUserGroupKey($user_id);
        return $this->redis->zRem($user_groups_key, $group_id);
    }

    public function getGroup($group_id) {
        $str = $this->redis->hGet($this->groupsMap, $group_id);
        return json_decode($str, true);
    }

    public function getGroupMembers($group_id, $with_scores = false) {
        $group_members_key = $this->getGroupMembersKey($group_id);
        return $this->redis->zRange($group_members_key, 0, -1, $with_scores);
    }

    public function getGroupMembersInfo($group_id, $info) {
        $members = $this->getGroupMembers($group_id, true);
        $data = [];
        foreach ($members as $member => $timestamp) {
            $user = $this->getUserById($member, $info);
            $user and $user['join_timestamp'] = $timestamp;
            $data[] = $user;
        }
        return $data;
    }

    public function getUserGroups($user_id) {
        $user_groups_key = $this->getUserGroupKey($user_id);
        return $this->redis->zRange($user_groups_key, 0, -1);
    }

    public function getUserGroupsInfo($user_id) {
        $groups = $this->getUserGroups($user_id);
        if (empty($groups)) return [];
        $list = $this->redis->hMGet($this->groupsMap, $groups);
        array_walk($list, function (&$item, $key) {
            $item = json_decode($item, true);
        });
        return $list;
    }

    public function getGroupMemberRank($group_id, $user_id) {
        $group_members_key = $this->getGroupMembersKey($group_id);
        return $this->redis->zRank($group_members_key, $user_id);
    }

    public function isGroupMember($group_id, $user_id) {
        $group_members_key = $this->getGroupMembersKey($group_id);
        return (bool) $this->redis->zScore($group_members_key, $user_id);
    }

    public function isGroupAdmin($group_id, $user_id) {
        $group = $this->getGroup($group_id);
        return (isset($group['admin_id']) && ($group['admin_id'] === $user_id));
    }

    public function getRTCRoomKey($room_id) {
        return 'rtc_room:' . $room_id;
    }

    public function addRTCRoom($room_id, $user_id) {
        // 清除之前数据
        $this->redis->del($this->getRTCRoomKey($room_id));
        return $this->joinRTCRoom($room_id, $user_id);
    }

    public function getRTCRoom($room_id) {
        $key = $this->getRTCRoomKey($room_id);
        return $this->redis->zRange($key, 0, -1);
    }

    public function joinRTCRoom($room_id, $user_id) {
        $key = $this->getRTCRoomKey($room_id);
        $timestamp = microtime(true);
        return $this->redis->zAdd($key, $timestamp, $user_id);
    }

    public function exitRTCRoom($room_id, $user_id) {
        $key = $this->getRTCRoomKey($room_id);
        return $this->redis->zRem($key, $user_id);
    }

    public function delRTCRoom($room_id) {
        $key = $this->getRTCRoomKey($room_id);
        return $this->redis->del($key);
    }

    public function flushRTCRoom() {
        $keys = $this->redis->keys($this->getRTCRoomKey('') . '*');
        return $keys ? $this->redis->del($keys) : true;
    }
}