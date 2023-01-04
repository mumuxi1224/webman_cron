<?php

declare(strict_types=1);

namespace app\service\crontab\Mutex;


interface TaskMutex
{
    /**
     * Attempt to obtain a task mutex for the given crontab.
     * @param  $crontab
     * @return bool
     */
    public function create($crontab): bool;

    /**
     * Determine if a task mutex exists for the given crontab.
     * @param  $crontab
     * @return bool
     */
    public function exists($crontab): bool;

    /**
     * Clear the task mutex for the given crontab.
     * @param  $crontab
     */
    public function remove($crontab);
}
