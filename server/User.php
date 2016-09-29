<?php
namespace server;

class User
{
	public $redis;
	public $userSet = 'user';//在线用户ID集合

	public function __construct()
	{
		$this->redis = new \Redis();
		$res = $this->redis->pconnect('127.0.0.1', 6379);
		if (false == $res)
			throw new \Exception('连接REDIS失败！');
	}

	/**
	 * 注册
	 * @param string $name 昵称
	 * @param string $password
	 * @param array $headers
	 * @return array
	 */
	public function register($name, $password, $headers = array())
	{
		$user_id = uniqid();
		$users = array(
			'user_id' => $user_id,
			'username' => $name,
			'password' => $password,
			'reg_time' => date('Y-m-d H:i:s'),
			'role_id' => 0,
			'is_active' => 1,
			'avatar' => '',
		);
		$users = array_merge($headers, $users);
		$hKey = 'user_id:' . $user_id;
		$res = $this->redis->hMset($hKey, $users);
		$key = 'username:' . $name;
		$this->redis->set($key, $user_id);
		return $res ? $users : $res;
	}

	/**
	 * 通过用户ID获取用户信息
	 * @param $user_id
	 * @param array $info
	 * @return array
	 */
	public function getUserById($user_id, $info = array('user_id', 'username', 'role_id', 'is_active', 'avatar'))
	{
		$hKey = 'user_id:' . $user_id;
		return $this->redis->hMGet($hKey, $info);
	}

	/**
	 * 通过用户昵称获取用户信息
	 * @param $name
	 * @param array $info
	 * @return array|bool
	 */
	public function getUserByName($name, $info = array('user_id', 'username', 'role_id', 'is_active'))
	{
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
	public function getOnlineUsers()
	{
		return $this->redis->sMembers($this->userSet);
	}

	/**
	 * 登录/添加用户
	 * @param $user_id
	 * @return int
	 */
	public function login($user_id)
	{
		return $this->redis->sAdd($this->userSet, $user_id);
	}

	/**
	 * 退出/删除用户
	 * @param $user_id
	 * @return int
	 */
	public function logout($user_id)
	{
		return $this->redis->sRem($this->userSet, $user_id);
	}

	/**
	 * 更新信息
	 * @param $user_id
	 * @param array $info
	 * @return array
	 */
	public function update($user_id, array $info)
	{
		$hKey = 'user_id:' . $user_id;
		return $this->redis->hMset($hKey, $info);
	}
}