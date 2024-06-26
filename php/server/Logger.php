<?php
namespace server;

class Logger {
    private static $_instances;
    private $info_handler;
    private $warning_handler;
    private $error_handler;

    private function __construct(string $path) {
        $this->info_handler = fopen($path . '@INFO.log', 'a');
        $this->warning_handler = fopen($path . '@WARNING.log', 'a');
        $this->error_handler = fopen($path . '@ERROR.log', 'a');
    }

    public static function getInstance(string $path): self {
        if (!isset(self::$_instances[$path])) {
            self::$_instances[$path] = new self($path);
        }

        return self::$_instances[$path];
    }

    public function info(string $message, array $context = []) {
        $content = $this->decorator($message, $context);
        return fwrite($this->info_handler, $content);
    }

    public function warning(string $message, array $context = []) {
        $content = $this->decorator($message, $context);
        return fwrite($this->warning_handler, $content);
    }

    public function error(string $message, array $context = []) {
        $content = $this->decorator($message, $context);
        return fwrite($this->error_handler, $content);
    }

    private function decorator(string $message, array $context = []): string {
        $context_str = json_encode($context, JSON_UNESCAPED_UNICODE);
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        array_shift($trace);
        $current = current($trace);
        $next = next($trace);
        $pid = function_exists('posix_getpid') ? posix_getpid() : null;
        $record = [
            'file' => $current['file'] ?? null,
            'line' => $current['line'] ?? null,
            'class' => $next['class'] ?? null,
            'function' => $next['function'] ?? null,
            'pid' => $pid,
        ];
        $now = (new \DateTime())->format('Y-m-d H:i:s.u');
        $curr = json_encode($record, JSON_UNESCAPED_UNICODE);
        $content = '[' . $now . '] ' . $message . ' ' . $context_str . PHP_EOL . $curr . PHP_EOL . PHP_EOL;
        return $content;
    }

    public function __destruct() {
        fclose($this->info_handler);
        fclose($this->error_handler);
    }
}