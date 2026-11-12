<?php
// +----------------------------------------------------------------------
// | sandadmin [ sandadmin快速开发框架 ]
// +----------------------------------------------------------------------
// | Author: sai <1430792918@qq.com>
// +----------------------------------------------------------------------
namespace plugin\sandadmin\app\logic\system;

use plugin\sandadmin\app\cache\ConfigCache;
use plugin\sandadmin\app\model\system\SystemConfig;
use plugin\sandadmin\app\model\system\SystemConfigGroup;
use plugin\sandadmin\basic\think\BaseLogic;
use plugin\sandadmin\exception\ApiException;
use plugin\sandadmin\utils\Helper;
use plugin\sandadmin\utils\SensitiveData;

/**
 * 参数配置逻辑层
 */
class SystemConfigLogic extends BaseLogic
{
    /**
     * 构造函数
     */
    public function __construct()
    {
        $this->model = new SystemConfig();
    }

    /**
     * 添加数据
     * @param mixed $data
     * @return mixed
     */
    public function add($data): mixed
    {
        // 打码占位符 '******' 表示"保持原值"；新记录没有原值，绝不能落库，按空串处理
        if (SensitiveData::isMaskedPlaceholder($data['value'] ?? null)) {
            $data['value'] = '';
        }
        // 新建请求一律忽略调用方传入的主键，id 由数据库生成
        unset($data['id']);
        $result = $this->model->create($data);
        $group = SystemConfigGroup::find($data['group_id']);
        ConfigCache::clearConfig($group->code);
        return $result;
    }

    /**
     * 编辑数据
     * @param mixed $id
     * @param mixed $data
     * @return bool
     */
    public function edit($id, $data): bool
    {
        // 打码占位符 '******' 表示"保持原值"：save 只更新传入字段，剔除后天然保留库中现值
        if (SensitiveData::isMaskedPlaceholder($data['value'] ?? null)) {
            unset($data['value']);
        }
        $result = parent::edit($id, $data);
        $group = SystemConfigGroup::find($data['group_id']);
        ConfigCache::clearConfig($group->code);
        return $result;
    }

    /**
     * 批量更新
     * @param mixed $group_id
     * @param mixed $config
     * @return bool
     */
    public function batchUpdate($group_id, $config): bool
    {
        $group = SystemConfigGroup::find($group_id);
        if (!$group) {
            throw new ApiException('配置组未找到');
        }

        // 打码占位符 '******' 表示"保持原值"：先收集涉及 id，一次回查库中现值
        $placeholderIds = [];
        foreach ($config as $item) {
            if (is_array($item)
                && isset($item['id'])
                && $item['id'] !== ''
                && SensitiveData::isMaskedPlaceholder($item['value'] ?? null)
            ) {
                $placeholderIds[] = $item['id'];
            }
        }
        $existingValues = [];
        if ($placeholderIds !== []) {
            $existingValues = $this->model
                ->whereIn('id', array_values(array_unique($placeholderIds)))
                ->column('value', 'id');
        }

        $saveData = [];
        foreach ($config as $key => $value) {
            $saveData[] = [
                'id' => $value['id'],
                'group_id' => $group_id,
                'name' => $value['name'],
                'key' => $value['key'],
                // 占位符回写现值（查无记录按空串）；正常值与空串 '' 语义不变
                'value' => SensitiveData::resolveWriteValue(
                    $value['value'] ?? null,
                    $existingValues[$value['id'] ?? null] ?? null
                )
            ];
        }
        // upsert: 根据 id 更新，如果不存在则插入
        $this->model->saveAll($saveData);
        ConfigCache::clearConfig($group->code);
        return true;
    }

    /**
     * 获取配置数据
     * @param mixed $code
     * @return array
     */
    public function getData($code): array
    {
        $group = SystemConfigGroup::where('code', $code)->findOrEmpty();
        if (empty($group)) {
            return [];
        }
        $config = SystemConfig::where('group_id', $group['id'])->select()->toArray();
        return $config;
    }

    /**
     * 获取配置组
     */
    public function getGroup($config): array
    {
        return ConfigCache::getConfig($config);
    }

    /**
     * 配置列表出参打码：兼容分页结构（含 data 行集）与 saiType=all 平铺行集。
     * 仅用于 index 出参；getData/getGroup/ConfigCache 内部读取保持明文，不得调用本方法。
     *
     * @param mixed $data getList 返回的分页数组或行集
     * @return mixed
     */
    public function maskConfigOutput($data): mixed
    {
        return is_array($data) ? SensitiveData::maskConfigRows($data) : $data;
    }

}

