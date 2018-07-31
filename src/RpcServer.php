<?php
namespace DWDRPC;

abstract class RpcServer
{

    private $tcpserver = null;
    private $taskInfo = array();

    private $serverIP;
    private $serverPort;

    private $monitorProcess = null;

    protected $tcpConfig = array(
        'reactor_num' => 4,
        'worker_num' => 4,
        'task_worker_num' => 4,
        'task_max_request' => 100000,
        'daemonize' => false,
        'open_length_check' => 1,
        'package_length_type' => 'N',
        'package_length_offset' => 0,
        'package_body_offset' => 4,
        'package_max_length' => 2097152, // 1024 * 1024 * 2,
        'buffer_output_size' => 3145728, //1024 * 1024 * 3,
        'pipe_buffer_size' => 33554432, // 1024 * 1024 * 32,
        'open_tcp_nodelay' => 1,
        'backlog' => 3000,
        'log_file' => '/tmp/rpc_server.log',
    );

    abstract public function initServer($server);

    final public function __construct($ip = "0.0.0.0", $port = 9567)
    {
        $this->tcpserver = new \swoole_server($ip, $port, SWOOLE_BASE, SWOOLE_SOCK_TCP);
        $this->tcpserver->on('Receive', array($this, 'onReceive'));
        $this->tcpserver->on('Start', array($this, 'onStart'));
        $this->tcpserver->on('ManagerStart', array($this, 'onManagerStart'));
        $this->tcpserver->on('ManagerStop', array($this, 'onManagerStop'));
        $this->tcpserver->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->tcpserver->on('WorkerError', array($this, 'onWorkerError'));
        $this->tcpserver->on('Task', array($this, 'onTask'));
        $this->tcpserver->on('Finish', array($this, 'onFinish'));

        //invoke the start
        $this->initServer($this->tcpserver);

        //store current ip port
        $this->serverIP = $ip;
        $this->serverPort = $port;

    }

    /**
     * Configuration Server.必须在start之前执行
     *
     * @param array $config
     * @return $this
     */
    public function configure(array $config)
    {
        $this->tcpConfig = array_merge($this->tcpConfig, $config);

        return $this;
    }

    /**
     * 启动服务发现服务
     * @param array $group
     * @param array $report
     */
    public function discovery(array $group, array $report)
    {
        $self = $this;
        $this->monitorProcess = new \swoole_process(function () use ($group, $report, $self) {
            while (true) {
                // 上报的服务器IP
                $reportServerIP = $self->getLocalIp();
                foreach ($report as $config) {
                    if (trim($config["ip"]) && $config["port"] > 0) {
                        $key = $config["ip"] . "_" . $config["port"];
                        try {
                            if (!isset($_redisObj[$key])) {
                                //if not connect
                                $_redisObj[$key] = new \Redis();
                                $_redisObj[$key]->connect($config["ip"], $config["port"]);
                                if(isset($config['isauth']) && $config['isauth']){
                                    $_redisObj[$key]->auth($config['auth']);
                                }
                            }
                            //register this server
                            $_redisObj[$key]->sadd("rpc.serverlist", json_encode(array(
                                "node" => array(
                                    "ip" => $reportServerIP,
                                    "port" => $self->serverPort
                                ),
                                "group" => $group,
                            )));
                            //set time out
                            $_redisObj[$key]->set("rpc.servertime." . $reportServerIP . "." . $self->serverPort . ".time", time());
                            echo "Reported Service Discovery:" . $config["ip"] . ":" . $config["port"] . PHP_EOL;

                        } catch (\Exception $ex) {
                            $_redisObj[$key] = null;
                            echo "connect to Service Discovery error:" . $config["ip"] . ":" . $config["port"] . PHP_EOL;
                        }
                    }
                    sleep(10);
                }
                }
        });
        $this->tcpserver->addProcess($this->monitorProcess);

    }

    /**
     * Start Server.
     *
     * @return void;
     */
    public function start()
    {
        //config the server config
        $this->tcpserver->set($this->tcpConfig);
        $this->tcpserver->start();
    }

    //application server first start
    final public function onStart(\swoole_server $serv)
    {
//        swoole_set_process_name("dora: master");
        echo "MasterPid={$serv->master_pid}\n";
        echo "ManagerPid={$serv->manager_pid}\n";
        echo "Server: start.Swoole version is [" . SWOOLE_VERSION . "]\n";
    }

    //application server first start
    final public function onManagerStart(\swoole_server $serv)
    {
//        swoole_set_process_name("manager");
    }

    final public function onManagerStop(\swoole_server $serv)
    {
        //echo "Manager Stop , shutdown server\n";
        //$serv->shutdown();
    }

    //worker and task init
    final public function onWorkerStart(\swoole_server $server, $worker_id)
    {
        $istask = $server->taskworker;
        if (!$istask) {
            //worker
//            swoole_set_process_name("worker {$worker_id}");
        } else {
            //task
//            swoole_set_process_name("task {$worker_id}");
            $this->initTask($server, $worker_id);
        }

    }

    abstract public function initTask($server, $worker_id);

    //tcp request process
    final public function onReceive(\swoole_server $serv, $fd, $from_id, $data)
    {
        $requestInfo = Packet::packDecode($data);

        #decode error
        if ($requestInfo["code"] != 0) {
            $req = Packet::packEncode($requestInfo);
            $serv->send($fd, $req);

            return true;
        } else {
            $requestInfo = $requestInfo["data"];
        }

        #api was not set will fail
        if (!is_array($requestInfo["api"]) && count($requestInfo["api"])== 0) {
            $pack = Packet::packFormat($requestInfo["guid"], "param api is empty", 100003);
            $pack = Packet::packEncode($pack);
            $serv->send($fd, $pack);

            return true;
        }
        $guid = $requestInfo["guid"];

        //prepare the task parameter
        $task = array(
            "type" => $requestInfo["type"],
            "guid" => $requestInfo["guid"],
            "fd" => $fd,
            "protocol" => "tcp",
        );

        //different task type process
        switch ($requestInfo["type"]) {

            case RpcConst::SW_MODE_WAITRESULT_SINGLE:
                $task["api"] = $requestInfo["api"]["one"];
                $taskid = $serv->task($task);

                //result with task key
                $this->taskInfo[$fd][$guid]["taskkey"][$taskid] = "one";

                return true;
                break;
            case RpcConst::SW_MODE_NORESULT_SINGLE:
                $task["api"] = $requestInfo["api"]["one"];
                $serv->task($task);

                //return success deploy
                $pack = Packet::packFormat($guid, "transfer success.已经成功投递", 100001);
                $pack = Packet::packEncode($pack);
                $serv->send($fd, $pack);

                return true;

                break;

            case RpcConst::SW_MODE_WAITRESULT_MULTI:
                foreach ($requestInfo["api"] as $k => $v) {
                    $task["api"] = $requestInfo["api"][$k];
                    $taskid = $serv->task($task);
                    $this->taskInfo[$fd][$guid]["taskkey"][$taskid] = $k;
                }

                return true;
                break;
            case RpcConst::SW_MODE_NORESULT_MULTI:
                foreach ($requestInfo["api"] as $k => $v) {
                    $task["api"] = $requestInfo["api"][$k];
                    $serv->task($task);
                }

                $pack = Packet::packFormat($guid, "transfer success.已经成功投递", 100001);
                $pack["guid"] = $task["guid"];
                $pack = Packet::packEncode($pack);

                $serv->send($fd, $pack);

                return true;
                break;
            case RpcConst::SW_CONTROL_CMD:
                switch ($requestInfo["api"]["cmd"]["name"]) {
                    case "getStat":
                        $pack = Packet::packFormat($guid, "OK", 0, array("server" => $serv->stats(), "logqueue" => LogAgent::getQueueStat()));
                        $pack = Packet::packEncode($pack);
                        $serv->send($fd, $pack);
                        return true;

                        break;
                    case "reloadTask":
                        $pack = Packet::packFormat($guid, "OK", 0, array("server" => $serv->stats(), "logqueue" => LogAgent::getQueueStat()));
                        $pack = Packet::packEncode($pack);
                        $serv->send($fd, $pack);
                        $serv->reload();
                        return true;

                        break;
                    default:
                        $pack = Packet::packFormat($guid, "unknow cmd", 100011);
                        $pack = Packet::packEncode($pack);

                        $serv->send($fd, $pack);
                        unset($this->taskInfo[$fd]);
                        break;
                }
                break;

            case RpcConst::SW_MODE_ASYNCRESULT_SINGLE:
                $task["api"] = $requestInfo["api"]["one"];
                $taskid = $serv->task($task);
                $this->taskInfo[$fd][$guid]["taskkey"][$taskid] = "one";

                //return success
                $pack = Packet::packFormat($guid, "transfer success.已经成功投递", 100001);
                $pack = Packet::packEncode($pack);
                $serv->send($fd, $pack);

                return true;
                break;
            case RpcConst::SW_MODE_ASYNCRESULT_MULTI:
                foreach ($requestInfo["api"] as $k => $v) {
                    $task["api"] = $requestInfo["api"][$k];
                    $taskid = $serv->task($task);
                    $this->taskInfo[$fd][$guid]["taskkey"][$taskid] = $k;
                }

                //return success
                $pack = Packet::packFormat($guid, "transfer success.已经成功投递", 100001);
                $pack = Packet::packEncode($pack);

                $serv->send($fd, $pack);
                break;
            default:
                $pack = Packet::packFormat($guid, "unknow task type.未知类型任务", 100002);
                $pack = Packet::packEncode($pack);

                $serv->send($fd, $pack);
                //unset($this->taskInfo[$fd]);

                return true;
        }

        return true;
    }

    final public function onTask($serv, $task_id, $from_id, $data)
    {
        try {
            $data["result"] = Packet::packFormat($data["guid"], "OK", 0, $this->doWork($data));
        } catch (\Exception $e) {
            $data["result"] = Packet::packFormat($data["guid"], $e->getMessage(), $e->getCode());
        }

        return $data;
    }

    abstract public function doWork($param);


    final public function onWorkerError(\swoole_server $serv, $worker_id, $worker_pid, $exit_code)
    {
        //using the swoole error log output the error this will output to the swtmp log
        var_dump("workererror", array($this->taskInfo, $serv, $worker_id, $worker_pid, $exit_code));
    }

    /**
     * 获取当前服务器ip，用于服务发现上报IP
     *
     * @return string
     */
    protected function getLocalIp()
    {
        if ($this->serverIP == '0.0.0.0' || $this->serverIP == '127.0.0.1') {
            $serverIps = swoole_get_local_ip();
            $patternArray = array(
                '10\.',
                '172\.1[6-9]\.',
                '172\.2[0-9]\.',
                '172\.31\.',
                '192\.168\.'
            );
            foreach ($serverIps as $serverIp) {
                // 匹配内网IP
                if (preg_match('#^' . implode('|', $patternArray) . '#', $serverIp)) {
                    return $serverIp;
                }
            }
        }

        return $this->serverIP;
    }

    //task process finished
    final public function onFinish(\swoole_server $serv, $task_id, $data)
    {
        $fd = $data["fd"];
        $guid = $data["guid"];

        //if the guid not exists .it's mean the api no need return result
        if (!isset($this->taskInfo[$fd][$guid])) {
            return true;
        }

        //get the api key
        $key = $this->taskInfo[$fd][$guid]["taskkey"][$task_id];

        //save the result
        $this->taskInfo[$fd][$guid]["result"][$key] = $data["result"];

        //remove the used taskid
        unset($this->taskInfo[$fd][$guid]["taskkey"][$task_id]);

        switch ($data["type"]) {

            case RpcConst::SW_MODE_WAITRESULT_SINGLE:
                $packet = Packet::packFormat($guid, "OK", 0, $data["result"]);
                $packet = Packet::packEncode($packet, $data["protocol"]);
                $serv->send($fd, $packet);
                unset($this->taskInfo[$fd][$guid]);

                return true;
                break;

            case RpcConst::SW_MODE_WAITRESULT_MULTI:
                if (count($this->taskInfo[$fd][$guid]["taskkey"]) == 0) {
                    $packet = Packet::packFormat($guid, "OK", 0, $this->taskInfo[$fd][$guid]["result"]);
                    $packet = Packet::packEncode($packet, $data["protocol"]);
                    $serv->send($fd, $packet);
                    //$serv->close($fd);
                    unset($this->taskInfo[$fd][$guid]);

                    return true;
                } else {
                    //multi call task
                    //not finished
                    //waiting other result
                    return true;
                }
                break;

            case RpcConst::SW_MODE_ASYNCRESULT_SINGLE:
                $packet = Packet::packFormat($guid, "OK", 0, $data["result"]);
                //flag this is result
                $packet["isresult"] = 1;
                $packet = Packet::packEncode($packet, $data["protocol"]);

                //sys_get_temp_dir
                $serv->send($fd, $packet);
                unset($this->taskInfo[$fd][$guid]);

                return true;
                break;
            case RpcConst::SW_MODE_ASYNCRESULT_MULTI:
                if (count($this->taskInfo[$fd][$guid]["taskkey"]) == 0) {
                    $packet = Packet::packFormat($guid, "OK", 0, $this->taskInfo[$fd][$guid]["result"]);
                    $packet["isresult"] = 1;
                    $packet = Packet::packEncode($packet, $data["protocol"]);
                    $serv->send($fd, $packet);

                    unset($this->taskInfo[$fd][$guid]);

                    return true;
                } else {
                    //multi call task
                    //not finished
                    //waiting other result
                    return true;
                }
                break;
            default:
                //
                return true;
                break;
        }

    }

    final public function __destruct()
    {
        echo "Server Was Shutdown..." . PHP_EOL;
        //shutdown
        $this->tcpserver->shutdown();
    }

}
