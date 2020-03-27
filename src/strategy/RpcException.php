<?php
declare(strict_types = 1);
/**
 * Created by PhpStorm.
 * User: XYQ
 * Date: 2020-03-27
 * Time: 14:38
 */

namespace xyqWeb\rpc\strategy;


class RpcException extends \Exception
{
    private $msg;

    public function __construct($message = "", $code = 0, $msg = '', \Exception $previous = null)
    {
        $this->msg = $msg;
        parent::__construct($message, $code, $previous);
    }

    /**
     * 返回自定义msg
     *
     * @author xyq
     * @return string
     */
    public function getMsg()
    {
        return $this->msg;
    }
}