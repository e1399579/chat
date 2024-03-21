<?php
namespace server;

error_reporting(E_ALL);//调试时为E_ALL
set_time_limit(0);
ob_implicit_flush();//调试时打开

class WsServer implements IService {
    protected $masterProcess;
    protected $childProcess;

    /**
     * @param int $port
     * @param array $ssl
     * @param int $bandwidth // 网络带宽 Mbps
     */
    public function __construct(int $port, array $ssl = [], int $bandwidth = 100) {
        $this->checkEnvironment();

        $this->masterProcess = new MasterProcess($port, $ssl, $bandwidth);
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

    protected function checkEnvironment() {
        if (php_sapi_name() !== 'cli') {
            throw new \RuntimeException('Only run in command line');
        }

        if (!extension_loaded('event')) {
            throw new \RuntimeException("Please install event extension firstly");
        }
    }
}