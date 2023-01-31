<?php

namespace app\model;

use plugin\admin\app\model\Base;

/**
 * @property integer $id (主键)
 * @property string $host 节点ip
 * @property string $username 账号
 * @property string $alias 节点别名
 * @property integer $port 节点端口
 * @property string $remark 备注
 * @property integer $create_time 创建时间
 * @property integer $update_time 更新时间
 * @property integer $create_user_id 创建人
 * @property integer $rsa 创建人
 */
class SystemCrontabNode extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_crontab_node';

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
