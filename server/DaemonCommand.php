<?php

namespace server;

class DaemonCommand {
    protected $is_singleton;
    protected $output;
    protected $user;
    private $info_dir = "/tmp";
    private $pid_file = "";
    private $workers_count = 0;
    private $jobs = array();
    private $master_job;

    public function __construct($is_singleton = false, $user = 'nobody', $output = '/dev/null') {

        $this->is_singleton = $is_singleton;  // 是否单例运行，单例运行会在tmp目录下建立一个唯一的PID
        $this->user = $user; // 设置运行的用户 默认情况下nobody
        $this->output = $output; // 设置输出的地方
        $this->checkPcntl();
    }

    /**
     * 检查环境是否支持pcntl
     * @throws \RuntimeException
     */
    public function checkPcntl() {
        if (!function_exists('pcntl_signal')) {
            $message = 'PHP does not appear to be compiled with the PCNTL extension.  This is necessary for daemonization';
            throw new \RuntimeException($message);
        }
    }

    /**
     * daemon化程序
     */
    public function daemonize() {
        // 只允许在cli下面运行
        if (php_sapi_name() != "cli") {
            die("only run in command line mode\n");
        }

        $argv = $_SERVER['argv'];
        // 只能单例运行
        if ($this->is_singleton == true) {
            $this->pid_file = $this->info_dir . "/" . basename($argv[0], '.php') . ".pid";
            $this->checkPidFile();
        }

        $pid = pcntl_fork();
        //父进程和子进程都会执行下面代码
        if (-1 === $pid) {
            //错误处理：创建子进程失败时返回-1.
            throw new \RuntimeException('could not fork');
        } else {
            if ($pid) {
                //父进程会得到子进程号，所以这里是父进程执行的逻辑
                exit(0);
            } else {
                //子进程得到的$pid为0, 所以这里是子进程执行的逻辑
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

                $this->setUser($this->user) or die("cannot change owner");
                $this->resetOutput();

                if ($this->is_singleton) {
                    $this->createPidFile();
                }
            }
        }
    }

    public function resetOutput() {
        //关闭打开的文件描述符
        fclose(STDOUT);
        fclose(STDERR);

        global $STDOUT, $STDERR;
        chmod($this->output, 0622);
        $STDOUT = fopen($this->output, 'a');
        $STDERR = fopen($this->output, 'a');
    }

    /**
     * 检测pid是否已经存在
     * @return bool
     */
    public function checkPidFile() {

        if (!file_exists($this->pid_file)) {
            return true;
        }
        $pid = file_get_contents($this->pid_file);
        $pid = intval($pid);
        if ($pid > 0 && posix_kill($pid, 0)) {
            $this->_log("the daemon process is already started");
        } else {
            $this->_log("the daemon process end abnormally, please check pidfile " . $this->pid_file);
        }
        exit(1);
    }

    /**
     * 创建pid
     */
    public function createPidFile() {

        if (!is_dir($this->info_dir)) {
            mkdir($this->info_dir);
        }
        $fp = fopen($this->pid_file, 'w') or die("cannot create pid file");
        fwrite($fp, posix_getpid());
        fclose($fp);
        $this->_log("create pid file " . $this->pid_file);
    }

    /**
     * 设置运行的用户
     * @param $name
     * @return bool
     */
    public function setUser($name) {
        $result = false;
        if (empty($name)) {
            return true;
        }
        $user = posix_getpwnam($name);
        if ($user) {
            $uid = $user['uid'];
            $gid = $user['gid'];
            $result = posix_setuid($uid);
            posix_setgid($gid);
        }
        return $result;
    }

    /**
     * 开始运行
     * @param int $count
     * @throws \RuntimeException
     */
    public function start($count = 1) {
        $count = max(1, $count);
        $this->_log("daemon process is running now");
        $pid_list = array();
        $socket_list = array();
        while ($this->workers_count < $count) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if (-1 === $pid) {
                throw new \RuntimeException('could not fork');
            } else {
                if ($pid) {
                    $this->workers_count++;
                    $pid_list[] = $pid;
                    fclose($sockets[1]);
                    $socket_list[] = $sockets[0];
                } else {
                    fclose($sockets[0]);
                    $this->resetOutput();
                    foreach ($this->jobs as $job) {
                        $pid = posix_getpid();
                        call_user_func_array($job, [$pid, $sockets[1]]);
                    }
                    exit(250);
                }
            }
        }

        while (true) {
            call_user_func_array($this->master_job, [$pid_list, $socket_list]);
            pcntl_signal_dispatch();
            $pid = pcntl_wait($status, WUNTRACED);
            pcntl_signal_dispatch();
            // 正常退出，清理
            $this->masterQuit();

            break;
        }
    }

    /**
     * 整个进程退出
     */
    public function masterQuit() {

        if (file_exists($this->pid_file)) {
            unlink($this->pid_file);
            $this->_log("delete pid file " . $this->pid_file);
        }
        $this->_log("daemon process exit now");
        posix_kill(0, SIGKILL);
        exit(0);
    }

    /**
     * 添加工作实例
     * @param $callback
     */
    public function addJob(callable $callback) {
        $this->jobs[] = $callback;
    }

    public function addMasterJob(callable $callback) {
        $this->master_job = $callback;
    }

    /**
     * 日志处理
     * @param $message
     */
    private function _log($message) {
        printf("%s\t%d\t%d\t%s\n", date("c"), posix_getpid(), posix_getppid(), $message);
    }

}
