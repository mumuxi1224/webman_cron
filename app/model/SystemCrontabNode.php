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

    /**
     * 获取节点列表
     * @param $items
     * @return array
     * @author guoliangchen
     * @date 2023/2/1 0001 10:49
     */
    public function getNodeList($items=['*']) {
        $data = $this->orderBy($this->primaryKey, 'desc')->get($items);
        if ($data) {
            $data = $data->toArray();
            return $data;
        }
        return [];
    }

    /**
     * 根据ID获取
     * @param array $ids
     * @param string[] $items
     * @return array
     * @author guoliangchen
     * @date 2023/2/1 0001 15:51
     */
    public function getNodeListByIds($ids = [],$items=['*']) {
        $data = $this->whereIn($this->primaryKey, $ids)->get($items);
        if ($data) {
            $data = $data->toArray();
            return $data;
        }
        return [];
    }
}
