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
     * @var string
     */
    private static $rpcDriverName = '';
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
        $class = "\\xyqWeb\\rpc\\drivers\\" . ucfirst($strategy);
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
     * @param array|null $userInfo 用户登录信息
     * @param bool $isParallel 并行标识，false为串行，true为并行
     * @return $this
     * @throws \Exception
     */
    public function setParams(array $urls, array $userInfo = null, $isParallel = false)
    {
        if ($isParallel) {
            $className = 'Parallel';
        } else {
            $className = 'Serial';
        }
        $className = "\\xyqWeb\\rpc\\strategy\\" . $className . 'Request';
        $this->object = new $className();
        $this->object->cleanResult();
        $this->object->setParams($urls);
        $this->object->setUserInfo($userInfo);
        $this->object->setRpc(self::$rpc);
        return $this;
    }

    /**
     * 获取远程请求数据
     *
     * @author xyq
     * @return array
     * @throws strategy\RpcException
     * @throws \Exception
     */
    public function get() : array
    {
        return $this->object->get();
    }
}