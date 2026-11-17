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
#   3. dev-only 引用门禁：php tools/check-dev-refs.php（清单
#      tools/dev-only-paths.txt，dev-only 符号不得漏出哨兵/豁免面）
#   4. 命名检查：code --diff / notes --diff / terms（对 --base…HEAD 的
#      diff，「不新增问题」口径；豁免登记 naming-allow.txt）
#   5. 基线行数：phpstan-baseline.neon / naming-allow.txt /
#      tools/strict-types-baseline.txt 对 --base 只减不增（与 CI
#      「快档：基线只减不增」同口径）；--base 上还没有该检查项时属
#      首次落地，增长只 WARN 不拦
#   6. 依赖方向：tools/check-deps.sh
#   7. 测试：php tools/run-tests.php（tests/ 独立断言脚本 +
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

step "1/6 PHPStan level max"
if [ ! -x vendor/bin/phpstan ]; then
    echo "[FAIL] vendor/bin/phpstan 不存在，先执行 composer install"
    fail=1
else
    vendor/bin/phpstan analyse --no-progress || fail=1
fi

step "2/7 strict types / 禁用写法（tools/check-strict-types.sh）"
bash tools/check-strict-types.sh || fail=1

step "3/7 dev-only 引用门禁（tools/check-dev-refs.php）"
php tools/check-dev-refs.php || fail=1

step "4/7 命名检查（diff 基准：${base}）"
diff_file=$(mktemp /tmp/sand-core-gate-diff.XXXXXX)
git diff "${base}"...HEAD > "${diff_file}"
python3 tools/naming_check.py code --diff "$diff_file" --allow naming-allow.txt || fail=1
python3 tools/naming_check.py notes --diff "$diff_file" || fail=1
python3 tools/naming_check.py terms tools/naming-terms.tsv --diff "$diff_file" --allow naming-allow.txt || fail=1
rm -f "$diff_file"

step "5/7 基线行数（diff 基准：${base}）"
# 与 CI「快档：基线只减不增」同一 bootstrap 锚点：${base} 上的
# merge-gates.yml 还没有该检查项时闸属首次落地，增长只 WARN 不拦
if git show "${base}:.github/workflows/merge-gates.yml" 2>/dev/null | grep -q '基线只减不增'; then
    bootstrap=0
else
    bootstrap=1
fi
for f in phpstan-baseline.neon naming-allow.txt tools/strict-types-baseline.txt; do
    before=$(git show "${base}:${f}" 2>/dev/null | wc -l | tr -d ' ')
    if [ -f "${f}" ]; then
        after=$(wc -l < "${f}" | tr -d ' ')
    else
        after=0
    fi
    if [ "${after}" -gt "${before}" ]; then
        if [ "${bootstrap}" -eq 1 ]; then
            echo "[WARN] ${f}：${before} 行 → ${after} 行（+$((after - before))）"
        else
            echo "[FAIL] ${f}：${before} 行 → ${after} 行（+$((after - before))），基线只许减不许加"
            fail=1
        fi
    else
        echo "[PASS] ${f}：${before} 行 → ${after} 行"
    fi
done
if [ "${bootstrap}" -eq 1 ]; then
    echo "[WARN] 基线闸首次落地，增长部分随本 PR 人审；合并入 main 后本检查生效"
fi

step "6/7 依赖方向（tools/check-deps.sh）"
bash tools/check-deps.sh || fail=1

step "7/7 测试（php tools/run-tests.php）"
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
