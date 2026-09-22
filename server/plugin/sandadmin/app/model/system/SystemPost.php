<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\model\system;

use plugin\sandadmin\basic\think\BaseModel;

/**
 * 岗位模型
 *
 * sand_system_post 岗位信息表
 *
 * @property  $id 主键
 * @property  $name 岗位名称
 * @property  $code 岗位代码
 * @property  $sort 排序
 * @property  $status 状态
 * @property  $remark 备注
 * @property  $created_by 创建者
 * @property  $updated_by 更新者
 * @property  $create_time 创建时间
 * @property  $update_time 修改时间
 */
class SystemPost extends BaseModel
{
    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    protected $table = 'sand_system_post';

}
