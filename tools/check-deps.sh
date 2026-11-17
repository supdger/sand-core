#!/usr/bin/env bash
# 依赖方向检查（grep 扫 use 语句与 FQCN 字面量）。
#
# 本仓分层（composer.json autoload）：
#   server/*.php                 → SandAdmin\Core\*   宿主侧发布器层（3 个文件）
#   server/plugin/sandadmin/     → plugin\sandadmin\*  插件源码层
#
# 规则（依据：v4 D 必改 1——sand-core 是待脱钩的旧兼容层，不许反向
# 长出对宿主、其他插件或旧上游命名空间的依赖）：
#   1. 发布器层不得引用插件命名空间（use plugin\、\plugin\Xxx）。
#   2. 插件层不得引用 SandAdmin\Core\（宿主发布器）、plugin\<其他插件>、
#      Saithink\（旧上游命名空间）、app\（宿主应用层）。
#   允许：plugin\sandadmin 自引用、support\*（webman 框架 API）、PHP 内置。
#
# 用法：tools/check-deps.sh；命中即 FAIL、exit 1。

set -u
cd "$(dirname "$0")/.."
fail=0

hit() {
    # $1=描述 $2=grep -n 结果（空=干净）
    if [ -n "$2" ]; then
        echo "[FAIL] $1"
        echo "$2" | head -20
        fail=1
    fi
}

core_files=$(find server -maxdepth 1 -name '*.php' -print)

# 规则 1：SandAdmin\Core 不得依赖任何插件命名空间
hit "发布器层引用插件命名空间（use plugin\\…）" \
    "$(echo "$core_files" | xargs grep -nE '^use[[:space:]]+(function[[:space:]]+)?plugin\\' 2>/dev/null)"
hit "发布器层内联 FQCN 引用插件类（\plugin\…）" \
    "$(echo "$core_files" | xargs grep -nE '(new|extends|implements)[[:space:]]+\\plugin\\' 2>/dev/null)"

# 规则 2：插件层不得反向依赖宿主 / 其他插件 / 旧上游 / 宿主应用层
hit "插件层引用宿主发布器 SandAdmin\\Core" \
    "$(grep -rnE '^use[[:space:]]+(function[[:space:]]+)?SandAdmin\\' server/plugin/sandadmin --include='*.php' 2>/dev/null)"
hit "插件层引用其他插件命名空间（use plugin\ 非 sandadmin）" \
    "$(grep -rnE '^use[[:space:]]+(function[[:space:]]+)?plugin\\' server/plugin/sandadmin --include='*.php' 2>/dev/null | grep -v 'use plugin\\sandadmin')"
hit "插件层引用旧上游 Saithink 命名空间" \
    "$(grep -rnE '^use[[:space:]]+(function[[:space:]]+)?Saithink\\' server/plugin/sandadmin --include='*.php' 2>/dev/null)"
hit "插件层引用宿主应用层 app\\ 命名空间" \
    "$(grep -rnE '^use[[:space:]]+(function[[:space:]]+)?app\\' server/plugin/sandadmin --include='*.php' 2>/dev/null)"
hit "插件层内联 FQCN 引用宿主/其他插件类" \
    "$(grep -rnE '(new|extends|implements)[[:space:]]+\\(SandAdmin|Saithink|app|plugin)\\' server/plugin/sandadmin --include='*.php' 2>/dev/null | grep -v '\\plugin\\sandadmin')"

if [ "$fail" -eq 0 ]; then
    echo "[PASS] 依赖方向检查通过：发布器层不依赖插件层，插件层不反向依赖宿主/其他插件/旧上游"
fi
exit "$fail"
