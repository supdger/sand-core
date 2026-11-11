<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\exception;

use Throwable;

/**
 * 系统接口错误-返回json数据,并且记录异常日志
 *
 * $httpStatus 为可选真实 HTTP 状态码（如权限拒绝用 403）；缺省 0 表示沿用
 * 宿主既有响应壳（HTTP 200 + code 字段），不改变历史调用方行为。
 */
class SystemException extends \RuntimeException
{
    public function __construct(
        $message,
        $code = 400,
        ?Throwable $previous = null,
        public readonly int $httpStatus = 0
    ) {
        parent::__construct($message, $code, $previous);
    }
}
