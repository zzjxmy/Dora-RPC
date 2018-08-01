<?php
/**
 * Created by PhpStorm.
 * User: zhangzhijian
 * Date: 2018/8/1
 * Time: 上午9:43
 */

namespace DWDRPC;

include_once __DIR__ .'/RpcServer.php';

class DWDRPCServer extends \DWDRPC\RpcServer
{
    private $yaf;
    public function initServer($server)
    {
        define('CALL_RPC', true);
    }

    public function doWork($server, $param)
    {
        $_GET = isset($param['api']['param']['RPC_GET'])?$param['api']['param']['RPC_GET']:[];
        $_POST = isset($param['api']['param']['RPC_POST'])?$param['api']['param']['RPC_POST']:[];
        $_SERVER = isset($param['api']['param']['RPC_SERVER'])?$param['api']['param']['RPC_SERVER']:[];
        \HttpServer::getInstance()->init();
        try{
            $request = new \Yaf\Request\Http($param['api']['name']);
            $response = $this->yaf->getDispatcher()->returnResponse(true)->dispatch($request);
            $body = $response->getBody();
            $response->clearBody();
            return $body;
        }catch (\Exception $exception){
            return $exception->getMessage();
        }
    }

    function initTask($server, $worker_id)
    {
        if(defined('APP_PATH') && file_exists(APP_PATH)){
            define('APPLICATION_PATH', file_get_contents(APP_PATH));
        }else{
            define('APPLICATION_PATH', APP_START_PATH);
        }
        $path     = APPLICATION_PATH . "/conf/application.ini";
        $configs = new \Yaf\Config\Ini($path, 'product');
        $errLevel = $configs->get('error_level') ? $configs->get('error_level') : 0;
        error_reporting($errLevel);
        if ($configs->get('high-performance.mode') == 'open') {
            define('HIGH_PERFORMANCE', true);
        } else {
            define('HIGH_PERFORMANCE', false);
        }
        require_once APPLICATION_PATH . '/vendor/autoload.php';
        $this->yaf = new \Yaf\Application($configs->toArray());
        require_once APPLICATION_PATH . '/server.php';
        $this->yaf->getDispatcher()->autoRender(false);
        $this->yaf->bootstrap();
    }
}