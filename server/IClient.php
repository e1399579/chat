<?php
namespace server;

interface IClient
{
	/**
	 * 打开服务时
	 * @param int $key 服务索引
	 * @param array $headers
	 * @return void
	 */
	public function onOpen($key, $headers);

	/**
	 * 有消息时
	 * @param int $key
	 * @param string $message
	 * @return void
	 */
	public function onMessage($key, $message);

	/**
	 * 出错时
	 * @param int $key
	 * @param string $err
	 * @return void
	 */
	public function onError($key, $err);

	/**
	 * 关闭连接时
	 * @param $key
	 * @return void
	 */
	public function onClose($key);
}