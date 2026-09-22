<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\logic\system;

use plugin\sandadmin\app\model\system\SystemOperLog;
use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandadmin\utils\Helper;

/**
 * 操作日志逻辑层
 */
class SystemOperLogLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemOperLog();
    }

    /**
     * 获取自己的操作日志
     * @param mixed $where
     * @return array
     */
    public function getOwnOperLogList($where): array
    {
        $query = $this->search($where);
        $query->field('id, username, method, router, service_name, ip, ip_location, create_time');
        return $this->getList($query);
    }

}

