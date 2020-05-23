<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:32
 */

namespace xyqWeb\rpc\drivers;


abstract class RpcStrategy
{
    /**
     * @var Yar|Http RPC的客户端
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
        $this->params = $params;
    }

    /**
     * 设置串行请求参数
     *
     * @author xyq
     * @param string $url 请求URL
     * @param bool $isIndependent 独立站点标识
     * @param array|string|null $token
     * @return mixed
     */
    abstract public function setParams(string $url, bool $isIndependent = false, $token = null);

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
     * @param array|string|null $token
     * @return array
     */
    protected function getHeaders($token = null)
    {
        $tokenKey = isset($this->params['tokenKey']) && !empty($this->params['tokenKey']) ? $this->params['tokenKey'] : 'token';
        $header = [
            $tokenKey => (is_array($token) ? json_encode($token) : (is_string($token) ? $token : ""))
        ];
        $env = php_sapi_name();
        if ('cli' == $env) {
            $header['env'] = "shell";
        } else {
            $header['env'] = 'browser';
        }
        $header["x-real-ip"] = $this->getUserIp();
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
}