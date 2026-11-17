#!/usr/bin/env bash
# strict types / 禁用写法检查（grep 口径，只扫 git 跟踪的 PHP 文件）。
#
# 范围：server/、tools/、tests/ 下全部 .php，与 phpstan.neon.dist 的
# paths 一致；vendor 不入 git，天然排除。
#
# 检查项：
#   A. 每个文件须含 declare(strict_types=1)；
#   B. 禁定义 __get/__set/__call/__callStatic、eval(、extract(、
#      可变变量 $$ ——这些写法让 PHPStan 与 AOT 无法静态确定行为。
#
# 基线：tools/strict-types-baseline.txt，每行「路径<TAB>规则名」。
# 命中 − 基线 = 新增违规 → 逐条列出并 exit 1；
# 基线里已修掉的条目打印 WARN 提醒删行收紧，不拦。
#
# 用法：
#   tools/check-strict-types.sh          门禁模式（默认）
#   tools/check-strict-types.sh --list   只输出当前命中（供一次性生成基线）

set -u
cd "$(dirname "$0")/.."

baseline="tools/strict-types-baseline.txt"

# 每行输出「路径<TAB>规则名」，即基线文件条目格式
list_violations() {
    git ls-files '*.php' | grep -E '^(server|tools|tests)/' | while IFS= read -r f; do
        if ! grep -Eq 'declare[[:space:]]*\([[:space:]]*strict_types[[:space:]]*=[[:space:]]*1' "$f"; then
            printf '%s\t%s\n' "$f" 'no-strict-types'
        fi
        for magic in __get __set __call __callStatic; do
            if grep -Eq "function[[:space:]]+${magic}[[:space:]]*\\(" "$f"; then
                printf '%s\t%s\n' "$f" "$magic"
            fi
        done
        # 方法调用 ->eval() / ::extract() 不算，故排除 > : 前置字符
        grep -Eq '(^|[^a-zA-Z0-9_>:])eval[[:space:]]*\(' "$f" \
            && printf '%s\t%s\n' "$f" 'eval'
        grep -Eq '(^|[^a-zA-Z0-9_>:])extract[[:space:]]*\(' "$f" \
            && printf '%s\t%s\n' "$f" 'extract'
        grep -Eq '\$\$[a-zA-Z_{]' "$f" \
            && printf '%s\t%s\n' "$f" 'variable-variable'
    done | LC_ALL=C sort -u
}

if [ "${1:-}" = "--list" ]; then
    list_violations
    exit 0
fi

total=$(git ls-files '*.php' | grep -cE '^(server|tools|tests)/' || true)
tmp_now=$(mktemp /tmp/strict-types-now.XXXXXX)
tmp_base=$(mktemp /tmp/strict-types-base.XXXXXX)
tmp_added=$(mktemp /tmp/strict-types-added.XXXXXX)
tmp_fixed=$(mktemp /tmp/strict-types-fixed.XXXXXX)
trap 'rm -f "$tmp_now" "$tmp_base" "$tmp_added" "$tmp_fixed"' EXIT

list_violations > "$tmp_now"
grep -vE '^[[:space:]]*(#|$)' "$baseline" 2>/dev/null | LC_ALL=C sort -u > "$tmp_base"
# comm 两列差集：只出现在命中里 = 新增违规；只出现在基线里 = 已修复
comm -13 "$tmp_base" "$tmp_now" > "$tmp_added"
comm -23 "$tmp_base" "$tmp_now" > "$tmp_fixed"

hits=$(wc -l < "$tmp_now" | tr -d ' ')
added=$(wc -l < "$tmp_added" | tr -d ' ')
fixed=$(wc -l < "$tmp_fixed" | tr -d ' ')

if [ "$added" -gt 0 ]; then
    echo "[FAIL] 新增违规 ${added} 条（命中 − 基线）："
    sed 's/^/  /' "$tmp_added"
    echo "       请改代码消除；确需登记须经人审，基线只许减不许加（M-09）"
fi
if [ "$fixed" -gt 0 ]; then
    echo "[WARN] 基线 ${fixed} 条对应违规已修复，可从 ${baseline} 删行收紧："
    sed 's/^/  /' "$tmp_fixed"
fi
if [ "$added" -eq 0 ]; then
    echo "[PASS] strict types 检查：${total} 个文件、命中 ${hits} 条，全部在基线内"
    exit 0
fi
exit 1
