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
     * 关闭连接
     * @param $key
     * @return void
     */
    public function close($key);

    /**
     * 发送消息
     * @param $key
     * @param $msg
     * @return void
     */
    public function send($key, $msg);

    /**
     * 给所有完成握手的客户端发送消息
     * @param $msg
     * @return void
     */
    public function sendAll($msg);

    /**
     * 开始运行
     * @param int $num
     * @return void
     */
    public function run($num);

    /**
     * 转发消息
     * @param $pid_list
     * @param $socket_list
     * @return mixed
     */
    public function forwardMessage($pid_list, $socket_list);
}