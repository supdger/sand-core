<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\exception;

use Throwable;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Exception\ExceptionHandler;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\exception\SystemException;
use plugin\sandadmin\utils\DebugGuard;
use plugin\sandadmin\utils\ErrorPayload;
use plugin\sandadmin\utils\SensitiveData;

/**
 * 异常处理类
 */
class Handler extends ExceptionHandler
{
    public $dontReport = [
        ApiException::class,
    ];

    public function report(Throwable $exception)
    {
        if ($this->shouldntReport($exception)) {
            return;
        }
        $logs = '';
        if ($request = \request()) {
            $user = getCurrentInfo();
            $logs .= $request->method() . ' ' . $request->uri();
            $logs .= PHP_EOL . '[request_param]: ' . json_encode(SensitiveData::maskParams($request->all()));
            $logs .= PHP_EOL . '[timestamp]: ' . date('Y-m-d H:i:s');
            $logs .= PHP_EOL . '[client_ip]: ' . $request->getRealIp();
            $logs .= PHP_EOL . '[action_user]: ' . var_export($user, true);
            $logs .= PHP_EOL . '[exception_handle]: ' . get_class($exception);
            $logs .= PHP_EOL . '[exception_info]: ' . PHP_EOL . $exception;
        }
        $this->logger->error($logs);
    }

    public function render(Request $request, Throwable $exception): Response
    {
        $requestId = ErrorPayload::newRequestId();
        // 日志与响应分离：内部信息只进日志，用 request_id 关联。
        $this->logger->error('[request_id]: ' . $requestId . ' ' . get_class($exception));
        $details = [];
        if (DebugGuard::exposeDetails()) {
            $details = [
                'request_url' => $request->method() . ' ' . $request->uri(),
                'timestamp' => date('Y-m-d H:i:s'),
                'client_ip' => $request->getRealIp(),
                'request_param' => SensitiveData::maskParams($request->all()),
                'exception_handle' => get_class($exception),
                'exception_info' => [
                    'code' => $exception->getCode(),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => explode("\n", $exception->getTraceAsString()),
                ],
            ];
        }
        $json = ErrorPayload::build($exception, $requestId, $details);
        // 显式声明了真实 HTTP 状态（如权限拒绝 403）的异常按声明返回；
        // 其余沿用项目既有响应壳：HTTP 200 + code 字段，不改为裸 500。
        $httpStatus = $exception instanceof SystemException
            && $exception->httpStatus >= 400 && $exception->httpStatus < 600
            ? $exception->httpStatus
            : 200;
        return new Response($httpStatus, ['Content-Type' => 'application/json;charset=utf-8'], json_encode($json, JSON_UNESCAPED_UNICODE));
    }
}
