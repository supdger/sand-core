# Sand Core

SandAdmin 的 Composer 核心包，包含：

- `server/plugin/sandadmin/`：Webman 后台核心后端源码；
- `sandadmin-artd/`：与后端同版本的管理端前端源码。

安装后端：

```bash
composer config repositories.sand-core vcs https://github.com/supdger/sand-core
composer require supdger/sand-core:^0.1
```

前端源码不会自动安装依赖或编译。将源码发布到 `vendor` 外的开发目录：

```bash
php vendor/supdger/sand-core/tools/publish-frontend.php ../sandadmin-artd
```

发布工具不会静默覆盖未知修改。
