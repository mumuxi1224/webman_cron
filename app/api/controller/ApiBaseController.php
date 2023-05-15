<?php
namespace app\api\controller;
use support\Db;
use support\Request;
class ApiBaseController {

    public $operate_man = 0;
//    // 不能访问的api
//    protected $canNotApi = [];
//    // 防止被记录到权限菜单中
//    protected $noNeedLogin = ['beforeAction', 'afterAction'];
//
//    /**
//     * 该方法会在请求前调用
//     * @param Request $request
//     * @return Response
//     * @author guoliangchen
//     * @date 2023/1/13 0013 1]3:53
//     */
//    public function beforeAction(Request $request) {
//        $action = strtolower($request->action);
//        if ($this->canNotApi && in_array($action, $this->canNotApi)) {
//            return response('权限不足', 401);
//        }
//    }

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
                'agent'          => $request->header('User-Agent')?:'',
                'req_body'       => json_encode($request->all(),JSON_UNESCAPED_UNICODE),
                'resp_body'      => $response->rawBody(),
                'code'           => $response->getStatusCode(),
                'create_user_id' => $this->operate_man,
                'create_time'    => time(),
                'method'         => $request->method(),
            ];
            Db::table('wa_system_log')->insert($log);
        }
    }
}
