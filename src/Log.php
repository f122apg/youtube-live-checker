<?php
namespace F122apg\YoutubeLiveChecker;

class Log {
    private static $_log = fopen('php://stderr', 'wb');
    private static $_severities = [
        'default',
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
        'alert',
        'emergency',
    ];

    public static function __callStatic(string $name, array $arguments) {
        $severity = strtolower($name);

        if (!in_array($severity, self::$_severities)) {
            throw new \RuntimeException('no such function:' . $name);
        }

        fwrite(self::$_log, json_encode([
            'message' => $arguments[0] . "\n",
            'severity' => $severity
        ]));
    }
}