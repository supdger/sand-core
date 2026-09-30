# Sand Core

SandAdmin 的 Composer 核心包，包含：

- `server/plugin/sandadmin/`：Webman 后台核心后端源码；
- `sandadmin-artd/`：与后端同版本的管理端前端源码。

## 安装前提

面向已有 SandAdmin 宿主；尚未准备宿主时，先按[本地运行与首次安装](https://github.com/supdger/sandadmin/wiki/getting-started)获取源码和准备 PostgreSQL。

下列后端命令在宿主 `server/` 目录执行，需要 PHP ≥8.2 和 Composer，并保留宿主 `composer.json` 中 `support\Plugin::install` 的 `post-package-install`、`post-package-update` 钩子。安装前备份现有配置和前端修改；冲突时先核对发布清单，不强行覆盖。

安装后端：

```bash
composer require supdger/sand-core:^0.1
```

Composer 安装或更新时，Webman 插件安装钩子会将匹配版本的前端源码自动发布到
宿主的 `sandadmin-artd/`。它不会安装 Node.js 依赖或执行编译，也不会静默覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandadmin/`，前端目录应有 `.sand-core-source-manifest.json`。这只确认源码发布，不代表数据库初始化或登录通过。

运行管理端还需要 Node.js 和 pnpm：前端声明下限为 Node.js 20.19.0、pnpm 8.8.0；当前 v9 锁文件应使用 pnpm 9，推荐按上述指南使用 Node.js 22（≥22.12.0）和 pnpm 9.15.9。环境文件、依赖安装、服务启动及首次登录步骤沿用该指南，以前端启动地址、登录后菜单和用户信息正常加载作为运行结果。
