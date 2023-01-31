<?php

namespace app\model;

use plugin\admin\app\model\Base;

/**
 * @property integer $log_id 日志ID(主键)
 * @property string $api api名称
 * @property string $ip IP地址
 * @property string $agent 浏览器信息
 * @property string $req_body 请求参数
 * @property string $resp_body 响应参数
 * @property integer $code 响应状态码
 * @property integer $create_user_id 账号
 * @property integer $create_time 创建时间
 * @property string $method 请求方式
 */
class SystemLog extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_log';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'log_id';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
    
    
}
