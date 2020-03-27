<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:24
 */

namespace xyqWeb\rpc\drivers;


use xyqWeb\rpc\strategy\RpcException;

class Yar extends RpcStrategy
{
    /**
     * @var null|array $urls yar并行请求的URL数组
     */
    protected $urls = null;

    /**
     * 设置URL、userInfo等参数
     *
     * @author xyq
     * @param string $url 请求地址
     * @param array $userInfo 用户登录数据
     * @return $this
     * @throws \Exception
     */
    public function setParams(string $url, array $userInfo = null)
    {
        //URL最前面加上_是为了兼容线上URL地址，强制执行
        $this->client = new \Yar_Client($this->getRealUrl($url));
        $this->client->SetOpt(YAR_OPT_PERSISTENT, true);
        $this->client->SetOpt(YAR_OPT_HEADER, $this->getHeaders($userInfo));
        $this->client->SetOpt(YAR_OPT_PACKAGER, $this->params['yarPackageType']);
        $this->client->SetOpt(YAR_OPT_TIMEOUT, $this->params['timeout']);
        $this->isMulti = false;
        return $this;
    }

    /**
     * 发送并行请求
     *
     * @author xyq
     * @param array $urls
     * @param array $userInfo 用户数据
     * @return $this
     * @throws \Exception
     */
    public function setMultiParams(array $urls, array $userInfo = null)
    {
        \Yar_Concurrent_Client::reset();
        $this->result = null;
        $this->urls = null;
        $this->isMulti = true;
        $header = [
            YAR_OPT_PERSISTENT => true,
            YAR_OPT_HEADER     => $this->getHeaders($userInfo),
            YAR_OPT_PACKAGER   => $this->params['yarPackageType'],
            YAR_OPT_TIMEOUT    => $this->params['timeout'],
        ];
        $urls = array_values($urls);
        foreach ($urls as $key => $url) {
            \Yar_Concurrent_Client::call($this->getRealUrl($url['url']), $url['method'], isset($url['params']) ? ['params' => $url['params']] : null, null, null, $header);
        }
        $this->urls = $urls;
        return $this;
    }


    /**
     * 获取最终数据
     *
     * @author xyq
     * @param string $method 对地址中service内的方法名称
     * @param null|int|string|array $data 传输的参数
     * @return array
     * @throws RpcException
     */
    public function get(string $method, $data = null) : array
    {
        try {
            if (!$this->client instanceof \Yar_Client) {
                throw new \Exception('必须先设置URI！');
            }
            $result = $this->client->{$method}($data);
            return $this->formatResponse($result);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $msg = str_replace('malformed response header ', '', $msg);
            return $this->formatResponse(trim($msg, "'"), $e->getCode());
        }
    }

    /**
     * 格式化响应数据
     *
     * @author xyq
     * @param string $msg 响应过来的消息
     * @param int $code 响应code
     * @param string $key 并行请求的key值
     * @return array|string
     * @throws RpcException
     */
    protected function formatResponse(string $msg, int $code = 2, $key = '')
    {
//        var_dump($msg, $code, $key);die;
        if ($code == 2) {
            $result = json_decode(trim($msg, "'"), true);
            if (!isset($result['status']) || !isset($result['msg'])) {
                $exceptionMsg = empty($key) ? '' : "键值{$key}：";
                $exceptionMsg .= "响应数据结构不合规范";
                throw new RpcException($exceptionMsg, $code, $exceptionMsg);
            }
            return $result;
        } elseif ($code == 16) {
            $finalMsg = trim(str_replace('server responsed non-200 code ', '', $msg), "''");
            if (404 == $finalMsg) {
                $msg = "服务URL地址不存在";
            } elseif (500 == $finalMsg) {
                $msg = '服务内部错误';
            } else {
                $msg = '服务异常：' . $finalMsg;
            }
            $exceptionMsg = empty($key) ? '' : "键值{$key}：";
            if (is_int(strpos($msg, 'Timeout was reached'))) {
                $msg = '服务响应超时';
            }
            $exceptionMsg .= $msg;
            throw new RpcException($exceptionMsg, $code, $exceptionMsg);
        } else {
            $exceptionMsg = empty($key) ? '' : "键值{$key}：";
            $exceptionMsg .= '服务异常：' . $msg;
            throw new RpcException($exceptionMsg, $code, $exceptionMsg);
        }
    }

    /**
     * 获取并行请求的数组
     *
     * @author xyq
     * @return array
     * @throws \Exception
     */
    public function multiGet() : array
    {
        \Yar_Concurrent_Client::loop(
        //成功的回调函数zz
            function ($data, $callInfo) {
                if ($callInfo != NULL) {
                    $key = $this->urls[$callInfo['sequence'] - 1]['key'];
                    $this->result[$key] = $this->formatResponse($data, 2, $key);
                }
            },
            //失败的回调函数
            function ($type, $error, $callInfo) {
                $key = $this->urls[$callInfo['sequence'] - 1]['key'];
                $error = str_replace('malformed response header ', '', $error);
                $this->result[$key] = $this->formatResponse(!is_string($error) ? json_encode($error) : $error, $type, $key);
            });
        return ['status' => 1, 'msg' => '获取成功', 'data' => $this->result];
    }
}