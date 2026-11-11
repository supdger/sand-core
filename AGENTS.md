# AGENTS.md — Sand Core

## 本仓是什么

PHP 库 `supdger/sand-core`：SandAdmin 核心后端（Webman 插件）+ 与之同版本的管理端前端源码，经 Composer 安装进已有宿主，不是可独立运行的宿主工程。

主要目录：

- `composer.json`：autoload `SandAdmin\Core\` → `server/`、`plugin\sandadmin\` → `server/plugin/sandadmin/`；script `sand:publish`。
- `server/`：核心发布器 `Install.php`、`FrontendPublisher.php`、`PublishExclusions.php`，及 `plugin/sandadmin/` 完整插件——`app/`（controller/logic/model/service 等）、`basic/`、`db/`、`command/`、`tools/`、`public/`、`config/`。
- `server/plugin/sandadmin/db/`：PostgreSQL 初始化与升级脚本（`*.pgsql`，含 `update-*.pgsql`）。
- `sandadmin-artd/`：完整前端工程（package.json + pnpm-lock）。
- `tests/`：PHP 检查脚本；`tools/`：前端发布与断言工具。

## 命名与注释规范

PHP ≥8.2（Webman/Workerman，PSR-12/PSR-4）+ Vue3/TS 前端。完整规范见 `/Users/supdger/agent-work/grokbot/devin-queue/命名与注释规范.md`，要点：

**数据库（PostgreSQL，小写蛇形）**

- 业务表前缀 `sand_<域>_*`（核心为 `sand_system_*`/`sand_tool_*`），主键 `id`；列名主体 ≤3 词、≤30 字符。
- 布尔 `is_`/`has_`/`can_`/`must_` 或形容词；时间点 `*_time`、日期 `*_date`，固定三列 `create_time`/`update_time`/`delete_time`；数量带单位。
- `status` 表示启停（1 启用 / 2 停用），`state` 表示流转，两者分开；索引约束 `uk_`/`ix_`/`fk_`/`ck_` + 完整表名。
- 历史 `sa_*` 表与存量列名保留原样；改到那段代码时按规范顺手改，已发布结构不为本规范单独改名。

**接口与契约**

- 路径全小写连字符、资源用复数；JSON/查询参数小写蛇形、与列同名。
- 枚举小写蛇形（任务状态 `queued`/`running`/`succeeded`/`failed`/`cancelled`）；错误码大写蛇形；权限码 `域:资源:动作`。
- 迁移/升级脚本 `<序号或版本>_<动词>_<对象>`（本仓 `db/` 下为 `update-<版本>.pgsql` 等），已发布不改名、不改内容。

**代码**

- 类/组件/类型 PascalCase，函数/变量/属性 camelCase，常量大写蛇形；与列、接口字段一一对应的键保持蛇形原样。
- 函数/方法 ≤4 词且动词开头；不用 Manager/Helper/Util/Processor/Wrapper/Info/Data 收尾；不用 usr/pwd/cnt/msg/btn/tbl/mgr/svc/cfg/val/ret 等自造缩写；变量名不带类型后缀；布尔以 is/has/can/should 开头。
- 前端组件 `PascalCase.tsx`（一文件一主组件，文件名同组件名），hook `useXxx.ts`，测试 `同名.test.ts(x)`。

**注释**

- 只写代码看不出的原因、约束、单位、取值含义；禁逐行翻译、禁「此函数用于……」、禁流水账、禁横幅分隔线、禁表情、禁改动记录、禁注释掉的旧代码。
- 新文件注释用简体中文；TODO 写成 `TODO(负责人或#编号): 原因`。

**统一叫法**（同一概念全仓一个词）：`dept`、`file`、`create_time`/`update_time`/`delete_time`、`status`=启停、`state`=流转、`announcement`、`task`/`task_run`（后台任务）、`schedule`/`schedule_run`（定时任务）、`dict_type`、`audit_log`、`visibility`、`cancelled`（双写 l）、`client`（OAuth 接入方）。

交付前自查：`python3 /Users/supdger/agent-work/grokbot/devin-queue/naming_check.py sql|code|notes|terms ...`（用法见脚本开头）。

## 提交规矩

- 只 `git add` 具体文件，禁止 `git add -A` / `git add .`。
- 不提交 `.env`、密钥、token、`glm.key` 等敏感文件。
- 不 force push、不 `reset --hard`、不用 `--no-verify`；不合并自己的 PR。

## 测试和检查命令

- `composer run sand:publish`（= `php tools/publish-frontend.php`，把前端源码发布到宿主 `sandadmin-artd/`）。
- `php tests/http-error-data-test.php`、`php tests/phase1-fixes.php`、`php tests/secret-exposure-test.php`（直接执行，无 phpunit）。
- `php tools/assert-frontend-parity.php`、`php tools/assert-publish-exclusions.php`（发布产物断言）。
- 前端：`cd sandadmin-artd && pnpm dev` / `pnpm lint` / `pnpm build`（含 `vue-tsc --noEmit`）/ `pnpm lint:prettier` / `pnpm lint:stylelint`；Node ≥20.19、pnpm 固定 11.19.0。
- 无 CI；本地开发用 `pnpm dev`，不做多余构建。

## 许可证

MIT（根 LICENSE，含 saithink 双版权行；NOTICE 声明含衍生自 saiadmin 的代码）。
