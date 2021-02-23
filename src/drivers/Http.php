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
use GuzzleHttp\Exception\GuzzleException;
use function GuzzleHttp\Promise\unwrap;
use GuzzleHttp\TransferStats;
use Psr\Http\Message\ResponseInterface;
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
     * 获取客户端地址
     *
     * @author xyq
     */
    public function getClient()
    {
        if (isset($this->params['proxy']) && isset($this->params['proxy']['host']) && !empty($this->params['proxy']['host']) && isset($this->params['proxy']['port']) && !empty($this->params['proxy']['port'])) {
            $this->proxy = $this->params['proxy']['host'] . ':' . $this->params['proxy']['port'];
        }
        if (!($this->client instanceof Client)) {
            $connectTimeout = 1;
            if (isset($this->params['connect_timeout'])) {
                if ($this->params['connect_timeout'] > 1000) {
                    $connectTimeout = $this->params['connect_timeout'] / 1000;
                } else {
                    $connectTimeout = $this->params['connect_timeout'];
                }
            }
            $responseTimeout = 5;
            if (isset($this->params['timeout'])) {
                if ($this->params['timeout'] > 1000) {
                    $responseTimeout = $this->params['timeout'] / 1000;
                } else {
                    $responseTimeout = $this->params['timeout'];
                }
            }
            $this->client = new Client(['connect_timeout' => $connectTimeout, 'timeout' => $responseTimeout]);
        }
    }

    /**
     * 设置URL、token等参数
     *
     * @author xyq
     * @param string $url 请求地址
     * @param bool $isIndependent 独立站点标识
     * @param array|string|null $token 用户登录数据
     * @param array $headers
     * @return $this
     * @throws \Exception
     */
    public function setParams(string $url, bool $isIndependent = false, $token = null, array $headers = [])
    {
        $this->request_time = microtime(true);
        //URL最前面加上_是为了兼容线上URL地址，强制执行
        $this->getClient();
        $this->url = $this->getRealUrl($url, $isIndependent);
        $this->requireKey = md5($this->url);
        $this->isIndependent = $isIndependent;
        $this->headers = $this->getHeaders($token, $headers);
        $this->isMulti = false;
        $this->logData[$this->requireKey] = [
            'url'          => $this->url,
            'headers'      => $this->headers,
            'request_time' => $this->request_time,
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
        $options = [];
        $promises = [];
        $key = null;
        try {
            foreach ($urls as $url) {
                $isIndependent = isset($url['outer']) && $url['outer'] ? true : false;
                $key = $url['key'];
                $realUrl = $this->getRealUrl($url['url'], $isIndependent);
                $method = strtoupper($url['method']);
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
                $this->logData[$key] = [
                    'url'          => $realUrl,
                    'method'       => $method,
                    'proxy'        => $options['proxy'] ?? [],
                    'headers'      => $options['headers'],
                    'params'       => $options['json'] ?? [],
                    'request_time' => $this->request_time,
                ];
                $options['on_stats'] = function (TransferStats $stats) use ($key) {
                    $this->logData[$key]['use_time'] = $stats->getTransferTime();
                };
                $promises[$key] = $this->client->requestAsync($method, $realUrl, $options);
            }
            $this->result = unwrap($promises);
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
        $e = $result = null;
        try {
            if (!$this->client instanceof Client) {
                throw new RpcException('必须先设置URI！', 500);
            }
            $method = strtoupper($method);
            $options = [
                'headers' => $this->headers,
            ];
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
            $this->logData[$this->requireKey]['params'] = $options['params'] ?? [];
            $result = $this->client->request($method, $realUrl, $options);
            $responseCode = $result->getStatusCode();
            $result = $this->formatResponse($result->getBody()->getContents(), (int)$responseCode);
        } catch (GuzzleException $e) {
        } catch (\Exception $e) {
        } catch (\TypeError $e) {
        } catch (\Throwable $e) {
        }
        if (is_object($e)) {
            $result = $this->formatResponse($e->getMessage(), (int)$e->getCode());
            unset($e);
        }
        $this->logData[$this->requireKey]['use_time'] = microtime(true) - $this->request_time;
        $this->logData[$this->requireKey]['result'] = $result;
        if (!is_array($result)) {
            if ($this->display_error) {
                throw new RpcException($result, 500);
            } else {
                $result = [$this->error_code_key => 0, $this->error_msg_key => $result];
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
        } elseif ($code == 404) {
            $exceptionMsg .= "服务URL地址不存在";
        } elseif ($code == 500) {
            $exceptionMsg .= '服务内部错误';
        } else {
            $temp = current(explode(':', $msg));
            preg_match('/\d+/', $temp, $arr);
            if (!empty($arr)) {
                $exceptionMsg .= '服务异常：' . $this->getMessage(intval($arr[0]));
            } else {
                $exceptionMsg .= '服务异常：' . $msg;
            }
        }
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
        $finalResult = [];
        foreach ($this->result as $key => $item) {
            if ($item instanceof ResponseInterface) {
                $responseCode = $item->getStatusCode();
                $result = $this->formatResponse($item->getBody()->getContents(), (int)$responseCode);
            } elseif ($item instanceof \Exception) {
                $errCode = (int)$item->getCode();
                $errMsg = $this->formatResponse($item->getMessage(), $errCode);
                $result = $errMsg;
            } else {
                $result = $item;
            }
            $this->logData[$key]['result'] = $result;
            if (!is_array($result)) {
                if ($this->display_error) {
                    throw new RpcException($result, 500);
                } else {
                    $result = [$this->error_code_key => 0, $this->error_msg_key => $result];
                }
            }
            $finalResult[$key] = $result;
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
}
