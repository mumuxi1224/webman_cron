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

    /**
     * 获取所有分类
     * @param $items
     * @return array
     * @author guoliangchen
     * @date 2023/2/1 0001 10:48
     */
    public function getAllCategory($items){
        $data = $this->orderBy($this->primaryKey, 'desc')->get($items);
        if ($data){
            $data = $data->toArray();
            return $data;
        }
        return [];
    }

    /**
     * 根据ID获取分类
     * @param array $ids
     * @param string[] $item
     * @return array
     * @author guoliangchen
     * @date 2023/2/1 0001 15:46
     */
    public function getCategoryByIds($ids = [],$item = ['*']){
        $data = $this->whereIn($this->primaryKey,$ids)->get($item);
        if ($data){
            $data = $data->toArray();
            return $data;
        }
        return [];
    }
}
