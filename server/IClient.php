<?php
namespace server;

interface IClient {
    /**
     * 打开服务时
     * @param int $key 服务索引
     * @param array $headers
     * @return void
     */
    public function onOpen(int $key, array $headers): void;

    /**
     * 有消息时
     * @param int $key
     * @param string $message
     * @return void
     */
    public function onMessage(int $key, string $message): void;

    /**
     * 出错时
     * @param int $key
     * @param string $err
     * @return void
     */
    public function onError(int $key, string $err): void;

    /**
     * 关闭连接时
     * @param int $key
     * @return void
     */
    public function onClose(int $key): void;
}