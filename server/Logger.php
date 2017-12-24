<?php
namespace server;

class Logger {
    private static $_instances;
    private $info_handler;
    private $error_handler;

    private function __construct($path) {
        $this->info_handler = fopen($path . '@INFO.log', 'a');
        $this->error_handler = fopen($path . '@ERROR.log', 'a');
    }

    public static function getInstance($path) {
        if (!isset(self::$_instances[$path])) {
            self::$_instances[$path] = new self($path);
        }

        return self::$_instances[$path];
    }

    public function info($message, $context = array()) {
        $content = $this->decorator($message, $context);
        return fwrite($this->info_handler, $content);
    }

    public function error($message, $context = array()) {
        $content = $this->decorator($message, $context);
        return fwrite($this->error_handler, $content);
    }

    private function decorator(&$message, &$context = array()) {
        $context_str = json_encode($context, JSON_UNESCAPED_UNICODE);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        array_shift($trace);
        array_shift($trace);
        $curr = json_encode(current($trace), JSON_UNESCAPED_UNICODE);
        $content = '[' . date('Y-m-d H:i:s') . '] ' . $message . ' ' . $context_str . PHP_EOL . $curr . PHP_EOL . PHP_EOL;
        return $content;
    }

    public function __destruct() {
        fclose($this->info_handler);
        fclose($this->error_handler);
    }
}