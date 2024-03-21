<?php
namespace server;

class ChildProcess extends AProcess implements ISubject, IServer {
    /**
     * @var \SplObjectStorage
     */
    protected $storage; // 业务处理对象存储容器

    public function __construct() {
        parent::__construct();
        $this->storage = new \SplObjectStorage();
    }

    /**
     * 观察对象
     * @param IClient $client
     */
    public function attach(IClient $client): void {
        $this->storage->attach($client);
    }

    /**
     * 解除对象
     * @param IClient $client
     */
    public function detach(IClient $client): void {
        $this->storage->detach($client);
    }

    /**
     * 通知对象
     * @param string $method
     * @param array $params
     */
    public function notify(string $method, array $params): void {
        foreach ($this->storage as $client) {
            call_user_func_array([$client, $method], $params);
        }
    }

    public function listen(array $sockets, array $arg): void {
        $title = 'php: worker process ' . $arg['index'];
        cli_set_process_title($title);

        // 子进程：接收主进程程消息，处理业务。此处在每个不同的新子进程中执行1次
        fclose($sockets[0]);
        $this->sockets[] = $sockets[1];

        $this->setEventBase(); // 重新设置base，不能共用master
        $this->setChannels($sockets[1], $arg);
    }

    public function dispatch(): void {
        foreach ($this->storage as $client) {
            $client->setServer($this);
            $client->onStart();
        }
        $this->base->dispatch();
    }

    public function channelReadCallback(\EventBufferEvent $bev, $arg): void {
        $data_list = $this->receiveFromChannel($bev);
        foreach ($data_list as list($priority, $notify_type, $index, $data)) {
            $this->debug(posix_getpid() . '# work callback:' . sprintf('%08b', $notify_type));
            if ($notify_type === self::NOTIFY_TYPE_ON_OPEN) {
                $data = json_decode($data, true);
            }

            $this->notify($this->notify_map[$notify_type], [$index, $data]);
        }
    }

    public function close(int $key): void {
        $data = '';
        $this->sendToChannel($this->channels[0], self::CALL_TYPE_PREPARE_CLOSE, $key, $data);
    }

    /**
     * 发送消息
     * @param int $key socket index
     * @param string $msg 消息
     */
    public function send(int $key, string $msg): void {
        // 发给主进程
        $this->sendToChannel($this->channels[0], self::CALL_TYPE_SEND_TO, $key, $msg);
    }

    public function sendAll(string $msg, int $priority = 10): void {
        // 发给主进程
        $this->sendToChannel($this->channels[0], self::CALL_TYPE_SEND_TO_ALL, 0, $msg, $priority);
    }
}