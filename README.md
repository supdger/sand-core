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
composer require supdger/sand-core:^0.2.4
```

Composer 安装或更新时，Webman 插件安装钩子会将匹配版本的前端源码自动发布到
宿主的 `sandadmin-artd/`。它不会安装 Node.js 依赖或执行编译，也不会静默覆盖未知修改。

发布成功后，宿主应有 `server/plugin/sandadmin/`，前端目录应有 `.sand-core-source-manifest.json`。这只确认源码发布，不代表数据库初始化或登录通过。

任何版本升级后，先确认 Composer 钩子已发布匹配的管理端源码，再重新构建并使用更新后的管理端；只更新后端不会更新已部署的前端构建。各版本的具体修复见 [CHANGELOG](CHANGELOG.md)。

### 管理端运行环境

运行管理端推荐已验证的 Node.js 22（≥22.12.0）和 pnpm 11.19.0；`packageManager` 固定为 `pnpm@11.19.0`，pnpm 工具链下限对齐已验证版本，Node.js 保留原有 `>=20.19.0` 声明。Windows 已验证的安装与启动组合为 Node.js 22.23.3、pnpm 11.19.0；pnpm 9.15.9 对当前工作区配置报错，请使用固定工具链。环境文件、依赖安装、服务启动及首次登录步骤沿用上述指南，以前端启动地址、登录后菜单和用户信息正常加载作为运行结果。

### 首次安装：只走 CLI

没有 HTTP 安装或重装页面。`/install`、`/core/install` 以及任何 `/install*` 路径都没有路由，开发环境和生产构建都一样；删掉 `.env` 也不会把安装入口打开。Worker 启动时只检查 JWT 密钥，不生成安装令牌，也不改写进程内的“已安装”标志。

在宿主 Webman 根目录（含 `plugin/sandadmin`）执行。源码仓库里等价入口是 `php bin/sand`。Composer 安装本包后也可以用 `vendor/bin/sand`。密码只放环境变量，不要写进命令行参数。

非交互安装（数据库必须已经建好，命令不会创建数据库，也不会把库名拼进 shell）：

```bash
export SAND_DB_PASSWORD='数据库密码'
export SAND_ADMIN_PASSWORD='至少 12 位的管理员密码'   # 省略则生成强密码，只在当前终端打印一次
php bin/sand install \
  --confirm=INSTALL \
  --host=127.0.0.1 \
  --port=5432 \
  --database=库名 \
  --user=数据库用户 \
  --data=pure \
  --admin-user=admin
```

`--data=pure` 是纯净数据，`--data=demo` 才会写入演示数据。演示账号保持禁用。终端里省略 `--host` 等项时会逐项询问；密码仍然只读 `SAND_DB_PASSWORD` 和可选的 `SAND_ADMIN_PASSWORD`。空数据库密码只允许 `--allow-empty-password=local` 且主机是 `127.0.0.1` 或 `::1`。

已有 `.env` 或 `runtime/install.lock` 时，安装命令会拒绝覆盖，除非同时加上 `--force` 和 `--confirm=INSTALL`，并且目标库里还没有 SandAdmin 表。目标库已有表时直接停止。安装成功后会写入 `.env`、数据库配置，以及 `runtime/install.lock`。请重启 Webman 进程后再登录。不要把 `.env`、安装锁或生成的密码提交到 Git。

升级已有库的 SQL 用迁移命令，按 `db/update-*.pgsql` 文件名顺序应用，并记入 `sand_schema_migration`。本包没有 doctrine/migrations：

```bash
php bin/sand migrate
```

已有部署增加强制改密字段时，仍可手动执行 `server/plugin/sandadmin/db/update-0.2.7.pgsql`（幂等）。`bin/sand migrate` 会应用同一类 `update-*.pgsql`。

1. **管理员账号**：`--admin-user` 默认 `admin`。`SAND_ADMIN_PASSWORD` 至少 12 位；留空则随机生成，只打印到当前终端一次，不写日志。仓库种子数据不含默认口令。
2. **强制改密**：初始管理员在密码随机生成或命中旧默认口令时，首次登录后必须先修改密码（至少 12 位且不同于原密码）；操作者自行设置符合要求的密码则不强制。强制改密期间后端拒绝除“修改本人密码”“获取用户信息”之外的所有接口，管理端自动跳转 `/auth/change-password`。
3. **JWT 密钥**：安装前 `config/plugin/tinywan/jwt/app.php` 必须存在，且不得仍含 `__JWT_ACCESS_SECRET_KEY__` / `__JWT_REFRESH_SECRET_KEY__` 占位符。密钥至少 32 字节，可用 `php -r "echo bin2hex(random_bytes(32));"` 生成。启动、登录和鉴权仍会拒绝空密钥、占位符和过短密钥。
4. **生产构建检查**：`php bin/sand build --production --out=目录` 只编译当前路由文件，不读取 `.env`。`php tools/assert-no-http-install.php` 会构建两次（有 `.env` / 删除 `.env`）并请求 `/install*`，期望全部为 404。
5. SecurityBootstrap 在每个 worker 启动时校验 JWT 密钥；密钥无效时 Workerman 主进程随之停止。`php webman <cmd>` 同样会执行该校验。数据库安装命令本身不启动 Webman。

绑定一台已经导入过结构的数据库，而不是做全新安装时，继续使用 `php plugin/sandadmin/tools/attach-existing.php`。那个工具只做只读核对并写 `.env`，不会走 HTTP。

### 管理端入口

数据库安装完成后直接访问前端后台地址并登录。开发端口被占用会在前端启动时报错，不再自动换端口。入口检查只证明静态资源响应，实际登录仍需正常完成；旧构建缺少标记文件时，请发布并重新构建匹配的前端源码。

### 前端地址与独立域名部署

独立域名或子路径部署可在服务端环境配置 `SANDADMIN_FRONTEND_URL`（如 `https://admin.example.com/` 或 `/admin/`），并让后端进程重新加载环境；该地址优先于开发端口。未配置时使用当前访问主机及前端环境文件的 `VITE_PORT`、`VITE_BASE_URL`。

## 配套的 SandPackage

后台「系统更新」和插件安装器由 [SandPackage](https://github.com/supdger/sand-package) 提供：0.2.0 起提供后台系统更新，0.2.4 起提供仓库读取的暂态恢复。两个包按版本配套发行，应同时升级；具体的配套版本要求以各自 CHANGELOG 中对应版本的说明为准。更新契约与发行提交绑定，实际支持范围见对应版本说明。

## 许可与反馈

本包以 [MIT License](LICENSE) 发行。问题与建议请提交到 [Issues](https://github.com/supdger/sand-core/issues)。
