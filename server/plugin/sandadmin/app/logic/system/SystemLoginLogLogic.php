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
        $sql = env('DB_TYPE', 'mysql') === 'pgsql'
            ? "
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
            "
            : "
                SELECT
                    d.date AS login_date,
                    COUNT(l.login_time) AS login_count
                FROM
                    (SELECT CURDATE() - INTERVAL (a.N) DAY AS date
                     FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                           UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
                           UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
                     ) d
                LEFT JOIN sand_system_login_log l
                    ON DATE(l.login_time) = d.date
                GROUP BY d.date
                ORDER BY d.date ASC
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
        $sql = env('DB_TYPE', 'mysql') === 'pgsql'
            ? "
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
            "
            : "
                SELECT
                    CONCAT(LPAD(m.month_num, 2, '0'), '月') AS login_month,
                    COUNT(l.login_time) AS login_count
                FROM
                    (SELECT 1 AS month_num UNION ALL SELECT 2 UNION ALL SELECT 3
                     UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6
                     UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9
                     UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12) m
                LEFT JOIN sand_system_login_log l
                    ON YEAR(l.login_time) = YEAR(CURDATE())
                    AND MONTH(l.login_time) = m.month_num
                GROUP BY m.month_num
                ORDER BY m.month_num ASC
            ";

        $data = Db::query($sql);
        return [
            'login_count' => array_column($data, 'login_count'),
            'login_month' => array_column($data, 'login_month'),
        ];
    }
}
