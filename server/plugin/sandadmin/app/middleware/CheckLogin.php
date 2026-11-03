<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;
use Tinywan\Jwt\JwtToken;
use plugin\sandadmin\app\cache\ReflectionCache;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\utils\ForcedPasswordChange;
use plugin\sandadmin\utils\JwtSecretGuard;

/**
 * 登录检查中间件
 */
class CheckLogin implements MiddlewareInterface
{
    public function process(Request $request, callable $handler): Response
    {
        // 通过反射获取控制器哪些方法不需要登录
        $noNeedLogin = ReflectionCache::getNoNeedLogin($request->controller);
        // 访问的方法需要登录
        if (!in_array($request->action, $noNeedLogin)) {
            // 密钥为空、占位符或过短时拒绝处理，避免接受伪造令牌。
            try {
                JwtSecretGuard::assertRuntimeConfig();
            } catch (\RuntimeException $e) {
                throw new ApiException($e->getMessage(), 400);
            }
            try {
                $token = JwtToken::getExtend();
            } catch (\Throwable $e) {
                throw new ApiException('您的登录凭证错误或者已过期，请重新登录', 401);
            }
            if ($token['plat'] !== 'sandadmin') {
                throw new ApiException('登录环境已更新，请重新登录', 401);
            }
            // 首次登录强制改密：标志为真时仅放行改密与用户信息接口。
            ForcedPasswordChange::assertAllowed(
                ForcedPasswordChange::mustChange((int) ($token['id'] ?? 0)),
                (string) $request->controller,
                (string) $request->action
            );
            $request->setHeader('check_login', true);
            $request->setHeader('check_admin', $token);
        }
        return $handler($request);
    }
}
