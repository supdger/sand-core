#!/usr/bin/env bash
# 合并前门禁：一条命令跑完 CI 同款检查，任一项不过 exit 非 0。
#
# 用法：tools/pre-merge.sh [--base <ref>]
#   --base  命名检查 diff 的基准 ref（默认 origin/main，取不到退 main）
#
# 步骤：
#   1. PHPStan level max（phpstan.neon.dist；存量错误登记在
#      phpstan-baseline.neon，口径 = 不新增错误）
#   2. 测试：php tools/run-tests.php（tests/ 独立断言脚本 + tools/ 发布断言
#      + 前端发布回环）
#   3. 命名检查：code --diff / notes --diff / terms（对 --base…HEAD 的 diff，
#      「不新增问题」口径；豁免登记 naming-allow.txt）
#   4. 依赖方向：tools/check-deps.sh
#
# 前置：vendor/bin/phpstan 不存在时先 composer install。
#
# DB 说明：本仓现有测试均为静态/桩断言、不连数据库，故无 DB 步骤；
# 将来新增 DB 测试时须经由环境变量（如 GATE_DB_DSN）配置连接，
# 未配置须报错退出，不许静默跳过。

set -u
cd "$(dirname "$0")/.."
fail=0

base=origin/main
if [ "${1:-}" = "--base" ]; then
    base="${2:?--base 需要一个 ref}"
fi
if ! git rev-parse --verify --quiet "$base" >/dev/null; then
    base=main
fi

step() { echo "=== $1 ==="; }

step "1/4 PHPStan level max"
if [ ! -x vendor/bin/phpstan ]; then
    echo "[FAIL] vendor/bin/phpstan 不存在，先执行 composer install"
    fail=1
else
    vendor/bin/phpstan analyse --no-progress || fail=1
fi

step "2/4 测试（php tools/run-tests.php）"
php tools/run-tests.php || fail=1

step "3/4 命名检查（diff 基准：${base}）"
diff_file=$(mktemp /tmp/sand-core-gate-diff.XXXXXX)
git diff "${base}"...HEAD > "${diff_file}"
python3 tools/naming_check.py code --diff "$diff_file" --allow naming-allow.txt || fail=1
python3 tools/naming_check.py notes --diff "$diff_file" || fail=1
python3 tools/naming_check.py terms tools/naming-terms.tsv --diff "$diff_file" --allow naming-allow.txt || fail=1
rm -f "$diff_file"

step "4/4 依赖方向（tools/check-deps.sh）"
bash tools/check-deps.sh || fail=1

if [ "$fail" -eq 0 ]; then
    echo "=== 全部门禁通过 ==="
else
    echo "=== 存在未通过项，见上方 [FAIL] ==="
fi
exit "$fail"
