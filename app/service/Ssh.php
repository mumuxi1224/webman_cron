<?php
/**
 * @author guoliangchen
 * @date 2022年12月26日09:37:00
 * ssh链接的方法
 */
namespace app\service;
class Ssh {

    /**
     * 获取rsa文件位置
     * @param int $node_id system_crontab_node 主键
     * @return string
     * @author guoliangchen
     * @date 2022/12/26 0026 10:05
     */
    public static function getRsaFilePath(int $node_id):string
    {
        return app_path('privatekey'.DIRECTORY_SEPARATOR.'id_rsa_'.$node_id);
    }


    /**
     * 创建rsa文件
     * @param int $node_id
     * @param string $rsa_key
     * @author guoliangchen
     * @date 2022/12/26 0026 10:16
     */
    public static function createRsaFile(int $node_id,string $rsa_key){
        $file_path = self::getRsaFilePath($node_id);
        $rsa_file = fopen($file_path,'w');
        fwrite($rsa_file,$rsa_key);
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
    public static function createSshAndExecCommand(array $data){
        $rsa_file = self::getRsaFilePath($data['node_id']);
        $process = \Spatie\Ssh\Ssh::create($data['username'], $data['host'])
            ->usePort($data['port'])
            ->usePrivateKey($rsa_file)
            ->disableStrictHostKeyChecking()
            ->execute($data['target']);
        $output = '';
        $success = $process->isSuccessful();

        if ($success){
            $output = $process->getOutput();
        }else{
            $output = $process->getErrorOutput();
        }
        return [$success,$output];
    }

}