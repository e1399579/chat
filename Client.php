<?php
define('APP_PATH', realpath('.'));
function autoload($class) {
    require APP_PATH . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
}

spl_autoload_register('autoload');

function daemonize() {
    $pid = pcntl_fork();
    if (-1 === $pid) {
        throw new \RuntimeException('could not fork');
    }

    if ($pid) {
        exit(0);
    }

    //设置新会话组长，脱离终端
    if (-1 === posix_setsid()) {
        throw new \RuntimeException('posix_setsid fail');
    }

    $pid = pcntl_fork();
    if (-1 === $pid) {
        throw new \RuntimeException('could not fork');
    } else if ($pid) {
        exit(0);
    }
}

try {
    // php Client.php
    // php Client.php -d -p 8080 -n 8
    // php -f Client.php -- -d -p 8080 -n 8
    $opts = getopt('dp:n:');
    $port = ($opts['p'] ?? 8080) + 0;
    $daemon = isset($opts['d']);
    $num = ($opts['n'] ?? 8) + 0;
    $num = max(1, $num);
//    $ssl = [
//        'local_cert'  => '/usr/local/nginx/conf/1_chat.ridersam.cn_cert.crt',
//        'local_pk'    => '/usr/local/nginx/conf/2_chat.ridersam.cn.key',
//        'verify_peer' => false,
//    ];
    echo 'Server is starting.', PHP_EOL;
    if ($daemon) {
        daemonize();
    }
    $ssl = [];
    $server = new server\WsServer($port, $ssl);
    $client = new server\Worker($server);
    $client->run($num);
} catch (\Exception $e) {
    die($e);
}