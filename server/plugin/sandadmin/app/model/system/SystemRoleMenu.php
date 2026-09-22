<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\model\system;

use think\model\Pivot;

/**
 * 角色菜单关联模型
 *
 * sand_system_role_menu 角色权限关联
 *
 * @property  $id 
 * @property  $role_id 
 * @property  $menu_id 
 */
class SystemRoleMenu extends Pivot
{
    protected $pk = 'id';

    protected $table = 'sand_system_role_menu';
}
