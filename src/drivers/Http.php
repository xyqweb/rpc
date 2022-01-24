<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:31
 */

namespace xyqWeb\rpc\drivers;


use GuzzleHttp\Client;
use GuzzleHttp\TransferStats;
use xyqWeb\rpc\strategy\RpcException;

class Http extends RpcStrategy
{
    /**
     * @var string 串行请求URL地址
     */
    private $url;
    /**
     * @var array 串行请求头数组
     */
    private $headers;
    /**
     * @var string 代理配置
     */
    private $proxy = '';
    /**
     * @var bool 串行请求的外网标识
     */
    private $isIndependent = false;
    /**
     * @var array 单个请求自定义options
     */
    private $single_options = [];

    /**
     * 获取客户端地址
     *
     * @author xyq
     */
    public function getClient()
    {
        if (isset($this->params['proxy']) && isset($this->params['proxy']['host']) && '0.0.0.0' != $this->params['proxy']['host'] && isset($this->params['proxy']['port']) && $this->params['proxy']['port'] > 0) {
            $this->proxy = $this->params['proxy']['host'] . ':' . $this->params['proxy']['port'];
        } else {
            $this->proxy = '';
        }
        if (!($this->client instanceof Client)) {
            $connectTimeout = 1;
            $timeoutSetting = $this->getTimeout(false);
            if (is_int($timeoutSetting['connect_timeout'])) {
                $connectTimeout = $timeoutSetting['connect_timeout'];
            }
            $responseTimeout = 5;
            if (is_int($timeoutSetting['timeout'])) {
                $responseTimeout = $timeoutSetting['timeout'];
            }
            $this->client = new Client(['connect_timeout' => $connectTimeout, 'timeout' => $responseTimeout]);
        }
    }

    /**
     * 设置URL,token等参数
     *
     * @author xyq
     * @param string $url 请求地址
     * @param bool $isIndependent 独立站点标识
     * @param array|string|null $token 用户登录数据
     * @param array $headers 自定义headers
     * @param array $options 自定义options
     * @return $this
     * @throws \Exception
     */
    public function setParams(string $url, bool $isIndependent = false, $token = null, array $headers = [], array $options = [])
    {
        //URL最前面加上_是为了兼容线上URL地址，强制执行
        $this->getClient();
        $this->request_time = microtime(true);
        $urlResult = $this->getRealUrl($url, $isIndependent, 'http');
        $this->url = $urlResult['real_url'];
        if (!empty($urlResult['real_host'])) {
            $headers['host'] = $urlResult['real_host'];
        }
        $this->requireKey = md5($this->url);
        $this->isIndependent = $isIndependent;
        $this->headers = $this->getHeaders($token, $headers);
        $this->isMulti = false;
        $this->single_options = $options;
        $this->logData[$this->requireKey] = [
            'headers'      => $this->headers,
            'request_time' => $this->request_time,
            'request_uri'  => $this->getRequestUri(),
        ];
        return $this;
    }

    /**
     * 发送并行请求
     *
     * @author xyq
     * @param array $urls
     * @param null $token
     * @return $this|mixed
     * @throws \Throwable
     */
    public function setMultiParams(array $urls, $token = null)
    {
        $this->getClient();
        $this->request_time = microtime(true);
        $this->result = null;
        $this->isMulti = true;
        $options = ['http_errors' => false];
        $promises = [];
        $key = null;
        $request_uri = $this->getRequestUri();
        try {
            foreach ($urls as $url) {
                $isIndependent = isset($url['outer']) && $url['outer'] ? true : false;
                $key = $url['key'];
                $urlResult = $this->getRealUrl($url['url'], $isIndependent, 'http');
                $realUrl = $urlResult['real_url'];
                if (!empty($urlResult['real_host'])) {
                    $url['headers']['host'] = $urlResult['real_host'];
                }
                $method = strtoupper($url['method']);
                $url['params'] = $url['params'] ?? [];
                if ('GET' == $method) {
                    $realUrl .= (is_int(strpos($realUrl, '?')) ? '&' : '?') . http_build_query($url['params']);
                    if (isset($options['json'])) {
                        unset($options['json']);
                    }
                } else {
                    $options['json'] = $url['params'];
                }
                $realUrl .= $this->getSign($realUrl);
                $needProxy = $this->needProxy($isIndependent, $realUrl);
                if ($needProxy && !empty($this->proxy)) {
                    $options['proxy'] = $this->proxy;
                }
                $options['headers'] = $this->getHeaders($token, isset($url['headers']) && is_array($url['headers']) ? $url['headers'] : []);
                if (isset($url['options']) && !empty($url['options']) && is_array($url['options'])) {
                    $options = $options + $url['options'];
                }
                $this->logData[$key] = [
                    'url'          => $realUrl,
                    'method'       => $method,
                    'proxy'        => $options['proxy'] ?? [],
                    'headers'      => $options['headers'],
                    'params'       => $url['params'],
                    'request_time' => $this->request_time,
                    'request_uri'  => $request_uri,
                ];
                $options['on_stats'] = function (TransferStats $stats) use ($key) {
                    $this->logData[$key]['use_time'] = $stats->getTransferTime();
                };
                $promises[$key] = $this->client->requestAsync($method, $realUrl, $options);
            }
            $this->result = $promises;
        } catch (\Exception $e) {
            $this->result[$key] = $e;
        }
        return $this;
    }

    /**
     * 发起请求并返回结果
     *
     * @author xyq
     * @param string $method 对地址中service内的方法名称
     * @param null $data 传输的参数
     * @return array
     * @throws RpcException
     */
    public function get(string $method, $data = null) : array
    {
        $result = null;
        try {
            if (!$this->client instanceof Client) {
                throw new RpcException('必须先设置URI！', 500);
            }
            $method = strtoupper($method);
            $options = [
                'headers'     => $this->headers,
                'http_errors' => false,
            ];
            if (!empty($this->single_options)) {
                $options = $options + $this->single_options;
                $this->single_options = [];
            }
            $needProxy = $this->needProxy($this->isIndependent, $this->url);
            if ($needProxy && !empty($this->proxy)) {
                $options['proxy'] = $this->proxy;
            }
            $realUrl = $this->url;
            if ('GET' == $method) {
                $realUrl .= (is_int(strpos($this->url, '?')) ? '&' : '?') . http_build_query($data);
            } else {
                $options['json'] = $data;
            }
            $realUrl .= $this->getSign($realUrl);
            $this->logData[$this->requireKey]['url'] = $realUrl;
            $this->logData[$this->requireKey]['method'] = $method;
            $this->logData[$this->requireKey]['proxy'] = $options['proxy'] ?? [];
            $this->logData[$this->requireKey]['params'] = $data;
            $result = $this->client->request($method, $realUrl, $options);
            $responseCode = $result->getStatusCode();
            $responseContent = $result->getBody()->getContents();
            $result = $this->formatResponse($responseContent, (int)$responseCode);
            //如果响应结果不正确，记录下原始结果
            if (!is_array($result)) {
                $this->logData[$this->requireKey]['origin_response'] = $responseContent;
            }
            unset($responseContent);
        } catch (\Throwable $e) {
            $this->logData[$this->requireKey]['origin_response'] = $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
            $result = $this->formatResponse($e->getMessage(), (int)$e->getCode());
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
    protected function formatResponse(string $msg, int $code = 200, $key = '')
    {
        $exceptionMsg = empty($key) ? '' : "键值{$key}：";
        if ($code == 200) {
            $result = json_decode($msg, true);
            if (!is_array($result)) {
                $exceptionMsg .= "响应数据结构不合规范";
                return $exceptionMsg;
            }
            return $result;
        } else {
            $httpMsg = $this->getHttpMsg($code);
            if (empty($httpMsg)) {
                $temp = current(explode(':', $msg));
                preg_match('/\d+/', $temp, $arr);
                if (!empty($arr)) {
                    $exceptionMsg .= '服务异常-' . $this->getMessage(intval($arr[0]));
                } else {
                    $exceptionMsg .= '服务异常-' . $msg;
                }
                unset($temp, $arr);
            } else {
                $exceptionMsg .= '服务异常-' . $httpMsg;
            }
        }
        unset($msg, $code, $key, $httpMsg);
        return $exceptionMsg;
    }

    /**
     * 获取并行请求的数组
     *
     * @author xyq
     * @return array
     * @throws RpcException
     */
    public function multiGet() : array
    {
        $requestResult = [];
        foreach ($this->result as $key => $promise) {
            if ($promise instanceof \Exception) {
                $errCode = (int)$promise->getCode();
                $responseContent = $promise->getMessage() . ':' . $promise->getFile() . ':' . $promise->getLine();
                $responseData = $this->formatResponse($responseContent, $errCode);
            } else {
                try {
                    /** @var $promise \GuzzleHttp\Promise\PromiseInterface */
                    $response = $promise->wait();
                    $responseCode = (int)$response->getStatusCode();
                    $responseContent = $response->getBody()->getContents();
                    $responseData = $this->formatResponse($responseContent, $responseCode);
                    //如果响应结果为正确的话，原始响应信息记录实际上是无效的
                    if (is_array($responseData)) {
                        $responseContent = [];
                    }
                } catch (\Exception $e) {
                    $errCode = (int)$e->getCode();
                    $responseContent = $e->getMessage() . ':' . $e->getFile() . ':' . $e->getLine();
                    $responseData = $this->formatResponse($responseContent, $errCode);
                }
            }
            $this->logData[$key]['result'] = $requestResult[$key] = $responseData;
            $this->logData[$key]['origin_response'] = $responseContent;
            unset($responseContent, $responseData);
        }
        $finalResult = [];
        foreach ($requestResult as $key => $item) {
            if (!is_array($item)) {
                if ($this->display_error) {
                    throw new RpcException($item, 500);
                } else {
                    $item = [$this->code_key => 0, $this->msg_key => $item];
                }
            }
            $finalResult[$key] = $item;
        }
        return ['status' => 1, 'msg' => '获取成功', 'data' => $finalResult];
    }
}
