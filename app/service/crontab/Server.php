<?php
declare ( strict_types = 1 );

namespace app\service\crontab;

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
use function Symfony\Component\Console\Helper\calculateRowCount;

/**
 * 注意：定时器开始、暂停、重起
 * Workerman\Crontab 1.0.4 起 立即执行
 */
class Server
{
    const FORBIDDEN_STATUS = '0';

    const NORMAL_STATUS = '1';

    // 命令任务
    public const COMMAND_CRONTAB = '1';
    // 类任务
    public const CLASS_CRONTAB = '2';
    // URL任务
    public const URL_CRONTAB = '3';
    // EVAL 任务
    public const EVAL_CRONTAB = '4';
    //shell 任务
    public const SHELL_CRONTAB = '5';
    // 节点 任务
    public const NODE_CRONTAB = '6';

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
     * 进程异常退出时 清楚所有reids
     * @var array
     */
    private $crontabList = [];

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

    public function __construct()
    {
    }

    public function onClose(){
    }

    public function onWorkerStop(){
        if ($this->crontabList){
            $taskMutex = $this->getTaskMutex();
            foreach ($this->crontabList as $crontab){
                $taskMutex->remove($crontab);
            }
        }
    }

    public function onWorkerExit(Worker $worker, $status, $pid){
    }

    public function onWorkerStart(Worker $worker)
    {
        $config                = config('crontab.task');
        $this->debug           = $config['debug'] ?? true;
        $this->writeLog        = $config['write_log'] ?? true;
        $this->crontabTable    = $config['crontab_table'];
        $this->crontabLogTable = $config['crontab_table_log'];
        $this->crontabNodeTable = $config['crontab_table_node'];
        $this->worker          = $worker;

        $this->checkCrontabTables();
        $this->crontabInit();
    }

    /**
     * 当客户端与Workman建立连接时(TCP三次握手完成后)触发的回调函数
     * 每个连接只会触发一次onConnect回调
     * 此时客户端还没有发来任何数据
     * 由于udp是无连接的，所以当使用udp时不会触发onConnect回调，也不会触发onClose回调
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection)
    {
        $this->checkCrontabTables();
    }


    public function onMessage(TcpConnection $connection, $data)
    {
        $data   = json_decode($data, true);
        $method = $data['method'];
        $args   = $data['args'];
        $connection->send(call_user_func([$this, $method], $args));
    }


    /**
     * 定时器列表
     * @param array $data
     * @return false|string
     */
    private function crontabIndex(array $data)
    {
        $limit = $data['limit'] ?? 15;
        $page  = $data['page'] ?? 1;
        $where = $data['where'] ?? [];
        $data  = Db::table($this->crontabTable)
            ->alias('c')
            ->field('c.*,n.host,n.alias,n.port,n.id as node_id')
            ->join($this->crontabNodeTable.' n','c.node_id = n.id')
            ->where($where)
            ->order('c.id', 'desc')
            ->paginate(['list_rows' => $limit, 'page' => $page]);
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    private function crontabIndexFind(array $data){
        $where = $data['where'] ?? [];
        $data  = Db::table($this->crontabTable)
            ->alias('c')
            ->field('c.*,n.host,n.alias,n.port,n.id as node_id')
            ->join($this->crontabNodeTable.' n','c.node_id = n.id')
            ->where($where)
            ->find();

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    /**
     * 节点列表
     * @param array $data
     * @return false|string
     */
    private function crontabNodeIndex(array $data)
    {
        $limit = $data['limit'] ?? 15;
        $page  = $data['page'] ?? 1;
        $where = $data['where'] ?? [];
        $data  = Db::table($this->crontabNodeTable)
            ->where($where)
            ->order('id', 'desc')
            ->paginate(['list_rows' => $limit, 'page' => $page]);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $data]);
    }

    /**
     * 初始化定时任务
     * @return void
     */
    private function crontabInit(): void
    {
        $ids = Db::table($this->crontabTable)
            ->where('status', self::NORMAL_STATUS)
            ->order('sort', 'desc')
            ->column('id');
        if ( !empty($ids) ) {
            foreach ( $ids as $id ) {
                $this->crontabRun($id);
            }
        }
    }

    /**
     * 创建定时器
     * @param $id
     * @param bool $is_init
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function crontabRun($id)
    {
        $data = Db::table($this->crontabTable)
            ->alias('c')
            ->field('c.*,n.host,n.alias,n.port,n.id as node_id,n.username')
            ->where('c.id', $id)
            ->join($this->crontabNodeTable.' n','c.node_id= n.id')
            ->where('status', self::NORMAL_STATUS)
            ->find();
        $this->crontabList[] = [
            'id'    => $data['id'],
            'title' => $data['title'],
            'rule'  => $data['rule'],
        ];
        $_that = $this;
        if ( !empty($data) ) {
            switch ( $data['type'] ) {
                case self::COMMAND_CRONTAB:
                    $res = $this->decorateRunnable($data);
                    if ($res) {
                        $this->crontabPool[$data['id']] = [
                            'id'          => $data['id'],
                            'target'      => $data['target'],
                            'rule'        => $data['rule'],
                            'parameter'   => $data['parameter'],
                            'singleton'   => $data['singleton'],
                            'create_time' => date('Y-m-d H:i:s'),
                            'crontab'     => new Crontab($data['rule'], function () use ($data) {
                                $this->decorateRunnable($data);
                                $time      = time();
                                $parameter = $data['parameter'] ?: '';
                                $startTime = microtime(true);
                                $code      = 0;
                                $result    = true;
                                try {
                                    if ( strpos($data['target'], 'php webman') !== false ) {
                                        $command = $data['target'];
                                    } else {
//                                        $command = "php webman " . $data['target'];
                                        $command = $data['target'];
                                    }
                                    $exception = shell_exec($command);
                                } catch ( \Throwable $e ) {
                                    $result    = false;
                                    $code      = 1;
                                    $exception = $e->getMessage();
                                }
                                $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);

                                $this->isSingleton($data);

                                $endTime = microtime(true);
                                Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                                $this->writeLog && $this->crontabRunLog([
                                    'crontab_id'   => $data['id'],
                                    'target'       => $data['target'],
                                    'parameter'    => $parameter,
                                    'exception'    => $exception,
                                    'return_code'  => $code,
                                    'running_time' => round($endTime - $startTime, 6),
                                    'create_time'  => $time,
                                    'update_time'  => $time,
                                ]);

                                $taskMutex = $this->getTaskMutex();
                                $taskMutex->remove($data);
                            })
                        ];
                    }
                    break;
                case self::CLASS_CRONTAB:
                    if ( $this->decorateRunnable($data) ) {
                        $this->crontabPool[$data['id']] = [
                            'id'          => $data['id'],
                            'target'      => $data['target'],
                            'rule'        => $data['rule'],
                            'parameter'   => $data['parameter'],
                            'singleton'   => $data['singleton'],
                            'create_time' => date('Y-m-d H:i:s'),
                            'crontab'     => new Crontab($data['rule'], function () use ($data) {
                                $time      = time();
                                $class     = trim($data['target']);
                                $startTime = microtime(true);
                                if ( $class ) {
                                    if ( strpos($class, '@') !== false ) {
                                        $class  = explode('@', $class);
                                        $method = end($class);
                                        array_pop($class);
                                        $class = implode('@', $class);
                                    } else {
                                        $method = 'execute';
                                    }
                                    if ( class_exists($class) && method_exists($class, $method) ) {
                                        try {
                                            $result     = true;
                                            $code       = 0;
                                            $instance   = Container::get($class);
                                            $parameters = !empty($data['parameter']) ? json_decode( $data['parameter'],true ) : [];
                                            if ( !empty($data['parameter']) && is_array($parameters) ) {
                                                $res = $instance->{$method}($parameters);
                                            } else {
                                                $res = $instance->{$method}();
                                            }
                                        } catch ( \Throwable $throwable ) {
                                            $result = false;
                                            $code   = 1;
                                        }
                                        $exception = isset($throwable) ? $throwable->getMessage() : $res;
                                    } else {
                                        $result    = false;
                                        $code      = 1;
                                        $exception = "方法或类不存在或者错误";
                                    }
                                }

                                $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);

                                $this->isSingleton($data);

                                $endTime = microtime(true);
                                Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                                $this->writeLog && $this->crontabRunLog([
                                    'crontab_id'   => $data['id'],
                                    'target'       => $data['target'],
                                    'parameter'    => $data['parameter'] ?? '',
                                    'exception'    => $exception ?? '',
                                    'return_code'  => $code,
                                    'running_time' => round($endTime - $startTime, 6),
                                    'create_time'  => $time,
                                    'update_time'  => $time,
                                ]);

                                $taskMutex = $this->getTaskMutex();
                                $taskMutex->remove($data);
                            })
                        ];
                    }
                    break;
                case self::URL_CRONTAB:
                    if ( $this->decorateRunnable($data) ) {
                        $this->crontabPool[$data['id']] = [
                            'id'          => $data['id'],
                            'target'      => $data['target'],
                            'rule'        => $data['rule'],
                            'parameter'   => $data['parameter'],
                            'singleton'   => $data['singleton'],
                            'create_time' => date('Y-m-d H:i:s'),
                            'crontab'     => new Crontab($data['rule'], function () use ($data) {
                                $this->decorateRunnable($data);
                                $time      = time();
                                $url       = trim($data['target']);
                                $startTime = microtime(true);
                                $client    = new \GuzzleHttp\Client();
                                try {
                                    $response = $client->get($url);
                                    $result   = $response->getStatusCode() === 200;
                                    $code     = 0;
                                } catch ( \Throwable $throwable ) {
                                    $result    = false;
                                    $code      = 1;
                                    $exception = $throwable->getMessage();
                                }
                                $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);

                                $this->isSingleton($data);

                                $endTime = microtime(true);
                                Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                                $this->writeLog && $this->crontabRunLog([
                                    'crontab_id'   => $data['id'],
                                    'target'       => $data['target'],
                                    'parameter'    => $data['parameter'],
                                    'exception'    => $exception ?? '',
                                    'return_code'  => $code,
                                    'running_time' => round($endTime - $startTime, 6),
                                    'create_time'  => $time,
                                    'update_time'  => $time,
                                ]);

                                $taskMutex = $this->getTaskMutex();
                                $taskMutex->remove($data);
                            })
                        ];
                    }
                    break;
                case self::SHELL_CRONTAB:
                    if ( $this->decorateRunnable($data) ) {
                        $this->crontabPool[$data['id']] = [
                            'id'          => $data['id'],
                            'target'      => $data['target'],
                            'rule'        => $data['rule'],
                            'parameter'   => $data['parameter'],
                            'singleton'   => $data['singleton'],
                            'create_time' => date('Y-m-d H:i:s'),
                            'crontab'     => new Crontab($data['rule'], function () use ($data) {
                                $this->decorateRunnable($data);
                                $time      = time();
                                $parameter = $data['parameter'] ?: '';
                                $startTime = microtime(true);
                                $code      = 0;
                                $result    = true;
                                try {
                                    $exception = shell_exec($data['target']);
                                } catch ( \Throwable $e ) {
                                    $result    = false;
                                    $code      = 1;
                                    $exception = $e->getMessage();
                                }
                                $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);

                                $this->isSingleton($data);

                                $endTime = microtime(true);
                                Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                                $this->writeLog && $this->crontabRunLog([
                                    'crontab_id'   => $data['id'],
                                    'target'       => $data['target'],
                                    'parameter'    => $parameter,
                                    'exception'    => $exception,
                                    'return_code'  => $code,
                                    'running_time' => round($endTime - $startTime, 6),
                                    'create_time'  => $time,
                                    'update_time'  => $time,
                                ]);

                                $taskMutex = $this->getTaskMutex();
                                $taskMutex->remove($data);
                            })
                        ];
                    }
                    break;
                case self::EVAL_CRONTAB:
                    if ( $this->decorateRunnable($data) ) {
                        $this->crontabPool[$data['id']] = [
                            'id'          => $data['id'],
                            'target'      => $data['target'],
                            'rule'        => $data['rule'],
                            'parameter'   => $data['parameter'],
                            'singleton'   => $data['singleton'],
                            'create_time' => date('Y-m-d H:i:s'),
                            'crontab'     => new Crontab($data['rule'], function () use ($data) {
                                $this->decorateRunnable($data);
                                $time      = time();
                                $startTime = microtime(true);
                                $result    = true;
                                $code      = 0;
                                try {
                                    eval($data['target']);
                                } catch ( \Throwable $throwable ) {
                                    $result    = false;
                                    $code      = 1;
                                    $exception = $throwable->getMessage();
                                }
                                $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], $result);

                                $this->isSingleton($data);

                                $endTime = microtime(true);
                                Db::query("UPDATE {$this->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                                $this->writeLog && $this->crontabRunLog([
                                    'crontab_id'   => $data['id'],
                                    'target'       => $data['target'],
                                    'parameter'    => $data['parameter'],
                                    'exception'    => $exception ?? '',
                                    'return_code'  => $code,
                                    'running_time' => round($endTime - $startTime, 6),
                                    'create_time'  => $time,
                                    'update_time'  => $time,
                                ]);

                                $taskMutex = $this->getTaskMutex();
                                $taskMutex->remove($data);
                            })
                        ];
                    }
                    break;
                case self::NODE_CRONTAB:{
                    if ( $this->decorateRunnable($data) ) {
                        var_dump($this->worker->id.'--run--'.$data['id'].'--'.$data['title']);
                        $this->crontabPool[$data['id']] = [
                            'id'          => $data['id'],
                            'target'      => $data['target'],
                            'title'      =>  $data['title'],
                            'rule'        => $data['rule'],
                            'parameter'   => $data['parameter'],
                            'singleton'   => $data['singleton'],
                            'create_time' => date('Y-m-d H:i:s'),
                            'crontab'     => new Crontab($data['rule'], function () use ($data,$_that) {
                                \Swoole\Runtime::enableCoroutine();
                                \Swoole\Coroutine::set(['enable_deadlock_check' => false]);
                                go(function ()use ($data,$_that){
                                    $_that->decorateRunnable($data);
                                    $time      = time();
                                    $startTime = microtime(true);
                                    $result    = true;
                                    $output    = '';
                                    $code      = 0;
                                    try {
                                        list($result,$output) = Ssh::createSshAndExecCommand($data);
                                    } catch ( \Throwable $throwable ) {
                                        $result    = false;
                                        $code      = 1;
                                        $output = $throwable->getMessage();
                                    }

                                    $_that->debug && $_that->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'].'---'.$output, $result);

                                    $_that->isSingleton($data);

                                    $endTime = microtime(true);
                                    Db::query("UPDATE {$_that->crontabTable} SET running_times = running_times + 1, last_running_time = {$time} WHERE id = {$data['id']}");
                                    $_that->writeLog && $_that->crontabRunLog([
                                        'crontab_id'   => $data['id'],
                                        'target'       => $data['target'],
                                        //                                    'parameter'    => $data['parameter'],
                                        'parameter'    => $_that->worker->id,
                                        'exception'    => $output,
                                        'return_code'  => $code,
                                        'running_time' => round($endTime - $startTime, 6),
                                        'create_time'  => $time,
                                        'update_time'  => $time,
                                    ]);

                                    $taskMutex = $_that->getTaskMutex();
                                    $taskMutex->remove($data);
                                });
                            })
                        ];
                    }


                }
            }
        }
    }

    /**
     * 是否单次
     * @param $crontab
     * @return void
     */
    private function isSingleton($crontab)
    {
        if ( $crontab['singleton'] == 0 && isset($this->crontabPool[$crontab['id']]) ) {
            $this->debug && $this->writeln("定时器销毁", true);
            $this->crontabPool[$crontab['id']]['crontab']->destroy();
        }
    }


    /**
     * 解决任务的并发执行问题，任务永远只会同时运行 1 个
     * @param $crontab
     * @return bool
     */
    private function runInSingleton($crontab): bool
    {
        $taskMutex = $this->getTaskMutex();
        if ( $taskMutex->exists($crontab) || !$taskMutex->create($crontab) ) {
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
    private function runOnOneServer($crontab): bool
    {
        $taskMutex = $this->getServerMutex();
        if ( !$taskMutex->attempt($crontab) ) {
            $this->debug && $this->writeln(sprintf('Crontab task [%s] skipped execution at %s.', $crontab['title'], date('Y-m-d H:i:s')), true);
            return false;
        }
        return true;
    }

    protected function decorateRunnable($crontab): bool
    {
        if ( $this->runInSingleton($crontab) && $this->runOnOneServer($crontab) ) {
            return true;
        }
        return false;
    }

    private function  getTaskMutex(): TaskMutex
    {
        if ( !$this->taskMutex ) {
            $this->taskMutex = Container::has(TaskMutex::class)
                ? Container::get(TaskMutex::class)
                : Container::get(RedisTaskMutex::class);
        }
        return $this->taskMutex;
    }

    private function getServerMutex(): ServerMutex
    {
        if ( !$this->serverMutex ) {
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
    private function crontabRunLog(array $param): void
    {
        Db::table($this->crontabLogTable)->insert($param);
    }

    /**
     * 创建定时任务
     * @param array $param
     * @return string
     */
    private function crontabCreate(array $param): string
    {
        $param['create_time'] = $param['update_time'] = time();
        $id                   = Db::table($this->crontabTable)
            ->insertGetId($param);
        $id && $this->crontabRun($id);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$id]]);
    }

    /**
     * 创建节点
     * @param array $param
     * @return string
     */
    private function crontabNodeCreate(array $param): string
    {
        $rsa = $param['rsa'];
        unset($param['rsa']);
        try {
            Db::startTrans();
            $param['create_time'] = $param['update_time'] = time();
            $id                   = Db::table($this->crontabNodeTable)
                ->insertGetId($param);
            if (!$id){
                throw new Exception('节点信息入库失败');
            }
            Ssh::createRsaFile((int)$id,$rsa);
            Db::commit();
        }catch (Exception $e){
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
    private function crontabNodeUpdate(array $param): string
    {
        $rsa = $param['rsa'];
        unset($param['rsa']);
        try {
            Db::startTrans();
            $row = Db::table($this->crontabNodeTable)
                ->where('id', $param['id'])
                ->update($param);
            if ($rsa){
                Ssh::createRsaFile((int)$param['id'],$rsa);
            }
            Db::commit();
        }catch (Exception $e){
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
    private function crontabUpdate(array $param): string
    {
        $row = Db::table($this->crontabTable)
            ->where('id', $param['id'])
            ->update($param);

        if ( isset($this->crontabPool[$param['id']]) ) {
            $this->crontabPool[$param['id']]['crontab']->destroy();
            unset($this->crontabPool[$param['id']]);
        }
        if ( $param['status'] == self::NORMAL_STATUS ) {
            $this->crontabRun($param['id']);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$row]]);

    }


    /**
     * 清除定时任务
     * @param array $param
     * @return string
     */
    private function crontabDelete(array $param): string
    {
        if ( $id = $param['id'] ) {
            $ids = explode(',', (string)$id);

            foreach ( $ids as $item ) {
                if ( isset($this->crontabPool[$item]) ) {
                    $this->crontabPool[$item]['crontab']->destroy();
                    unset($this->crontabPool[$item]);
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
    private function crontabReload(array $param): string
    {
        $ids = explode(',', (string)$param['id']);

        foreach ( $ids as $id ) {
            if ( isset($this->crontabPool[$id]) ) {
                $this->crontabPool[$id]['crontab']->destroy();
                unset($this->crontabPool[$id]);
            }
            Db::table($this->crontabTable)
                ->where('id', $id)
                ->update(['status' => self::NORMAL_STATUS]);
            $this->crontabRun($id);
        }

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => true]]);
    }


    /**
     * 执行日志列表
     * @param array $param
     * @return string
     */
    private function crontabLog(array $param): string
    {
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
    private function writeln($msg, bool $isSuccess)
    {
        echo 'worker:'.$this->worker->id.' [' . date('Y-m-d H:i:s') . '] ' . $msg . ( $isSuccess ? " [Ok] " : " [Fail] " ) . PHP_EOL;
    }

    /**
     * 检测表是否存在
     */
    private function checkCrontabTables()
    {
//        $allTables = $this->getDbTables();
//        !in_array($this->crontabTable, $allTables) && $this->createCrontabTable();
//        !in_array($this->crontabLogTable, $allTables) && $this->createCrontabLogTable();
//        !in_array($this->crontabNodeTable, $allTables) && $this->createCrontabNodeTable();
    }




}