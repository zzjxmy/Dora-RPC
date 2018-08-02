<?php
/**
 * Created by PhpStorm.
 * User: zhangzhijian
 * Date: 2018/7/31
 * Time: 下午5:28
 */

namespace DWDRPC;

include_once __DIR__ . '/RpcConst.php';

class RpcKernel{
    private static $registerAddress;
    private static $_instance;
    private static $config;
    private $clients = [];

    const DEFAULT_KEY = 'internalapi';

    public static function getInstance()
    {
        if (!self::$_instance) {
            self::init();
            self::$_instance = new static();
        }

        return self::$_instance;
    }

    private static function init(){
        $map = include(dirname(__DIR__) . '/conf/map.php');
        $application = \Yaf\Application::app();
        if(!$application instanceof \Yaf\Application){
            $path     = APPLICATION_PATH . "/conf/application.ini";
            $application = new \Yaf\Application($path);
        }
        $configObj = $application->getConfig();
        self::$config = $configObj->toArray();
        foreach ($map as $key => $value){
            self::$registerAddress[md5($configObj->get($value))] = $key;
        }
    }

    public function getKeyByUrl($url){
        if(isset(self::$registerAddress[md5($url)])){
            return self::$registerAddress[md5($url)];
        }

        return self::DEFAULT_KEY;
    }

    public function getRegisterAddress(){
        return self::$registerAddress;
    }

    /**
     * @param $url
     * @return mixed
     * @throws \Exception
     */
    public function getClient($url){
        $group = $this->getKeyByUrl($url);
        if(!isset($this->clients[$group])){
            if(isset(self::$config['rpc']['server']['config_path'])){
                $configPath = self::$config['rpc']['server']['config_path'];
            }else{
                $configPath = APPLICATION_PATH . '/conf/client.conf.php';
            }
            $config = include($configPath);
            $mode = array("type" => RpcConst::MODEL_DEFAULT, "group" => $group);
            $obj = new \DWDRPC\RpcClient($this->getConfig());
            $obj->changeMode($mode);
            $this->clients[$group] = $obj;
        }

        return $this->clients[$group];
    }

    public function getClientByKey($key){
        if(!isset($this->clients[$key])){
            $mode = array("type" => RpcConst::MODEL_DEFAULT, "group" => $key);
            $obj = new \DWDRPC\RpcClient($this->getConfig());
            $obj->changeMode($mode);
            $this->clients[$key] = $obj;
        }

        return $this->clients[$key];
    }

    public function getConfig(){
        if(isset(self::$config['rpc']['server']['config_path'])){
            $configPath = self::$config['rpc']['server']['config_path'];
        }else{
            $configPath = APPLICATION_PATH . '/conf/client.conf.php';
        }
        $config = include($configPath);
        return $config;
    }

}