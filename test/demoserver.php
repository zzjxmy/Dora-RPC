<?php
include "../src/DoraConst.php";
include "../src/Packet.php";
include "../src/BackEndServer.php";
include "../src/LogAgent.php";

class APIServer extends \DoraRPC\RpcServer
{

    function initServer($server)
    {
        //the callback of the server init 附加服务初始化
        //such as swoole atomic table or buffer 可以放置swoole的计数器，table等
    }

    function doWork($param)
    {
        //process you logical 业务实际处理代码仍这里
        //return the result 使用return返回处理结果
        //throw new Exception("asbddddfds",1231);
        \DoraRPC\LogAgent::recordLog(\DoraRPC\RpcConst::LOG_TYPE_INFO, "dowork", __FILE__, __LINE__, array("esfs"));
        return array("hehe" => "ohyes123");
    }

    function initTask($server, $worker_id)
    {
        //require_once() 你要加载的处理方法函数等 what's you want load (such as framework init)
    }
}

//ok start server
$server = new APIServer("0.0.0.0", 9567);

$server->configure(array(
    'tcp' => array(
        'reactor_num' => 4,
        'worker_num' => 4,
        'task_worker_num' => 4,
        'task_max_request' => 100000,
        'daemonize' => false,
        'log_file' => '/tmp/sw_server.log',
    ),
    'dora' => array(
        'pid_path' => '/tmp/',//dora 自定义变量，用来保存pid文件
        'master_pid' => 'doramaster.pid', //dora master pid 保存文件
        'manager_pid' => 'doramanager.pid',//manager pid 保存文件
        'log_path' => '/tmp/bizlog/', //业务日志
    ),
));

//redis for service discovery register
//when you on product env please prepare more redis to registe service for high available
$server->discovery(
    array(
        'group1', 'group2'
    ),
    array(
        array(
            array(//first reporter
                "ip" => "127.0.0.1",
                "port" => "6379",
            ),
            array(//next reporter
                "ip" => "127.0.0.1",
                "port" => "6379",
            ),
        ),
    ));

$server->start();
