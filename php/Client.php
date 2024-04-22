<?php

use server\WsServer;
use server\Worker;

define('APP_PATH', realpath('.'));
function autoload($class) {
    require APP_PATH . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
}

spl_autoload_register('autoload');

try {
    // php Client.php
    // php Client.php -d -p 8080 -n 4
    // php -f Client.php -- -d -p 8080 -n 4
    $opts = getopt('dp:n:');
    $port = ($opts['p'] ?? 8080) + 0;
    $daemon = isset($opts['d']);
    $num = ($opts['n'] ?? 4) + 0;
    $num = max(1, $num);
//    $ssl = [
//        'local_cert'  => '/usr/local/nginx/conf/1_domain_cert.crt',
//        'local_pk'    => '/usr/local/nginx/conf/2_domain.key',
//        'ca_file'     => '/usr/local/nginx/conf/3_domain_ca.crt',
//    ];
    if ($daemon) {
        WsServer::daemonize();
    }
    $config = parse_ini_file('./local.ini', true);
    $ssl = isset($config['ssl']['local_cert'], $config['ssl']['local_pk']) ? $config['ssl'] : [];
    $security = $config['security'] ?? [];
    $bandwidth = $config['bandwidth'] ?? 100;
    $bandwidth += 0;
    $server = new WsServer($port, $ssl, $security, $bandwidth);
    $worker = new Worker();
    $worker->reset();
    $server->run($num, $worker);
} catch (\Exception $e) {
    die($e);
}