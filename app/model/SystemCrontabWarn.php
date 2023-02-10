<?php

namespace app\model;

use plugin\admin\app\model\Base;
use support\Redis;
use function Symfony\Component\String\indexOf;

/**
 * @property integer $warn_id (主键)
 * @property string $nick_name 任务标题
 * @property string $mobile 手机号
 * @property integer $create_user_id 创建人
 * @property integer $create_time 创建时间
 * @property integer $is_effect 1:启用 0：禁用
 */
class SystemCrontabWarn extends Base
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'wa_system_crontab_warn';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'warn_id';
    
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    protected static $_redis_save_key = 'crontab_warn_info';

    protected static $_redis_save_time = 3600;

    /**
     * 删除缓存
     * @author guoliangchen
     * @date 2023/1/31 0031 14:13
     */
    public static function removeWarnCache(){
        Redis::del(self::$_redis_save_key);
    }

    /**
     * 获取缓存
     * @return array|mixed
     * @author guoliangchen
     * @date 2023/1/31 0031 14:17
     */
    public static function getWarnCache(){
        $data = Redis::get(self::$_redis_save_key);
        if (empty($data)){
            $field = ['warn_id','nick_name','mobile'];
            $data = self::where('is_effect',1)->get($field);
            if ($data){
                $rds_save_data = [];
                foreach ($data as $value){
                    $rds_save_data[] = ['warn_id'=>$value->warn_id,'nick_name'=>$value->nick_name,'mobile'=>$value->mobile];
                }
                Redis::setEx(self::$_redis_save_key,self::$_redis_save_time,json_encode($rds_save_data));
                return $rds_save_data;
            }
            return [];
        }
        return json_decode($data,true);
    }

}
