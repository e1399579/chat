<?php
namespace server;

interface IClient {

    public function setServer(IServer $server): void;

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
     * @param string $data_type TEXT|BINARY
     * @return void
     */
    public function onMessage(int $key, string $message, string $data_type = 'TEXT'): void;

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

    /**
     * 启动时，设置回调函数
     */
    public function onStart(): void;
}