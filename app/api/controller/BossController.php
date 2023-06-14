<?php
namespace app\api\controller;

use app\controller\SystemCrontabController;
use app\ErrorCodeController;
use support\Db;
use support\Redis;
use support\Request;
/**
 * 和胡剑对接的接口
 */
class BossController extends ApiBaseController
{
    const BOSS_KEY = '^*sad(sgd)(g()*&tc(&asc';
    public $operate_man = null;
    public function addCron(Request $request) {
        $this->operate_man = -1;
        if ($request->method() === 'POST') {
//            if (!Redis::setNx('boss_api:addCron', 3)) {
//                return jsonMsg(ErrorCodeController::T_REQ_FRE_FAIL, '请勿频繁点击');
//            }
//            Redis::expire('boss_api:addCron', 3);
            $boss_key = $request->post('boss_key', '');
            if (!isset($boss_key) || $boss_key !== self::BOSS_KEY) {
                return jsonMsg(ErrorCodeController::T_TOKEN_FAIL, '非法请求');
            }
            $systemCrontabController = new SystemCrontabController();
            $post_data               = $systemCrontabController->filterData($request);
            $post_data['type']       = 1;
            if (!isset($post_data['category_id'])){
                $post_data['category_id'] = 1;
            }
            if (!isset($post_data['warning_ids'])){
                $post_data['warning_ids'] = 1;
            }
            list($status, $data) = $systemCrontabController->__validateParam($post_data);
            if (!$status) {
                return jsonMsg(ErrorCodeController::T_PARAMS_FAIL, $data);
            }
            // 判断是新增还是编辑
            $has     = Db::table('wa_system_crontab')->where('target', $data['target'])->first();
            $method  = 'crontabCreate';
            $suc_msg = '新增成功';
            if ($has) {
                $method     = 'crontabUpdate';
                $suc_msg    = '编辑成功';
                $data['id'] = $has->id;
            }
            $param  = [
                'method' => $method,//计划任务列表
                'args'   => $data
            ];
            $result = \app\service\crontab\Client::instance()->request($param);
            // 记录日志
            if ($result['code']) {
                return jsonMsg(ErrorCodeController::SUCCESS, $suc_msg, ['cron_id' => $result['data']['pk']]);
            }
            return jsonMsg(ErrorCodeController::T_CRON_FAIL, $result['msg']);
        }
        return jsonMsg(ErrorCodeController::T_REQ_TYPE_FAIL, '未知请求方式');
    }

}