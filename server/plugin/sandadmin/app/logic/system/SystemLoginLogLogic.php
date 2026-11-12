<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\logic\system;

use plugin\sandadmin\app\model\system\SystemLoginLog;
use plugin\sandadmin\basic\think\BaseLogic;
use support\think\Db;

/**
 * 登录日志逻辑层
 */
class SystemLoginLogLogic extends BaseLogic
{
    public function __construct()
    {
        $this->model = new SystemLoginLog();
    }

    /**
     * 最近十天的登录统计图表。
     */
    public function loginChart(): array
    {
        // 服务端只支持 PostgreSQL，日期序列固定用 generate_series。
        $sql = "
            SELECT
                d.login_date,
                COUNT(l.login_time) AS login_count
            FROM generate_series(
                CURRENT_DATE - INTERVAL '9 days',
                CURRENT_DATE,
                INTERVAL '1 day'
            ) AS d(login_date)
            LEFT JOIN sand_system_login_log l
                ON l.login_time >= d.login_date
                AND l.login_time < d.login_date + INTERVAL '1 day'
            GROUP BY d.login_date
            ORDER BY d.login_date ASC
        ";

        $data = Db::query($sql);
        return [
            'login_count' => array_column($data, 'login_count'),
            'login_date' => array_column($data, 'login_date'),
        ];
    }

    /**
     * 当前年度的按月登录统计图表。
     */
    public function loginBarChart(): array
    {
        // 服务端只支持 PostgreSQL，月份序列固定用 generate_series。
        $sql = "
            SELECT
                TO_CHAR(m.month_start, 'MM') || '月' AS login_month,
                COUNT(l.login_time) AS login_count
            FROM generate_series(
                DATE_TRUNC('year', CURRENT_DATE),
                DATE_TRUNC('year', CURRENT_DATE) + INTERVAL '11 months',
                INTERVAL '1 month'
            ) AS m(month_start)
            LEFT JOIN sand_system_login_log l
                ON l.login_time >= m.month_start
                AND l.login_time < m.month_start + INTERVAL '1 month'
            GROUP BY m.month_start
            ORDER BY m.month_start ASC
        ";

        $data = Db::query($sql);
        return [
            'login_count' => array_column($data, 'login_count'),
            'login_month' => array_column($data, 'login_month'),
        ];
    }
}
