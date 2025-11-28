<?php

namespace Framework;

use DateTime;
use Throwable;



class Logger
{
    private static ?Logger $_instance = null;
    private function __construct()
    {
    }

    const ERROR_STRINGS = [
        E_ERROR => "E_ERROR",
        E_WARNING => "E_WARNING",
        E_PARSE => "E_PARSE",
        E_NOTICE => "E_NOTICE",
        E_CORE_ERROR => "E_CORE_ERROR",
        E_CORE_WARNING => "E_CORE_WARNING",
        E_COMPILE_ERROR => "E_COMPILE_ERROR",
        E_COMPILE_WARNING => "E_COMPILE_WARNING",
        E_USER_ERROR => "E_USER_ERROR",
        E_USER_WARNING => "E_USER_WARNING",
        E_USER_NOTICE => "E_USER_NOTICE",
        E_STRICT => "E_STRICT",
        E_RECOVERABLE_ERROR => "E_RECOVERABLE_ERROR",
        E_DEPRECATED => "E_DEPRECATED",
        E_USER_DEPRECATED => "E_USER_DEPRECATED",
        E_ALL => "E_ALL"
    ];

    public static function get_instance(): Logger
    {
        if (Logger::$_instance === null) {
            Logger::$_instance = new Logger();
        }
        return Logger::$_instance;
    }

    // private array $lines = [];

    public function log_debug($message): void
    {
        if (DEBUG_MODE) {
            $this->write_to_file("DEBUG: $message");
        }
        // $this->lines[] = $message;
    }

    public function log_error($message): void
    {
        // $this->lines[] = 'ERROR ERROR : ' . $message;
        $this->write_to_file("ERROR: $message");
    }

    public function log_php_error(int $error_number, string $message, string $file, int $line_number): void
    {        
        $level = self::ERROR_STRINGS[$error_number];
        $this->write_to_file("$level: $message in file $file line $line_number");
    }

    public function log_php_exception(Throwable $exception): void
    {
        
        $level = 'FATAL EXCEPTION [' . $exception->getCode() . ']';
        $message = $exception->getMessage();
        $file = $exception->getFile();
        $line_number = $exception->getLine();
        $this->write_to_file("$level: $message in file $file line $line_number");
    }

    // public function get_all(): array
    // {
    //     $a = [];
    //     foreach ($this->lines as $line) {
    //         $a[] = $line;
    //     }
    //     return $a;
    // }

    private function write_to_file($line)
    {
        $file_path = ROOT_PATH . 'logs' . DIRECTORY_SEPARATOR . date('Y-m-d') . '.log';
        $line = (new DateTime())->format('H:i:s.u') . ' ' . $line;
        file_put_contents($file_path, $line . PHP_EOL, FILE_APPEND);
    }
}
