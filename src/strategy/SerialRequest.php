<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:38
 */

namespace xyqWeb\rpc\strategy;


class SerialRequest extends RequestFactory
{
    /**
     * 验证参数验证参数
     *
     * @author xyq
     * @throws RpcException
     */
    protected function checkParams()
    {
        $count = count($this->urlArray) - 1;
        foreach ($this->urlArray as $key => $url) {
            if (!isset($url['url']) || empty($url['url'])) {
                if (!isset($url['outer']) || false == $url['outer']) {
                    if (strpos($url['url'], '_') !== 0) {
                        throw new RpcException('请设置第' . ($key + 1) . '个的URL参数或者参数不正确', 500);
                    }
                }
            }
            if (!isset($url['method']) || empty($url['method'])) {
                throw new RpcException('请设置第' . ($key + 1) . '个的方法名称或者请求方式', 500);
            }
            if (($count > 0 && $key < $count) && (!isset($url['callback']) || empty($url['callback']) || !is_array($url['callback']) || !isset($url['callback'][0]) || !is_object($url['callback'][0]) || !isset($url['callback'][1]) || empty($url['callback'][1]))) {
                throw new RpcException('请正确设置第' . ($key + 1) . '个的回调参数', 500);
            }
        }
    }

    /**
     * 向RPC注入参数并发起请求
     *
     * @author xyq
     * @throws RpcException
     */
    protected function setMultiParams()
    {
        //二维数组
        $params = [];
        $result = '';
        foreach ($this->urlArray as $key => $url) {
            if (!is_array($params)) {
                throw new RpcException('在处理第' . ($key + 1) . '请求时回调参数错误');
            }
            if (isset($url['params']) && is_array($url['params'])) {
                $postParams = $url['params'];
                foreach ($params as $index => $param) {
                    $postParams[$index] = $param;
                }
                $params = [];
            } else {
                $postParams = $params;
            }
            $isIndependent = isset($url['outer']) && true == $url['outer'] ? true : false;
            $headers = isset($url['headers']) && is_array($url['headers']) ? $url['headers'] : [];
            $options = isset($url['options']) && is_array($url['options']) ? $url['options'] : [];
            $result = $this->rpc
                ->setParams($url['url'], $isIndependent, $this->token, $headers, $options)
                ->get($url['method'], $postParams);
            if (!isset($url['callback'])) {
                if (isset($url['key'])) {
                    $this->result[$url['key']] = $result;
                } else {
                    $this->result = $result;
                }
            } else {
                $params = call_user_func_array($url['callback'], ['data' => $result]);
            }
        }
        $this->result = $result;
    }

    /**
     * 返回远程调用数据
     *
     * @author xyq
     * @return array|null
     * @throws RpcException
     */
    public function get()
    {
        $this->checkParams();
        $this->setMultiParams();
        return $this->result;
    }
}
