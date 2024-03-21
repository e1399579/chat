<?php

namespace server;

interface ISubject {
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
     * 通知观察者
     * @param string $method
     * @param array $params
     * @return void
     */
    public function notify(string $method, array $params): void;
}