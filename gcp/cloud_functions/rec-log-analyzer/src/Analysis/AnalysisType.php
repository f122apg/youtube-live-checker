<?php
namespace F122apg\YoutubeLiveChecker\Analysis;

enum AnalysisType: string {
    case FAILURE = 'failure';
    case TIMEOUT = 'timeout';

    public function label(): string
    {
        return match($this) {
            self::FAILURE => '失敗',
            self::TIMEOUT => 'タイムアウト',
        };
    }
}
