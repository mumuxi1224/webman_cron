<?php

namespace app\model;

use plugin\admin\app\model\Base;
use support\Redis;

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
 * @property string $code_dir 代码运行的目录
 * @property string $index_name 入口文件名称
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

    protected static $_redis_save_key = 'crontab_node_info';

    protected static $_redis_save_time = 3600;

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

    /**
     * 获取节点缓存
     * @return array|mixed
     * @author guoliangchen
     * @date 2024/4/24 0024 19:23
     */
    public static function getNodeCache(){
        $data = Redis::get(self::$_redis_save_key);
        if (empty($data)){
            $field = ['id','host','alias','port','username','code_dir','index_name'];
            $data = self::get($field);
            if ($data){
                $rds_save_data = [];
                foreach ($data as $value){
                    $rds_save_data[$value->id] = [
                        'host' => $value->host,
                        'alias' => $value->alias,
                        'port' => $value->port,
                        'username' => $value->username,
                        'code_dir' => $value->code_dir,
                        'index_name' => $value->index_name,
                    ];
                }
                Redis::setEx(self::$_redis_save_key,self::$_redis_save_time,json_encode($rds_save_data));
                return $rds_save_data;
            }
            return [];
        }
        return json_decode($data,true);
    }

    /**
     * 删除缓存
     * @author guoliangchen
     * @date 2024/4/24 0024 19:23
     */
    public static function removeWarnCache(){
        Redis::del(self::$_redis_save_key);
    }
}
