<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\logic\system;

use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandadmin\exception\ApiException;
use support\think\Db;

/**
 * 数据表维护逻辑层
 */
class DatabaseLogic extends BaseLogic
{
    /**
     * 获取数据源
     * @return array
     */
    public function getDbSource(): array
    {
        $data = config('think-orm.connections');
        $list = [];
        foreach ($data as $k => $v) {
            $list[] = $k;
        }
        return $list;
    }

    /**
     * 数据列表
     * @param $query
     * @return mixed
     */
    public function getList($query): mixed
    {
        $request = request();
        $page = $request ? ($request->input('page') ?: 1) : 1;
        $limit = $request ? ($request->input('limit') ?: 10) : 10;

        return self::getTableList($query, $page, $limit);
    }

    /**
     * 获取数据库表数据
     */
    public function getTableList($query, $current_page = 1, $per_page = 10): array
    {
        $source = $query['source'] ?? '';
        if ($this->isPostgreSql($source)) {
            $params = [];
            $nameFilter = '';
            if (!empty($query['name'])) {
                $nameFilter = ' AND c.relname = :name';
                $params['name'] = $query['name'];
            }
            $sql = <<<SQL
SELECT c.relname AS "Name",
       'PostgreSQL' AS "Engine",
       COALESCE(s.n_live_tup, 0) AS "Rows",
       NULL::bigint AS "Data_free",
       pg_total_relation_size(c.oid) - pg_indexes_size(c.oid) AS "Data_length",
       pg_indexes_size(c.oid) AS "Index_length",
       current_database() AS "Collation",
       NULL::timestamp AS "Create_time",
       NULL::timestamp AS "Update_time",
       COALESCE(obj_description(c.oid, 'pg_class'), '') AS "Comment"
FROM pg_class c
INNER JOIN pg_namespace n ON n.oid = c.relnamespace
LEFT JOIN pg_stat_user_tables s ON s.relid = c.oid
WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p'){$nameFilter}
ORDER BY c.relname
SQL;
            $list = $this->query($sql, $params, $source);
        } elseif (!empty($source)) {
            if (!empty($query['name'])) {
                $list = $this->query('show table status where name=:name', ['name' => $query['name']], $source);
            } else {
                $list = $this->query('show table status', [], $source);
            }
        } else {
            if (!empty($query['name'])) {
                $list = $this->query('show table status where name=:name', ['name' => $query['name']]);
            } else {
                $list = $this->query('show table status');
            }
        }

        $data = [];
        foreach ($list as $item) {
            $data[] = [
                'name' => $item['Name'],
                'engine' => $item['Engine'],
                'rows' => $item['Rows'],
                'data_free' => $item['Data_free'],
                'data_length' => $item['Data_length'],
                'index_length' => $item['Index_length'],
                'collation' => $item['Collation'],
                'create_time' => $item['Create_time'],
                'update_time' => $item['Update_time'],
                'comment' => $item['Comment'],
            ];
        }
        $total = count($data);
        $last_page = ceil($total / $per_page);
        $startIndex = ($current_page - 1) * $per_page;
        $pageData = array_slice($data, $startIndex, $per_page);
        return [
            'data' => $pageData,
            'total' => $total,
            'current_page' => $current_page,
            'per_page' => $per_page,
            'last_page' => $last_page,
        ];
    }

    /**
     * 获取列信息
     */
    public function getColumnList($table, $source): array
    {
        $columnList = [];
        if (preg_match("/^[a-zA-Z0-9_]+$/", $table)) {
            if ($this->isPostgreSql($source)) {
                $sql = <<<'SQL'
SELECT c.column_name AS "Field",
       CASE
           WHEN c.data_type = 'character varying' THEN 'varchar(' || c.character_maximum_length || ')'
           WHEN c.data_type = 'numeric' AND c.numeric_precision IS NOT NULL THEN 'numeric(' || c.numeric_precision || ',' || c.numeric_scale || ')'
           ELSE c.data_type
       END AS "Type",
       CASE WHEN EXISTS (
           SELECT 1
           FROM information_schema.table_constraints tc
           INNER JOIN information_schema.key_column_usage kcu
             ON tc.constraint_name = kcu.constraint_name
            AND tc.table_schema = kcu.table_schema
           WHERE tc.table_schema = c.table_schema
             AND tc.table_name = c.table_name
             AND tc.constraint_type = 'PRIMARY KEY'
             AND kcu.column_name = c.column_name
       ) THEN 'PRI' ELSE '' END AS "Key",
       COALESCE(pg_catalog.col_description(
           format('%I.%I', c.table_schema, c.table_name)::regclass::oid,
           c.ordinal_position
       ), '') AS "Comment",
       CASE WHEN c.is_identity = 'YES' OR c.column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS "Extra",
       c.column_default AS "Default",
       CASE WHEN c.is_nullable = 'YES' THEN 'YES' ELSE 'NO' END AS "Null"
FROM information_schema.columns c
WHERE c.table_schema = current_schema() AND c.table_name = :table
ORDER BY c.ordinal_position
SQL;
                $list = $this->query($sql, ['table' => $table], $source);
            } elseif (!empty($source)) {
                $list = $this->query('SHOW FULL COLUMNS FROM `' . $table . '`', [], $source);
            } else {
                $list = $this->query('SHOW FULL COLUMNS FROM `' . $table . '`');
            }
            foreach ($list as $column) {
                preg_match('/^\w+/', $column['Type'], $matches);
                $columnList[] = [
                    'column_key' => $column['Key'],
                    'column_name' => $column['Field'],
                    'column_type' => $matches[0],
                    'column_comment' => trim(preg_replace("/\([^()]*\)/", "", $column['Comment'])),
                    'extra' => $column['Extra'],
                    'default_value' => $column['Default'],
                    'is_nullable' => $column['Null'],
                ];
            }
        }
        return $columnList;
    }

    /**
     * 优化表
     */
    public function optimizeTable($tables)
    {
        foreach ($tables as $table) {
            if (preg_match("/^[a-zA-Z0-9_]+$/", $table)) {
                $this->execute($this->isPostgreSql() ? 'ANALYZE "' . $table . '"' : 'ANALYZE TABLE `' . $table . '`');
            }
        }
    }

    /**
     * 清理表碎片
     */
    public function fragmentTable($tables)
    {
        foreach ($tables as $table) {
            if (preg_match("/^[a-zA-Z0-9_]+$/", $table)) {
                $this->execute($this->isPostgreSql() ? 'VACUUM (ANALYZE) "' . $table . '"' : 'OPTIMIZE TABLE `' . $table . '`');
            }
        }
    }

    /**
     * 获取回收站数据
     */
    public function recycleData($table)
    {
        if (preg_match("/^[a-zA-Z0-9_]+$/", $table)) {
            $columns = $this->isPostgreSql()
                ? $this->query(
                    "SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = :table AND column_name = 'delete_time'",
                    ['table' => $table],
                )
                : $this->query('SHOW COLUMNS FROM `' . $table . '` where Field = "delete_time"');
            $isDeleteTime = false;
            if (count($columns) > 0) {
                $isDeleteTime = true;
            }
            if (!$isDeleteTime) {
                throw new ApiException('当前表不支持回收站功能');
            }
            // 查询软删除数据
            $request = request();
            $limit = $request ? ($request->input('limit') ?: 10) : 10;
            return Db::table($table)->whereNotNull('delete_time')
                ->order('delete_time', 'desc')
                ->paginate($limit)
                ->toArray();
        } else {
            return [];
        }
    }

    /**
     * 删除数据
     * @param $table
     * @param $ids
     * @return bool
     */
    public function delete($table, $ids)
    {
        if (preg_match("/^[a-zA-Z0-9_]+$/", $table)) {
            $count = Db::table($table)->whereIn('id', $ids)->delete($ids);
            return $count > 0;
        } else {
            return false;
        }
    }

    /**
     * 恢复数据
     * @param $table
     * @param $ids
     * @return bool
     */
    public function recovery($table, $ids)
    {
        if (preg_match("/^[a-zA-Z0-9_]+$/", $table)) {
            $count = Db::table($table)
                ->where('id', 'in', $ids)
                ->update(['delete_time' => null]);
            return $count > 0;
        } else {
            return false;
        }
    }

    private function isPostgreSql(string $source = ''): bool
    {
        $connection = $source ?: config('think-orm.default');
        return config("think-orm.connections.{$connection}.type") === 'pgsql';
    }

    private function query(string $sql, array $params = [], string $source = ''): array
    {
        return $source === '' ? Db::query($sql, $params) : Db::connect($source)->query($sql, $params);
    }

    private function execute(string $sql): mixed
    {
        return Db::execute($sql);
    }

}

