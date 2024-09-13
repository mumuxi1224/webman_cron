<?php
namespace app\service\swooleCrontab;

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\DefaultHandler;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use mysql_xdevapi\BaseResult;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOPool;
use Swoole\Server;
use Swoole\Coroutine as Co;
use Swoole\Coroutine\MySQL;
use Swoole\Coroutine\Redis;
use Swoole\Coroutine\Scheduler;
use Swoole\Timer;
use Yurun\Util\Swoole\Guzzle\SwooleHandler;
use Swoole\Database\RedisConfig;
use Swoole\Database\RedisPool;
class Service
{
    /**
     * @var \Swoole\Database\PDOPool;
     */
    private static $dbPoll = null;

    /**
     * @var \Swoole\Coroutine\MySQL
     */
    private static $db = null;

    /**
     * @var \Swoole\Coroutine\Redis
     */
    private static $redis = null;

    /**
     * @var \Swoole\Database\RedisPool;
     */
    private static  $redisPoll = null;
    /**
     * 输出内容入库的最大长度
     * @var int
     */
    private $output_limit = 1000;

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


    // 节点任务
    public const NODE_CRONTAB = '1';
    // url任务
    public const URL_CRONTAB = '2';

    const FORBIDDEN_STATUS = '0';

    const NORMAL_STATUS = '1';

    /**
     * 任务进程池
     * @var \app\service\swooleCrontab\Crontab[] array
     */
    private $crontabPool = [];

    /**
     * 立即执行任务进程池
     * @var \app\service\swooleCrontab\Crontab[] array
     */
    private $runNowCrontabPool = [];

    private $runNowStartTime = 3;

    private $serverStartTimeStamp = 0;

    private $totalRunJobCount = 0;

    /**
     * 初始化配置
     * @author guoliangchen
     * @date 2024/8/21 上午10:29
     */
    public function initConfig() {
        // 初始化env
        if (class_exists(Dotenv::class) && file_exists(run_path('.env'))) {
            if (method_exists(Dotenv::class, 'createUnsafeImmutable')) {
                Dotenv::createUnsafeImmutable(run_path())->load();
            } else {
                Dotenv::createMutable(run_path())->load();
            }
        }
        // 初始化config
        \support\App::loadAllConfig(['route', 'container']);
        // 初始本地变量
        $config                 = config('crontab.task');
        $this->debug            = $config['debug'] ?? true;
        $this->writeLog         = $config['write_log'] ?? true;
        $this->crontabTable     = $config['crontab_table'];
        $this->crontabLogTable  = $config['crontab_table_log'];
        $this->crontabNodeTable = $config['crontab_table_node'];
    }

    /**
     * 初始化服务
     * @author guoliangchen
     * @date 2024/9/5 下午1:31
     */
    public function initService() {
        $this->initDbAndPoll();
        $this->crontabInit();
        $this->sendSmsMsg();
        $this->serverStartTimeStamp = time();
    }

    /**
     * 初始化DB
     * @author guoliangchen
     * @date 2024/8/20 下午6:02
     */
    private function initDbAndPoll() {
        // 初始化数据库
        if (is_null(self::$db)){
            self::$db = new MySQL();
//            Co\run(function () {
//                self::$db->connect([
//                    'host'     => getenv('DB_HOST'),
//                    'port'     => getenv('DB_PORT'),
//                    'user'     => getenv('DB_USER'),
//                    'password' => getenv('DB_PASSWORD'),
//                    'database' => getenv('DB_NAME'),
//                ]);
//                if (!self::$db->connected){
//                    throw new \Exception('数据库连接失败:'.self::$db->connect_error);
//                }
//            });
        }

        // 初始化数据库连接池
        if (is_null(self::$dbPoll)) {
            self::$dbPoll = new PDOPool((new PDOConfig)
                ->withHost(getenv('DB_HOST'))
                ->withPort(intval(getenv('DB_PORT')))
                // ->withUnixSocket('/tmp/mysql.sock')
                ->withDbName(getenv('DB_NAME'))
                ->withCharset('utf8mb4')
                ->withUsername(getenv('DB_USER'))
                ->withPassword(getenv('DB_PASSWORD'))
                , 10);
        }

        // 初始化redis
        if (is_null(self::$redis)){
//            Co\run(function () {
//                self::$redis = new Redis();
//                self::$redis->connect(getenv('REDIS_HOST'), (int)getenv('REDIS_PORT'));
//                $redis_password = getenv('REDIS_PASSWORD');
//                if ($redis_password) {
//                    self::$redis->auth($redis_password);
//                }
//                self::$redis->select((int)getenv('REDIS_DATABASE'));
//            });
        }

        if (is_null(self::$redisPoll)){
            $redis_password = getenv('REDIS_PASSWORD');
            self::$redisPoll = new RedisPool((new RedisConfig)
                ->withHost(getenv('REDIS_HOST'))
                ->withPort( (int)getenv('REDIS_PORT'))
                ->withAuth($redis_password)
                ->withDbIndex((int)getenv('REDIS_DATABASE'))
                ->withTimeout(1)
            ,10);
        }
    }


    /**
     * 初始化定时任务
     * @author guoliangchen
     * @date 2024/8/21 上午11:25
     */
    public function  crontabInit() {
//        Co\run(function () {
            $db = self::$dbPoll->get();
            $sql = "select * from {$this->crontabTable} where status = 1 order by sort desc";
            $crontab_data = $db->query($sql)->fetchAll();
            if ($crontab_data){
                foreach ($crontab_data as $cron){
                    $this->execJob($cron['id'],$db,[]);
                }
            }
            self::$dbPoll->put($db);
//        });
    }

    /**
     * 执行任务
     * @param $id
     * @param $db
     * @param $data
     * @param $type 1:正常定时器 2:立即执行
     * @return array|void
     * @author guoliangchen
     * @date 2024/9/5 下午1:33
     */
    public function execJob($id,$db,$data = [],$run_type = 1) {
        if (empty($id)){
            return [false,'参数缺失'];
        }
        if (empty($data)){
            $status_str = $run_type==1?" and  status = 1":"";
            $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $data = $db->query("select * from {$this->crontabTable} where id = {$id} {$status_str} limit 1")->fetch();
            if (!$data){
                return [false,'未知数据'];
            }
        }
        $now = time();
        // 如果到达了结束时间
        if ($run_type == 1 && $data['end_time'] > 0 && $now >= $data['end_time']) {
            $db->query($this->generateUpdateSql($this->crontabTable,['id'=>$data['id']],['status'=>0]));
            return [false,'任务已结束'];
        }
        $data['end_time'] = $data['end_time'] ? intval($data['end_time']):0;
        if ($data['warning_ids']) $data['warning_ids'] = explode(',', $data['warning_ids']);
        if ($data['type'] == self::NODE_CRONTAB){
            $node_cache = $this->getNodeCache();
            $node_info = $node_cache[ $data['node_id'] ];
            $data['index_name'] = empty($node_info['index_name'])?'index.php':$node_info['index_name'];
            $data['target'] = 'php '.$data['index_name'] .' '. $data['target'];
        }
        $next_minute = $now +  60 - ($now % 60);
        $data['run_type'] = $run_type;
        if ($run_type == 1){
            $this->crontabPool[$data['id']]            = [
                'id'                  => $data['id'],
                'target'              => $data['target'],
                'title'               => $data['title'],
                'rule'                => $data['rule'],
                'parameter'           => $data['parameter'],
                'singleton'           => $data['singleton'],
                'create_time'         => $now,
                'end_time'            => $data['end_time'],
                'single_run_max_time' => $data['single_run_max_time'] ?? 0,
                'warning_ids'         => $data['warning_ids'],
                'is_running'          => false,
                'last_run_time'       => $now,
                'has_send_sms'        => false,
                'next_minute'        =>  $next_minute,// 下一分钟时间
                'run_type'              =>$run_type,
            ];

            $this->crontabPool[$data['id']]['crontab'] = new \app\service\swooleCrontab\Crontab($data['rule'],function ()use ($data){
                Co::create(function ()use($data) {
                    $can_run = call_user_func([$this, 'beforeRunJob'], $data);
                    if ($can_run) {
                        list($code, $output, $start_time, $running_time, $last_run_time) = call_user_func([$this, 'runJob'], $data);
                        call_user_func([$this, 'afterRunJob'], $data, $code, $output, $start_time, $running_time, $last_run_time);
                    }else{
//                    var_dump('正在运行中');
                    }
                });
            });
        }else{
            $this->runNowCrontabPool[$data['id']]            = [
                'id'                  => $data['id'],
                'target'              => $data['target'],
                'title'               => $data['title'],
                'rule'                => $data['rule'],
                'parameter'           => $data['parameter'],
                'singleton'           => $data['singleton'],
                'create_time'         => $now,
                'end_time'            => $data['end_time'],
                'single_run_max_time' => $data['single_run_max_time'] ?? 0,
                'warning_ids'         => $data['warning_ids'],
                'is_running'          => false,
                'last_run_time'       => $now,
                'has_send_sms'        => false,
                'next_minute'        =>  $next_minute,// 下一分钟时间
                'run_type'              =>$run_type,
            ];
            $this->runNowCrontabPool[$data['id']]['crontab'] = \Swoole\Timer::after($this->runNowStartTime * 1000, function () use ($data) {
                list($code, $output, $start_time, $running_time, $last_run_time) = call_user_func([$this, 'runJob'], $data);
                call_user_func([$this, 'afterRunJob'], $data, $code, $output, $start_time, $running_time, $last_run_time);
            });

        }

    }

    /**
     * 执行任务前
     * @param $data
     * @return bool
     * @author guoliangchen
     * @date 2024/8/21 下午1:23
     */
    private function beforeRunJob($data) {
//
//        // 获取当前内存占用
//        $memoryUsage = memory_get_usage();
//
//        // 获取峰值内存占用
//        $peakMemoryUsage = memory_get_peak_usage();
//
//        // 将字节转换为 MB
//        $memoryUsageMB = $memoryUsage / (1024 * 1024);
//        $peakMemoryUsageMB = $peakMemoryUsage / (1024 * 1024);
//
//        // 打印当前内存占用
//        echo "当前内存占用: " . number_format($memoryUsageMB, 2) . " MB\n";
//
//        // 打印峰值内存占用
//        echo "峰值内存占用: " . number_format($peakMemoryUsageMB, 2) . " MB\n";

        $now = time();
        if (empty($this->crontabPool[$data['id']]) || empty($this->crontabPool[$data['id']]['crontab']) || $this->crontabPool[$data['id']]['is_running']) {
            return false;
        }
        if ($now < $this->crontabPool[$data['id']]['next_minute']){
//            var_dump('下一分钟开始才能执行');
            return false;
        }
        // 如果到达了结束时间
        if ($this->crontabPool[$data['id']]['end_time'] > 0 && time() >= $this->crontabPool[$data['id']]['end_time']) {
            $this->crontabPool[$data['id']]['crontab']->destroy();
            unset($this->crontabPool[$data['id']]);
            $db = self::$dbPoll->get();
            $update_sql = $this->generateUpdateSql($this->crontabTable,['id'=>$data['id']],['status = 0']);
            $db->exec($update_sql);
            self::$dbPoll->put($db);
            return false;
        }
        $this->crontabPool[$data['id']]['is_running']    = true;
        $this->crontabPool[$data['id']]['last_run_time'] = $now;
        $this->crontabPool[$data['id']]['has_send_sms']  = false;
        return true;
    }

    /**
     * 执行任务
     * @param $data
     * @return array
     * @author guoliangchen
     * @date 2024/8/21 下午1:32
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
                        $node_cache = $this->getNodeCache();
                        list($result, $output) = Ssh::createSshAndExecCommand($data,$node_cache);
                        list($result, $output,$code) = call_user_func([$this, 'checkNodeCommandIsSuccess'],$result, $output);
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
//        $this->debug && $this->writeln('执行定时器任务#' . $data['id'] . ' ' . $data['rule'] . ' ' . $data['target'], boolval($code));
        $endTime      = microtime(true);
        $running_time = round($endTime - $startTime, 6);
        return [$code, $output, $start_time, $running_time, $data['run_type']== 1?$this->crontabPool[$data['id']]['last_run_time']:$this->runNowCrontabPool[$data['id']]['last_run_time']];
    }

    /**
     * 接收消息
     * @param $method
     * @param $args
     * @return false|mixed|string
     * @author guoliangchen
     * @date 2024/9/5 上午11:47
     */
    public function onReceive($method,$args) {
        try {
            return call_user_func([$this, $method], $args);
        }catch (\Exception $exception){
            return json_encode(['code' => 0, 'msg' => $exception->getMessage() ]);
        }
    }

    /**
     * 获取节点缓存
     * @return array|mixed
     * @author guoliangchen
     * @date 2024/8/21 下午7:54
     */
    public function getNodeCache() {
        $_redis_save_key = 'crontab_node_info';
        $_redis_save_time = 3600;
//                        $rds = new \Swoole\Coroutine\Redis();
//                $rds->connect(getenv('REDIS_HOST'), (int)getenv('REDIS_PORT'));
//                $redis_password = getenv('REDIS_PASSWORD');
//                if ($redis_password) {
//                    $rds->auth($redis_password);
//                }
//                $rds->select((int)getenv('REDIS_DATABASE'));
        $rds = self::$redisPoll->get();
//        var_dump($rds);
////        $data = self::$redis->get($_redis_save_key);
        $data = $rds->get($_redis_save_key);
//        $data = '';
        if (empty($data)){
            $db = self::$dbPoll->get();
            $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $data = $db->query("select id,host,alias,port,username,code_dir,index_name from wa_system_crontab_node")->fetchAll();
            self::$dbPoll->put($db);
            if ($data){
                $data = array_column($data,null,'id');
                $rds->setNx($_redis_save_key,json_encode($data));
                $rds->expire($_redis_save_key,$_redis_save_time);
            }else{
                $data = [];
            }
        }else{
            $data = json_decode($data,true);
        }
        self::$redisPoll->put($rds);
//        $r = $rds->close();
//        var_dump('close');
//        var_dump($r);
        return $data;

    }

    /**
     * 执行任务之后
     * @param $data
     * @param $code
     * @param $output
     * @param $start_time
     * @param $running_time
     * @param $last_run_time
     * @author guoliangchen
     * @date 2024/9/5 下午1:34
     */
    private function afterRunJob($data, $code, $output, $start_time, $running_time, $last_run_time) {
        $this->totalRunJobCount++;
        $end_time   = time();
        $update_arr = [
            "last_running_time = {$last_run_time}",
            "running_times = running_times+1",
        ];
        // 如果到达了结束时间
        if ($data['run_type'] == 1 && $this->crontabPool[$data['id']]['end_time'] > 0 && $end_time >= $this->crontabPool[$data['id']]['end_time']) {
            $update_arr['status'] = 0;
            $this->crontabPool[$data['id']]['crontab']->destroy();
            unset($this->crontabPool[$data['id']]);
        }
        if ($update_arr){
            $db = self::$dbPoll->get();
            $update_sql = $this->generateUpdateSql($this->crontabTable,['id'=>$data['id']],$update_arr);
            $db->exec($update_sql);
            self::$dbPoll->put($db);
        }

        if($this->writeLog){
            if (mb_strlen($output) > $this->output_limit) {
                $output = mb_substr($output, 0, $this->output_limit);
                $output .= '...';
            }
            if ($data['run_type'] == 2){
                $output = "立即执行日志：".$output;
            }
            $log_arr = [
                'crontab_id'   => $data['id'],
                'target'       => $data['target'],
                'parameter'    => 1,
                'exception'    => $output,
                'return_code'  => $code,
                'running_time' => $running_time,
                'create_time'  => $start_time,
                'update_time'  => $end_time,
                'node_id'      => $data['node_id']?:0,
                'category_id'  => $data['category_id']?:0,
            ];
            $db = self::$dbPoll->get();
            $log_ins_sql = $this->generateInsertSql('wa_system_crontab_log',$log_arr);
            $db->exec($log_ins_sql);
            self::$dbPoll->put($db);
        }

        if ($data['run_type'] == 2){
            unset($this->runNowCrontabPool[$data['id']]);
        }

        if ($data['run_type'] == 1){
            if (isset($this->crontabPool[$data['id']])) {
                $this->crontabPool[$data['id']]['is_running'] = false;
            }
            // 发送短信
            if ($code == 1) {
                $msg = "定时任务：{$data['title']}-ID{$data['id']}-命令：{$data['target']}-运行出错，请去查看";
                $this->crontabPool[$data['id']]['has_send_sms']  = true;
                call_user_func([$this, 'createSmsMsg'], $data['warning_ids'], $data['id'], $msg);
            }
            elseif (isset($data['single_run_max_time']) && $data['single_run_max_time'] > 0 && $data['warning_ids']) {
                if ($running_time > $data['single_run_max_time']) {
                    $msg = "定时任务：{$data['title']}-ID{$data['id']}-命令：{$data['target']}-已运行{$running_time}秒，超过超过最大时间{$data['single_run_max_time']}秒，请去查看";
                    // 发送预计信息
                    $this->crontabPool[$data['id']]['has_send_sms']  = true;
                    call_user_func([$this, 'createSmsMsg'], $data['warning_ids'], $data['id'], $msg);
                }
            }
        }
    }


    /**
     * 启动项目
     * @author guoliangchen
     * @date 2024/8/21 上午10:14
     */
    public function run() {
        // 启用 Swoole 协程支持
        $this->initConfig();
        DefaultHandler::setDefaultHandler(SwooleHandler::class);

        $listen = config('crontab.task.listen');
        list($host, $port) = explode(':', $listen);


        // 创建 TCP 服务器
//        $server = new Server($host, (int)$port, SWOOLE_BASE);
        $server = new Server($host, (int)$port, SWOOLE_PROCESS);
        echo "监听地址:{$listen}\r\n";

        // 设置服务器为异步非阻塞模式
        $server->set([
            'worker_num'       => 1,
            'enable_coroutine' => true,
            'max_coroutine'    => 3000,
            'daemonize'        => true,
            'log_file'         => runtime_path() . '/logs/swoole.log',
            'log_date_format'  => '%Y-%m-%d %H:%M:%S',
            'log_rotation'     => SWOOLE_LOG_ROTATION_DAILY,
            'display_errors'   => true,
            'hook_flags'       => SWOOLE_HOOK_ALL,
        ]);

        $server->on('start', function (Server $server) {
            var_dump(date('Y-m-d H:i:s').' start server');
        });


        // 当有客户端连接事件时
        $server->on('connect', function (\Swoole\Server $server, int $fd) {
//            echo "Client {$fd} connected.\n";
        });


        // 设置 onWorkerStart 事件处理函数
        $server->on('workerStart', function (\Swoole\Server $server, int $workerId) {
            $this->initService();
        });


        // 当有客户端数据到达时
        $server->on('receive', function (Server $server, $fd, $reactor_id, $data) {
            Co::create(function () use ($server, $fd, $data) {
//                echo "Received data from client {$fd}: {$data}\n";
                try {
                    $data   = json_decode($data, true);
//                        var_dump($data);
                    $method = $data['method']?:'';
                    $args   = $data['args']?:[];
                    $res = $this->onReceive($method,$args);
                    $server->send($fd,$res);
                }catch (\Exception $exception){
                    $server->send($fd,json_encode(['code' => 0, 'msg' => $exception->getMessage() ]));
                }
//                Co::yield();
//                    $server->close($fd);
            });
        });

        // 当客户端断开连接时
        $server->on('close', function (\Swoole\Server $server, int $fd) {
//            echo "Client {$fd} closed.\n";
        });


        // 启动服务
        $server->start();
    }

    /**
     * 创建定时任务
     * @param array $param
     * @return string
     * @author guoliangchen
     * @date 2024/9/2 下午2:49
     */
    private function crontabCreate(array $param): string {
        $param['create_time'] = $param['update_time'] = time();
        $db = self::$dbPoll->get();
        $insert_sql = $this->generateInsertSql($this->crontabTable,$param);
        $db->query($insert_sql);
        $id = $db->lastInsertId();
        $id && $this->execJob($id,$db,[]);
        self::$dbPoll->put($db);
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$id,'pk'=>$id]]);
    }

    /**
     * 创建节点
     * @param array $param
     * @return string
     * @author guoliangchen
     * @date 2024/9/2 下午2:51
     */
    private function crontabNodeCreate(array $param): string {
        $rsa = $param['rsa'];
        unset($param['rsa']);
        $param['create_time'] = $param['update_time'] = time();
        $db = self::$dbPoll->get();
        $insert_sql = $this->generateInsertSql($this->crontabTable,$param);
        $db->query($insert_sql);
        $id = $db->lastInsertId();
        self::$dbPoll->put($db);
        if (!$id) {
            return json_encode(['code' => 0, 'msg' => '节点信息入库失败', 'data' => []]);
        }
        Ssh::createRsaFile((int)$id, $rsa);

        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$id]]);
    }

    /**
     * 修改节点
     * @param array $param
     * @return string
     * @author guoliangchen
     * @date 2024/9/2 下午2:55
     */
    private function crontabNodeUpdate(array $param): string {
        $rsa = $param['rsa'];
        unset($param['rsa']);
        $db = self::$dbPoll->get();
        $update_sql = $this->generateUpdateSql($this->crontabNodeTable,['id'=>$param['id']],$param);
        $db->query($update_sql);
        self::$dbPoll->put($db);
        Ssh::createRsaFile((int)$param['id'], $rsa);
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => true]]);
    }

    /**
     * 修改定时器
     * @param array $param
     * @return string
     */
    private function crontabUpdate(array $param): string {
        $db = self::$dbPoll->get();
        $update_sql = $this->generateUpdateSql($this->crontabTable,['id'=>$param['id']],$param);
        $row = $db->query($update_sql);
        if (isset($this->crontabPool[$param['id']])) {
            if (isset($this->crontabPool[$param['id']]['crontab'])){
                $this->crontabPool[$param['id']]['crontab']->destroy();
            }
            // 只清除定时器
            unset($this->crontabPool[$param['id']]['crontab']);
        }
        if ($param['status'] == self::NORMAL_STATUS) {
            $this->execJob($param['id'],$db,[]);
        }
        self::$dbPoll->put($db);
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['code' => (bool)$row,'pk'=>$param['id']]]);
    }



    /**
     * 发送预警短信
     * @param $warning_ids
     * @param $crontab_id
     * @param string $msg
     * @return false|void
     * @author guoliangchen
     * @date 2024/8/22 上午9:49
     */
    private function createSmsMsg($warning_ids, $crontab_id, string $msg = '') {
        if (!$warning_ids) {
            return false;
        }
        $now       = time();

        $warn_info = $this->getWarnCache();
        if (empty($warn_info)){
            return;
        }
        $base_url = getenv('CP_URL');
        $key      = getenv('CP_KEY');
        $open_sms = getenv('CP_SEND_MSG');
        foreach ($warning_ids as $warning_id) {
            if (isset($warn_info[$warning_id])){
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
    }

    /**
     * @return bool
     * @author guoliangchen
     * @date 2023/2/8 0008 10:00
     */
    private function sendSmsMsg() {
        $this->smsData['crontab'] = new Crontab($this->smsData['rule'], function (){
            $this->sendSmsMsgDo();
        });
    }

    /**
     * 发送短信
     * @return bool
     * @author guoliangchen
     * @date 2024/9/2 下午2:28
     */
    private function sendSmsMsgDo() {
        if ($this->smsData['is_running']) {
            return false;
        }
        $this->smsData['is_running'] = true;
        if ($this->smsData['warn_insert']) {
            $db = self::$dbPoll->get();
            $log_ins_sql = $this->generateBatchInsertSQL('wa_system_crontab_warn_history',$this->smsData['warn_insert']);
            $db->exec($log_ins_sql);
            self::$dbPoll->put($db);
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
        }
        $this->smsData['is_running'] = false;
        return true;

    }
    /**
     * 获取预警信息
     * @return array|mixed
     * @author guoliangchen
     * @date 2024/8/22 上午10:02
     */
    private function getWarnCache() {
        $_redis_save_key = 'crontab_warn_info';
        $_redis_save_time = 3600;
        $rds = self::$redisPoll->get();
//        $data = self::$redis->get($_redis_save_key);
        $data = $rds->get($_redis_save_key);
        if (empty($data)){
            $db = self::$dbPoll->get();
            $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $data = $db->query("select warn_id,nick_name,mobile from wa_system_crontab_warn where is_effect = 1")->fetchAll();
            self::$dbPoll->put($db);
            if ($data){
                $data = array_column($data,null,'warn_id');
                $rds->setNx($_redis_save_key,json_encode($data));
                $rds->expire($_redis_save_key,$_redis_save_time);
            }else{
                $data = [];
            }
        }else{
            $data = json_decode($data,true);
        }
        self::$redisPoll->put($rds);
        return $data;
    }

    /**
     * 输出日志
     * @param $msg
     * @param bool $isSuccess
     * @author guoliangchen
     * @date 2024/8/21 下午1:25
     */
    private function writeln($msg, bool $isSuccess) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . ($isSuccess ? " [Ok] " : " [Fail] ") . PHP_EOL;
    }

    /**
     * 检测返回是否成功
     * @param $result
     * @param $output
     * @return array
     * @author guoliangchen
     * @date 2024/8/21 下午1:25
     */
    private function checkNodeCommandIsSuccess($result, $output): array {
        $code = 0;
        $suc_string = 'huibo_job_status:true';
        $fail_string = 'huibo_job_status:false';
        if (strpos($output, $suc_string) !== false) {
            return [$result, $output,0];
        } else if (strpos($output, $fail_string) !== false) {
            return [$result, $output,1];
        }

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
        return [$result,$output,$code];
    }

    /**
     * 生成更新SQL
     * @param $tableName
     * @param $updateConditions
     * @param $updateValues
     * @return string
     * @author guoliangchen
     * @date 2024/8/21 上午11:49
     */
    public function generateUpdateSql($tableName, $updateConditions, $updateValues): string {
        // 初始化 SQL 语句
        $sql = "UPDATE `$tableName` SET ";

        // 处理更新内容
        $setClauses = [];

        // 检查 $updateValues 是否为数组
        if (is_array($updateValues)) {
            // 如果是数组，则按 key-value 形式处理
            foreach ($updateValues as $column => $value) {
                if (is_string($column) && !is_numeric($column)) {
                    // 如果是 key-value 形式的数组
                    $setClauses[] = "`$column` = '" . addslashes($value) . "'";
                } else {
                    // 如果不是 key-value 形式的数组，则直接添加更新语句
                    $setClauses[] = $value;
                }
            }
        } else {
            // 如果不是数组，则直接添加到 setClauses
            $setClauses[] = $updateValues;
        }

        $sql .= implode(', ', $setClauses);

        // 处理更新条件
        if (!empty($updateConditions)) {
            $whereClauses = [];
            foreach ($updateConditions as $column => $value) {
                $whereClauses[] = "`$column` = '" . addslashes($value) . "'";
            }
            $sql .= " WHERE " . implode(' AND ', $whereClauses);
        }

        return $sql;
    }

    /**
     * 生成插入SQL
     * @param $tableName
     * @param $columnsAndValues
     * @return string
     * @author guoliangchen
     * @date 2024/8/21 下午1:35
     */
    public function generateInsertSql($tableName, $columnsAndValues)
    {
        // 初始化 SQL 语句
        $sql = "INSERT INTO `$tableName` (";

        // 处理列名
        $columnNames = [];
        foreach (array_keys($columnsAndValues) as $columnName) {
            $columnNames[] = "`$columnName`";
        }
        $sql .= implode(', ', $columnNames) . ") VALUES (";

        // 处理值
        $values = [];
        foreach ($columnsAndValues as $value) {
            $values[] = "'" . addslashes($value) . "'";
        }
        $sql .= implode(', ', $values) . ");";

        return $sql;
    }

    public function generateBatchInsertSQL($tableName, $fields) {
        $columns = implode(', ', array_keys($fields[0]));

        $values = [];
        foreach ($fields as $row) {
            $rowValues = implode("', '", array_values($row));
            $values[] = "('$rowValues')";
        }

        $valuesString = implode(', ', $values);

        $insertStatement = "INSERT INTO $tableName ($columns) VALUES $valuesString";

        return $insertStatement;
    }
    /**
     * 格式化时间
     * @param $seconds
     * @return string
     * @author guoliangchen
     * @date 2024/4/24 0024 18:47
     */
    function formatSeconds($seconds): string {
        if ($seconds < 60) {
            return "{$seconds}秒";
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $remainingSeconds = $seconds % 60;
            return "{$minutes}分钟，{$remainingSeconds}秒";
        } elseif ($seconds < 86400) {
            $hours = floor($seconds / 3600);
            $remainingSeconds = $seconds % 3600;
            $minutes = floor($remainingSeconds / 60);
            $remainingSeconds %= 60;
            return "{$hours}小时，{$minutes}分钟，{$remainingSeconds}秒";
        } else {
            $days = floor($seconds / 86400);
            $remainingSeconds = $seconds % 86400;
            $hours = floor($remainingSeconds / 3600);
            $remainingSeconds %= 3600;
            $minutes = floor($remainingSeconds / 60);
            $remainingSeconds %= 60;
            return "{$days}天, {$hours}小时, {$minutes}分钟, {$remainingSeconds}秒";
        }
    }

    /**
     *  获取运行状态
     * @param array $param
     * @return string
     * @author guoliangchen
     * @date 2024/4/24 0024 19:20
     */
    private function getRunStatus(array $param): string {
        $id  = $param['id'] ?? [];
        $return_data = ['msg'=>''];
        if (!isset( $this->crontabPool[$id] )){
            $return_data['msg'] = '当前任务未启用';
            return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $return_data]);
        }else{
            if ($this->crontabPool[$id]['is_running']){
                $cost_time = time() - $this->crontabPool[$id]['last_run_time'];
                $run_time_str = $this->formatSeconds($cost_time);
                $return_data['msg'] = '当前任务正在运行中，执行开始时间：'.date('Y-m-d H:i:s',$this->crontabPool[$id]['last_run_time']).",当前执行耗时：".$run_time_str;
            }else{
                $return_data['msg'] = '当前任务未运行';
            }
        }
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $return_data]);
    }

    /**
     * 立即执行
     * @param array $param
     * @return string
     * @author guoliangchen
     * @date 2024/9/13 上午9:54
     */
    private function runNow(array $param): string {
        $id  = $param['id'] ?? 0;
        if (empty($id)){
            $return_data['msg'] = '参数缺失';
            return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $return_data]);
        }
        if (isset($this->runNowCrontabPool[$id])){
            if (!$this->runNowCrontabPool[$id]['is_running']){
                $return_data['msg'] = '定时任务正在准备中，请稍后再试';
            }else{
                $cost_time = time() - $this->runNowCrontabPool[$id]['last_run_time'];
                $run_time_str = $this->formatSeconds($cost_time);
                $return_data['msg'] = '上一次立即执行任务正在运行中，执行开始时间：'.date('Y-m-d H:i:s',$this->runNowCrontabPool[$id]['last_run_time']).",当前执行耗时：".$run_time_str;
            }
            return json_encode(['code' => 200, 'msg' => 'ok', 'data' => $return_data]);
        }
        $db = self::$dbPoll->get();
        $this->execJob($id,$db,[],2);
        self::$dbPoll->put($db);
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['msg'=>"定时任务将在{$this->runNowStartTime}秒后开始执行"]]);
    }

    /**
     * 获取系统信息
     * @param array $param
     * @return string
     * @author guoliangchen
     * @date 2024/9/13 上午10:01
     */
    private function crontabSystem(array $param): string {
        if ($this->serverStartTimeStamp){
            $msg_arr[] = "服务启动时间：".date('Y-m-d H:i:s',$this->serverStartTimeStamp)."，运行时间：".$this->formatSeconds(time() - $this->serverStartTimeStamp);
        }
        // 获取当前内存占用
        $memoryUsage = memory_get_usage();
        // 获取峰值内存占用
        $peakMemoryUsage = memory_get_peak_usage();

        // 将字节转换为 MB
        $memoryUsageMB = number_format($memoryUsage / (1024 * 1024),4);
        $peakMemoryUsageMB = number_format($peakMemoryUsage / (1024 * 1024),4);
        $msg_arr[] = "当前内存占用：{$memoryUsageMB}MB，峰值内存占用：{$peakMemoryUsageMB}MB";
        $running_jobs = 0;
        $running_now_jobs = count($this->runNowCrontabPool);
        foreach ($this->crontabPool as $c){
            if ($c['crontab'] && $c['is_running']){
                $running_jobs++;
            }
        }
        $msg_arr[] = "当前运行中的定时任务：{$running_jobs}，立即执行中的定时任务：{$running_now_jobs}";
        // 计算总运行时间（秒）
        $totalRunTime = time() - $this->serverStartTimeStamp;
        // 计算每小时和每天的平均次数
        $hourlyAverage = 0;
        $dailyAverage = 0;

        if ($totalRunTime > 0) {
            // 每小时的平均次数
            $hourlyAverage = $totalRunTime >= 3600 ? round($this->totalRunJobCount / ($totalRunTime / 3600), 2) : 0;

            // 每天的平均次数
            $dailyAverage = $totalRunTime >= 86400 ? round($this->totalRunJobCount / ($totalRunTime / 86400), 2) : 0;
        }

        // 如果运行时间不足一小时或一天，显示实际运行次数
        if ($totalRunTime < 3600) {
            $hourlyAverage = $this->totalRunJobCount;
        }
        if ($totalRunTime < 86400) {
            $dailyAverage = $this->totalRunJobCount;
        }

        $msg_arr[] = "当前运行定时任务次数：{$this->totalRunJobCount}，每小时平均运行次数{$hourlyAverage} 次，每天平均运行次数：{$dailyAverage} 次";
        return json_encode(['code' => 200, 'msg' => 'ok', 'data' => ['msg'=>implode("<br>",$msg_arr) ]]);
    }
}
