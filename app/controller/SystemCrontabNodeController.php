<?php

namespace app\controller;

use app\service\Ssh;
use support\Db;
use support\Redis;
use support\Request;
use support\Response;
use app\model\SystemCrontabNode;
use support\exception\BusinessException;
use think\Validate;

/**
 * 节点列表
 */
class SystemCrontabNodeController extends MyCrudController {

    private $_redis_ssh_test_key = 'ssh_test:';
    private $_redis_ssh_test_key_time = 10;
    /**
     * @var SystemCrontabNode
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct() {
        $this->model = new SystemCrontabNode;
    }

    /**
     * 浏览
     * @return Response
     */
    public function index(): Response {
        return view('system-crontab-node/index');
    }

    /**
     * 列表
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2023/2/1 0001 16:59
     */
    public function select(Request $request): Response {
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $query     = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items     = $paginator->items();
        if ($items) {
            $items        = arrayObjToArray($items);
            foreach ($items as &$item) {
                $item['create_time']     = date('Y-m-d H:i:s', $item['create_time']);
                $item['update_time']     = date('Y-m-d H:i:s', $item['update_time']);
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
    public function insert(Request $request): Response {
        if ($request->method() === 'POST') {
            $data        = $this->insertInput($request);
            $data['rsa'] = $request->input('rsa', '');
            // 数据验证
            list($status, $msg) = $this->__validateParam($data);
            if (!$status) {
                return $this->json(1, $msg);
            }
            $data['create_user_id'] = admin_id();
            $data['create_time']    = $data['update_time'] = time();
            $param                  = [
                'method' => 'crontabNodeCreate',//计划任务列表
                'args'   => $data
            ];
            $result                 = \app\service\crontab\Client::instance()->request($param);
            if ($result['code']) {
                SystemCrontabNode::removeWarnCache();
                return $this->json(0, 'ok');
            }
            return $this->json(1, $result['msg']);
        }
        return view('system-crontab-node/insert');
    }

    /**
     * 更新
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function update(Request $request): Response {
        if ($request->method() === 'POST') {
            $data = $this->insertInput($request);
            $data['rsa'] = $request->input('rsa', '');
            // 数据验证
            list($status, $msg) = $this->__validateParam($data, false);
            if (!$status) {
                return $this->json(1, $msg);
            }
            $data['create_user_id'] = admin_id();
            $data['update_time'] = time();
            $param                  = [
                'method' => 'crontabNodeUpdate',//计划任务列表
                'args'   => $data
            ];
            $result                 = \app\service\crontab\Client::instance()->request($param);
            if ($result['code']) {
                SystemCrontabNode::removeWarnCache();
                return $this->json(0, 'ok');
            }
            return $this->json(1, $result['msg']);
        }
        return view('system-crontab-node/update');
    }

    /**
     * 测试主机连接
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2023/1/29 0029 15:42
     */
    public function ssh(Request $request): Response {
        if ($request->method() === 'POST') {
            $id = $request->input('id', 0);
            if (!$id || $id <= 0) {
                return $this->json(1, '参数缺失');
            }
            if (!Redis::setNx($this->_redis_ssh_test_key . $id, $this->_redis_ssh_test_key_time)) {
                return $this->json(1, '请勿频繁点击');
            }
            Redis::expire($this->_redis_ssh_test_key . $id, $this->_redis_ssh_test_key_time);
            $field = ['host', 'username', 'port','code_dir','index_name'];
            $data  = $this->model->find($id, $field)->toArray();
            if (empty($data)) {
                return $this->json(1, '记录不存在！');
            }
            $ssh_data = [
                'node_id'    => $id,
                'port'       => $data['port'],
                'username'   => $data['username'],
                'host'       => $data['host'],
                'code_dir'   => $data['code_dir'],
                'index_name' => $data['index_name'],
                'target'     => 'php '.$data['index_name'].' crontabtest',
            ];
            list($error, $msg) = Ssh::createSshAndExecCommand($ssh_data);
            if (!$error) {
                return $this->json(0, 'ok');
            }
            return $this->json(1, $msg);
        }
    }

    /**
     * 节点参数验证
     * @param array $data
     * @param bool $insert
     * @return array
     * @author guoliangchen
     * @date 2023/1/29 0029 9:23
     */
    private function __validateParam(array $data, $insert = true): array {
        if (empty($data)) {
            return [false, '参数缺失'];
        }
        $rule    = [
            'host'     => 'require|max:64|ip',
            'alias'    => 'max:32',
            'port'     => 'require|gt:0|lt:65536',
            'remark'   => 'max:100',
            'username' => 'require|max:64',
            'index_name' => 'require|max:50',
        ];
        $message = [
            'host.require' => '主机ip必填',
            'host.max'     => '主机ip不能超过64字',
            'host.ip'      => 'ip格式错误',

            'alias.max' => '节点名称不能超过32字',

            'port.require' => '端口必填',
            'port.gt'      => '端口要大于0！',
            'port.lt'      => '端口不能超过65536',

            'remark.max'       => '备注不能超过100字',
            'username.require' => '账号必填',
            'username.max'     => '账号不能超过64字',

            'index_name.require' => '入口文件名称必填',
            'index_name.max'     => '入口文件名称不能超过50字',
        ];
        if ($insert) {
            $rule['rsa']            = 'require';
            $message['rsa.require'] = '请输入私钥，在linux下输入：cat ~/.ssh/id_rsa即可查看';

        }else {
            $rule['id']            = 'require';
            $message['id.require'] = '节点不存在！';
        }
        if (!empty($data['code_dir'])){
            if (mb_strlen($data['code_dir'])>50){
                return [false, '代码路径长度不能超过50字符'];
            }
            $data['code_dir'] = str_replace('\\',DIRECTORY_SEPARATOR,$data['code_dir']);
            if ($data['code_dir'][0] !==DIRECTORY_SEPARATOR){
                return [false, '请输入绝对路径'];
            }
        }
        $index_name_ext = getFileExt($data['index_name']);
        if ($index_name_ext!=='php'){
            return [false, '入口文件必须是php文件！'];
        }
        $data['index_name'] = str_replace('\\',DIRECTORY_SEPARATOR,$data['index_name']);
        $single_path = explode(DIRECTORY_SEPARATOR,$data['index_name']);
        if (count($single_path) >1){
            return [false, '请输入不带路径的php入口文件！'];
        }
        $validate = new Validate();
        $validate->rule($rule)->message($message);
        if (!$validate->check($data)) {
            return [false, $validate->getError()];
        }
        return [true, 'ok'];
    }

    /**
     * 重写删除
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2024/4/24 0024 19:52
     */
    public function delete(Request $request): Response
    {
        $ids = $this->deleteInput($request);
        if (empty($ids)) {
            return $this->json(1, '请选择要删除的节点');
        }
        $has_use = Db::table('wa_system_crontab')->where('node_id','in',$ids)->count(['id']);
        if ($has_use >0){
            return $this->json(1, "该节点正在被{$has_use}个定时任务使用，请先删除对应定时任务");
        }
        $this->doDelete($ids);
        // 清除缓存
        SystemCrontabNode::removeWarnCache();
        return $this->json(0);
    }
}