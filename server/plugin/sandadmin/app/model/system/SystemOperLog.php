<?php

// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\model\system;

use plugin\sandadmin\basic\think\BaseModel;
use plugin\sandadmin\utils\OperRouteLike;

/**
 * 操作日志模型
 *
 * sand_system_oper_log 操作日志表
 *
 * @property  $id 主键
 * @property  $username 用户名
 * @property  $app 应用名称
 * @property  $method 请求方式
 * @property  $router 请求路由
 * @property  $service_name 业务名称
 * @property  $ip 请求IP地址
 * @property  $ip_location IP所属地
 * @property  $request_data 请求数据
 * @property  $remark 备注
 * @property  $created_by 创建者
 * @property  $updated_by 更新者
 * @property  $create_time 创建时间
 * @property  $update_time 更新时间
 */
class SystemOperLog extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sand_system_oper_log';

    /**
     * 路由按片段匹配。输入里的 % 和 _ 不当作通配符。
     */
    public function searchRouterAttr(mixed $query, mixed $value): void
    {
        $text = trim((string) $value);
        if ($text === '') {
            return;
        }
        $query->where('router', 'like', OperRouteLike::pattern($text));
    }

}
