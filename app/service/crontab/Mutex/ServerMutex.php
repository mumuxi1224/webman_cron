<?php

declare(strict_types=1);

namespace app\service\crontab\Mutex;


interface ServerMutex
{
    /**
     * Determine if a task mutex exists for the given crontab.
     */
    public function attempt($crontab): bool;

    /**
     * Clear the task mutex for the given crontab.
     */
    public function get($crontab):string;
}
