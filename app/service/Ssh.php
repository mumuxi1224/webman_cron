<?php
/**
 * @author guoliangchen
 * @date 2022年12月26日09:37:00
 * ssh链接的方法
 */

namespace app\service;

use DivineOmega\SSHConnection\SSHConnection;

class Ssh {

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
            ->withPrivateKey($rsa_file)
             ->timeout(999)
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
            return [true, '请检查代码运行路径' . $data['code_dir'] . '是否存在！'];
        }
        if (strpos($exc, 'Could not open input file')!==false) {
            return [true, '入口文件'.$data['index_name'].'不存在！'];
        }
//        $connection->disconnect();
        return [$error, $output];
    }

    /**
     * 检查要执行的命令是否安全
     * @param string $command
     * @return array
     * @author guoliangchen
     * @date 2023/1/29 0029 17:07
     */
    public static function checkCommandIsDanger(string $command): array {
        if (!$command) {
            return [false, '命令不存在'];
        }
        $command_arr = explode(' ', $command);
        $command_arr = array_filter($command_arr, function ($v) {
            return $v ? true : false;
        });
        if ($command_arr[0] == 'php') {
            unset($command_arr[0]);
        }
        if (!isset($command_arr[1])) {
            return [false, '请输入要执行的定时任务文件！'];
        }
        $new_command = [];
        foreach ($command_arr as $key => $value) {
            if (empty($value)) {
                continue;
            }
            $value = trim($value);


        }
        if (empty($new_command)) {
            return [false, '命令不合法！'];
        }

        return [true, 'ok'];
    }

}