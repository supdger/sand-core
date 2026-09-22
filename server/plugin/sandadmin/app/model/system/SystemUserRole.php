<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\model\system;

use think\model\Pivot;

/**
 * 用户角色关联模型
 *
 * sand_system_user_role 用户角色关联
 *
 * @property  $id 
 * @property  $user_id 
 * @property  $role_id 
 */
class SystemUserRole extends Pivot
{
    protected $pk = 'id';

    protected $table = 'sand_system_user_role';

    /**
     * 获取角色id
     * @param mixed $user_id
     * @return array
     */
    public static function getRoleIds($user_id): array
    {
        return static::where('user_id', $user_id)->column('role_id');
    }
}
