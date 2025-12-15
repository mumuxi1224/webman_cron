<?php

namespace app\controller;

use app\model\SystemCrontabCategory;
use app\model\SystemCrontabNode;
use app\model\SystemCrontabWarn;
use app\service\Ssh;
use support\Db;
use support\Request;
use support\Response;
use app\model\SystemCrontab;
use plugin\admin\app\controller\Crud;
use support\exception\BusinessException;
use think\Validate;

/**
 * 定时任务列表
 */
class SystemCrontabController extends MyCrudController {
    protected $dangerCommand = [
        'rm',
        'echo',
        'mv',
        'wget',
        '>',
        'dd',
        'mkfs',
        '^',
        ':',
        'kill',
    ];

    /**
     * @var SystemCrontab
     */
    protected $model = null;

    /**
     * 构造函数
     * @return void
     */
    public function __construct() {
        $this->model = new SystemCrontab;
    }

    /**
     * 浏览
     * @return Response
     */
    public function index(): Response {
        return view('system-crontab/index');
    }

    /**
     * 插入
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function insert(Request $request): Response {
        if ($request->method() === 'POST') {
            $data = $this->insertInput($request);
            list($status, $data) = $this->__validateParam($data);
            if (!$status) {
                return $this->json(1, $data);
            }
            $param  = [
                'method' => 'crontabCreate',//计划任务列表
                'args'   => $data
            ];
            $result = \app\service\crontab\Client::instance()->request($param);
            if ($result['code']) {
                return $this->json(0, 'ok');
            }
            return $this->json(1, $result['msg']);
        }
        return view('system-crontab/insert');
    }

    public function filterData(Request $request){
        return $this->insertInput($request);
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
            list($status, $data) = $this->__validateParam($data);
            if (!$status) {
                return $this->json(1, $data);
            }
            $param  = [
                'method' => 'crontabUpdate',
                'args'   => $data
            ];
            $result = \app\service\crontab\Client::instance()->request($param);
            if ($result['code']) {
                return $this->json(0, 'ok');
            }
            return $this->json(1, $result['msg']);
        }
        return view('system-crontab/update');
    }

    /**
     * 列表
     * @param Request $request
     * @return Response
     * @throws BusinessException
     * @author guoliangchen
     * @date 2023/2/1 0001 16:58
     */
    public function select(Request $request): Response {
        [$where, $format, $limit, $field, $order] = $this->selectInput($request);
        $where     = $this->__formatSearch($where);
        $query     = $this->doSelect($where, $field, $order);
        $paginator = $query->paginate($limit);
        $items     = $paginator->items();
        if ($items) {
            $items        = arrayObjToArray($items);
            // 任务类型
            $cron_type = $this->model->getCronType();
            $cron_type = array_column($cron_type,null,'value');
            // 分类列表
            $cate_ids = array_column($items,'category_id');
            $category_list = [];
            if ($cate_ids){
                $systemCrontabCategory = new SystemCrontabCategory();
                $category_list         = $systemCrontabCategory->getCategoryByIds($cate_ids,['category_id', 'name']);
                if($category_list) $category_list = array_column($category_list,null,'category_id');
            }
            // 节点列表
            $node_ids = array_column($items,'node_id');
            $node_list = [];
            if ($node_ids){
                $SystemCrontabCategory = new SystemCrontabNode();
                $node_list_temp             = $SystemCrontabCategory->getNodeListByIds($node_ids,['id', 'host', 'alias']);
                foreach ($node_list_temp as $node) {
                    $node_list[$node['id']] = $node['alias'] . '(' . $node['host'] . ')';
                }
            }
            // 预警人员
            $warn_list = SystemCrontabWarn::getWarnCache();
            if ($warn_list)$warn_list = array_column($warn_list,null,'warn_id');
            foreach ($items as &$item) {
                $item['end_time']     = $item['end_time']?date('Y-m-d H:i:s', $item['end_time']):'';
                $item['last_running_time']     = $item['last_running_time']?date('Y-m-d H:i:s', $item['last_running_time']):'未运行过';
                $item['create_time']     = $item['create_time'] ?date('Y-m-d H:i:s', $item['create_time']):'';
                // 任务类型
                $item['type_name'] = $cron_type[ $item[ 'type' ] ]['name']??'';
                // 节点
                $item['node_name'] = $node_list[ $item[ 'node_id' ] ]??'';
                // 分类
                $item['category_name'] = $category_list[ $item[ 'category_id' ] ]['name']??'';
                $item['warn_info'] = [];
                if ($item['warning_ids']){
                    $warn_ids =  explode(',',$item['warning_ids']);
                    foreach ($warn_ids as $warn){
                        if (isset($warn_list[$warn]  )){
                            $item['warn_info'][] = $warn_list[$warn]['nick_name'].'('.$warn_list[$warn]['mobile'].')';
                        }
                    }
                }
            }
        }

        return json(['code' => 0, 'msg' => 'ok', 'count' => $paginator->total(), 'data' => $items]);
    }

    /**
     * 定时任务页面配置
     * @param bool $json
     * @return Response
     * @author guoliangchen
     * @date 2023/2/1 0001 14:17
     */
    public function getHtmlConfig() {
        $params = [];
        // 常用时间表达式
        $params['cron_tips'] = $this->model->getCronTips();
        // 任务类型
        $params['cron_type'] = $this->model->getCronType();
        // 分类列表
        $systemCrontabCategory = new SystemCrontabCategory();
        $category_list         = $systemCrontabCategory->getAllCategory(['category_id', 'name']);
        $params['category_list'] = [];
        foreach ($category_list as $cate) {
            $params['category_list'][] = ['name' => $cate['name'], 'value' => $cate['category_id']];
        }
        // 节点列表
        $SystemCrontabCategory = new SystemCrontabNode();
        $node_list             = $SystemCrontabCategory->getNodeList(['id', 'host', 'alias']);
        $params['node_list'] = [];
        foreach ($node_list as $node) {
            $params['node_list'][] = ['name' => $node['alias'] . '(' . $node['host'] . ')', 'value' => $node['id']];
        }
        // 预警人员
        $warn_list = SystemCrontabWarn::getWarnCache();
        $params['warn_list'] = [];
        foreach ($warn_list as $warn) {
            $params['warn_list'][] = [
                'value' => $warn['warn_id'],
                'name'  => $warn['nick_name'] . '(' . $warn['mobile'] . ')'
            ];
        }

        return $this->json(0, 'ok', $params);

    }

    /**
     * 参数校验时间表达式在线校验
     * @param array $data
     * @return array
     * @author guoliangchen
     * @date 2023/2/1 0001 15:11
     */
    public function __validateParam(array $data): array {
        if (empty($data)) {
            return [false, '参数缺失'];
        }
        // 任务类型
        $cron_type = $this->model->getCronType();
        $cron_type = array_column($cron_type, 'value');
        $cron_type = implode(',', $cron_type);
        $rule      = [
            'title'               => 'require|max:100',
            'type'                => 'require|in:' . $cron_type,
            'rule'                => 'require|max:100',
            'target'              => 'require|max:150',
            'remark'              => 'max:255',
            'status'              => 'require|in:1,0',
            //            'node_id' => 'require|max:64',
            //            'category_id' => 'require|max:64',
            'end_time'            => 'date',
            'single_run_max_time' => 'egt:0',
            'warning_ids'         => 'max:500',
        ];
        $message   = [
            'title.require' => '任务标题必填',
            'title.max'     => '任务标题不能超过100字',

            'type.require' => '任务类型必选',
            'type.in'      => '任务类型不存在',

            'rule.require' => '执行时间必填',
            'rule.max'     => '执行时间不能超过100字',

            'target.require' => '执行命令必填',
            'target.max'     => '执行命令不能超过150字',

            'remark.max' => '备注不能超过255字',

            'status.require' => '任务状态必选',
            'status.in'      => '任务状态不存在',

            'end_time.date' => '结束时间格式不合法',

            'single_run_max_time.egt' => '单次运行最大时间要大于等于0',

            'warning_ids.max' => '预警人员超出上限！',
        ];

        $validate = new Validate();
        $validate->rule($rule)->message($message);
        if (!$validate->check($data)) {
            return [false, $validate->getError()];
        }

        // 时间表达式是否合法
        $parse = new \Workerman\Crontab\Parser();
        if (!$parse->isValid($data['rule'])) {
            return [false, '时间表达式格式错误！'];
        }
        // 结束时间
        if (!empty($data['end_time'])) {
            $end_time = strtotime($data['end_time']);
            if (!$end_time) {
                return [false, '结束时间格式错误！'];
            }
            $now = time();
            if ($end_time <= $now) {
                return [false, '结束时间要大于当前时间！'];
            }
        }
        if (!isset($data['single_run_max_time']) || empty($data['single_run_max_time'])) $data['single_run_max_time'] = 0;
        if ($data['single_run_max_time']>0 && $data['single_run_max_time']<60){
            return [false, '目前单次运行最大时间至少要超过60秒才会触发预警！'];
        }
        // 验证命令
        $data['target'] = trim($data['target']);
        $target         = explode(' ', $data['target']);
        $target         = array_filter($target);
        if ($data['type'] == 1) {
            if (empty($data['node_id'])) {
                return [false, '请选择节点'];
            }
            // 节点执行
            $first_target_key = key($target);
            if (empty($target[$first_target_key])) {
                return [false, '请输入执行命令'];
            }
            // 去掉可能输入的php
            if ($target[$first_target_key] == 'php') {
                unset($target[$first_target_key]);
            }
            else {
                // 是否是php文件
//                $ext = getFileExt($target[$first_target_key]);
//                if ($ext != 'php') {
//                    return [false, '目前仅开放执行php文件'];
//                }
            }
            // 验证命令是否安全
            // todo 2023年2月1日13:37:55 可能还是不够安全 日后也可能需要完善 glc
            $pattern = '/^[0-9a-zA-Z_]+$/';
            foreach ($target as $key => $t) {
                if ($key == $first_target_key) {
                    continue;
                }
                if (stripos($t, '|') !== false) {
                    return [false, '请勿输入管道符！！！'];
                }
                if (in_array($t, $this->dangerCommand)) {
                    return [false, '请勿输入危险命令！！！'];
                }
                if (!preg_match($pattern, $t)) {
                    return [false, '请输入数字、字母、下划线的参数！！！'];
                }
            }
            $data['target'] = implode(' ', $target);
        }
        elseif ($data['type'] == 2) {
            if (count($target) != 1) {
                return [false, '请求url执行不支持多个参数，请自行拼接在url地址中'];
            }
            // 请求url
            if (filter_var($data['target'], FILTER_VALIDATE_URL) !== false) {

            }
            else {
                return [false, 'url地址不正确,请以http|https开头'];
            }
            // 去掉可能存储的节点信息
            $data['node_id'] = 0;
        }
        if (!isset($data['end_time']) || empty($data['end_time'])) $data['end_time'] = 0;
        if (!isset($data['warning_ids']) || empty($data['warning_ids'])) $data['warning_ids'] = '';
        if (!isset($data['single_run_max_time']) || empty($data['single_run_max_time'])) $data['single_run_max_time'] = 0;
        if (!isset($data['category_id']) || empty($data['category_id'])) $data['category_id'] = 0;
        if ($data['end_time']) $data['end_time'] = strtotime($data['end_time']);
        $data['rule'] = trim($data['rule']);
        return [true, $data];
    }


    /**
     * 删除
     * @param Request $request
     * @return Response
     * @throws BusinessException
     */
    public function delete(Request $request): Response
    {
        $ids = $this->deleteInput($request);

        $status = $request->post('status');
        if (!in_array($status,[0,1])){
            return $this->json(1, '未知状态');
        }
        $status = $status==1?0:1;
        $data = [
            'id'=>$ids[0],
            'status'=>$status
        ];
        $param  = [
            'method' => 'crontabUpdate',
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        if ($result['code']) {
            return $this->json(0, 'ok');
        }
        return $this->json(1, $result['msg']);
        $result = \app\service\crontab\Client::instance()->request($param);
        if ($result['code']) {
            return $this->json(0, 'ok');
        }
        return $this->json(1, $result['msg']);
    }

    /**
     * 校验要导入excel文件
     * @param Request $request
     * @return Response
     * @author guoliangchen
     * @date 2023/3/6 0006 14:55
     */
    public function export(Request $request){
//        if (strtolower($request->method())=='post'){
//            if (!$file = $request->file()){
//                return $this->json(0, '请选择要上传的文件');
//            }
//            $file  = array_shift($file);
//            var_dump($file);
//            if (!$file->isValid()){
//                return $this->json(0, '文件无效');
//            }
//            $allow_ext = ['xls','xlsx'];
//            $file_ext = $file->getUploadExtension();
//            var_dump($file_ext);
//            if (!in_array($file_ext,$allow_ext)){
//                return $this->json(0, '请上传'.implode('、',$allow_ext).'格式的文件');
//            }
//            $file_temp_path = $file->getRealPath();
//            var_dump($file_temp_path);
//            var_export($spl_file->isValid()); // 文件是否有效，例如ture|false
//            var_export($spl_file->getUploadExtension()); // 上传文件后缀名，例如'jpg'
//            var_export($spl_file->getUploadMineType()); // 上传文件mine类型，例如 'image/jpeg'
//            var_export($spl_file->getUploadErrorCode()); // 获取上传错误码，例如 UPLOAD_ERR_NO_TMP_DIR UPLOAD_ERR_NO_FILE UPLOAD_ERR_CANT_WRITE
//            var_export($spl_file->getUploadName()); // 上传文件名，例如 'my-test.jpg'
//            var_export($spl_file->getSize()); // 获得文件大小，例如 13364，单位字节
//            var_export($spl_file->getPath()); // 获得上传的目录，例如 '/tmp'
//            var_export($spl_file->getRealPath()); // 获得临时文件路径，例如 `/tmp/workerman.upload.SRliMu`

//            return '';
//        }
//        return view('system-crontab/export');
    }

    /**
     * 获取示例excel
     * @param Request $request
     * @return Response|\Webman\Http\Response
     * @author guoliangchen
     * @date 2023/3/6 0006 17:29
     */
    public function getExcel(Request $request){
        $file_path = public_path().DIRECTORY_SEPARATOR.'example.xlsx';
        if (file_exists($file_path)){
            return \response()->download($file_path,'上传示例excel.xlsx');
        }
        return view('404', ['error' => '示例excel不存在'])->withStatus(404);
    }

//    /**
//     * 立刻执行
//     * @param Request $request
//     * @return Response|void
//     * @author guoliangchen
//     * @date 2023/8/16 0016 17:54
//     */
//    public function runNow(Request $request){
//        $id = $request->input('id');
//        if (empty($id) || $id<=0){
//            return $this->json(1, '未知定时器');
//        }
//        $data = [
//            'id'=>$id,
//        ];
//        $param  = [
//            'method' => 'runNow',
//            'args'   => $data
//        ];
//        $result = \app\service\crontab\Client::instance()->request($param);
//        var_dump($result);
//        if ($result['code']) {
//            return $this->json(0, 'ok');
//        }
//    }
//
//    public function sshChannelStatus(Request $request){
//        list($status,$msg,$data) = Ssh::channelStatus();
//        return $this->json($status?0:1, $msg,$data);
//    }

    /**
     * 获取运行状态
     * @param Request $request
     * @return Response|void
     * @author guoliangchen
     * @date 2024/4/24 0024 18:40
     */
    public function getRunStatus(Request $request) {
        $id = $request->input('id');
        if (empty($id) || $id <= 0) {
            return $this->json(1, '未知定时器');
        }
        $data   = [
            'id' => $id,
        ];
        $param  = [
            'method' => 'getRunStatus',
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        if ($result['code']) {
            return $this->json(0, $result['data']['msg']);
        }
    }

    /**
     * 立即执行
     * @param Request $request
     * @return Response|void
     * @author guoliangchen
     * @date 2024/9/11 上午11:03
     */
    public function runNow(Request $request){
        $id = $request->input('id');
        if (empty($id) || $id<=0){
            return $this->json(1, '未知定时器');
        }
        $data = [
            'id'=>$id,
        ];
        $param  = [
            'method' => 'runNow',
            'args'   => $data
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        if ($result['code']) {
            return $this->json(0, $result['data']['msg']);
        }
    }

    public function crontabSystem(Request $request) {
        $param  = [
            'method' => 'crontabSystem',
            'args'   => []
        ];
        $result = \app\service\crontab\Client::instance()->request($param);
        if ($result['code']) {
            return $this->json(0, $result['data']['msg']);
        }
    }
}
