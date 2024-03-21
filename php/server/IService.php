<?php
namespace server;

interface IService {
    public function run(int $num, IClient $worker): void;
}