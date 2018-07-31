<?php
/**
 * Created by PhpStorm.
 * User: zhangzhijian
 * Date: 2018/7/31
 * Time: 下午5:28
 */

namespace DWDRPC;

class AddressMap{
    private static $registerAddress;
    private static $_instance;
    private static $application;
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
        $map = include('../conf/map.php');
        self::$application = \Yaf\Application::app();
        if(!self::$application instanceof \Yaf\Application){
            $path     = APPLICATION_PATH . "/conf/application.ini";
            self::$application = new \Yaf\Application($path);
        }
        $config = self::$application->getConfig();
        foreach ($map as $key => $value){
            self::$registerAddress[md5($config->get($value))] = $key;
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
            $config = include(self::$application->getConfig()->get('rpc.server.config_path'));
            //define the mode
            $mode = array("type" => 1, "group" => $group);
            //new obj
            $obj = new \DWDRPC\RpcClient($config);
            //change connect mode
            $obj->changeMode($mode);
            $this->clients[$group] = $obj;
        }

        return $this->clients[$group];
    }

}