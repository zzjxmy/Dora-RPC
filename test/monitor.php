<?php
include "../src/RpcConst.php";
include "../src/Packet.php";
include "../src/RpcMonitor.php";

$config = new \Yaf\Config\Ini("../conf/application.ini", 'product');
$redisConfig = $config->get('redis.config')->toArray();
$redisConfig['ip'] = $redisConfig['host'];
$setConfig = array(
    "discovery" => array(
        $redisConfig,
    ),
    //general config path for client
    "configPath" => $config->get('rpc.server.config_path')?:'./client.conf.php',
);

//ok start server
$monitor = new \DWDRPC\RpcMonitor(
    $config->get('rpc.server.monitor.host')?:'0.0.0.0',
    $config->get('rpc.server.monitor.port')?:2103,
    $setConfig
);

$monitor->start();
