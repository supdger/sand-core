<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\model\system;

use plugin\sandadmin\basic\think\BaseModel;

/**
 * 用户信息模型
 *
 * sand_system_user 用户表
 *
 * @property  $id 
 * @property  $username 登录账号
 * @property  $password 加密密码
 * @property  $realname 真实姓名
 * @property  $gender 性别
 * @property  $avatar 头像
 * @property  $email 邮箱
 * @property  $phone 手机号
 * @property  $signed 个性签名
 * @property  $dashboard 工作台
 * @property  $dept_id 主归属部门
 * @property  $is_super 是否超级管理员: 1是
 * @property  $status 状态: 1启用, 2禁用
 * @property  $remark 备注
 * @property  $login_time 最后登录时间
 * @property  $login_ip 最后登录IP
 * @property  $created_by 创建者
 * @property  $updated_by 更新者
 * @property  $create_time 创建时间
 * @property  $update_time 修改时间
 * @property  $must_change_password 首次登录必须修改密码: 1是
 */
class SystemUser extends BaseModel
{

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 数据表完整名称
     * @var string
     */
    protected $table = 'sand_system_user';

    /**
     * 隐藏字段：password 哈希绝不进入 toArray/JSON/分页输出。
     * 子类重声明会覆盖 BaseModel::$hidden，故须保留 delete_time。
     * 属性读取、save、value() 不受影响（登录校验/改密/重置密码照常）。
     * @var array
     */
    protected $hidden = ['password', 'delete_time'];

    public function searchKeywordAttr($query, $value)
    {
        if ($value) {
            $query->where('username|realname|phone', 'like', '%' . $value . '%');
        }
    }

    /**
     * 权限范围 - 过滤部门用户
     */
    public function scopeAuth($query, $value)
    {
        if (!empty($value)) {
            $deptIds = [$value['id']];
            $deptLevel = $value['level'] . $value['id'] . ',';
            $dept_ids = SystemDept::whereLike('level', $deptLevel . '%')->column('id');
            $deptIds = array_merge($deptIds, $dept_ids);
            $query->whereIn('dept_id', $deptIds);
        }
    }

    /**
     * 通过中间表关联角色
     */
    public function roles()
    {
        return $this->belongsToMany(SystemRole::class, SystemUserRole::class, 'role_id', 'user_id');
    }

    /**
     * 通过中间表关联岗位
     */
    public function posts()
    {
        return $this->belongsToMany(SystemPost::class, SystemUserPost::class, 'post_id', 'user_id');
    }

    /**
     * 通过中间表关联部门
     */
    public function depts()
    {
        return $this->belongsTo(SystemDept::class, 'dept_id', 'id');
    }
}
