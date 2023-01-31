<?php
return [
    'enable' => true,
    'task'   => [
        'listen'             => getenv('CRONTAB_LISTEN'),
        'crontab_table'      => 'wa_system_crontab', //任务计划表
        'crontab_table_log'  => 'wa_system_crontab_log',//任务计划流水表
        'crontab_table_node' => 'wa_system_crontab_node',//任务节点表
        'debug'              => getenv('CRONTAB_DEBUG'), //控制台输出日志
        'write_log'          => true,// 任务计划日志
    ],
];