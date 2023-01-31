<?php

namespace app\model;

use plugin\admin\app\model\Base;

/**
 * @property integer $history_id (主键)
 * @property string $nick_name 任务标题
 * @property string $mobile 手机号
 * @property integer $crontab_id 预警的定时任务
 * @property string $sms_content 预警内容
 * @property integer $create_time 创建时间
 */
class SystemCrontabWarnHistory extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_crontab_warn_history';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'history_id';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
    
    
}
