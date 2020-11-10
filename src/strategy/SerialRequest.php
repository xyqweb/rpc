<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:38
 */

namespace xyqWeb\rpc\strategy;


use Exception;

class SerialRequest extends RequestFactory
{
    /**
     * 验证参数验证参数
     *
     * @author xyq
     * @throws Exception
     */
    protected function checkParams()
    {
        foreach ($this->urlArray as $key => &$url) {
            if (!isset($url['url']) || empty($url['url'])) {
                if (!isset($url['outer']) || false == $url['outer']) {
                    if (strpos($url['url'], '_') !== 0) {
                        throw new Exception('请设置第' . ($key + 1) . '个的URL参数或者参数不正确');
                    }
                }
            }
            if (!isset($url['method']) || empty($url['method'])) {
                throw new Exception('请设置第' . ($key + 1) . '个的方法名称或者请求方式');
            }
            if (!isset($url['params'])) {
                $url['params'] = null;
            }
            if (isset($url['callback']) && (empty($url['callback']) || !is_array($url['callback']))) {
                throw new Exception('请正确设置第' . ($key + 1) . '个的回调参数');
            }
        }
    }

    /**
     * 向RPC注入参数并发起请求
     *
     * @author xyq
     * @throws Exception
     */
    protected function setMultiParams()
    {
        //二维数组
        $params = [];
        $result = '';
        foreach ($this->urlArray as $key => $url) {
            if (!is_array($params)) {
                throw new Exception('在处理第' . ($key + 1) . '请求时回调参数错误');
            }
            if (is_array($url['params'])) {
                $postParams = $url['params'];
                foreach ($params as $index => $param) {
                    $postParams[$index] = $param;
                }
                unset($params);
            } else {
                $postParams = $params;
            }
            $isIndependent = isset($url['outer']) && true == $url['outer'] ? true : false;
            $result = $this->rpc
                ->setParams($url['url'], $isIndependent, $this->token, isset($url['headers']) && is_array($url['headers']) ? $url['headers'] : [])
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
     * @throws Exception
     */
    public function get()
    {
        $this->checkParams();
        $this->setMultiParams();
        return $this->result;
    }
}