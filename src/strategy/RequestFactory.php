<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:37
 */

namespace xyqWeb\rpc\strategy;


use Exception;
use xyqWeb\rpc\drivers\RpcStrategy;

abstract class RequestFactory
{
    /**
     * @var null|array|string
     */
    protected $token = null;
    /**
     * @var null|array|string
     */
    protected $urlArray = null;
    /**
     * @var RpcStrategy
     */
    protected $rpc = null;
    /**
     * @var null|array 远程返回的数据
     */
    protected $result = null;

    /**
     * 设置用户登录信息
     *
     * @author xyq
     * @param null $token
     * @throws Exception
     */
    public function setToken($token = null)
    {
        if (is_array($token) || is_string($token) || is_null($token)) {
            $this->token = $token;
        } else {
            throw new Exception('token only accepet array or string or null');
        }
    }

    /**
     * 设置URLS请求参数
     *
     * @author xyq
     * @param array $urlArray url数组
     * @throws Exception
     */
    public function setParams(array $urlArray)
    {
        if (empty($urlArray) || !is_array($urlArray)) {
            throw new Exception('URL参数设置错误');
        }
        $this->urlArray = $urlArray;
    }

    /**
     * 设置RPC类库
     *
     * @author xyq
     * @param $rpc
     */
    public function setRpc(RpcStrategy $rpc)
    {
        $this->rpc = $rpc;
    }

    /**
     * 清理结果
     *
     * @author xyq
     */
    public function cleanResult()
    {
        $this->result = null;
    }

    /**
     * 校验参数
     *
     * @author xyq
     * @return mixed
     */
    abstract protected function checkParams();

    /**
     * 设置RPC请求参数
     *
     * @author xyq
     */
    abstract protected function setMultiParams();

    /**
     * 返回远程获取的数据
     *
     * @author xyq
     * @return array
     */
    abstract public function get();
}