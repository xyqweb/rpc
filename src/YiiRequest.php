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
        $class = "\\xyqWeb\\rpc\\drivers\\" . ucfirst($strategy);
        $this->rpc = new $class($config);
    }


    /**
     * 设置参数
     *
     * @author xyq
     * @param array $urls URL
     * @param array|null $userInfo 用户登录信息
     * @param bool $isParallel 并行标识，false为串行，true为并行
     * @return $this
     * @throws \Exception
     */
    public function setParams(array $urls, array $userInfo = null, $isParallel = false)
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
        $this->object->setUserInfo($userInfo);
        $this->object->setRpc($this->rpc);
        return $this;
    }

    /**
     * 获取远程请求数据
     *
     * @author xyq
     * @return array
     * @throws strategy\RpcException
     * @throws \Exception
     */
    public function get() : array
    {
        return $this->object->get();
    }
}