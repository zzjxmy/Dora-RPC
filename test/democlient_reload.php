<?php
include "../src/DoraConst.php";
include "../src/Packet.php";
include "../src/Client.php";

$config = array(
    array("ip" => "2.0.0.1", "port" => 9567),
);

//define the mode
$mode = array("type" => 2, "ip" => "1.0.0.1", "port" => 9567);

$obj = new \DoraRPC\RpcClient($config);
$obj->changeMode($mode);

$ret = $obj->reloadServerTask("127.0.0.1", 9567);
var_dump($ret);

