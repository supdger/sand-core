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
use plugin\sandadmin\utils\SensitiveData;
use support\exception\BusinessException;

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
        // 保留了基类的分发：声明了 render() 的异常（ApiException 等）自己组装响应，
        // 保证 error_code / rejected 等机器可读字段不被这里吞掉。
        if (method_exists($exception, 'render') && ($response = $exception->render($request))) {
            return $response;
        }
        $debug = config('app.debug', true);
        $code = $exception->getCode();
        // 只有声明为业务提示的异常可以把原文返回给页面；PDO/SQLSTATE、内部路径、
        // 堆栈等一律按系统故障兜底，原文只进 report() 写的日志。
        $safe = $exception instanceof BusinessException
            || $exception instanceof \think\exception\ValidateException;
        $json = [
            'code' => $safe && $code ? $code : 500,
            'message' => $safe
                ? ($code !== 500 ? $exception->getMessage() : 'Server internal error')
                : '系统出了点问题，请稍后再试',
            'type' => 'failed'
        ];
        if ($debug) {
            $json['request_url'] = $request->method() . ' ' . $request->uri();
            $json['timestamp'] = date('Y-m-d H:i:s');
            $json['client_ip'] = $request->getRealIp();
            $json['request_param'] = SensitiveData::maskParams($request->all());
            $json['exception_handle'] = get_class($exception);
            // 内部异常（PDO/SQLSTATE 等）即使在 debug 下也只回类名，
            // 原文、文件路径、堆栈一律只进 report() 日志，不进响应体。
            if ($safe) {
                $json['exception_info'] = [
                    'code' => $exception->getCode(),
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => explode("\n", $exception->getTraceAsString())
                ];
            }
        }
        // 显式声明了真实 HTTP 状态（如权限拒绝 403）的异常按声明返回；
        // 其余沿用项目既有响应壳：HTTP 200 + code 字段，不改为裸 500。
        $httpStatus = $exception instanceof SystemException
            && $exception->httpStatus >= 400 && $exception->httpStatus < 600
            ? $exception->httpStatus
            : 200;
        return new Response($httpStatus, ['Content-Type' => 'application/json;charset=utf-8'], json_encode($json));
    }
}

