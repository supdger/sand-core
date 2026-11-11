<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\exception;

use Webman\Http\Request;
use Webman\Http\Response;
use support\exception\BusinessException;

/**
 * 常规操作异常-只返回json数据,不记录异常日志
 */
class ApiException extends BusinessException
{
    public function render(Request $request): ?Response
    {
        $json = ['code' => $this->getCode() ?: 500, 'message' => $this->getMessage()];
        $data = $this->data;
        if ($data === [] && preg_match('/^([A-Z][A-Z0-9_]{2,60}): /', (string) $this->getMessage(), $matches) === 1) {
            // 「CODE: 文案」契约消息自动补机器可读字段；业务码 4xx 视为明确拒绝，
            // 5xx 表示结果未确认，前端不得据此解除本地操作锁。
            $code = $this->getCode();
            $data = ['error_code' => $matches[1], 'rejected' => $code >= 400 && $code < 500];
        }
        if ($data !== []) {
            $json['data'] = $data;
        }
        return json($json);
    }
}
