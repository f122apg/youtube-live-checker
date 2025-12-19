<?php

namespace F122apg\YoutubeLiveChecker\Notification;

enum NotificationType: string
{
    case START = 'start';
    case SUCCESS = 'success';
    case FAILURE = 'failure';
    case TIMEOUT = 'timeout';
    case PROGRESS = 'progress';
    case RETRY = 'retry';
}
