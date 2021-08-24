<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-28
 * Time: 10:06
 */

namespace xyqWeb\rpc;

use xyqWeb\rpc\strategy\RpcException;
use yii\base\Component;
use yii\base\Application as BaseApp;
use yii\base\Event;

class YiiRequest extends Component
{
    /**
     * @var string
     */
    public $driver = 'http';
    /**
     * @var array 配置
     */
    public $config = [];
    /**
     * @var null|\xyqWeb\rpc\strategy\ParallelRequest|\xyqWeb\rpc\strategy\SerialRequest
     */
    private $object = null;
    /**
     * @var null
     */
    private $rpc = null;
    /**
     * @var array 设置自定义options
     */
    private $options = [];

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();
        $this->initRpc($this->driver, $this->config);
        Event::on(BaseApp::class, BaseApp::EVENT_AFTER_REQUEST, function () {
            $this->close();
        });
    }

    /**
     * 初始化Rpc
     *
     * @author xyq
     * @param string $strategy
     * @param array $config
     * @throws RpcException
     */
    public function initRpc(string $strategy, array $config = [])
    {
        if (!in_array($strategy, ['yar', 'http'])) {
            throw new RpcException('RPC strategy error,only accept yar or http');
        }
        if ('yar' === $strategy && !extension_loaded('yar')) {
            throw new RpcException('please install yar extension');
        }
        if (isset($config['yarPackageType']) && 'msgpack' === $config['yarPackageType'] && !extension_loaded('msgpack')) {
            throw new RpcException('please install msgpack extension');
        }
        $logConfig = [];
        if (isset($config['logs']['driver']) && !empty($config['logs']['driver'])) {
            $logConfig = $config['logs'];
            unset($config['logs']);
        } elseif (isset($config['log']['driver']) && !empty($config['log']['driver'])) {
            $logConfig = $config['log'];
            unset($config['log']);
        }
        if (defined('APP_ENVIRONMENT') && extension_loaded('phalcon')) {
            $logConfig['driver'] = \Yii::$app->get($logConfig['driver']);
        }
        $config['logs'] = $logConfig;
        unset($logConfig);
        $class = '\xyqWeb\rpc\drivers\\' . ucfirst($strategy);
        $this->rpc = new $class($config);
    }


    /**
     * 设置参数
     *
     * @author xyq
     * @param array $urls URL
     * @param array|string|null $token 用户登录信息
     * @param bool $isParallel 并行标识，false为串行，true为并行
     * @return $this
     * @throws \Exception
     */
    public function setParams(array $urls, $token = null, $isParallel = false)
    {
        if ($isParallel) {
            $className = 'Parallel';
        } else {
            $className = 'Serial';
        }
        $className = "\\xyqWeb\\rpc\\strategy\\" . $className . 'Request';
        $this->object = new $className();
        $this->object->cleanResult();
        $this->object->setParams($urls);
        $this->object->setToken($token);
        $this->object->setRpc($this->rpc);
        $this->object->setRequestId();
        return $this;
    }

    /**
     * 向RPC注入自定义options
     *
     * @author xyq
     * @param array $options
     * @return $this
     */
    public function setOptions(array $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * 获取远程请求数据
     *
     * @author xyq
     * @return array
     * @throws RpcException
     */
    public function get() : array
    {
        $errMsg = $result = null;
        try {
            !empty($this->options) && $this->object->setOptions($this->options);
            $result = $this->object->get();
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
        }
        $this->object->saveLog();
        //清除自定义options
        if (!empty($this->options)) {
            $this->setOptions([]);
            $this->object->setOptions([]);
        }
        if (!is_null($errMsg)) {
            throw new RpcException($errMsg, 500, $errMsg);
        }
        return $result;
    }

    /**
     * 释放RPC连接对象
     *
     * @author xyq
     */
    public function close()
    {
        $this->object = null;
        $this->rpc = null;
    }
}
