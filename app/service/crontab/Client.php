<?php
declare (strict_types=1);

namespace app\service\crontab;

class Client
{

    private $client;
    protected static $instance = null;

    public function __construct()
    {
        $this->client = stream_socket_client('tcp://' . getenv('CRONTAB_LISTEN'));
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
        fwrite($this->client, json_encode($param) . "\n"); // text协议末尾有个换行符"\n"
        $result = fgets($this->client, 10240000);
        return json_decode($result,true);
    }

}