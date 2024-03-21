<?php

namespace server;

interface IServer {

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
     * @param int $priority
     * @return void
     */
    public function sendAll(string $msg, int $priority = 10): void;
}