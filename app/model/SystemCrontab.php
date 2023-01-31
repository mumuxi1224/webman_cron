<?php

namespace app\model;

use plugin\admin\app\model\Base;

/**
 * @property integer $id (主键)
 * @property string $title 任务标题
 * @property integer $type 任务类型 (1 command, 2 class, 3 url, 4 eval)
 * @property string $rule 任务执行表达式
 * @property string $target 调用任务字符串
 * @property string $parameter 任务调用参数
 * @property integer $running_times 已运行次数
 * @property integer $last_running_time 上次运行时间
 * @property string $remark 备注
 * @property integer $sort 排序，越大越前
 * @property integer $status 任务状态状态[0:禁用;1启用]
 * @property integer $create_time 创建时间
 * @property integer $update_time 更新时间
 * @property integer $singleton 是否单次执行 (0 是 1 不是)
 * @property integer $node_id 节点表ID
 * @property integer $category_id 分类ID
 * @property integer $single_run_max_time 单次运行最大时间，超长会报警，单位：秒
 * @property integer $end_time 结束时间
 * @property string $warning_ids 要预警的人id集合，逗号隔开
 */
class SystemCrontab extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_crontab';

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
