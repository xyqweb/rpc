<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:32
 */

namespace xyqWeb\rpc\drivers;


use xyqWeb\rpc\strategy\RpcException;

abstract class RpcStrategy
{
    /**
     * @var Yar|\GuzzleHttp\Client RPC的客户端
     */
    protected $client = null;
    /**
     * @var null|array $result yar并行请求返回的数据
     */
    protected $result = null;

    /**
     * @var array $params 系统配置项
     */
    protected $params = [];

    /**
     * @var bool
     */
    protected $isMulti = false;

    /**
     * @var array 内网网址列表
     */
    private $intranetAddress = [];

    /**
     * @var int|string 请求键值
     */
    protected $requireKey = 0;

    /**
     * @var null|object
     */
    protected $logDriver = null;

    /**
     * @var string 日志名称
     */
    protected $logName = 'rpc.log';
    /**
     * @var array 日志等级，只支持error，info
     */
    protected $logLevel = ['error'];
    /**
     * @var string 日志info日志最小执行时长
     */
    protected $infoMinTime = 0;

    /**
     * @var array 临时存储日志内容
     */
    protected $logData = [];

    /**
     * @var bool 是否显示错误
     */
    protected $display_error = true;

    /**
     * @var string 状态码的键值
     */
    protected $code_key = 'status';

    /**
     * @var string 消息内容键值
     */
    protected $msg_key = 'msg';

    /**
     * @var array 成功响应code_key对应的值
     */
    protected $response_success_code = [1];

    /**
     * @var array 成功响应code_key对应的值
     */
    protected $response_fail_code = [0];

    /**
     * @var int 请求发起时间
     */
    protected $request_time = 0;

    /**
     * RpcStrategy constructor.
     * @param array $params
     */
    public function __construct(array $params)
    {
        $this->result = null;
        $this->client = null;
        if (!isset($params['timeout']) || !is_int($params['timeout'])) {
            $params['timeout'] = 5000;
        }
        if (isset($params['intranetAddress'])) {
            if (is_array($params['intranetAddress']) && !empty($params['intranetAddress'])) {
                $this->intranetAddress = $params['intranetAddress'];
            }
            unset($params['intranetAddress']);
        }
        $logConfig = [];
        if (isset($params['log']) && !empty($params['log'])) {
            $logConfig = $params['log'];
            unset($params['log']);
        }
        if (isset($params['logs']) && !empty($params['logs'])) {
            $logConfig = $params['logs'];
            unset($params['logs']);
        }
        if (is_array($logConfig) && isset($logConfig['driver']) && method_exists($logConfig['driver'], 'write')) {
            $this->logDriver = $logConfig['driver'];
            if (isset($logConfig['file']) && !empty($logConfig['file'])) {
                $this->logName = $logConfig['file'];
            }
            if (isset($logConfig['levels']) && is_array($logConfig['levels']) && !empty($logConfig['levels'])) {
                $this->logLevel = $logConfig['levels'];
            }
            if (isset($logConfig['infoMinTime']) && is_int($logConfig['infoMinTime'])) {
                $this->infoMinTime = $logConfig['infoMinTime'];
            }
        }
        unset($logConfig);
        if (isset($params['error']) && is_array($params['error'])) {
            $error = $params['error'];
            if (isset($error['display_error']) && is_bool($error['display_error'])) {
                $this->display_error = $error['display_error'];
            }
            if (isset($error['code_key']) && is_string($error['code_key'])) {
                $this->code_key = $error['code_key'];
            }
            if (isset($error['msg_key']) && is_string($error['msg_key'])) {
                $this->msg_key = $error['msg_key'];
            }
            if (isset($error['success_code'])) {
                $this->response_success_code = is_array($error['success_code']) && !empty($error['success_code']) ? $error['success_code'] : (is_int($error['success_code']) ? [$error['success_code']] : [1]);
            }
            if (isset($error['fail_code'])) {
                $this->response_fail_code = is_array($error['fail_code']) && !empty($error['fail_code']) ? $error['fail_code'] : (is_int($error['fail_code']) ? [$error['fail_code']] : [0]);
            }
            unset($params['error'], $error);
        }
        $this->params = $params;
    }

    /**
     * 设置串行请求参数
     *
     * @author xyq
     * @param string $url 请求URL
     * @param bool $isIndependent 独立站点标识
     * @param array|string|null $token
     * @param array $headers 自定义headers
     * @return mixed
     */
    abstract public function setParams(string $url, bool $isIndependent = false, $token = null, array $headers = []);

    /**
     * 获取串行请求结果
     *
     * @author xyq
     * @param string $method
     * @param null $data
     * @return array
     */
    abstract public function get(string $method, $data = null) : array;

    /**
     * 设置并行请求参数
     *
     * @author xyq
     * @param array $urls
     * @param array|string|null $token
     * @return mixed
     */
    abstract public function setMultiParams(array $urls, $token = null);

    /**
     * 获取并行请求结果
     *
     * @author xyq
     * @return array
     */
    abstract public function multiGet() : array;

    /**
     * 获取发送的header
     *
     * @author xyq
     * @param null $token 安全标示token
     * @param array $headers 自定义header
     * @param string|null $glue 分割方式
     * @return array
     * @throws RpcException
     */
    protected function getHeaders($token = null, array $headers = [], string $glue = null)
    {
        $tokenKey = isset($this->params['tokenKey']) && !empty($this->params['tokenKey']) ? $this->params['tokenKey'] : 'token';
        $header = [
            $tokenKey => (is_array($token) ? json_encode($token) : (is_string($token) ? $token : ""))
        ];
        if (!empty($headers)) {
            foreach ($headers as $key => $value) {
                if (!isset($header[$key])) {
                    if (is_object($value)) {
                        throw new RpcException('Object not supported for header ' . $key);
                    }
                    $header[$key] = is_array($value) ? json_encode($value) : $value;
                }
            }
        }
        $env = php_sapi_name();
        if ('cli' == $env) {
            $header['env'] = "shell";
        } else {
            $header['env'] = 'browser';
        }
        $header["x-real-ip"] = $this->getUserIp();
        if (!empty($glue)) {
            $finalHeader = [];
            foreach ($header as $key => $value) {
                $finalHeader[] = $key . $glue . $value;
            }
            unset($header);
            return $finalHeader;
        }
        return $header;
    }

    /**
     * 获取用户IP地址
     *
     * @return string
     * @author xyq
     */
    protected function getUserIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"] ?? '127.0.0.1';
        }
        return $ip;
    }

    /**
     * 转换成真实的地址
     *
     * @author xyq
     * @param string $url 请求地址
     * @param bool $isIndependent 独立站点标识
     * @return string
     * @throws \Exception
     */
    protected function getRealUrl(string $url, $isIndependent = false) : string
    {
        //请求的外部站点
        if ($isIndependent) {
            return $url;
        } else {
            $url = explode('/', $url);
            $first = trim(array_shift($url), '_');
            //服务类型为模块
            if (isset($this->params['serverType']) && 'module' == $this->params['serverType']) {
                $module = $this->params['module'];
                if (!in_array($first, $module)) {
                    throw new \Exception('模块不存在于配置列表中');
                }
                $realUrl = $this->params['serverPort'] == 443 ? 'https://' : 'http://';
                $realUrl .= $this->params['rootDomain'] . $first . '/' . implode('/', $url);
            } else {
                //独立子服务类型
                $domain = $this->params['domain'];
                $domainName = array_keys($domain);
                if (!in_array($first, $domainName)) {
                    throw new \Exception('域名不存在于配置列表中');
                }
                $realUrl = $this->params['serverPort'] == 443 ? 'https://' : 'http://';
                if ('local' != $this->params['server']) {
                    if (0 === strpos($domain[$first], 'http')) {
                        $realUrl = $domain[$first] . implode('/', $url);
                    } else {
                        $realUrl .= $domain[$first] . implode('/', $url);
                    }
                } else {
                    $realUrl .= $first . $this->params['rootDomain'] . implode('/', $url);
                }
            }
//            echo $realUrl."\n";
            return $realUrl;
        }
    }

    /**
     * 判断外网标识，如果是内网ip时强制把是返回false
     *
     * @author xyq
     * @param bool $isIndependent
     * @param string $url
     * @return bool
     */
    protected function needProxy(bool $isIndependent, string $url)
    {
        if (!$isIndependent) {
            return false;
        }
        $info = parse_url($url);
        if (!isset($info['host']) || empty($info['host'])) {
            return false;
        }
        $convert = ip2long($info['host']);
        $needProxy = true;
        if (!is_int($convert)) {
            if (!empty($this->intranetAddress)) {
                foreach ($this->intranetAddress as $intranetAddress) {
                    if ($info['host'] == $intranetAddress) {
                        $needProxy = false;
                        break;
                    }
                }
            }
            return $needProxy;
        }
        $innerIpArray = [
            [
                'min' => 167772160,//10.0.0.0
                'max' => 184549375,//10.255.255.255
            ],
            [
                'min' => 2130706432,//127.0.0.0
                'max' => 2147483647,//127.255.255.255
            ],
            [
                'min' => 2885681152,//172.0.0.0
                'max' => 2902458367,//172.255.255.255
            ],
            [
                'min' => 3221225472,//192.0.0.0
                'max' => 3238002687,//192.255.255.255
            ],
        ];
        foreach ($innerIpArray as $item) {
            if ($convert >= $item['min'] && $convert <= $item['max']) {
                $needProxy = false;
                break;
            }
        }
        return $needProxy;
    }

    /**
     * 保存日志
     *
     * @author xyq
     * @return bool|null
     */
    public function saveLog()
    {
        if (!empty($this->logData) && is_object($this->logDriver)) {
            $finalLog = [];
            if (empty($this->logLevel)) {
                return null;
            }
            foreach ($this->logData as $key => $item) {
                if (isset($item['result']) && is_array($item['result']) && isset($item['result'][$this->code_key])) {
                    if (in_array($item['result'][$this->code_key], $this->response_success_code)) {
                        $level = 'info';
                    } elseif (in_array($item['result'][$this->code_key], $this->response_fail_code)) {
                        $level = 'error';
                    } else {
                        $level = 'debug';
                    }
                } else {
                    $level = 'error';
                }
                //不记录info级别
                if (!in_array('info', $this->logLevel) && 'info' === $level) {
                    continue;
                } elseif (!in_array('error', $this->logLevel) && 'error' === $level) {//不记录error级别
                    continue;
                } elseif (!in_array('debug', $this->logLevel) && 'debug' === $level) {
                    continue;
                }
                //当成功时且使用时间小于规定值
                if ('info' === $level && isset($item['use_time']) && $this->infoMinTime > $item['use_time']) {
                    continue;
                }
                $finalLog[] = $item;
            }
            if (!empty($finalLog)) {
                $result = $this->logDriver->write($this->logName, $finalLog);
            } else {
                $result = null;
            }
            unset($finalLog);
        } else {
            $result = null;
        }
        $this->logData = [];
        return $result;
    }

    /**
     * @return string
     */
    protected function getRequestUri() : string
    {
        $env = php_sapi_name();
        if ('cli' == $env) {
            $require_uri = json_encode($_SERVER['argv']??[]);
        } else {
            $require_uri = $_SERVER['REQUEST_URI'] ?? '';
        }
        return $require_uri;
    }
}
