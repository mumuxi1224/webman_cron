<?php
/**
 * @author guoliangchen
 * @date 2022年12月26日09:37:00
 * ssh链接的方法
 */

namespace app\service;

use app\model\SystemCrontabNode;
use DivineOmega\SSHConnection\SSHConnection;
use support\Db;

class Ssh {

    /**
     * @var array
     */
    private static $privateKeyContent = [];

    private static $lock;
    /**
     * 获取rsa文件位置
     * @param int $node_id system_crontab_node 主键
     * @return string
     * @author guoliangchen
     * @date 2022/12/26 0026 10:05
     */
    public static function getRsaFilePath(int $node_id): string {
        return app_path('privatekey' . DIRECTORY_SEPARATOR . 'id_rsa_' . $node_id);
    }


    /**
     * 创建rsa文件
     * @param int $node_id
     * @param string $rsa_key
     * @author guoliangchen
     * @date 2022/12/26 0026 10:16
     */
    public static function createRsaFile(int $node_id, string $rsa_key) {
        $file_path = self::getRsaFilePath($node_id);
        $rsa_file  = fopen($file_path, 'w');
        fwrite($rsa_file, $rsa_key);
        fclose($rsa_file);
        \chmod($file_path, 0600);
    }

    /**
     * 链接sshAnd执行command
     * @param array $data
     * @return array
     * @throws \Exception
     * @author guoliangchen
     * @date 2023/1/3 0003 15:52
     */
    public static function createSshAndExecCommand(array $data) {
        $rsa_file = self::getRsaFilePath($data['node_id']);
        if (!isset(self::$privateKeyContent[$data['node_id']])) {
            self::$privateKeyContent[$data['node_id']] = file_get_contents($rsa_file);
        }
        $node_cache = SystemCrontabNode::getNodeCache();
        if (!isset($node_cache[$data['node_id']])){
            return [false, '节点不存在'];
        }
        $data['host'] = $node_cache[$data['node_id']]['host'];
        $data['port'] = $node_cache[$data['node_id']]['port'];
        $data['username'] = $node_cache[$data['node_id']]['username'];
        $data['code_dir'] = $node_cache[$data['node_id']]['code_dir'];

//        $process = \Spatie\Ssh\Ssh::create($data['username'], $data['host'])
//            ->usePort($data['port'])
//            ->usePrivateKey($rsa_file)
//            ->disableStrictHostKeyChecking()
//            ->execute($data['target']);
        $connection = (new SSHConnection())
            ->to($data['host'])
            ->onPort($data['port'])
            ->as($data['username'])
//            ->withPassword('password')
//            ->withPrivateKey($rsa_file)
            ->withPrivateKeyContent(self::$privateKeyContent[$data['node_id']])
            ->timeout(1800)
            ->connect();
        if ($data['code_dir']) {
            $data['target'] = 'cd ' . $data['code_dir'] . ' && ' . $data['target'];
//            $command = $connection->run('cd '.$data['code_dir']);
//            $output = $command->getOutput();
//            $error = $command->getError();
//            if (strpos($error,'No such file or directory')){
//                return [false,'代码运行路径不存在'];
//            }
        }
        $command = $connection->run($data['target']);
        $output  = $command->getOutput();
        $error   = $command->getError();
        $exc = $output . $error;
        if (strpos($exc, 'No such file or directory')!==false) {
            return [true, '请检查代码运行路径' . $data['code_dir'] . '是否存在！-'.$exc];
        }
        if (strpos($exc, 'Could not open input file')!==false) {
            return [true, '入口文件'.$data['index_name'].'不存在！-'.$exc];
        }
//        $connection->disconnect();
        return [$error, $output];
    }

    /**
     * 初始化私钥文件
     * @author guoliangchen
     * @date 2023/9/6 0006 13:47
     */
    public static function buildPrivateKeyContent(){
        $node_list = Db::table('wa_system_crontab_node')->select(['id as node_id'])->get();
        if ($node_list->isNotEmpty()){
            $node_list = $node_list->toArray();
            foreach ($node_list as $n){
                $rsa_file = self::getRsaFilePath($n->node_id);
                self::$privateKeyContent[$n->node_id] = file_get_contents($rsa_file);
            }
        }
    }

}