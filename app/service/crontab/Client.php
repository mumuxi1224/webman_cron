<?php
declare (strict_types=1);

namespace app\service\crontab;

class Client
{

//    private $client;
    protected static $instance = null;

    public function __construct()
    {
//        $this->client = stream_socket_client('tcp://' . getenv('CRONTAB_LISTEN'));
    }

    public static function instance()
    {
        if (!static::$instance) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * @param array $param
     * @return mixed
     */
    public function request(array $param)
    {
        $client = stream_socket_client(
            'tcp://' . getenv('CRONTAB_LISTEN'),
            $errno,
            $errstr,
            5 // 设置连接超时为5秒
        );
        // 设置读写超时
        stream_set_timeout($client, 5); // 读取超时5秒

        fwrite($client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
        $result = fgets($client, 10240000);
        if(!$result){
            fwrite($client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
            $result = fgets($client, 10240000);
//            self::$instance = new static();
        }
        return json_decode($result,true);
    }

}