<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:36
 */

namespace xyqWeb\rpc\strategy;


class ParallelRequest extends RequestFactory
{
    /**
     * 验证参数
     *
     * @author xyq
     * @throws RpcException
     */
    protected function checkParams()
    {
        if (count($this->urlArray) == count($this->urlArray, COUNT_RECURSIVE)) {
            throw new RpcException('请调用串行类');
        }
        foreach ($this->urlArray as $key => $url) {
            if (!isset($url['url']) || empty($url['url'])) {
                throw new RpcException('请设置第' . ($key + 1) . '个的URL参数', 500);
            }
            if ((!isset($url['outer']) || !$url['outer']) && strpos($url['url'], '_') !== 0) {
                throw new RpcException('第' . ($key + 1) . '个的URL参数不正确', 500);
            }
            if (!isset($url['method']) || empty($url['method'])) {
                throw new RpcException('请设置第' . ($key + 1) . '个的方法名称或者请求方式');
            }
            if (!isset($url['key']) || is_null($url['key'])) {
                throw new RpcException('请设置第' . ($key + 1) . '个的返回键名');
            }
        }
    }

    /**
     * 向RPC注入参数
     *
     * @author xyq
     */
    protected function setMultiParams()
    {
        $this->rpc->setMultiParams($this->urlArray, $this->token);
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
     * 获取并行请求的数据
     *
     * @author xyq
     * @return array
     * @throws RpcException
     */
    public function get()
    {
        $this->checkParams();
        $this->setMultiParams();
        return $this->rpc->multiGet();
    }
}
