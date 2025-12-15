<?php
require_once __DIR__ . '/vendor/autoload.php';
date_default_timezone_set('Asia/Shanghai');
ini_set('memory_limit', '1024M');
ini_set('display_errors', 'on');
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    $date = date('Y-m-d H:i:s');
    var_dump("[{$date}] Error: [$errno] $errstr in $errfile on line $errline");
});

set_exception_handler(function ($exception) {
    $date = date('Y-m-d H:i:s');
    var_dump("[{$date}] Exception: " . $exception->getTraceAsString());
    var_dump("[{$date}] Exception: ".$exception->getMessage()." in File:" .$exception->getFile().' on Line:'.$exception->getLine() );
});

$crontabService = new \app\service\swooleCrontab\Service();

// 启动服务
$crontabService->run();

\Swoole\Event::wait();


