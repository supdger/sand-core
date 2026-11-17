#!/usr/bin/env bash
# 合并前门禁：一条命令跑完 CI 同款检查，任一项不过 exit 非 0。
#
# 用法：tools/pre-merge.sh [--fast|--full] [--base <ref>]
#   --fast  快档（默认）：与 merge-gates.yml 同款，分钟内跑完
#   --full  慢档：快档全部步骤 + 预留的 DB 测试位
#           （本仓暂无 DB 套件，两档当前一致；位先占着）
#   --base  命名检查 diff 的基准 ref（默认 origin/main，取不到退 main）
#
# 步骤：
#   1. PHPStan level max（phpstan.neon.dist；存量错误登记在
#      phpstan-baseline.neon，口径 = 不新增错误）
#   2. strict types / 禁用写法（tools/check-strict-types.sh；
#      存量登记 tools/strict-types-baseline.txt，口径 = 不新增违规）
#   3. 命名检查：code --diff / notes --diff / terms（对 --base…HEAD 的
#      diff，「不新增问题」口径；豁免登记 naming-allow.txt）
#   4. 依赖方向：tools/check-deps.sh
#   5. 测试：php tools/run-tests.php（tests/ 独立断言脚本 +
#      tools/ 发布断言 + 前端发布回环）
#
# 前置：vendor/bin/phpstan 不存在时先 composer install。
#
# DB 说明：本仓现有测试均为静态/桩断言、不连数据库，故慢档的 DB 位
# 现在只打印占位信息；将来新增 DB 测试时须经由环境变量（如
# GATE_DB_DSN）配置连接，未配置须报错退出，不许静默跳过。
#
# CI 对应：快档 = merge-gates.yml（PR + 任意分支 push），
# 慢档 = merge-gates-slow.yml（main push + 每日定时 + 手动触发）。

set -u
cd "$(dirname "$0")/.."
fail=0

mode=fast
base=origin/main
while [ $# -gt 0 ]; do
    case "$1" in
        --fast|--full)
            mode="${1#--}"
            shift
            ;;
        --base)
            base="${2:?--base 需要一个 ref}"
            shift 2
            ;;
        *)
            echo "未知参数：$1（用法：tools/pre-merge.sh [--fast|--full] [--base <ref>]）" >&2
            exit 2
            ;;
    esac
done
if ! git rev-parse --verify --quiet "$base" >/dev/null; then
    base=main
fi

step() { echo "=== $1 ==="; }

step "1/5 PHPStan level max"
if [ ! -x vendor/bin/phpstan ]; then
    echo "[FAIL] vendor/bin/phpstan 不存在，先执行 composer install"
    fail=1
else
    vendor/bin/phpstan analyse --no-progress || fail=1
fi

step "2/5 strict types / 禁用写法（tools/check-strict-types.sh）"
bash tools/check-strict-types.sh || fail=1

step "3/5 命名检查（diff 基准：${base}）"
diff_file=$(mktemp /tmp/sand-core-gate-diff.XXXXXX)
git diff "${base}"...HEAD > "${diff_file}"
python3 tools/naming_check.py code --diff "$diff_file" --allow naming-allow.txt || fail=1
python3 tools/naming_check.py notes --diff "$diff_file" || fail=1
python3 tools/naming_check.py terms tools/naming-terms.tsv --diff "$diff_file" --allow naming-allow.txt || fail=1
rm -f "$diff_file"

step "4/5 依赖方向（tools/check-deps.sh）"
bash tools/check-deps.sh || fail=1

step "5/5 测试（php tools/run-tests.php）"
php tools/run-tests.php || fail=1

if [ "$mode" = "full" ]; then
    step "慢档预留位：DB 测试"
    # 套件未落地先占位；落地后此步须真实执行，不许静默跳过
    echo "[INFO] 本仓暂无 DB 测试套件，此位预留"
fi

if [ "$fail" -eq 0 ]; then
    echo "=== 全部门禁通过（${mode} 档） ==="
else
    echo "=== 存在未通过项，见上方 [FAIL] ==="
fi
exit "$fail"
