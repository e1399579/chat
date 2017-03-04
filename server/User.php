<?php
namespace server;

class User {
    public $redis;
    public $userSet = 'user';//用户ID集合
    public $fileStore = 'file:md5'; //文件库:md5 => path

    public function __construct() {
        $this->redis = new \Redis();
        $res = $this->redis->pconnect('127.0.0.1', 6379);
        if (false == $res)
            throw new \Exception('连接REDIS失败！');
        $this->redis->select(15);
    }

    /**
     * 注册
     * @param string $name 昵称
     * @param string $password
     * @param array $headers
     * @return array|bool
     */
    public function register($name, $password, $headers = array()) {
        $user_id = uniqid();
        $user = array(
            'user_id' => $user_id,
            'username' => $name,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'reg_time' => date('Y-m-d H:i:s'),
            'role_id' => 0,
            'is_active' => 1,
            'avatar' => '',
        );
        $user = array_merge($headers, $user);
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
    public function getUserById($user_id, $info = array('user_id', 'username', 'role_id', 'is_active', 'avatar')) {
        $hKey = 'user_id:' . $user_id;
        return $this->redis->hMGet($hKey, $info);
    }

    /**
     * 通过用户昵称获取用户信息
     * @param $name
     * @param array $info
     * @return array|bool
     */
    public function getUserByName($name, $info = array('user_id', 'username', 'role_id', 'is_active')) {
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
     * 登录/添加用户
     * @param $user_id
     * @return int
     */
    public function login($user_id) {
        return $this->redis->sAdd($this->userSet, $user_id);
    }

    /**
     * 退出/删除用户
     * @param $user_id
     * @return int
     */
    public function logout($user_id) {
        //return $this->redis->sRem($this->userSet, $user_id);
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

    public function getCommonMessageKey($timestamp) {
        return 'message:common:' . date('Y-m-d', $timestamp);
    }

    public function addCommonMessage($timestamp, $message) {
        $key = $this->getCommonMessageKey($timestamp);
        return $this->redis->zAdd($key, $timestamp, $message);
    }

    public function getPersonalMessageKey($users, $timestamp) {
        sort($users);
        return 'message:users:[' . implode(',', $users) . ']:' . date('Y-m-d', $timestamp);
    }

    public function addPersonalMessage(array $users, $timestamp, $message) {
        $key = $this->getPersonalMessageKey($users, $timestamp);
        return $this->redis->zAdd($key, $timestamp, $message);
    }

    public function getPrevMessage($key, $timestamp, $size = 10) {
        //0-score之间的数量
        $end = $this->redis->zCount($key, 0, $timestamp) - 1;
        if ($end < 0)
            return array();
        $start = $end - $size + 1; //包含start，所有要少一个下标
        $start = max(0, $start);
        return $this->redis->zRange($key, $start, $end);
    }

    public function getPrevCommonMessage($timestamp, $size = 10) {
        $key = $this->getCommonMessageKey($timestamp);
        return $this->getPrevMessage($key, $timestamp, $size);
    }

    public function getPrevPersonalMessage(array $users, $timestamp, $size = 10) {
        $key = $this->getPersonalMessageKey($users, $timestamp);
        return $this->getPrevMessage($key, $timestamp, $size);
    }

    public function addFile($md5, $path) {
        return $this->redis->hSet($this->fileStore, $md5, $path);
    }

    public function getFileByMd5($md5) {
        return $this->redis->hGet($this->fileStore, $md5);
    }
}