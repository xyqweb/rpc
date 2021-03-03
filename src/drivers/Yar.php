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
     * 设置URL、token等参数
     *
     * @author xyq
     * @param string $url 请求地址
     * @param bool $isIndependent 独立站点标识
     * @param array|string|null $token 用户登录数据
     * @param array $headers 自定义headers
     * @return $this
     * @throws \Exception
     */
    public function setParams(string $url, bool $isIndependent = false, $token = null, array $headers = [])
    {
        $this->request_time = microtime(true);
        $this->isMulti = false;
        //URL最前面加上_是为了兼容线上URL地址，强制执行
        $realUrl = $this->getRealUrl($url, $isIndependent);
        $headers = $this->getHeaders($token, $headers, ':');
        $this->client = new \Yar_Client($realUrl);
        $this->client->SetOpt(YAR_OPT_PERSISTENT, true);
        $this->client->SetOpt(YAR_OPT_HEADER, $headers);
        $this->client->SetOpt(YAR_OPT_PACKAGER, $this->params['yarPackageType']);
        if (isset($this->params['timeout']) && is_int($this->params['timeout'])) {
            ini_set('yar.timeout', (string)$this->params['timeout']);
        }
        if (isset($this->params['connect_timeout']) && is_int($this->params['connect_timeout'])) {
            ini_set('yar.connect_timeout', (string)$this->params['connect_timeout']);
        }
        $this->requireKey = md5($realUrl);
        $this->logData[$this->requireKey] = [
            'url'          => $realUrl,
            'header'       => $headers,
            'request_time' => $this->request_time,
        ];
        return $this;
    }

    /**
     * 发送并行请求
     *
     * @author xyq
     * @param array $urls
     * @param array|string|null $token 用户数据
     * @return $this|mixed
     * @throws RpcException
     * @throws \Exception
     */
    public function setMultiParams(array $urls, $token = null)
    {
        \Yar_Concurrent_Client::reset();
        $this->result = $this->urls = null;
        $this->isMulti = true;
        $this->request_time = microtime(true);
        $header = [
            YAR_OPT_PERSISTENT => true,
            YAR_OPT_PACKAGER   => $this->params['yarPackageType'],
        ];
        if (isset($this->params['timeout']) && is_int($this->params['timeout'])) {
            ini_set('yar.timeout', (string)$this->params['timeout']);
        }
        if (isset($this->params['connect_timeout']) && is_int($this->params['connect_timeout'])) {
            ini_set('yar.connect_timeout', (string)$this->params['connect_timeout']);
        }
        $urls = array_values($urls);
        foreach ($urls as $key => $url) {
            $urlKey = $url['key'];
            $isIndependent = isset($url['outer']) && $url['outer'] ? true : false;
            $header[YAR_OPT_HEADER] = $this->getHeaders($token, isset($url['headers']) && is_array($url['headers']) ? $url['headers'] : [], ':');
            $realUrl = $this->getRealUrl($url['url'], $isIndependent);
            $this->logData[md5('key' . $urlKey)] = [
                'url'          => $realUrl,
                'header'       => $header,
                'params'       => $url['params'],
                'request_time' => $this->request_time,
                'method'       => $url['method'],
            ];
            \Yar_Concurrent_Client::call($realUrl, $url['method'], isset($url['params']) ? ['params' => $url['params']] : null,
                function ($data) use ($urlKey) {
                    $this->formatCallback($data, 2, $urlKey);
                }, function ($type, $error) use ($urlKey) {
                    $this->formatCallback($error, intval($type), $urlKey);
                }, $header);
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
        $e = $result = null;
        try {
            if (!$this->client instanceof \Yar_Client) {
                throw new \Exception('必须先设置URI！');
            }
            $this->logData[$this->requireKey]['method'] = $method;
            $this->logData[$this->requireKey]['params'] = $data;
            $result = $this->client->{$method}($data);
            if (!empty($result) && is_string($result)) {
                $result = $this->formatResponse($result);
            }
        } catch (\Exception $e) {
        } catch (\Throwable $e) {
        }
        if (is_object($e)) {
            $msg = str_replace('malformed response header ', '', $e->getMessage());
            $result = $this->formatResponse($msg, (int)$e->getCode());
            $this->logData[$this->requireKey]['origin_response'] = $e->getMessage();
            unset($e);
        }
        $this->logData[$this->requireKey]['use_time'] = microtime(true) - $this->request_time;
        $this->logData[$this->requireKey]['result'] = $result;
        if (!is_array($result)) {
            if ($this->display_error) {
                throw new RpcException($result, 500);
            } else {
                $result = [$this->code_key => 0, $this->msg_key => $result];
            }
        }
        return $result;
    }

    /**
     * 格式化响应数据
     *
     * @author xyq
     * @param string $msg 响应过来的消息
     * @param int $code 响应code
     * @param string $key 并行请求的key值
     * @return array|string
     */
    protected function formatResponse(string $msg, int $code = 2, $key = '')
    {
        if (2 == $code) {
            $result = json_decode(trim($msg, "'"), true);
            if (!is_array($result)) {
                $exceptionMsg = empty($key) ? '' : "键值{$key}：";
                $exceptionMsg .= '响应数据结构不合规范';
                return $exceptionMsg;
            }
            return $result;
        } elseif (16 == $code) {
            $finalMsg = trim(str_replace('server responsed non-200 code ', '', $msg), "''");
            if (404 == $finalMsg) {
                $msg = '服务URL地址不存在';
            } elseif (500 == $finalMsg) {
                $msg = '服务内部错误';
            } else {
                $msg = '服务异常-' . $finalMsg;
            }
            $exceptionMsg = empty($key) ? '' : "键值{$key}:";
            if (is_int(strpos($msg, 'Timeout was reached'))) {
                $msg = '服务响应超时';
            } elseif (is_int(strpos($msg, "Couldn't connect to server"))) {
                $msg = '连接服务失败';
            }
            $exceptionMsg .= $msg;

        } elseif (64 == $code) {
            $exceptionMsg = empty($key) ? '' : "键值{$key}：";
            $temp = json_decode($msg, true);
            if (is_array($temp) && isset($temp['message'])) {
                $exceptionMsg .= '服务异常：' . $temp['message'];
            } else {
                $exceptionMsg .= '服务异常-' . $msg;
            }
        } else {
            $exceptionMsg = empty($key) ? '' : "键值{$key}：";
            $exceptionMsg .= '服务异常-' . $msg;

        }
        return $exceptionMsg;
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
        $status = @\Yar_Concurrent_Client::loop();
        if (!$status) {
            $error = error_get_last();
            if (strpos($error['message'], 'select timeout')) {
                $errorMsg = ($this->params['timeout'] / 1000) < (microtime(true) - $this->request_time) ? '服务响应超时' : '连接服务失败';
            } else {
                $errorMsg = $error['message'];
            }
            foreach ($this->urls as $url) {
                if (!isset($this->result[$url['key']])) {
                    $logKey = md5('key' . $url['key']);
                    $this->result[$url['key']] = $this->display_error ? $errorMsg : [$this->code_key => 0, $this->msg_key => $errorMsg];
                    $this->logData[$logKey]['use_time'] = microtime(true) - $this->request_time;
                    $this->logData[$logKey]['origin_response'] = $error['message'];
                    $this->logData[$logKey]['result'] = $errorMsg;
                }
            }
        }
        if ($this->display_error) {
            foreach ($this->result as $item) {
                if (!is_array($item)) {
                    throw new RpcException($item['msg'], 500);
                }
            }
        }
        return ['status' => 1, 'msg' => '获取成功', 'data' => $this->result];
    }

    /**
     * 格式化响应结果
     *
     * @author xyq
     * @param $data
     * @param int $type
     * @param int|string $key
     */
    private function formatCallback($data, int $type, $key)
    {
        if (!is_string($data)) {
            $data = json_encode($data);
        }
        $result = $this->formatResponse($data, $type, $key);
        $logKey = md5('key' . $key);
        $this->logData[$logKey]['use_time'] = microtime(true) - $this->request_time;
        $this->logData[$logKey]['result'] = $result;
        if (!is_array($result)) {
            $this->logData[$logKey]['origin_response'] = $data;
            if (!$this->display_error) {
                $result = [$this->code_key => 0, $this->msg_key => $result];
            }
        }
        $this->result[$key] = $result;
    }
}
