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
class SystemCrontab extends Base {
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

    /**
     * 常用的cron表达式
     * http://cron.ciding.cc/
     * @return array|\string[][]
     * @author guoliangchen
     * @date 2023/1/31 0031 15:55
     */
    public function getCronTips(): array {
        return [
            ['value' => '0 0 10,14,16 * * *', 'name' => '每天上午10点，下午2点，4点执行'],
            ['value' => '15 21 * * 2,5,0', 'name' => '每周周二、周五、周日的晚上21点15分执行'],
            ['value' => '0 32 5-15 * * *', 'name' => '每天5-15点整点的32分触发'],
            ['value' => '28 0 * * *', 'name' => '每天0点28分触发'],
            ['value' => '0 2 1 * *', 'name' => '每个月1号的2点执行'],
            ['value' => '*/5 * * * * *', 'name' => '每隔5秒执行一次'],
            ['value' => '2 */1 * * *', 'name' => '每1小时的第二分钟执行一次'],
            ['value' => '0 */1 * * * *', 'name' => '每隔1分钟执行一次'],
        ];
    }

    public function getCronType() {
        return [
            ['value' => 1, 'name' => '节点执行(同步)'],
            ['value' => 2, 'name' => '请求url(同步)'],
        ];
    }
}
