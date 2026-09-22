# Sand Core

SandAdmin 的 Composer 核心包，包含：

- `server/plugin/sandadmin/`：Webman 后台核心后端源码；
- `sandadmin-artd/`：与后端同版本的管理端前端源码。

安装后端：

```bash
composer require supdger/sand-core:^0.1
```

Composer 安装或更新时，Webman 插件安装钩子会将匹配版本的前端源码自动发布到
宿主的 `sandadmin-artd/`。它不会安装 Node.js 依赖或执行编译，也不会静默覆盖未知修改。
