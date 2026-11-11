<?php

declare(strict_types=1);

namespace plugin\sandadmin\app\controller;

use support\Request;
use support\Response;
use plugin\sandadmin\basic\BaseController;
use plugin\sandadmin\app\logic\system\SystemUserLogic;
use plugin\sandadmin\utils\DevLoginGuard;

/**
 * 开发本机登录：仅供开发联调，不可用场景一律回真实 404，
 * 对外不暴露功能是否存在及拒绝原因。
 */
class DevLoginController extends BaseController
{

    /**
     * 不需要登录的方法
     */
    protected array $noNeedLogin = ['available', 'login'];

    /**
     * 查询开发体验是否可用：不可用时只回 available=false，不透出原因。
     * @param Request $request
     * @return Response
     */
    public function available(Request $request): Response
    {
        if (!DevLoginGuard::isAvailable($request->getRemoteIp())) {
            return $this->success(['available' => false], 'ok');
        }
        return $this->success([
            'available' => true,
            'username' => DevLoginGuard::username(),
        ], 'ok');
    }

    /**
     * 开发本机登录：可用时为配置账号签发与 login() 同构的令牌。
     * @param Request $request
     * @return Response
     */
    public function login(Request $request): Response
    {
        if (!DevLoginGuard::isAvailable($request->getRemoteIp())) {
            return response('Not Found', 404);
        }
        $token = (new SystemUserLogic())->devLogin();
        if ($token === null) {
            return response('Not Found', 404);
        }
        return $this->success($token);
    }

}
