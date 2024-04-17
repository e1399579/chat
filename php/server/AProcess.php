<?php
namespace server;

abstract class AProcess {
    const NOTIFY_TYPE_ON_OPEN = 0b1;
    const NOTIFY_TYPE_ON_CLOSE = 0b10;
    const NOTIFY_TYPE_ON_MESSAGE = 0b100;
    const NOTIFY_TYPE_ON_ERROR = 0b1000;

    protected $notify_map = [
        self::NOTIFY_TYPE_ON_OPEN => 'onOpen',
        self::NOTIFY_TYPE_ON_CLOSE => 'onClose',
        self::NOTIFY_TYPE_ON_MESSAGE => 'onMessage',
        self::NOTIFY_TYPE_ON_ERROR => 'onError',
    ];

    const CALL_TYPE_SEND_TO = 0b1;
    const CALL_TYPE_SEND_TO_ALL = 0b10;
    const CALL_TYPE_PREPARE_CLOSE = 0b100;
    const CALL_TYPE_SEND_TO_MULTIPLY = 0b1000;

//    protected $call_map = [
//        self::CALL_TYPE_SEND_TO => 'sendTo',
//        self::CALL_TYPE_SEND_TO_ALL => 'sendToAll',
//        self::CALL_TYPE_PREPARE_CLOSE => 'prepareClose',
//        self::CALL_TYPE_SEND_TO_MULTIPLY => 'sendToMultiple',
//    ];

    protected $debug = false; // 调试
        /**
     * @var Logger
     */
    protected $logger; // 日志

    protected $frame_max_length = 2 ** 32; // 一帧最大长度，判断是否出现异常


    /**
     * @var \EventBase
     */
    protected $base; // 基础服务

    /**
     * @var \EventBufferEvent[]
     */
    protected $channels = []; // 管道bev
    protected $sockets = []; // 管道socket

    public function __construct() {
        $path = './logs/socket';
        $this->logger = Logger::getInstance($path);
    }

    protected function setEventBase() {
        $cfg = new \EventConfig();
        $cfg->requireFeatures(\EventConfig::FEATURE_O1 | \EventConfig::FEATURE_ET);
        $this->base = new \EventBase($cfg);
    }

    protected function setEventOption(\EventBufferEvent $bev, $priority, $lowmark, $highmark): void {
        $bev->enable(\Event::READ | \Event::WRITE);
        $bev->setWatermark(\Event::READ, $lowmark, $highmark);
        $bev->setWatermark(\Event::WRITE, $lowmark, $highmark);
        $bev->setPriority($priority);
    }

    protected function setChannels($socket, $arg) {
        $bev = new \EventBufferEvent(
            $this->base,
            $socket,
            \EventBufferEvent::OPT_CLOSE_ON_FREE
        );

        $this->setEventOption($bev, 10, 8, 0);
        $bev->setCallbacks(
            [$this, 'channelReadCallback'],
            function() {},
            [$this, 'channelEventCallback'],
            $arg
        );
        $this->channels[] = $bev;
    }

    protected function sendToChannel(\EventBufferEvent $bev, int $notify_type, int $index, string &$data, int $priority = 0, int $opcode = 0x1): void {
        // IPC消息格式：| 数据长度 4 byte | opcode 4 bit & 优先级 4 bit | 通知类型 1 byte | 服务标识 2 byte | 数据 |
        $len = strlen($data);
        $payload = pack('N', $len) . chr(($opcode << 4) | ($priority & 0b1111)) . chr($notify_type) . pack('n', $index);
        $bev->write($payload . $data);
    }

    protected function receiveFromChannel(\EventBufferEvent $bev, &$opcode = 0): array {
        $data_list = [];
        // 可能写入的数据有多条，但是都在一个缓存里面，故延迟处理
        $input = $bev->input;
        $payload_length = 8;
        $offset = 4;
        while ($length = $input->length) {
            $payload = $input->substr(0, $payload_length);
            if (strlen($payload) < $payload_length) {
                break;
            }

            $data_length = current(unpack('N', substr($payload, 0, 4)));
            if ($payload_length + $data_length > $length) {
                // 未接收完，下次回调再作处理
                break;
            }

            if (($data_length > $this->frame_max_length) || ($data_length < 0)) {
                // 协议出错，清空数据
                $input->drain($input->length);
                $this->logger->error('Receive length error:' . $data_length);
                break;
            }

            $byte_ord = ord(substr($payload, $offset, 1));
            $opcode = $byte_ord >> 4; // 去掉后4位
            $priority = $byte_ord & 0b1111; // 去掉前4位
            $notify_type = ord(substr($payload, $offset + 1, 1));
            $index = current(unpack('n', substr($payload, $offset + 2, 2)));

            $input->drain($payload_length);
            $data = $input->read($data_length);
            $data_list[] = [$priority, $notify_type, $index, $data];
        }

        return $data_list;
    }

    public function channelEventCallback(\EventBufferEvent $bev, int $events, $arg): void {
        $index = $arg['index'];
        if ($events & \EventBufferEvent::ERROR) {
            $this->logger->error("BEV error:" . $this->getError($bev->fd));
        }

        if ($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR)) {
            $bev->free();
            unset($this->channels[$index]);
        }
    }

    /**
     * 获取错误信息
     * @param int|null $fd
     * @return string
     */
    protected function getError($fd): string {
        return (string) \EventUtil::getLastSocketError($fd);
    }

    protected function debug(string $content) {
        if (!$this->debug) return;
        echo (new \DateTime())->format('Y-m-d H:i:s.u') . ' ' . $content . PHP_EOL;
    }

    abstract public function listen(array $sockets, array $arg): void;

    abstract public function dispatch(): void;

    abstract public function channelReadCallback(\EventBufferEvent $bev, $arg): void;
}