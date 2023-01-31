<?php

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;
use app\model\SystemCrontabWarn;
use support\exception\BusinessException;

/**
 * 预警账号 
 */
class SystemCrontabWarnController extends MyCrudController
{
    
    /**
     * @var SystemCrontabWarn
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new SystemCrontabWarn;
    }
    
    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('system-crontab-warn/index');
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
            if (empty($data['nick_name'])){
                return $this->json(1, '请输入昵称');
            }
            if (mb_strlen($data['nick_name']) > 100){
                return $this->json(1, '昵称不能超过100字');
            }
            if (empty($data['mobile'])){
                return $this->json(1, '请输入昵称');
            }
            if (!isMobile($data['mobile'])){
                return $this->json(1, '手机号码格式错误');
            }
            $has = $this->model->where('mobile',$data['mobile'])->first('warn_id');
            if ($has){
                return $this->json(1, '手机号已存在');
            }
            $data['create_time']  = time();
            $data['create_user_id'] = admin_id();
            $id = $this->doInsert($data);
            // 清除缓存
            SystemCrontabWarn::removeWarnCache();
            return $this->json(0, 'ok', ['id' => $id]);
        }
        return view('system-crontab-warn/insert');
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
            if (empty($data['nick_name'])){
                return $this->json(1, '请输入昵称');
            }
            if (mb_strlen($data['nick_name']) > 100){
                return $this->json(1, '昵称不能超过100字');
            }
            if (empty($data['mobile'])){
                return $this->json(1, '请输入昵称');
            }
            if (!isMobile($data['mobile'])){
                return $this->json(1, '手机号码格式错误');
            }
            [$id, $data] = $this->updateInput($request);
            $has = $this->model->where('warn_id','<>',$id)->where('mobile',$data['mobile'])->first('warn_id');
            if ($has){
                return $this->json(1, '手机号已存在');
            }
            $this->doUpdate($id, $data);
            return $this->json(0);
        }
        return view('system-crontab-warn/update');
    }

    public function effect(Request $request): Response
    {
        if ($request->method() === 'POST') {
            return parent::update($request);
        }
    }


    public function select(Request $request): Response{
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $query = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items = $paginator->items();
        if ($items){
            $items = arrayObjToArray($items);
            foreach ($items as &$item) {
                $item['create_time'] = date('Y-m-d H:i:s',$item['create_time']);
            }
        }
        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);
    }
}
