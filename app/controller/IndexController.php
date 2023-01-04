<?php

namespace app\controller;

use support\Request;
use think\Validate;

class IndexController {
    public function index(Request $request) {
        return view('index/index', ['name' => 'webman11']);
    }


    public function view(Request $request) {
        return view('index/view', ['name' => 'webman']);
    }

    public function json(Request $request) {
        return json(['code' => 0, 'msg' => 'ok']);
    }

    public function getCron(Request $request) {
        $crontab_id = $request->input('crontab_id', 0);
        $param      = [
            'method' => 'crontabIndexFind',//计划任务列表
            'args'   => ['where' => ['c.id' => $crontab_id]]//参数
        ];
        $result     = \app\service\crontab\Client::instance()->request($param);
        $data       = $result['data'];
        // 获取节点
        $param     = [
            'method' => 'crontabNodeIndex',//计划任务列表
            'args'   => ['limit' => 999999, 'page' => 1]//参数
        ];
        $result    = \app\service\crontab\Client::instance()->request($param);
        $node_list = $result['data']['data'] ?? [];
        $cron_tips = getCronTips();
        return view('index/edit', ['data' => $data, 'node_list' => $node_list, 'cron_tips' => $cron_tips]);
    }


    public function getCronNode(Request $request) {
        $node_id = $request->input('node_id', 0);
        $param   = [
            'method' => 'crontabNodeIndex',//计划任务列表
            'args'   => ['where' => ['id' => $node_id]]//参数
        ];
        $result  = \app\service\crontab\Client::instance()->request($param);
        $data    = $result['data']['data'][0];
        return view('index/edit_node', ['data' => $data]);
    }

    public function getCronList(Request $request) {
        $page      = $request->input('page', 1);
        $page_size = $request->input('page_size', 10);
        $param     = [
            'method' => 'crontabIndex',//计划任务列表
            'args'   => ['limit' => $page_size, 'page' => $page]//参数
        ];
        $result    = \app\service\crontab\Client::instance()->request($param);
        $data      = [];
        $total     = 0;
        if ($result['code'] == 200) {
            foreach ($result['data']['data'] as $v) {
                $v['create_time']       = date('Y-m-d H:i:s', $v['create_time']);
                $v['last_running_time'] = $v['last_running_time']?date('Y-m-d H:i:s', $v['last_running_time']):'';
                $v['status']            = $v['status'] ? '启用' : '禁用';
                $v['node_info']         = $v['alias'] ? $v['alias'] . "({$v['host']})" : '';
                $data[]                 = $v;
            }
        }
        return json(['code' => 0, 'msg' => $result['msg'], 'count' => $total, 'data' => $data]);
    }

    public function getCronNodeList(Request $request) {

        $page      = $request->input('page', 1);
        $page_size = $request->input('page_size', 10);
        $param     = [
            'method' => 'crontabNodeIndex',//计划任务列表
            'args'   => ['limit' => $page_size, 'page' => $page]//参数
        ];
        $result    = \app\service\crontab\Client::instance()->request($param);
        $data      = [];
        $total     = 0;
        if ($result['code'] == 200) {
            foreach ($result['data']['data'] as $v) {
                $v['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
                $data[]           = $v;
            }
        }
        return json(['code' => 0, 'msg' => $result['msg'], 'count' => $total, 'data' => $data]);
    }


    public function getCronLog(Request $request) {
        $crontab_id = $request->input('crontab_id', 0);
        if ($request->isAjax()) {
            $page      = $request->input('page', 1);
            $page_size = $request->input('page_size', 10);
            $param     = [
                'method' => 'crontabLog',//计划任务列表
                'args'   => ['limit' => $page_size, 'page' => $page, 'crontab_id' => $crontab_id]//参数
            ];
            $result    = \app\service\crontab\Client::instance()->request($param);
            $data      = [];
            $total     = 0;
            if ($result['code'] == 200) {
                foreach ($result['data']['data'] as $v) {
                    $v['create_time'] = date('Y-m-d H:i:s', $v['create_time']);
                    $data[]           = $v;
                }
            }
            return json(['code' => 0, 'msg' => $result['msg'], 'count' => $total, 'data' => $data]);
        }

        return view('index/log', ['crontab_id' => $crontab_id]);
    }


    public function crontabCreate(Request $request) {
        // 获取节点
        $param     = [
            'method' => 'crontabNodeIndex',//计划任务列表
            'args'   => ['limit' => 999999, 'page' => 1]//参数
        ];
        $result    = \app\service\crontab\Client::instance()->request($param);
        $node_list = $result['data']['data'] ?? [];
        $cron_tips = getCronTips();
        return view('index/add', ['node_list' => $node_list, 'cron_tips' => $cron_tips]);
    }

    public function crontabNodeCreate(Request $request) {
        return view('index/add_node');
//        $param = [
//            'method' => 'crontabCreate',
//            'args'   => [
//                'title'     => '输出 webman 版本',
//                'type' =>1,
//                'rule' => '*/5 * * * * *',
//                'target'     => 'php5 \code\huibo\job\index.php apprecommendjobforperson 15',
//                'status'    => 1,
//                'remark'    => '每30秒执行',
//            ]
//        ];
//        $result  = \app\service\crontab\Client::instance()->request($param);
//        return json($result);

    }

    public function crontabNodeCreateDo(Request $request) {

        $data     = [
            'host'     => $request->input('host', ''),
            'alias'    => $request->input('alias', ''),
            'port'     => $request->input('port', ''),
            'remark'   => $request->input('remark', ''),
            'username' => $request->input('username', ''),
            'rsa'      => $request->input('rsa', ''),
        ];
        $rule     = [
            'host'     => 'require|max:64',
            'alias'    => 'require|max:32',
            'port'     => 'require|egt:0',
            'remark'   => 'max:100',
            'username' => 'require|max:64',
            'rsa'      => 'require',
        ];
        $message  = [
            'host.require'     => '主机ip必填',
            'host.max'         => '主机ip不能超过64字',
            'alias.require'    => '节点名称必填',
            'alias.max'        => '节点名称不能超过32字',
            'port.require'     => '端口必填',
            'port.max'         => '端口不能超过100字',
            //            'remark.require' => '备注必填',
            'remark.max'       => '备注不能超过100字',
            'username.require' => '账号必填',
            'username.max'     => '账号不能超过64字',
            'rsa.require'      => '请输入私钥，在linux下输入：cat ~/.ssh/id_rsa即可查看',
        ];
        $validate = new Validate();
        $validate->rule($rule)->message($message);
        if (!$validate->check($data)) {
            return json(['code' => 0, 'msg' => $validate->getError()]);
        }
        $param  = [
            'method' => 'crontabNodeCreate',//计划任务列表
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        return json(['code' => 1, 'msg' => 'ok']);
    }

    public function crontabNodeEditDo(Request $request) {
        $data     = [
            'id'       => $request->input('node_id', 0),
            'host'     => $request->input('host', ''),
            'alias'    => $request->input('alias', ''),
            'port'     => $request->input('port', ''),
            'remark'   => $request->input('remark', ''),
            'username' => $request->input('username', ''),
            'rsa'      => $request->input('rsa', ''),
        ];
        $rule     = [
            'id'       => 'require|gt:0',
            'host'     => 'require|max:64',
            'alias'    => 'require|max:32',
            'port'     => 'require|egt:0',
            'remark'   => 'max:100',
            'username' => 'require|max:64',
        ];
        $message  = [
            'id.require'       => 'id必填',
            'id.gt'            => 'id要大于0',
            'host.require'     => '主机ip必填',
            'host.max'         => '主机ip不能超过64字',
            'alias.require'    => '节点名称必填',
            'alias.max'        => '节点名称不能超过32字',
            'port.require'     => '端口必填',
            'port.max'         => '端口不能超过100字',
            //            'remark.require' => '备注必填',
            'remark.max'       => '备注不能超过100字',
            'username.require' => '账号必填',
            'username.max'     => '账号不能超过64字',
        ];
        $validate = new Validate();
        $validate->rule($rule)->message($message);
        if (!$validate->check($data)) {
            return json(['code' => 0, 'msg' => $validate->getError()]);
        }
        $param  = [
            'method' => 'crontabNodeUpdate',//计划任务列表
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        return json(['code' => 1, 'msg' => 'ok']);
    }

    public function crontabCreateDo(Request $request) {

        $data         = [
            'title'     => $request->input('title', ''),
            'type'      => $request->input('type', 0),
            'rule'      => $request->input('rule', ''),
            'target'    => $request->input('target', ''),
            'parameter' => $request->input('parameter', []),
            'remark'    => $request->input('remark', ''),
            'sort'      => $request->input('sort', ''),
            'status'    => $request->input('status', 1),
            'singleton' => $request->input('singleton', 1),
            'node_id'   => $request->input('node_id', 0),
        ];
        $data['type'] = 6;
        $rule         = [
            'title'   => 'require|max:100',
            'rule'    => 'require|max:100',
            'target'  => 'require|max:150',
            'remark'  => 'require|max:255',
            'status'  => 'in:0,1',
            'node_id' => 'egt:0',
        ];
        $message      = [
            'title.require'  => '任务标题必填',
            'title.max'      => '任务标题不能超过100字',
            'rule.require'   => '任务执行表达式必填',
            'rule.max'       => '任务执行表达式不能超过100字',
            'target.require' => '调用任务字符串必填',
            'target.max'     => '调用任务字符串不能超过100字',
            'remark.require' => '备注必填',
            'remark.max'     => '备注不能超过100字',
            'status.in'      => '状态值错误',
            'node_id.egt'    => '节点信息错误',
        ];
        $validate     = new Validate();
        $validate->rule($rule)->message($message);
        if (!$validate->check($data)) {
            return json(['code' => 0, 'msg' => $validate->getError()]);
        }
        $parse = new \Workerman\Crontab\Parser();
        if (!$parse->isValid($data['rule'])) {
            return json(['code' => 0, 'msg' => '时间表达式格式错误！']);
        }
        $param  = [
            'method' => 'crontabCreate',//计划任务列表
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        return json(['code' => 1, 'msg' => 'ok']);
    }

    public function delCrontab(Request $request) {
        $id = $request->input('id', 0);
        if ($id <= 0) {
            return json(['code' => 0, 'msg' => '任务不存在']);
        }

        $param  = [
            'method' => 'crontabDelete',//计划任务列表
            'args'   => [
                'id' => $id
            ]
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        return json(['code' => 1, 'msg' => 'ok']);
//        return json($result);
    }

    public function editCrontab(Request $request) {

        $data         = [
            'id'        => $request->input('crontab_id', 0),
            'title'     => $request->input('title', ''),
            'type'      => $request->input('type', 0),
            'rule'      => $request->input('rule', ''),
            'target'    => $request->input('target', ''),
            'parameter' => $request->input('parameter', []),
            'remark'    => $request->input('remark', ''),
            'sort'      => $request->input('sort', ''),
            'status'    => $request->input('status', 1),
            'singleton' => $request->input('singleton', 1),
        ];
        $data['type'] = 6;
        $rule         = [
            'id'     => 'require|gt:0',
            'title'  => 'require|max:100',
            'rule'   => 'require|max:100',
            'target' => 'require|max:150',
            'remark' => 'require|max:255',
            'status' => 'in:0,1',
        ];
        $message      = [
            'id.require'     => '任务不存在',
            'id.gt'          => '任务不存在2',
            'title.require'  => '任务标题必填',
            'title.max'      => '任务标题不能超过100字',
            'rule.require'   => '任务执行表达式必填',
            'rule.max'       => '任务执行表达式不能超过100字',
            'target.require' => '调用任务字符串必填',
            'target.max'     => '调用任务字符串不能超过100字',
            'remark.require' => '备注必填',
            'remark.max'     => '备注不能超过100字',
            'status.in'      => '状态值错误',
        ];
        $validate     = new Validate();
        $validate->rule($rule)->message($message);
        if (!$validate->check($data)) {
            return json(['code' => 0, 'msg' => $validate->getError()]);
        }
        $parse = new \Workerman\Crontab\Parser();
        if (!$parse->isValid($data['rule'])) {
            return json(['code' => 0, 'msg' => '时间表达式格式错误！']);
        }
        $param  = [
            'method' => 'crontabUpdate',//计划任务列表
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        return json(['code' => 1, 'msg' => 'ok']);
    }

    /**
     * 获取节点列表
     * @param Request $request
     * @return \support\Response
     * @author guoliangchen
     * @date 2022/12/22 0022 15:23
     */
    public function getNodeList(Request $request) {
        return view('index/node_list', ['name' => 'webman']);
    }
}
