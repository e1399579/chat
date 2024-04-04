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
     * @param string $data_type
     * @return void
     */
    public function send(int $key, string $msg, string $data_type = 'TEXT'): void;

    /**
     * 给所有完成握手的客户端发送消息
     * @param string $msg
     * @param int $priority
     * @return void
     */
    public function sendAll(string $msg, int $priority = 10, string $data_type = 'TEXT'): void;

    /**
     * 给多人发送消息
     * @param array $keys
     * @param string $msg
     * @param string $data_type
     * @return void
     */
    public function sendMultiple(array $keys, string $msg, string $data_type = 'TEXT'): void;
}