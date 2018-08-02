<?php
namespace DWDRPC;

include __DIR__. '/Packet.php';

class RpcClient
{

    //客户端连接池
    private static $client = array();

    //异步连接列表
    private static $asynclist = array();

    //异步结果列表
    private static $asynresult = array();

    //连接配置
    private $serverConfig = array();

    //错误的连接配置
    private $serverConfigBlock = array();

    private $guid;

    //1 随机从指定group名称内选择客户端，2 指定ip进行连接
    private $connectMode = 0;

    private $connectIp = "";
    private $connectPort = 0;

    private $connectGroup = "";

    private $currentClientKey = "";

    public function __construct($serverConfig)
    {
        if (count($serverConfig) == 0) {
            echo "未找到服务器配置";
            throw new \Exception("请配置服务器列表", -1);
        }
        $this->serverConfig = $serverConfig;
    }

    /**
     * 更换连接模式，用于指定ip请求和普通请求切换
     * @param $param
     * @throws \Exception
     */
    public function changeMode($param)
    {
        switch ($param["type"]) {
            case RpcConst::MODEL_DEFAULT:
                if ($param["group"] == "") {
                    throw new \Exception("连接模式切换，未知的group", -1);
                }
                $this->connectMode = RpcConst::MODEL_DEFAULT;
                $this->connectGroup = $param["group"];
                $this->connectIp = "";
                $this->connectPort = "";
                $this->currentClientKey = "";
                break;
            case RpcConst::MODEL_APPOINT:
                if ($param["ip"] == "" || $param["port"] == "") {
                    throw new \Exception("连接模式切换，空的IP OR PORT", -1);
                }
                $this->connectMode = RpcConst::MODEL_APPOINT;
                $this->connectGroup = "default";
                $this->connectIp = $param["ip"];
                $this->connectPort = $param["port"];
                $this->currentClientKey = "";
                break;
            default:
                throw new \Exception("未知的连接模式", -1);
                break;
        }
    }

    /**
     * 返回当前连接模式及相关信息
     * @return array
     * @throws \Exception unknow mode
     */
    public function getConnectMode()
    {
        switch ($this->connectMode) {
            case RpcConst::MODEL_DEFAULT:
                return array(
                    "type" => RpcConst::MODEL_DEFAULT,
                    "group" => $this->connectGroup,
                    "ip" => $this->connectIp,
                    "port" => $this->connectPort
                );
                break;
            case RpcConst::MODEL_APPOINT:
                return array(
                    "type" => RpcConst::MODEL_APPOINT,
                    "group" => "",
                    "ip" => $this->connectIp,
                    "port" => $this->connectPort
                );
                break;
            default:
                throw new \Exception("未知的连接模式", -1);
                break;
        }
    }

    //random get config key
    private function getConfigObjKey()
    {

        if (!isset($this->serverConfig[$this->connectGroup])) {
            throw new \Exception("没有可用服务器", 100010);
        }
        //可用服务器小于错误服务器总数
        //当前连接服务器在错误连接中 则清空错误服务器列表
        if (isset($this->serverConfigBlock[$this->connectGroup]) &&
            count($this->serverConfig[$this->connectGroup]) <= count($this->serverConfigBlock[$this->connectGroup])
        ) {
            $this->serverConfigBlock[$this->connectGroup] = array();
        }

        do {
            $key = array_rand($this->serverConfig[$this->connectGroup]);

            if (!isset($this->serverConfigBlock[$this->connectGroup][$key])) {
                return $key;
            }

        } while (count($this->serverConfig[$this->connectGroup]) > count($this->serverConfigBlock));

        throw new \Exception("没有可用服务器", 100010);

    }

    //get current client
    private function getClientObj()
    {
        $key = "";

        switch ($this->connectMode) {
            case RpcConst::MODEL_DEFAULT:
                $key = $this->getConfigObjKey();
                $clientKey = $this->serverConfig[$this->connectGroup][$key]["ip"] . "_" . $this->serverConfig[$this->connectGroup][$key]["port"];
                //set the current client key
                $this->currentClientKey = $clientKey;
                $connectHost = $this->serverConfig[$this->connectGroup][$key]["ip"];
                $connectPort = $this->serverConfig[$this->connectGroup][$key]["port"];
                break;
            case RpcConst::MODEL_APPOINT:
                $clientKey = trim($this->connectIp) . "_" . trim($this->connectPort);
                $this->currentClientKey = $clientKey;
                $connectHost = $this->connectIp;
                $connectPort = $this->connectPort;
                break;
            default:
                throw new \Exception("current connect mode is unknow", -1);
                break;
        }

        if (!isset(self::$client[$clientKey]) || !self::$client[$clientKey]->isConnected()) {
            $client = new \swoole_client(SWOOLE_SOCK_TCP | SWOOLE_KEEP);
            $client->set(array(
                'open_length_check' => 1,
                'package_length_type' => 'N',
                'package_length_offset' => 0,
                'package_body_offset' => 4,
                'package_max_length' => 1024 * 1024 * 2,
                'open_tcp_nodelay' => 1,
                'socket_buffer_size' => 1024 * 1024 * 4,
            ));

            if (!$client->connect($connectHost, $connectPort, RpcConst::SW_RECIVE_TIMEOUT)) {
                $errorCode = $client->errCode;
                if ($errorCode == 0) {
                    $msg = "连接服务端错误";
                    $errorCode = -1;
                } else {
                    $msg = \socket_strerror($errorCode);
                }

                if ($key !== "") {
                    $this->serverConfigBlock[$this->connectGroup][$key] = 1;
                }

                throw new \Exception($msg . " " . $clientKey, $errorCode);
            }

            self::$client[$clientKey] = $client;
        }

        return self::$client[$clientKey];
    }

    /*
     * mode :
     *      0 代表阻塞等待任务执行完毕拿到结果 ；
     *      1 代表下发任务成功后就返回不等待结果 ；
     *      2 代表下发任务成功后直接返回guid 然后稍晚通过调用阻塞接收函数拿到所有结果
     */
    /**
     * 单api请求
     * @param  string $name api地址
     * @param  array $param 参数
     * @param  int $mode
     * @param  int $retry 通讯错误时重试次数
     * @return mixed  返回单个请求结果
     * @throws \Exception unknow mode type
     */
    public function singleAPI($name, $param, $mode = RpcConst::SW_MODE_WAITRESULT, $retry = 0)
    {
        $this->guid = $this->generateGuid();

        $packet = array(
            'api' => array(
                "one" => array(
                    'name' => $name,
                    'param' => $param,
                )
            ),
            'guid' => $this->guid,
        );

        switch ($mode) {
            case RpcConst::SW_MODE_WAITRESULT:
                $packet["type"] = RpcConst::SW_MODE_WAITRESULT_SINGLE;
                break;
            case RpcConst::SW_MODE_NORESULT:
                $packet["type"] = RpcConst::SW_MODE_NORESULT_SINGLE;
                break;
            case RpcConst::SW_MODE_ASYNCRESULT:
                $packet["type"] = RpcConst::SW_MODE_ASYNCRESULT_SINGLE;
                break;
            default:
                throw new \Exception("未知类型", 100099);
                break;
        }

        $sendData = Packet::packEncode($packet);

        $result = $this->doRequest($sendData, $packet["type"]);

        while ((!isset($result["code"]) || $result["code"] != 0) && $retry > 0) {
            $result = $this->doRequest($sendData, $packet["type"]);
            $retry--;
        }

        if ($this->guid != $result["guid"]) {
            return Packet::packFormat($this->guid, "结果集不匹配", 100100, $result["data"]);
        }

        return $result;
    }

    /**
     * 并发请求api，使用方法如
     * $params = array(
     *  "api_1117"=>array("name"=>"apiname1",“param”=>array("id"=>1117)),
     *  "api_2"=>array("name"=>"apiname2","param"=>array("id"=>2)),
     * )
     * @param  array $params 提交参数 请指定key好方便区分对应结果，注意考虑到硬件资源有限并发请求不要超过50个
     * @param  int $mode
     * @param  int $retry 通讯错误时重试次数
     * @return mixed 返回指定key结果
     * @throws \Exception unknow mode type
     */
    public function multiAPI($params, $mode = RpcConst::SW_MODE_WAITRESULT, $retry = 0)
    {
        //get guid
        $this->guid = $this->generateGuid();

        $packet = array(
            'api' => $params,
            'guid' => $this->guid,
        );

        switch ($mode) {
            case RpcConst::SW_MODE_WAITRESULT:
                $packet["type"] = RpcConst::SW_MODE_WAITRESULT_MULTI;
                break;
            case RpcConst::SW_MODE_NORESULT:
                $packet["type"] = RpcConst::SW_MODE_NORESULT_MULTI;
                break;
            case RpcConst::SW_MODE_ASYNCRESULT:
                $packet["type"] = RpcConst::SW_MODE_ASYNCRESULT_MULTI;
                break;
            default:
                throw new \Exception("未知的类型", 100099);
                break;
        }

        $sendData = Packet::packEncode($packet);

        $result = $this->doRequest($sendData, $packet["type"]);

        while ((!isset($result["code"]) || $result["code"] != 0) && $retry > 0) {
            $result = $this->doRequest($sendData, $packet["type"]);
            $retry--;
        }

        if ($this->guid != $result["guid"]) {
            return Packet::packFormat($this->guid, "结果集不匹配", 100100, $result["data"]);
        }

        return $result;
    }


    private function doRequest($sendData, $type)
    {
        try {
            $client = $this->getClientObj();
        } catch (\Exception $e) {
            $data = Packet::packFormat($this->guid, $e->getMessage(), $e->getCode());
            return $data;
        }

        $ret = $client->send($sendData);

        //ok fail
        if (!$ret) {
            $errorcode = $client->errCode;

            //关闭连接
            self::$client[$this->currentClientKey]->close(true);
            unset(self::$client[$this->currentClientKey]);
            //数据发送失败，则把服务器放入到错误连接服务器中
            $this->serverConfigBlock[$this->connectGroup][$this->currentClientKey] = 1;

            if ($errorcode == 0) {
                $msg = "数据发送失败，请重试";
                $errorcode = -1;
                $packet = Packet::packFormat($this->guid, $msg, $errorcode);
            } else {
                $msg = \socket_strerror($errorcode);
                $packet = Packet::packFormat($this->guid, $msg, $errorcode);
            }

            return $packet;
        }

        //异步结果，将连接放入到异步连接队列中
        if ($type == RpcConst::SW_MODE_ASYNCRESULT_MULTI || $type == RpcConst::SW_MODE_ASYNCRESULT_SINGLE) {
            self::$asynclist[$this->guid] = $client;
        }

        $data = $this->waitResult($client);
        $data["guid"] = $this->guid;
        return $data;
    }

    private function waitResult($client)
    {
        while (true) {
            $result = $client->recv();

            if ($result !== false && $result != "") {
                $data = Packet::packDecode($result);
                //获取的内容不是当前需要的同步结果，则把当前内容放入异步结果集合中
                if ($data["data"]["guid"] != $this->guid) {
                    if (isset(self::$asynclist[$data["data"]["guid"]]) && isset($data["data"]["isresult"]) && $data["data"]["isresult"] == 1) {

                        unset(self::$asynclist[$data["data"]["guid"]]);
                        self::$asynresult[$data["data"]["guid"]] = $data["data"];
                        self::$asynresult[$data["data"]["guid"]]["fromwait"] = 1;
                    } else {
                        continue;
                    }
                } else {
                    return $data['data'];
                }
            } else {
                //time out
                $packet = Packet::packFormat($this->guid, "the recive wrong or timeout", 100009);
                return $packet;
            }
        }
    }

    public function getAsyncData()
    {
        while (true) {
            if (count(self::$asynclist) > 0) {
                foreach (self::$asynclist as $k => $client) {
                    if ($client->isConnected()) {
                        $data = $client->recv();
                        if ($data !== false && $data != "") {
                            $data = Packet::packDecode($data);

                            if (isset(self::$asynclist[$data["data"]["guid"]]) && isset($data["data"]["isresult"]) && $data["data"]["isresult"] == 1) {
                                unset(self::$asynclist[$data["data"]["guid"]]);
                                self::$asynresult[$data["data"]["guid"]] = $data["data"];
                                self::$asynresult[$data["data"]["guid"]]["fromwait"] = 0;
                                continue;
                            } else {
                                continue;
                            }
                        } else {
                            unset(self::$asynclist[$k]);
                            self::$asynresult[$k] = Packet::packFormat($this->guid, "获取异步结果失败：数据获取超时", 100009);
                            continue;
                        }
                    } else {
                        unset(self::$asynclist[$k]);
                        self::$asynresult[$k] = Packet::packFormat($this->guid, "获取异步结果失败: 连接已经关闭.", 100012);
                        continue;
                    }
                }
            } else {
                break;
            }
        }//while

        $result = self::$asynresult;
        self::$asynresult = array();
        return $result;
    }

    public function clearAsyncData()
    {
        self::$asynresult = array();
        self::$asynclist = array();
    }

    private function generateGuid()
    {
        while (true) {
            $guid = md5(microtime(true) . mt_rand(1, 1000000) . mt_rand(1, 1000000));
            if (!isset(self::$asynclist[$guid])) {
                return $guid;
            }
        }
    }


    public function __destruct()
    {

    }
}
