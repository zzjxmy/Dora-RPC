<?php
include "../src/RpcConst.php";
include "../src/Packet.php";
include "../src/RpcMonitor.php";

$config = array(
    "discovery" => array(
        //first reporter
        array(
            "ip" => "127.0.0.1",
            "port" => "6379",
        ),
        //next reporter
        array(
            "ip" => "127.0.0.1",
            "port" => "6379",
        ),
    ),
    //general config path for client
    "config" => "./client.conf.php",

    //log monitor path
    "log" => array(
        "tag1" => array("tag" => "", "path" => "./log/"),
        "tag2" => array("tag" => "", "path" => "./log2/"),
    ),
);

//ok start server
$monitor = new \DWDRPC\RpcMonitor("0.0.0.0", 2103, $config);

$monitor->start();
