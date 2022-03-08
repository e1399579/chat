<?php

namespace server;

interface IServer {
    /**
     * 观察对象
     * @param IClient $client
     * @return void
     */
    public function attach(IClient $client): void;

    /**
     * 解除对象
     * @param IClient $client
     * @return void
     */
    public function detach(IClient $client): void;

    /**
     * 关闭连接
     * @param int $key
     * @return void
     */
    public function close(int $key): void;

    /**
     * 发送消息
     * @param int $key
     * @param string $msg
     * @return void
     */
    public function send(int $key, string $msg): void;

    /**
     * 给所有完成握手的客户端发送消息
     * @param string $msg
     * @return void
     */
    public function sendAll(string $msg): void;

    /**
     * 开始运行
     * @param int $num
     * @param ?callable $callback
     * @return void
     */
    public function run(int $num, ?callable $callback = null): void;
}