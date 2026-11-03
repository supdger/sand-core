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

### 首次安装：令牌、管理员密码与强制改密

安装向导（`/core/install`）只在未安装时可用：宿主根目录 `.env` 或 `runtime/install.lock` 任一存在，页面与安装接口都返回 404。

1. **安装令牌**：服务启动（或首次打开安装页）时，服务器生成一次性令牌，输出到运行 SandAdmin 的控制台/日志，同时保存在仅属主可读的 `runtime/install-token`。安装页必须填写该令牌；缺失或错误时在连接数据库之前即被拒绝。安装成功后令牌文件自动删除并写入 `runtime/install.lock`。令牌不会出现在任何网页中。
2. **管理员账号**：安装页填写管理员用户名和密码（至少 12 位，需确认）；密码留空则随机生成强密码，只在安装成功页显示一次。仓库种子数据不含任何默认口令；选择“附带演示数据”时，演示账号保持禁用。数据库须已存在，库名由安装页填写，没有默认库名；`DB_PASSWORD` 也没有默认值。
3. **强制改密**：初始管理员在密码随机生成或命中旧默认口令时，首次登录后必须先修改密码（至少 12 位且不同于原密码）；操作者自行填写符合要求的密码则不强制。强制改密期间后端拒绝除“修改本人密码”“获取用户信息”之外的所有接口，管理端自动跳转 `/auth/change-password`。升级已有部署时先执行 `server/plugin/sandadmin/db/update-0.2.7.pgsql` 增加 `must_change_password` 字段，并将口令哈希仍命中旧种子默认哈希的账号标记为强制改密（幂等，不修改密码本身）。
4. **JWT 密钥**：`config/plugin/tinywan/jwt/app.php` 中 `access_secret_key`、`refresh_secret_key` 不得为空、不得仍是 `__JWT_ACCESS_SECRET_KEY__` / `__JWT_REFRESH_SECRET_KEY__` 占位符，且至少 32 字节；否则服务拒绝启动、登录与鉴权请求被拒绝，并提示重新生成（例如 `php -r "echo bin2hex(random_bytes(32));"`）。`tinywan/jwt` 安装时配置文件不可写会静默保留占位符，请在安装后确认。
5. 令牌文件、安装锁和 JWT 配置含运行时秘密，不得提交到 Git。
6. SecurityBootstrap 在每个 worker 启动时校验 JWT 密钥，`php webman <cmd>` 等 CLI 命令同样会执行该校验；密钥无效时 Workerman 主进程随之停止（不会陷入 worker 重启循环），按报错信息重新生成密钥后重新启动即可。
7. 纯环境变量部署（无 `.env`、无锁文件）的宿主会一直被视为未安装，安装入口保持令牌门控；此类部署建议安装后手工创建 `server/runtime/install.lock`。

### 安装页的静态资源检查

数据库安装完成后，安装页先检查管理端专属静态资源；未启动时保留完成页并提供前端启动命令，启动后点击“重新检查”，通过后再进入登录。开发端口被占用会明确报错，不再自动换到其他端口。检查在安装完成页内进行；安装锁生效后 `/core/install` 返回 404，刷新或关闭该页后请直接访问前端后台地址。检查只证明静态资源响应，实际登录仍需正常完成；旧构建缺少标记文件时，请发布并重新构建匹配的前端源码。

### 前端地址与独立域名部署

独立域名或子路径部署可在服务端环境配置 `SANDADMIN_FRONTEND_URL`（如 `https://admin.example.com/` 或 `/admin/`），并让后端进程重新加载环境；该地址优先于开发端口。未配置时使用当前访问主机及前端环境文件的 `VITE_PORT`、`VITE_BASE_URL`。安装页支持临时更正地址。HTTPS、浏览器网络策略或站点 `img-src` 策略阻止检查时，不会宣称后台已可用；可核对部署后重试，或主动使用明确标记的手工核验入口，保持现有安全策略。

## 配套的 SandPackage

后台「系统更新」和插件安装器由 [SandPackage](https://github.com/supdger/sand-package) 提供：0.2.0 起提供后台系统更新，0.2.4 起提供仓库读取的暂态恢复。两个包按版本配套发行，应同时升级；具体的配套版本要求以各自 CHANGELOG 中对应版本的说明为准。更新契约与发行提交绑定，实际支持范围见对应版本说明。

## 许可与反馈

本包以 [MIT License](LICENSE) 发行。问题与建议请提交到 [Issues](https://github.com/supdger/sand-core/issues)。
