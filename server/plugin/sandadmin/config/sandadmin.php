<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
return [

    'access_exp' => 8 * 60 * 60, // 登录token有效期，默认8小时

	// 验证码存储模式
    'captcha' => [
        // 验证码存储模式 session或者cache
        'mode' => getenv('CAPTCHA_MODE'),
        // 验证码过期时间 (秒)
        'expire' => 300,
    ],

    // excel模板下载路径
    'template' => base_path(). '/plugin/sandadmin/public/template',

    // excel导出文件路径
    'export_path' => base_path() . '/plugin/sandadmin/public/export/',

    // 文件开启hash验证，开启后上传文件将会判断数据库中是否存在，如果存在直接获取
    'file_hash' => false,

    // 用户信息缓存
    'user_cache' => [
        'prefix' => 'sandadmin:user_cache:info_',
        'expire' => 60 * 60 * 4,
        'dept' => 'sandadmin:user_cache:dept_',
        'role' => 'sandadmin:user_cache:role_',
        'post' => 'sandadmin:user_cache:post_',
    ],

    // 用户权限缓存
    'button_cache' => [
        'prefix' => 'sandadmin:button_cache:user_',
        'expire' => 60 * 60 * 2,
        'all' => 'sandadmin:button_cache:all',
        'role' => 'sandadmin:button_cache:role_',
        'tag' => 'sandadmin:button_cache',
    ],

    // 用户菜单缓存
    'menu_cache' => [
        'prefix' => 'sandadmin:menu_cache:user_',
        'expire' => 60 * 60 * 24 * 7,
        'tag' => 'sandadmin:menu_cache',
    ],

    // 字典缓存
    'dict_cache' => [
        'expire' => 60 * 60 * 24 * 365,
        'tag' => 'sandadmin:dict_cache',
    ],

    // 配置数据缓存
    'config_cache' => [
        'expire' => 60 * 60 * 24 * 365,
        'prefix' => 'sandadmin:config_cache:config_',
        'tag' => 'sandadmin:config_cache'
    ],

    // 反射缓存
    'reflection_cache' => [
        'tag' => 'sandadmin:reflection',
        'expire' => 60 * 60 * 24 * 365,
        'no_need' => 'sandadmin:reflection_cache:no_need_',
        'attr' => 'sandadmin:reflection_cache:attr_',
    ],

];

