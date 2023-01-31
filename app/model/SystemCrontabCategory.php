<?php

namespace app\model;

use plugin\admin\app\model\Base;

/**
 * @property integer $category_id (主键)
 * @property string $name 分类名称
 * @property integer $create_user_id 账号
 * @property integer $create_time 创建时间
 * @property integer $update_time 更新时间
 */
class SystemCrontabCategory extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_crontab_category';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'category_id';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;
    
    
}
