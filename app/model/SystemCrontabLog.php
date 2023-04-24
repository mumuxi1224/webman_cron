<?php

namespace app\model;

use plugin\admin\app\model\Base;

/**
 * @property integer $id (主键)
 * @property integer $crontab_id 任务id
 * @property string $target 任务调用目标字符串
 * @property string $parameter 任务调用参数
 * @property string $exception 任务执行或者异常信息输出
 * @property integer $return_code 执行返回状态[0成功; 1失败]
 * @property string $running_time 执行所用时间
 * @property integer $create_time 创建时间
 * @property integer $update_time 更新时间
 */
class SystemCrontabLog extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_crontab_log';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
}
