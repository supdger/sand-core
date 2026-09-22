<?php

use Webman\Route;

Route::group('/core', function () {

    Route::get('/install', [plugin\sandadmin\app\controller\InstallController::class, 'index']);
    Route::post('/install/install', [plugin\sandadmin\app\controller\InstallController::class, 'install']);

    Route::get('/captcha', [plugin\sandadmin\app\controller\LoginController::class, 'captcha']);
    Route::post('/login', [plugin\sandadmin\app\controller\LoginController::class, 'login']);

    Route::get('/system/user', [plugin\sandadmin\app\controller\SystemController::class, 'userInfo']);
    Route::get("/system/dictAll", [plugin\sandadmin\app\controller\SystemController::class, 'dictAll']);
    Route::get('/system/menu', [plugin\sandadmin\app\controller\SystemController::class, 'menu']);

    Route::get('/system/statistics', [plugin\sandadmin\app\controller\SystemController::class, 'statistics']);
    Route::get('/system/loginChart', [plugin\sandadmin\app\controller\SystemController::class, 'loginChart']);
    Route::get('/system/loginBarChart', [plugin\sandadmin\app\controller\SystemController::class, 'loginBarChart']);
    Route::get('/system/clearAllCache', [plugin\sandadmin\app\controller\SystemController::class, 'clearAllCache']);

    Route::get("/system/getResourceCategory", [plugin\sandadmin\app\controller\SystemController::class, 'getResourceCategory']);
    Route::get("/system/getResourceList", [plugin\sandadmin\app\controller\SystemController::class, 'getResourceList']);
    Route::post("/system/saveNetworkImage", [plugin\sandadmin\app\controller\SystemController::class, 'saveNetworkImage']);
    Route::post("/system/uploadImage", [plugin\sandadmin\app\controller\SystemController::class, 'uploadImage']);
    Route::post("/system/uploadFile", [plugin\sandadmin\app\controller\SystemController::class, 'uploadFile']);
    Route::post("/system/chunkUpload", [plugin\sandadmin\app\controller\SystemController::class, 'chunkUpload']);
    Route::get("/system/getUserList", [plugin\sandadmin\app\controller\SystemController::class, 'getUserList']);
    Route::get("/system/getLoginLogList", [plugin\sandadmin\app\controller\SystemController::class, 'getLoginLogList']);
    Route::get("/system/getOperationLogList", [plugin\sandadmin\app\controller\SystemController::class, 'getOperationLogList']);

    // 用户管理
    fastRoute("user", \plugin\sandadmin\app\controller\system\SystemUserController::class);
    Route::post("/user/updateInfo", [\plugin\sandadmin\app\controller\system\SystemUserController::class, 'updateInfo']);
    Route::post("/user/modifyPassword", [\plugin\sandadmin\app\controller\system\SystemUserController::class, 'modifyPassword']);
    Route::post("/user/clearCache", [\plugin\sandadmin\app\controller\system\SystemUserController::class, 'clearCache']);
    Route::post("/user/initUserPassword", [\plugin\sandadmin\app\controller\system\SystemUserController::class, 'initUserPassword']);
    Route::post("/user/setHomePage", [\plugin\sandadmin\app\controller\system\SystemUserController::class, 'setHomePage']);

    // 角色管理
    fastRoute('role', \plugin\sandadmin\app\controller\system\SystemRoleController::class);
    Route::get("/role/accessRole", [\plugin\sandadmin\app\controller\system\SystemRoleController::class, 'accessRole']);
    Route::get("/role/getMenuByRole", [\plugin\sandadmin\app\controller\system\SystemRoleController::class, 'getMenuByRole']);
    Route::post("/role/menuPermission", [\plugin\sandadmin\app\controller\system\SystemRoleController::class, 'menuPermission']);

    // 部门管理
    fastRoute("dept", \plugin\sandadmin\app\controller\system\SystemDeptController::class);
    Route::get("/dept/accessDept", [\plugin\sandadmin\app\controller\system\SystemDeptController::class, 'accessDept']);

    // 岗位管理
    fastRoute('post', \plugin\sandadmin\app\controller\system\SystemPostController::class);
    Route::get("/post/accessPost", [\plugin\sandadmin\app\controller\system\SystemPostController::class, 'accessPost']);
    Route::post("/post/downloadTemplate", [plugin\sandadmin\app\controller\system\SystemPostController::class, 'downloadTemplate']);

    // 菜单管理
    fastRoute('menu', \plugin\sandadmin\app\controller\system\SystemMenuController::class);
    Route::get("/menu/accessMenu", [\plugin\sandadmin\app\controller\system\SystemMenuController::class, 'accessMenu']);
    // 字典类型管理
    fastRoute('dictType', \plugin\sandadmin\app\controller\system\SystemDictTypeController::class);
    // 字典数据管理
    fastRoute('dictData', \plugin\sandadmin\app\controller\system\SystemDictDataController::class);
    // 附件管理
    fastRoute('attachment', \plugin\sandadmin\app\controller\system\SystemAttachmentController::class);
    Route::post("/attachment/move", [\plugin\sandadmin\app\controller\system\SystemAttachmentController::class, 'move']);
    // 附件分类
    fastRoute('category', \plugin\sandadmin\app\controller\system\SystemCategoryController::class);
    // 系统设置
    fastRoute('configGroup', \plugin\sandadmin\app\controller\system\SystemConfigGroupController::class);
    Route::post("/configGroup/email", [\plugin\sandadmin\app\controller\system\SystemConfigGroupController::class, 'email']);
    fastRoute('config', \plugin\sandadmin\app\controller\system\SystemConfigController::class);
    Route::post("/config/batchUpdate", [\plugin\sandadmin\app\controller\system\SystemConfigController::class, 'batchUpdate']);

    // 日志管理
    Route::get("/logs/getLoginLogPageList", [\plugin\sandadmin\app\controller\system\SystemLogController::class, 'getLoginLogPageList']);
    Route::delete("/logs/deleteLoginLog", [\plugin\sandadmin\app\controller\system\SystemLogController::class, 'deleteLoginLog']);
    Route::get("/logs/getOperLogPageList", [\plugin\sandadmin\app\controller\system\SystemLogController::class, 'getOperLogPageList']);
    Route::delete("/logs/deleteOperLog", [\plugin\sandadmin\app\controller\system\SystemLogController::class, 'deleteOperLog']);
    fastRoute("email", \plugin\sandadmin\app\controller\system\SystemMailController::class);

    // 服务管理
    Route::get("/server/monitor", [\plugin\sandadmin\app\controller\system\SystemServerController::class, 'monitor']);
    Route::get("/server/cache", [\plugin\sandadmin\app\controller\system\SystemServerController::class, 'cache']);
    Route::post("/server/clear", [\plugin\sandadmin\app\controller\system\SystemServerController::class, 'clear']);

    // 数据表维护
    Route::get("/database/index", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'index']);
    Route::get("/database/recycle", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'recycle']);
    Route::delete("/database/delete", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'delete']);
    Route::post("/database/recovery", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'recovery']);
    Route::get("/database/dataSource", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'source']);
    Route::get("/database/detailed", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'detailed']);
    Route::post("/database/optimize", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'optimize']);
    Route::post("/database/fragment", [\plugin\sandadmin\app\controller\system\DataBaseController::class, 'fragment']);

});

Route::group('/tool', function () {

    // 定时任务
    fastRoute('crontab', \plugin\sandadmin\app\controller\tool\CrontabController::class);
    Route::post("/crontab/run", [\plugin\sandadmin\app\controller\tool\CrontabController::class, 'run']);
    Route::get("/crontab/logPageList", [\plugin\sandadmin\app\controller\tool\CrontabController::class, 'logPageList']);
    Route::delete('/crontab/deleteCrontabLog', [\plugin\sandadmin\app\controller\tool\CrontabController::class, 'deleteCrontabLog']);

    // 代码生成
    fastRoute('code', \plugin\sandadmin\app\controller\tool\GenerateTablesController::class);
    Route::get("/code/getTableColumns", [\plugin\sandadmin\app\controller\tool\GenerateTablesController::class, 'getTableColumns']);
    Route::get("/code/preview", [\plugin\sandadmin\app\controller\tool\GenerateTablesController::class, 'preview']);
    Route::post("/code/loadTable", [\plugin\sandadmin\app\controller\tool\GenerateTablesController::class, 'loadTable']);
    Route::post("/code/generate", [\plugin\sandadmin\app\controller\tool\GenerateTablesController::class, 'generate']);
    Route::post("/code/generateFile", [\plugin\sandadmin\app\controller\tool\GenerateTablesController::class, 'generateFile']);
    Route::post("/code/sync", [\plugin\sandadmin\app\controller\tool\GenerateTablesController::class, 'sync']);
});

Route::disableDefaultRoute('sandadmin');
