<?php

namespace app\controller;

use support\Request;
use support\Response;
use app\model\SystemCrontab;
use plugin\admin\app\controller\Crud;
use support\exception\BusinessException;

/**
 * 定时任务列表 
 */
class SystemCrontabController extends Crud
{
    
    /**
     * @var SystemCrontab
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->model = new SystemCrontab;
    }
    
    /**
     * 浏览
     * @return Response
     */
    public function index(): Response
    {
        return view('system-crontab/index');
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
            return parent::insert($request);
        }
        return view('system-crontab/insert');
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
            return parent::update($request);
        }
        return view('system-crontab/update');
    }

}
