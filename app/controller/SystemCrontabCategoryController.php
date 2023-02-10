<?php

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;
use app\model\SystemCrontabCategory;
use plugin\admin\app\controller\Crud;
use support\exception\BusinessException;
use function Illuminate\Database\enableQueryLog;

/**
 * 定时任务分类 
 */
class SystemCrontabCategoryController extends MyCrudController
{
    
    /**
     * @var SystemCrontabCategory
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new SystemCrontabCategory;
    }
    
    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('system-crontab-category/index');
    }

    /**
     * 列表
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2023/2/1 0001 16:59
     */
    public function select(Request $request): Response{
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $query = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items = $paginator->items();
        if ($items){
            $items = arrayObjToArray($items);
            $create_user_ids = array_column($items,'create_user_id');
            $field = ['id','nickname'];
            $admin_info = Db::table('wa_admins')->whereIn('id',$create_user_ids)->select($field)->get()->toArray();
            $admin_info = array_column($admin_info,null,'id');
            foreach ($items as &$item) {
                $item['create_time'] = date('Y-m-d H:i:s',$item['create_time']);
                $item['update_time'] = date('Y-m-d H:i:s',$item['update_time']);
                $item['nick_name'] = isset($admin_info[$item['create_user_id']]) ? $admin_info[$item['create_user_id']]->nickname : '';
            }
        }
        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);
    }
    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function insert(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $data = $this->insertInput($request);
            if (empty($data['name'])){
                return $this->json(1, '请输入分类名称');
            }
            if (mb_strlen($data['name']) > 64){
                return $this->json(1, '分类名称不能超过64字');
            }
            $data['create_time'] = $data['update_time'] = time();
            $data['create_user_id'] = admin_id();
            $id = $this->doInsert($data);
            return $this->json(0, 'ok', ['id' => $id]);
        }
        return view('system-crontab-category/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException
    */
    public function update(Request $request): Response
    {
        if ($request->method() === 'POST') {
            $data = $this->insertInput($request);
            if (empty($data['name'])){
                return $this->json(1, '请输入分类名称');
            }
            if (mb_strlen($data['name']) > 64){
                return $this->json(1, '分类名称不能超过64字');
            }
            $data['update_time'] = time();
            [$id, $data] = $this->updateInput($request);
            $this->doUpdate($id, $data);
            return $this->json(0);
        }
        return view('system-crontab-category/update');
    }

    /**
     * 删除
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2023/1/28 0028 10:40
     */
    public function delete(Request $request): Response
    {
        $ids = $this->deleteInput($request);
        if (empty($ids)){
            return $this->json(1,'请选择要删除的分类');
        }
        // 查询分类下面是否有定时任务
        $has_crontabs = Db::table(config('crontab.task.crontab_table'))->whereIn('category_id', $ids)->select(['id','title'])->get()->toArray();
        if (!empty($has_crontabs)){
            return $this->json(1,'请先删除分类下启用的任务！');
        }
        $this->doDelete($ids);
        return $this->json(0);
    }

}
