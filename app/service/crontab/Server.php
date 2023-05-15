<?php
declare (strict_types=1);

namespace app\service\crontab;

use app\model\SystemCrontabWarn;
use app\service\Ssh;
use support\Container;
use think\Exception;
use think\facade\Db;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\Worker;
use app\service\crontab\Mutex\RedisServerMutex;
use app\service\crontab\Mutex\RedisTaskMutex;
use app\service\crontab\Mutex\ServerMutex;
use app\service\crontab\Mutex\TaskMutex;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Yurun\Util\Swoole\Guzzle\SwooleHandler;
use GuzzleHttp\DefaultHandler;
use support\Redis;

/**
 * 注意：定时器开始、暂停、重起
 * Workerman\Crontab 1.0.4 起 立即执行
 */
class Server {
    const FORBIDDEN_STATUS = '0';

    const NORMAL_STATUS = '1';

    // 节点任务
    public const NODE_CRONTAB = '1';
    // url任务
    public const URL_CRONTAB = '2';

    private $worker;

    /**
     * @var TaskMutex
     */
    private $taskMutex;

    /**
     * @var ServerMutex
     */
    private $serverMutex;

    /**
     * 每个节点任务后追加一个标识用于区分
     * @var string
     */
    private $ssh_flag = '__from_crontab';

    /**
     * 调试模式
     * @var bool
     */
    private $debug = false;

    /**
     * 记录日志
     * @var bool
     */
    private $writeLog = false;

    /**
     * 任务进程池
     * @var Crontab[] array
     */
    private $crontabPool = [];

    /**
     * 监控的定时任务
     * @var null
     */
    private $watchCrontab = [
        'is_running' => false,
        'crontab'    => null,
        'rule'       => '0 */1 * * * *',
    ];

    /**
     * 定时任务表
     * @var string
     */
    private $crontabTable;

    /**
     * 定时任务日志表
     * @var string
     */
    private $crontabLogTable;

    /**
     * 节点记录表
     * @var string
     */
    private $crontabNodeTable;

    /**
     * 短信
     * @var array
     */
    private $smsData = [
        'is_running'  => false,
        'crontab'     => null,
        'warn_insert' => [],
        'url'         => [],
        'rule'        => '0 */1 * * * *',
    ];

    public function __construct() {
    }

    public function onClose() {
    }

    public function onWorkerStop() {
//        // 清除redis缓存
//        if ($this->crontabPool){
//            var_dump('clear_redis_------------------------------------');
//            $taskMutex = $this->getTaskMutex();
//            foreach ($this->crontabPool as $crontab){
//                $data  = [
//                    'id'    => $crontab['id'],
//                    'title' => $crontab['title'],
//                    'rule'  => $crontab['rule'],
//                ];
//                $taskMutex->remove($data);
//            }
//        }
    }


    public function onWorkerStart(Worker $worker) {
        $config                 = config('crontab.task');
        $this->debug            = $config['debug'] ?? true;
        $this->writeLog         = $config['write_log'] ?? true;
        $this->crontabTable     = $config['crontab_table'];
        $this->crontabLogTable  = $config['crontab_table_log'];
        $this->crontabNodeTable = $config['crontab_table_node'];
        $this->worker           = $worker;
        $this->delTaskMutex();
        $this->checkCrontabTables();
        $this->crontabInit();
        $this->watchCrontabWarning();
        $this->sendSmsMsg();

        DefaultHandler::setDefaultHandler(SwooleHandler::class);
    }

    /**
     * 当客户端与Workman建立连接时(TCP三次握手完成后)触发的回调函数
     * 每个连接只会触发一次onConnect回调
     * 此时客户端还没有发来任何数据
     * 由于udp是无连接的，所以当使用udp时不会触发onConnect回调，也不会触发onClose回调
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection) {
        $this->checkCrontabTables();
    }


    public function onMessage(TcpConnection $connection, $data) {
        $data   = json_decode($data, true);
        $method = $data['method'];
        $args   = $data['args'];
        $connection->send(call_user_func([$this, $method], $args));
    }


    /**
     * 初始化定时任务
     * @return void
     */
    private function crontabInit(): void {
        $ids = Db::table($this->crontabTable)
            ->where('status', self::NORMAL_STATUS)
            ->order('sort', 'desc')
            ->column('id');
        if (!empty($ids)) {
            foreach ($ids as $id) {
//                $this->crontabRun($id);
                $this->execJob($id);
            }
        }
    }

    /**
     * 执行任务
     * @param $id
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author guoliangchen
     * @date 2023/2/21 0021 15:12
     */
    private function execJob($id) {
        $data = Db::table($this->crontabTable)
            ->alias('c')
            ->field('c.*,n.host,n.alias,n.port,n.id as node_id,n.username,n.code_dir,n.index_name')
            ->where('c.id', $id)
            ->join($this->crontabNodeTable . ' n', 'c.node_id= n.id', 'LEFT')
            ->where('status', self::NORMAL_STATUS)
            ->find();
        // 如果到达了结束时间
        $now = time();
        if ($data['end_time'] > 0 && $now >= $data['end_time']) {
            Db::table($this->crontabTable)->where('id', $data['id'])->update(['status' => 0]);
            return;
        }
        $_that = $this;

        if (!empty($data)) {
            $data['end_time'] = intval($data['end_time']);
            if ($data['warning_ids']) $data['warning_ids'] = explode(',', $data['warning_ids']);
            $data['index_name'] = empty($data['index_name'])?'index.php':$data['index_name'];
            if (!$this->decorateRunnable($data)) {
                return;
            }
            if ($data['type'] == self::NODE_CRONTAB){
//                $data['target'] = 'php '.$data['index_name'] .' '. $data['target'] . ' ' . $this->ssh_flag;
                $data['target'] = 'php '.$data['index_name'] .' '. $data['target'];
            }
            $this->crontabPool[$data['id']]            = [
                'id'                  => $data['id'],
                'target'              => $data['target'],
                'title'               => $data['title'],
                'rule'                => $data['rule'],
                'parameter'           => $data['parameter'],
                'singleton'           => $data['singleton'],
                'create_time'         => $now,
                'end_time'            => $data['end_time'] ?? 0,
                'single_run_max_time' => $data['single_run_max_time'] ?? 0,
                'warning_ids'         => $data['warning_ids'],
                'is_running'          => false,
                'last_run_time'       => $now,
                'has_send_sms'       => $now
            ];
            $this->crontabPool[$data['id']]['crontab'] = new Crontab($data['rule'], function () use ($data, $_that) {
                \Swoole\Runtime::enableCoroutine();
                \Swoole\Coroutine::set(['enable_deadlock_check' => false]);
                go(function () use ($data, $_that) {
                    $can_run = call_user_func([$_that, 'beforeRunJob'], $data);
                    if ($can_run) {
                        list($code, $output, $start_time, $running_time, $last_run_time) = call_user_func([$_that, 'runJob'], $data);
                        call_user_func([$_that, 'afterRunJob'], $data, $code, $output, $start_time, $running_time, $last_run_time);
                    }
                });
            });
        }
    }

    /**
     * 执行任务前
     * @param $data
     * @return bool
     * @author guoliangchen
     * @date 2023/2/21 0021 15:11
     */
    private function beforeRunJob($data) {
        if (empty($this->crontabPool[$data['id']]) || $this->crontabPool[$data['id']]['is_running']) {
            return false;
        }
        // 如果到达了结束时间
        if ($this->crontabPool[$data['id']]['end_time'] > 0 && time() >= $this->crontabPool[$data['id']]['end_time']) {
            $this->crontabPool[$data['id']]['crontab']->destroy();
            unset($this->crontabPool[$data['id']]);
            Db::table($this->crontabTable)->where('id', $data['id'])->update(['status'=>0]);
            return false;
        }
        $this->crontabPool[$data['id']]['is_running']    = true;
        $this->crontabPool[$data['id']]['last_run_time'] = time();
        $this->crontabPool[$data['id']]['has_send_sms']  = false;
        return true;
    }

    /**
     * 执行任务
     * @param $data
     * @return array
     * @author guoliangchen
     * @date 2023/2/21 0021 15:11
     */
    private function runJob($data) {
        $startTime  = microtime(true);
        $start_time = time();
        $result     = true;
        $output     = '';
        $code       = 0;
        switch ($data['type']) {
            // 节点任务
            case self::NODE_CRONTAB:
                {
                    try {
                        list($result, $output) = Ssh::createSshAndExecCommand($data);
                        if ($result){
                            $code = 1;
                            $output .= '--执行返回false';
                        }elseif (empty($output)){
                            $code = 1;
                            $output = '未接受到返回值，任务可能报错';
                        }else{
                            $json_output = json_decode($output,true);
                            if (json_last_error()){
                                $code = 1;
                                $output.= '--返回值不是json，可能报错';
                            }
                            if (isset($json_output['code'])){
                                if ($json_output['code']!=0){
                                    $code = 1;
                                    $output.= '--返回值报错';
                                }
                            }else{
                                $code = 1;
                                $output.= '--返回值无法识别';
                            }
                        }
                    } catch (\Throwable $throwable) {
                        $code   = 1;
                        $output .= '--catch到异常'.$throwable->getMessage().'---'.$throwable->getTraceAsString();
                    }
                    if($code==1){
                        if (!is_string($result)){
                            $result = json_encode($result);
                        }
                        $output.='--result:'.$result;
                    }
                }
                $result = boolval($result);
                break;
            // url任务
            case self::URL_CRONTAB:
                {
                    $url    = trim($data['target']);
                    $client = new \GuzzleHttp\Client();
                    try {
                        $response = $client->get($url);
                        $result   = $response->getStatusCode() === 200;
                        $code     = 0;
                        $output   = $response->getBody()->getContents();
                    } catch (\Throwable $throwable) {
                        $result = false;
                        $code   = 1;
                        $output = $throwable->getMessage();
                    }
                }
                break;
            default:
            {

            }
        }
        $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);
        $endTime      = microtime(true);
        $running_time = round($endTime - $startTime, 6);
        return [$code, $output, $start_time, $running_time, $this->crontabPool[$data['id']]['last_run_time']];
    }

    /**
     * 执行任务后
     * @param $data
     * @param $code
     * @param $output
     * @param $start_time
     * @param $running_time
     * @param $last_run_time
     * @throws \think\db\exception\BindParamException
     * @author guoliangchen
     * @date 2023/2/21 0021 15:12
     */
    private function afterRunJob($data, $code, $output, $start_time, $running_time, $last_run_time) {
        $end_time   = time();
        $update_arr = [
            'last_running_time' => $last_run_time,
            'running_times'     => Db::raw('running_times+1'),
        ];
        // 如果到达了结束时间
        if ($this->crontabPool[$data['id']]['end_time'] > 0 && $end_time >= $this->crontabPool[$data['id']]['end_time']) {
            $update_arr['status'] = 0;
            $this->crontabPool[$data['id']]['crontab']->destroy();
            unset($this->crontabPool[$data['id']]);
        }
        Db::table($this->crontabTable)->where('id', $data['id'])->update($update_arr);
        $this->writeLog && $this->crontabRunLog([
            'crontab_id'   => $data['id'],
            'target'       => $data['target'],
            'parameter'    => $this->worker->id,
            'exception'    => $output,
            'return_code'  => $code,
            'running_time' => $running_time,
            'create_time'  => $start_time,
            'update_time'  => $end_time,
        ]);

        $taskMutex = $this->getTaskMutex();
        $taskMutex->remove($data);
        if (isset($this->crontabPool[$data['id']])) {
            $this->crontabPool[$data['id']]['is_running'] = false;
        }
        // 发送短信
        if ($code == 1) {
            $msg = "定时任务：{$data['title']}-ID{$data['id']}-运行出错，请去查看";
            $this->crontabPool[$data['id']]['has_send_sms']  = true;
            call_user_func([$this, 'createSmsMsg'], $data['warning_ids'], $data['id'], $msg);
        }
        elseif (isset($data['single_run_max_time']) && $data['single_run_max_time'] > 0 && $data['warning_ids']) {
            if ($running_time > $data['single_run_max_time']) {
                $msg = "定时任务：{$data['title']}-ID{$data['id']}-已运行{$running_time}秒，超过超过最大时间{$data['single_run_max_time']}秒，请去查看";
                // 发送预计信息
                $this->crontabPool[$data['id']]['has_send_sms']  = true;
                call_user_func([$this, 'createSmsMsg'], $data['warning_ids'], $data['id'], $msg);
            }
        }
    }

    /**
     * 定时器运行时间和次数检测
     * @author guoliangchen
     * @date 2023/2/1 0001 17:24
     */
    private function watchCrontabWarning() {
        $_that                         = $this;
        $this->watchCrontab['crontab'] = [
            'crontab' => new Crontab($this->watchCrontab['rule'], function () use ($_that) {
                \Swoole\Runtime::enableCoroutine();
                \Swoole\Coroutine::set(['enable_deadlock_check' => false]);
                go(function () use ($_that) {
                    call_user_func([$_that, 'watchCrontabWarningDo']);
                });
            }),
        ];
    }

    private function watchCrontabWarningDo() {
        if ($this->watchCrontab['is_running']) {
            return;
        }
        else {
            $this->watchCrontab['is_running'] = true;
        }
        $now = time();
        if ($this->crontabPool) {
            foreach ($this->crontabPool as $crontab_id => $data) {
                if (isset($data['single_run_max_time']) && $data['single_run_max_time'] > 0 && $data['warning_ids'] && $data['has_send_sms']===false) {
                    $run_time = $now - $data['last_run_time'];
                    if ($data['is_running'] && $run_time > $data['single_run_max_time']) {
                        // 发送预计信息
                        $this->crontabPool[$crontab_id]['has_send_sms']  = true;
                        $msg = "定时任务：{$data['title']}-ID{$data['id']}-已运行{$run_time}秒，超过超过最大时间{$data['single_run_max_time']}，请去查看";
                        // 发送预计信息
                        $this->createSmsMsg($data['warning_ids'], $data['id'], $msg);
                    }
                }
            }
        }
        $this->watchCrontab['is_running'] = false;
    }

    /**
     * 是否单次
     * @param $crontab
     * @return void
     */
    private function isSingleton($crontab) {
        if ($crontab['singleton'] == 0 && isset($this->crontabPool[$crontab['id']])) {
            $this->debug && $this->writeln("定时器销毁", true);
            $this->crontabPool[$crontab['id']]['crontab']->destroy();
        }
    }


    /**
     * 解决任务的并发执行问题，任务永远只会同时运行 1 个
     * @param $crontab
     * @return bool
     */
    private function runInSingleton($crontab): bool {
        $taskMutex = $this->getTaskMutex();
        if ($taskMutex->exists($crontab) || !$taskMutex->create($crontab)) {
            $this->debug && $this->writeln(sprintf('Crontab task [%s] skipped execution at %s.', $crontab['title'], date('Y-m-d H:i:s')), true);
            return false;
        }
        return true;
    }


    /**
     * 只能一个实例执行
     * @param $crontab
     * @return bool
     */
    private function runOnOneServer($crontab): bool {
        $taskMutex = $this->getServerMutex();
        if (!$taskMutex->attempt($crontab)) {
            $this->debug && $this->writeln(sprintf('Crontab task [%s] skipped execution at %s.', $crontab['title'], date('Y-m-d H:i:s')), true);
            return false;
        }
        return true;
    }

    protected function decorateRunnable($crontab): bool {
        if ($this->runInSingleton($crontab) && $this->runOnOneServer($crontab)) {
            return true;
        }
        return false;
    }

    private function getTaskMutex(): TaskMutex {
        if (!$this->taskMutex) {
            $this->taskMutex = Container::has(TaskMutex::class)
                ? Container::get(TaskMutex::class)
                : Container::get(RedisTaskMutex::class);
        }
        return $this->taskMutex;
    }

    private function getServerMutex(): ServerMutex {
        if (!$this->serverMutex) {
            $this->serverMutex = Container::has(ServerMutex::class)
                ? Container::get(ServerMutex::class)
                : Container::get(RedisServerMutex::class);
        }
        return $this->serverMutex;
    }

    /**
     * 记录执行日志
     * @param array $param
     * @return void
     */
    private function crontabRunLog(array $param): void {
        Db::table($this->crontabLogTable)->insert($param);
    }

    /**
     * 创建定时任务
     * @param array $param
     * @return string
     */
    private function crontabCreate(array $param): string {
        $param['create_time'] = $param['update_time'] = time();
        $id                   = Db::table($this->crontabTable)
            ->insertGetId($param);
        $id && $this->execJob($id);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$id,'pk'=>$id]]);
    }

    /**
     * 创建节点
     * @param array $param
     * @return string
     */
    private function crontabNodeCreate(array $param): string {
        $rsa = $param['rsa'];
        unset($param['rsa']);
        try {
            Db::startTrans();
            $param['create_time'] = $param['update_time'] = time();
            $id                   = Db::table($this->crontabNodeTable)
                ->insertGetId($param);
            if (!$id) {
                throw new Exception('节点信息入库失败');
            }
            Ssh::createRsaFile((int)$id, $rsa);
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            return json_encode(['code' => 0, 'msg' => $e->getMessage(), 'data' => []]);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$id]]);
    }

    /**
     * 修改节点
     * @param array $param
     * @return string
     */
    private function crontabNodeUpdate(array $param): string {
        $rsa = $param['rsa'];
        unset($param['rsa']);
        try {
            Db::startTrans();
            $row = Db::table($this->crontabNodeTable)
                ->where('id', $param['id'])
                ->update($param);
            if ($rsa) {
                Ssh::createRsaFile((int)$param['id'], $rsa);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            return json_encode(['code' => 0, 'msg' => $e->getMessage(), 'data' => []]);
        }
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$row]]);

    }

    /**
     * 修改定时器
     * @param array $param
     * @return string
     */
    private function crontabUpdate(array $param): string {
        $row = Db::table($this->crontabTable)
            ->where('id', $param['id'])
            ->update($param);
        if (isset($this->crontabPool[$param['id']])) {
            $this->crontabPool[$param['id']]['crontab']->destroy();
            $taskMutex = $this->getTaskMutex();
            $taskMutex->remove($this->crontabPool[$param['id']]);
            unset($this->crontabPool[$param['id']]);
        }
        if ($param['status'] == self::NORMAL_STATUS) {
            $this->execJob($param['id']);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$row,'pk'=>$param['id']]]);

    }


    /**
     * 清除定时任务
     * @param array $param
     * @return string
     */
    private function crontabDelete(array $param): string {
        if ($id = $param['id']) {
            $ids = explode(',', (string)$id);
            $taskMutex = $this->getTaskMutex();
            foreach ($ids as $item) {
                if (isset($this->crontabPool[$item])) {
                    $this->crontabPool[$item]['crontab']->destroy();
                    unset($this->crontabPool[$item]);
                    $taskMutex->remove($param);
                }
            }

            $rows = Db::table($this->crontabTable)
                ->where('id in (' . $id . ')')
                ->delete();

            return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$rows]]);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => true]]);
    }

    /**
     * 重启定时任务
     * @param array $param
     * @return string
     */
    private function crontabReload(array $param): string {
        $ids = explode(',', (string)$param['id']);
        $taskMutex = $this->getTaskMutex();
        foreach ($ids as $id) {
            if (isset($this->crontabPool[$id])) {
                $this->crontabPool[$id]['crontab']->destroy();
                unset($this->crontabPool[$id]);
                $taskMutex->remove($param);
            }
            Db::table($this->crontabTable)
                ->where('id', $id)
                ->update(['status' => self::NORMAL_STATUS]);
            $this->execJob($id);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => true]]);
    }


    /**
     * 执行日志列表
     * @param array $param
     * @return string
     */
    private function crontabLog(array $param): string {
        $where = $param['where'] ?? [];
        $limit = $param['limit'] ?? 15;
        $page  = $param['page'] ?? 1;
        $param['crontab_id'] && $where[] = ['crontab_id', '=', $param['crontab_id']];

        $data = Db::table($this->crontabLogTable)
            ->where($where)
            ->order('id', 'desc')
            ->paginate(['list_rows' => $limit, 'page' => $page]);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    /**
     * 输出日志
     * @param $msg
     * @param bool $isSuccess
     */
    private function writeln($msg, bool $isSuccess) {
        echo 'worker:' . $this->worker->id . ' [' . date('Y-m-d H:i:s') . '] ' . $msg . ($isSuccess ? " [Ok] " : " [Fail] ") . PHP_EOL;
    }

    /**
     * 检测表是否存在
     */
    private function checkCrontabTables() {
//        $allTables = $this->getDbTables();
//        !in_array($this->crontabTable, $allTables) && $this->createCrontabTable();
//        !in_array($this->crontabLogTable, $allTables) && $this->createCrontabLogTable();
//        !in_array($this->crontabNodeTable, $allTables) && $this->createCrontabNodeTable();
    }

    /**
     * @param $warning_ids
     * @param $crontab_id
     * @param string $msg
     * @return bool
     * @author guoliangchen
     * @date 2023/2/8 0008 10:56
     */
    private function createSmsMsg($warning_ids, $crontab_id, string $msg = '') {
        if (!$warning_ids) {
            return false;
        }
        $now       = time();
        $warn_info = SystemCrontabWarn::getWarnCache();
        if ($warn_info) $warn_info = array_column($warn_info, null, 'warn_id');
        $base_url = getenv('CP_URL');
        $key      = getenv('CP_KEY');
        $open_sms = getenv('CP_SEND_MSG');
        foreach ($warning_ids as $warning_id) {
            // 入库信息
            $warn_insert                    = [
                'nick_name'   => $warn_info[$warning_id]['nick_name'],
                'mobile'      => $warn_info[$warning_id]['mobile'],
                'crontab_id'  => $crontab_id,
                'sms_content' => $msg,
                'create_time' => $now,
            ];
            $this->smsData['warn_insert'][] = $warn_insert;
            if ($base_url && $open_sms) {
                // 短信信息
                $url_params             = [
                    'key'    => $key,
                    'msg'    => $msg,
                    'mobile' => $warn_info[$warning_id]['mobile'],
                    'debug'  => $this->debug,
                ];
                $url_params_str         = http_build_query($url_params);
                $url_params_str         = $base_url . '?' . $url_params_str;
                $this->smsData['url'][] = $url_params_str;
            }
        }
    }

    /**
     * @return bool
     * @author guoliangchen
     * @date 2023/2/8 0008 10:00
     */
    private function sendSmsMsg() {
        $_that                    = $this;
        $this->smsData['crontab'] = new Crontab($this->smsData['rule'], function () use ($_that) {
            \Swoole\Runtime::enableCoroutine();
            \Swoole\Coroutine::set(['enable_deadlock_check' => false]);
            go(function () use ($_that) {
                call_user_func([$_that, 'sendSmsMsgDo']);
            });
        });
    }

    private function sendSmsMsgDo() {
        if ($this->smsData['is_running']) {
            return false;
        }
        $this->smsData['is_running'] = true;
        if ($this->smsData['warn_insert']) {
            Db::table('wa_system_crontab_warn_history')->insertAll($this->smsData['warn_insert'], 500);
        }
        $this->smsData['warn_insert'] = [];
        $request_url                  = [];
        while ($this->smsData['url']) {
            $url           = array_shift($this->smsData['url']);
            $request_url[] = new Request('GET', $url);
        }

        if ($request_url) {
            $client   = new Client();
            $response = Pool::batch($client, $request_url, array(
                'concurrency' => 15,
            ));
//            foreach ($response as $r) {
//                var_dump($r->getStatusCode());
//                $body = $r->getBody()->getContents();
//                var_dump($body);
//            }
        }
        $this->smsData['is_running'] = false;
        return true;

    }

    private function delTaskMutex() {
        $keys = Redis::keys('framework' . DIRECTORY_SEPARATOR . 'crontab-*');
        if ($keys) {
            Redis::del($keys);
        }
    }

}