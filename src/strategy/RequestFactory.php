<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:37
 */

namespace xyqWeb\rpc\strategy;


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
     * @throws RpcException
     */
    public function setToken($token = null)
    {
        if (is_array($token) || is_string($token) || is_null($token)) {
            $this->token = $token;
        } else {
            throw new RpcException('token only accepet array or string or null');
        }
    }

    /**
     * 设置URLS请求参数
     *
     * @author xyq
     * @param array $urlArray url数组
     * @throws RpcException
     */
    public function setParams(array $urlArray)
    {
        if (empty($urlArray) || !is_array($urlArray)) {
            throw new RpcException('URL参数设置错误');
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
     * 向RPC注入自定义options
     *
     * @author xyq
     * @param array $options
     */
    public function setOptions(array $options)
    {
        $this->rpc->setOptions($options);
    }

    /**
     * 清理结果
     *
     * @author xyq
     */
    public function cleanResult()
    {
        $this->result = null;
        $this->urlArray = [];
    }

    /**
     * 保存日志
     *
     * @author xyq
     * @return bool|null
     */
    public function saveLog()
    {
        return $this->rpc->saveLog();
    }

    /**
     * 设置请求标识-uuid
     *
     * @author xyq
     */
    public function setRequestId()
    {
        $chars = md5(uniqid((string)mt_rand(), true));
        $uuid = substr($chars, 0, 8) . '-'
            . substr($chars, 8, 8) . '-'
            . substr($chars, 20, 12);
        $this->rpc->setRequestId($uuid);
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
