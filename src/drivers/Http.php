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
        $this->url = $this->getRealUrl($url, $isIndependent);
        $this->requireKey = md5($this->url);
        $this->isIndependent = $isIndependent;
        $this->headers = $this->getHeaders($token, $headers);
        $this->isMulti = false;
        $this->single_options = $options;
        $this->logData[$this->requireKey] = [
            'url'          => $this->url,
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
                $realUrl = $this->getRealUrl($url['url'], $isIndependent);
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

    /**
     * 获取CURL错误信息
     *
     * @author xyq
     * @param int $code curl错误码
     * @return string
     */
    public function getMessage(int $code) : string
    {
        $array = [
            1  => '错误的协议',
            2  => '初始化代码失败',
            3  => 'URL格式不正确',
            4  => '请求协议错误',
            5  => '无法解析代理',
            6  => '无法解析主机地址',
            7  => '无法连接到主机',
            8  => '远程服务器不可用',
            9  => '访问资源错误',
            10 => '连接FTP对方发生错误',
            11 => 'FTP密码错误',
            13 => '结果错误',
            14 => 'FTP回应PASV命令',
            15 => '内部故障',
            17 => '设置传输模式为二进制',
            18 => '文件传输短或大于预期',
            19 => 'RETR命令传输完成',
            21 => '命令成功完成',
            22 => '返回正常',
            23 => '数据写入失败',
            25 => '无法启动上传',
            26 => '回调错误',
            27 => '内存分配请求失败',
            28 => '响应超时',
            30 => 'FTP端口错误',
            31 => 'FTP错误',
            33 => '不支持请求',
            34 => '内部发生错误',
            35 => 'SSL/TLS握手失败',
            36 => '下载无法恢复',
            37 => '文件权限错误',
            38 => 'LDAP可没有约束力',
            39 => 'LDAP搜索失败',
            41 => '函数没有找到',
            42 => '中止的回调',
            43 => '内部错误',
            45 => '接口错误',
            47 => '过多的重定向',
            48 => '无法识别选项',
            49 => 'TELNET格式错误',
            51 => '远程服务器的SSL证书',
            52 => '服务器无返回内容',
            53 => '加密引擎未找到',
            54 => '设定默认SSL加密失败',
            55 => '无法发送网络数据',
            56 => '接收数据失败',
            57 => '未知错误',
            58 => '本地客户端证书',
            59 => '无法使用密码',
            60 => '凭证无法验证',
            61 => '无法识别的传输编码',
            62 => '无效的LDAPURL',
            63 => '文件超过最大大小',
            64 => 'FTP失败',
            65 => '倒带操作失败',
            66 => 'SSL引擎失败',
            67 => '服务器拒绝登录',
            68 => '未找到文件',
            69 => '无权限',
            70 => '超出服务器磁盘空间',
            71 => '非法TFTP操作',
            72 => '未知TFTP传输的ID',
            73 => '文件已经存在',
            74 => '错误TFTP服务器',
            75 => '字符转换失败',
            76 => '必须记录回调',
            77 => 'CA证书权限',
            78 => 'URL中引用资源不存在',
            79 => '错误发生在SSH会话',
            80 => '无法关闭SSL连接',
            81 => '服务未准备',
            82 => '无法载入CRL文件',
            83 => '发行人检查失败',
        ];
        if (array_key_exists($code, $array)) {
            return $array[$code];
        } else {
            return '未知错误类型';
        }
    }

    /**
     * 获取http错误信息
     *
     * @author xyq
     * @param int $code
     * @return mixed|string
     */
    public function getHttpMsg(int $code)
    {
        $array = [
            100 => '客户端应继续其请求',
            101 => '协议错误，请切换到更高级的协议',
            200 => '请求成功',
            201 => '成功请求并创建了新的资源',
            202 => '已经接受请求，但未处理完成',
            203 => '非授权信息',
            204 => '无内容',
            205 => '重置内容',
            206 => '部分内容。服务器成功处理了部分GET请求',
            300 => '多种选择。请求的资源可包括多个位置，相应可返回一个资源特征与地址的列表用于用户终端（例如：浏览器）选择',
            301 => 'URI地址永久重定向',
            302 => 'URI地址临时重定向',
            303 => '查看其它地址',
            304 => '请求的资源未修改',
            305 => '请求的资源必须通过代理访问',
            307 => '请求的资源临时从不同的URI 响应请求',
            400 => '客户端请求的语法错误，服务器无法理解',
            401 => '请求要求用户的身份认证',
            403 => '拒绝访问',
            404 => '服务地址不存在',
            405 => '请求中方法禁止访问',
            406 => '服务器无法根据客户端请求的内容特性完成请求',
            407 => '请求要求代理的身份认证',
            408 => '服务器等待客户端发送的请求时间过长，超时',
            409 => '服务器处理请求时发生了冲突',
            410 => '客户端请求的资源已经不存在',
            411 => '服务器无法处理客户端发送的不带Content-Length的请求信息',
            412 => '客户端请求信息的先决条件错误',
            413 => '请求的实体过大，服务器无法处理',
            414 => '请求的URI过长',
            415 => '服务器无法处理请求附带的媒体格式',
            416 => '客户端请求的范围无效',
            417 => '服务器无法满足Expect的请求头信息',
            499 => '客户端关闭连接',
            500 => '服务器内部错误',
            501 => '服务器不支持请求的功能',
            502 => '无法连接upstream服务',
            503 => '服务超载，请稍后再试',
            504 => 'upstream响应超时',
            505 => '服务器不支持请求的HTTP协议的版本',
        ];
        if (array_key_exists($code, $array)) {
            return $array[$code];
        } else {
            return '';
        }
    }
}
