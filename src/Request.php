<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:31
 */

namespace xyqWeb\rpc;


use xyqWeb\rpc\strategy\RpcException;

class Request
{
    /**
     * @var $_instance
     */
    private static $_instance = null;
    /**
     * @var null|\xyqWeb\rpc\strategy\ParallelRequest|\xyqWeb\rpc\strategy\SerialRequest
     */
    private $object = null;
    /**
     * @var null
     */
    private static $rpc = null;

    /**
     * 初始化Rpc
     *
     * @author xyq
     * @param string $strategy
     * @param array $config
     * @throws RpcException
     */
    public static function initRpc(string $strategy, array $config = [])
    {
        if (!in_array($strategy, ['yar', 'http'])) {
            throw new RpcException('RPC strategy error,only accept yar or http');
        }
        if (isset($config['log']['driver']) && !empty($config['log']['driver']) && defined('APP_ENVIRONMENT') && extension_loaded('phalcon')) {
            $config['log']['driver'] = \Phalcon\DI::getDefault()->get($config['log']['driver']);
        }
        $class = '\xyqWeb\rpc\drivers\\' . ucfirst($strategy);
        self::$rpc = new $class($config);
    }

    /**
     * 单例入口
     *
     * @author xyq
     * @return Request
     */
    public static function init() : Request
    {
        if (is_null(self::$_instance) || !self::$_instance instanceof Request) {
            self::$_instance = new static();
        }
        return self::$_instance;
    }

    /**
     * 设置参数
     *
     * @author xyq
     * @param array $urls URL
     * @param array|string|null $token 用户登录信息
     * @param bool $isParallel 并行标识，false为串行，true为并行
     * @return $this
     * @throws \Exception
     */
    public function setParams(array $urls, $token = null, $isParallel = false)
    {
        if ($isParallel) {
            $className = 'Parallel';
        } else {
            $className = 'Serial';
        }
        $className = '\xyqWeb\rpc\strategy\\' . $className . 'Request';
        $this->object = new $className();
        $this->object->cleanResult();
        $this->object->setParams($urls);
        $this->object->setToken($token);
        $this->object->setRpc(self::$rpc);
        return $this;
    }

    /**
     * 获取远程请求数据
     *
     * @author xyq
     * @return array
     * @throws \Exception
     */
    public function get() : array
    {
        $errMsg = $result = null;
        try {
            $result = $this->object->get();
        } catch (RpcException $e) {
            $errMsg = $e->getMessage();
        } catch (\Exception $e) {
            $errMsg = $e->getMessage();
        } catch (\TypeError $e) {
            $errMsg = $e->getMessage();
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
        }
        $this->object->saveLog();
        if (!is_null($errMsg)) {
            throw new RpcException($errMsg, 500, $errMsg);
        }
        return $result;
    }
}
