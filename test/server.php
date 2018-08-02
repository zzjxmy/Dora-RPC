<?php
include "../src/RpcServer.php";

class APIServer extends \DWDRPC\RpcServer
{

    function initServer($server)
    {
        //the callback of the server init 附加服务初始化
        //such as swoole atomic table or buffer 可以放置swoole的计数器，table等
    }

    function doWork($server,$param)
    {
        //process you logical 业务实际处理代码仍这里
        return array("hehe" => "ohyes123");
    }

    function initTask($server, $worker_id)
    {
        //require_once() 你要加载的处理方法函数等 what's you want load (such as framework init)
    }
}

$config = new \Yaf\Config\Ini("../conf/application.ini", 'product');
$rpcConfig = $config->get('rpc.server');
//ok start server
$server = new APIServer($rpcConfig['host'], $rpcConfig['port']);

$server->configure($rpcConfig->toArray());
$redisConfig = $config->get('redis.config')->toArray();
$redisConfig['ip'] = $redisConfig['host'];
$server->discovery(
    array(
        'internalapi', 'marketingcenter'
    ),
    array(
        $redisConfig,
    ));

$server->start();
