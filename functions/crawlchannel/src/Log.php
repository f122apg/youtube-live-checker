<?php
namespace F122apg\YoutubeLiveChecker;

class Log {
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

        $resource = fopen('php://stderr', 'wb');
        fwrite($resource, json_encode([
            'message' => $arguments[0],
            'severity' => $severity
        ]) . PHP_EOL);
    }
}