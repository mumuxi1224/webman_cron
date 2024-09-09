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
//    public function request(array $param)
//    {
//        $client =  stream_socket_client('tcp://' . getenv('CRONTAB_LISTEN'));
//        fwrite($client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
//        $result = fgets($client, 10240000);
//        if(!$result){
//            fwrite($client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
//            $result = fgets($client, 10240000);
////            self::$instance = new static();
//        }
//        return json_decode($result,true);
//    }


    public function request(array $param)
    {
        $listen = config('crontab.task.listen');
        list($host,$port) = explode(':',$listen);
        $client = new \Swoole\Client(SWOOLE_SOCK_TCP);
        if (!$client->connect($host, (int)$port, -1)) {
            return ['code'=>0,'msg'=>"connect failed. Error: {$client->errCode}"];
        }
        $res = $client->send(json_encode($param));
        $recv =  $client->recv();
        $client->close();
        return json_decode($recv,true);
    }
}