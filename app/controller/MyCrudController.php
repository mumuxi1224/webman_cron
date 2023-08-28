<?php

namespace app\controller;

use plugin\admin\app\controller\Crud;
use support\Db;
use support\Request;
use support\Response;

class MyCrudController extends Crud {

    // 不能访问的api
    protected $canNotApi = [];
    // 防止被记录到权限菜单中
    protected $noNeedLogin = ['beforeAction', 'afterAction'];

    /**
     * 该方法会在请求前调用
     * @param Request $request
     * @return Response
     * @author guoliangchen
     * @date 2023/1/13 0013 1]3:53
     */
    public function beforeAction(Request $request) {
        $action = strtolower($request->action);
        if ($this->canNotApi && in_array($action, $this->canNotApi)) {
            return response('权限不足', 401);
        }
    }

    /**
     * 该方法会在请求后调用
     * @param Request $request
     * @param $response
     * @author guoliangchen
     * @date 2023/1/13 0013 13:53
     */
    public function afterAction(Request $request, $response) {
        if (strtolower($request->method()) != 'get') {
            // 记录日志
            $log = [
                'api'            => $request->uri(),
                'ip'             => $request->getRealIp(),
                'agent'          => $request->header('User-Agent'),
                'req_body'       => json_encode($request->all(),JSON_UNESCAPED_UNICODE),
                'resp_body'      => $response->rawBody(),
                'code'           => $response->getStatusCode(),
                'create_user_id' => admin_id(),
                'create_time'    => time(),
                'method'         => $request->method(),
            ];
            Db::table('wa_system_log')->insert($log);
        }
    }


    /**
     * 目前框架无法解决的一些特殊查询问题 先暂时手动处理
     * @param array $where
     * @return array
     * @author guoliangchen
     * @date 2023/1/28 0028 10:09
     */
    public function __formatSearch(array $where = []):array {
        if (!$where) {
            return [];
        }
        if (isset($where['create_time'])) {
            if (count($where['create_time']) == 2) {
                $where['create_time'][0] = strtotime($where['create_time'][0]);
                $where['create_time'][1] = strtotime($where['create_time'][1]);
            }
            else {
                unset($where['create_time']);
            }
        }
        if (!empty($where['title'])){
            $where['title'] = ['like','%'.$where['title'].'%'];
        }
        if (!empty($where['target'])){
            $where['target'] = ['like','%'.$where['target'].'%'];
        }
        return $where;
    }
}

