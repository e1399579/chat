<?php

namespace server;

interface IServer {
    /**
     * 观察对象
     * @param IClient $client
     * @return void
     */
    public function attach(IClient $client);

    /**
     * 解除对象
     * @param IClient $client
     * @return void
     */
    public function detach(IClient $client);

    /**
     * 断开连接
     * @param $index
     * @return void
     */
    public function disConnect($index);

    /**
     * 发送消息
     * @param $index
     * @param $msg
     * @return void
     */
    public function send($index, $msg);

    /**
     * 给所有完成握手的客户端发送消息
     * @param $msg
     * @return void
     */
    public function sendAll($msg);

    /**
     * 开始运行
     * @return void
     */
    public function run();
}