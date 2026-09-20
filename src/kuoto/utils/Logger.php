<?php

namespace kuoto\utils;

class Logger
{
    const LEVEL_DEBUG = 0;
    const LEVEL_INFO = 1;
    const LEVEL_NOTICE = 2;
    const LEVEL_WARNING = 3;
    const LEVEL_ERROR = 4;
    const LEVEL_CRITICAL = 5;

    private $level;
    private $logFile;
    private $useColors;
    private $hasPrompt = false;
    private $promptCallback = null;

    private static $LEVEL_NAMES = array(
        self::LEVEL_DEBUG => 'DEBUG',
        self::LEVEL_INFO => 'INFO',
        self::LEVEL_NOTICE => 'NOTICE',
        self::LEVEL_WARNING => 'WARNING',
        self::LEVEL_ERROR => 'ERROR',
        self::LEVEL_CRITICAL => 'CRITICAL'
    );

    private static $LEVEL_COLORS = array(
        self::LEVEL_DEBUG => "\033[0;37m",
        self::LEVEL_INFO => "\033[0;37m",
        self::LEVEL_NOTICE => "\033[0;36m",
        self::LEVEL_WARNING => "\033[1;33m",
        self::LEVEL_ERROR => "\033[1;31m",
        self::LEVEL_CRITICAL => "\033[1;31m"
    );

    const RESET = "\033[0m";
    const GRAY = "\033[0;37m";
    const WHITE = "\033[0;97m";
    const AQUA = "\033[0;36m";
    const YELLOW = "\033[1;33m";
    const RED = "\033[1;31m";

    public function __construct($level = self::LEVEL_INFO, $logFile = null)
    {
        $this->level = $level;
        $this->useColors = PHP_SAPI === 'cli';

        if ($logFile !== null && trim($logFile) !== '') {
            $this->logFile = $logFile;

            $directory = dirname($this->logFile);

            if (!is_dir($directory)) {
                @mkdir($directory, 0777, true);
            }

            @file_put_contents(
                $this->logFile,
                "=== Kuoto Proxy started at " . date('Y-m-d H:i:s') . " ===\n",
                FILE_APPEND
            );
        } else {
            $this->logFile = null;
        }
    }

    public function setPromptCallback($callback)
    {
        $this->promptCallback = $callback;
    }

    public function setHasPrompt($has)
    {
        $this->hasPrompt = $has;
    }

    public function setLevel($level)
    {
        $this->level = $level;
    }

    /**
     * Permite a los caminos calientes (un paquete por llamada) saltarse la
     * construccion del mensaje cuando debug() lo va a descartar igualmente.
     *
     * @return bool
     */
    public function isDebugEnabled()
    {
        return $this->level <= self::LEVEL_DEBUG;
    }

    public function log($level, $message)
    {
        if ($level < $this->level) {
            return;
        }

        $time = date('H:i:s');

        $name = isset(self::$LEVEL_NAMES[$level])
            ? self::$LEVEL_NAMES[$level]
            : 'UNKNOWN';

        if ($this->hasPrompt && $this->useColors) {
            echo "\r\033[K";
        }

        if ($this->useColors) {
            $color = isset(self::$LEVEL_COLORS[$level])
                ? self::$LEVEL_COLORS[$level]
                : self::WHITE;

            echo self::AQUA
                . "[{$time}]"
                . self::RESET
                . " "
                . $color
                . "[{$name}]"
                . self::RESET
                . " "
                . self::WHITE
                . $message
                . self::RESET
                . "\n";
        } else {
            echo "[{$time}] [{$name}] {$message}\n";
        }

        if ($this->logFile !== null) {
            @file_put_contents(
                $this->logFile,
                "[{$time}] [{$name}] {$message}\n",
                FILE_APPEND
            );
        }

        if ($this->hasPrompt && $this->promptCallback !== null) {
            call_user_func($this->promptCallback);
        }

        @ob_flush();
        @flush();
    }

    public function debug($msg)
    {
        $this->log(self::LEVEL_DEBUG, $msg);
    }

    public function info($msg)
    {
        $this->log(self::LEVEL_INFO, $msg);
    }

    public function notice($msg)
    {
        $this->log(self::LEVEL_NOTICE, $msg);
    }

    public function warning($msg)
    {
        $this->log(self::LEVEL_WARNING, $msg);
    }

    public function error($msg)
    {
        $this->log(self::LEVEL_ERROR, $msg);
    }

    public function critical($msg)
    {
        $this->log(self::LEVEL_CRITICAL, $msg);
    }
}