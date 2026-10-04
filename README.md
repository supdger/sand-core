# Sand Core

SandAdmin 的 Composer 核心包，包含：

- `server/plugin/sandadmin/`：Webman 后台核心后端源码；
- `sandadmin-artd/`：与后端同版本的管理端前端源码。

## 源码与安装

本仓提供 SandAdmin 核心后端及匹配的管理端源码。已有宿主通过 Composer 安装本包；[开发源码](https://github.com/supdger/sand-core/tree/main)与 GitHub 的 Source code 压缩包供开发使用，不是 SandPackage 业务插件安装包。

## 版本更新

[版本更新与升级影响（Wiki）](https://github.com/supdger/sandadmin/wiki/plugin-updates)按组件说明功能变化、修复和升级注意事项；[完整更新日志](CHANGELOG.md)保留历史记录。下载见 [最新稳定版](https://github.com/supdger/sand-core/releases/latest)；预发布及全部版本见 [公开发行](https://github.com/supdger/sand-core/releases)。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

安装后端：

```bash
composer require supdger/sand-core:^0.2
```

Composer 安装或更新时，Webman 插件安装钩子会将匹配版本的前端源码自动发布到
宿主的 `sandadmin-artd/`。它不会安装 Node.js 依赖或执行编译，也不会静默覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandadmin/`，前端目录应有 `.sand-core-source-manifest.json`。这只确认源码发布，不代表数据库初始化或登录通过。

升级至 0.2.1 后，确认 Composer 钩子已发布匹配的管理端源码，再重新构建并使用更新后的管理端。该版本修复插件安装重载后的初始化暂态失败；只更新后端不会更新已部署的前端构建。

运行管理端使用 Node.js 22（≥22.12.0）和 pnpm 11.19.0；`packageManager` 固定为 `pnpm@11.19.0`，声明下限为 Node.js 22.12.0、pnpm 11.19.0。Windows 已验证的安装与启动组合为 Node.js 22.23.3、pnpm 11.19.0；旧 pnpm 9 不适用于当前工作区配置。环境文件、依赖安装、服务启动及首次登录步骤沿用上述指南，以前端启动地址、登录后菜单和用户信息正常加载作为运行结果。

数据库安装完成后，安装页先检查管理端专属静态资源；未启动时保留完成页并提供前端启动命令，启动后点击“重新检查”，通过后再进入登录。开发端口被占用会明确报错，不再自动换到其他端口。重新访问 `/core/install` 可继续检查和恢复，不重复显示初始密码或初始化数据库。检查只证明静态资源响应，实际登录仍需正常完成；旧构建缺少标记文件时，请发布并重新构建匹配的前端源码。

独立域名或子路径部署可在服务端环境配置 `SANDADMIN_FRONTEND_URL`（如 `https://admin.example.com/` 或 `/admin/`），并让后端进程重新加载环境；该地址优先于开发端口。未配置时使用当前访问主机及前端环境文件的 `VITE_PORT`、`VITE_BASE_URL`。安装页支持临时更正地址。HTTPS、浏览器网络策略或站点 `img-src` 策略阻止检查时，不会宣称后台已可用；可核对部署后重试，或主动使用明确标记的手工核验入口，保持现有安全策略。

后台核心更新由配套的 [SandPackage 0.2.0](https://github.com/supdger/sand-package) 提供，初次启用按其配置示例准备执行环境。0.2.0 不变更数据库结构或宿主骨架；更新契约与发行提交绑定，实际支持范围见对应版本说明。
