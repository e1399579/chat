<?php
namespace server;

error_reporting(E_ALL);//调试时为E_ALL
set_time_limit(0);
ob_implicit_flush();//调试时打开

class WsServer implements IService {
    protected $masterProcess;

    /**
     * @param int $port
     * @param array $ssl
     * @param array $security
     * @param int $bandwidth // 网络带宽 Mbps
     */
    public function __construct(int $port, array $ssl = [], array $security = [], int $bandwidth = 100) {
        $this->checkEnvironment();

        $this->masterProcess = new MasterProcess($port, $ssl, $security, $bandwidth);
    }

    /**
     * 开始运行
     * @param int $num
     * @param IClient $worker
     * @return void
     */
    public function run(int $num, IClient $worker): void {
        $this->masterProcess->run($num, $worker);
    }

    public static function daemonize() {
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \RuntimeException('could not fork');
        }

        if ($pid) {
            exit(0);
        }

        // 设置新会话组长，脱离终端
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

    protected function checkEnvironment() {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Only run in command line');
        }

        if (!extension_loaded('event')) {
            throw new \RuntimeException("Please install event extension firstly");
        }
    }
}