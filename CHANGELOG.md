# 更新记录

## 未发布

安全（F2）：去掉 HTTP 免登安装面。无版本号变更，不随本次说明发布。

- 删除 `InstallController`、安装页和 `/core/install`、`/install/install` 路由。开发与生产使用同一份路由文件，没有按环境重新打开安装入口的开关。
- 全新安装只走 `bin/sand install`（密码用环境变量，不用命令行参数）。已有库的 `db/update-*.pgsql` 走 `bin/sand migrate`。本包没有 doctrine/migrations，也不再提供 HTTP 过渡入口。
- `bin/sand build --production` 拒绝任何 `/install*` 路由。`tools/assert-no-http-install.php` 在构建后请求这些路径，并在删除 `.env` 后再次请求，均须为 404。
- Worker 启动不再生成安装令牌，也不在进程内改写已安装标志。

## 0.2.8（2026-11-03）

安全修复：收敛接口与日志中的秘密字段输出，无数据库变更、无需迁移。

- 用户列表：`SystemUser` 模型声明 `$hidden = ['password', 'delete_time']`，`/core/user/index` 等经模型序列化/分页的输出不再包含口令哈希；登录校验、改密、重置密码、初始管理员写入等内部路径按属性读取/赋值，不受影响。
- 邮件记录：`SystemMail` 模型隐藏 `code`（邮箱验证码），邮件日志列表不再回显验证码（保留 `delete_time`）。
- 回收站：`recycleData` 直读数据表不经模型，输出前按列名剔除敏感列——名称（忽略大小写及 `_`/`-`）含 `password`、`passwd`、`pwd`、`secret`、`token`、`privatekey`、`apikey`、`accesskey`、`credential`、`authorization`、`cookie` 的列，以及验证码类列（`code`、`otp`、`captcha`、`verify_code` 等）。可用表范围与分页结构不变。
- 日志遮盖：新增 `utils/SensitiveData` 统一口径；异常日志、debug 模式 `request_param` 回显与操作日志 `request_data` 对敏感键递归遮盖（含嵌套数组），操作日志不再只遮顶层四个固定键。
- 代码生成器模型模板增加声明 `$hidden` 的提示注释。
- 未改变：系统配置管理（`SystemConfig`）仍按其管理用途原样返回配置值，含邮件/存储等凭据，访问由权限控制，不在本次范围。
- 已知遗留：回收站（`recycleData`）对 `sand_system_config` 仍会输出 `value` 列（含 SMTP/OSS 等凭据），与配置管理同属“凭权限访问的设计面”，该路径要求 `core:recycle:index` 权限。
- 功能副作用：回收站行会剔除名为 `code` 的列，包括部门、角色、字典等表的业务编码列。

## 0.2.7（2026-11-03）

安全加固，**升级须知见末条**。

- 安装器防接管：安装向导仅在未安装时可用，根 `.env` 或 `runtime/install.lock` 任一存在即对 `/core/install` 页面与安装接口一律返回 404。未安装时生成一次性令牌（`random_bytes(32)` 十六进制，写入仅属主可读的 `runtime/install-token` 并在服务器控制台/日志输出一次），安装表单必须填写；缺失或错误令牌在任何数据库连接之前即被拒绝（常量时间比较），安装成功后删除令牌并写入安装锁。
- 初始管理员：安装时由操作者填写用户名和密码（至少 12 位、需确认）；密码留空则随机生成强密码，仅在安装成功页显示一次。仓库种子 SQL 与代码不再包含 `123456` 或任何已知口令哈希；演示数据中的非管理员账号一律禁用并写入随机口令。安装数据默认改为“纯净数据”，演示数据需显式选择。
- 首次登录强制改密：`sand_system_user` 新增 `must_change_password`。初始管理员置位后，登录返回并在用户信息中带出该标志，除“修改本人密码”和“获取用户信息”外的受保护接口全部拒绝（HTTP 200 + `code: 400`，提示先修改初始密码）；修改成功（新密码至少 12 位且与原密码不同）后清除标志。管理端新增 `/auth/change-password` 页，登录后检测到标志即跳转，修改成功后需重新登录。
- 数据库配置：生成的 `config/think-orm.php`、`config/database.php` 不再回退 `DB_PASSWORD=123456` 与库名 `sandadmin`，`DB_NAME`/`DB_PASSWORD` 缺失时加载配置即抛出明确错误；安装器只使用操作者填写的库名。
- JWT 密钥自检：启动（`config/bootstrap.php` 注册的启动自检）以及登录、鉴权中间件都会校验 `tinywan/jwt` 的 access/refresh 密钥，为空、仍是 `__JWT_*__` 占位符或短于 32 字节即拒绝启动/请求并给出重新生成方法，不再静默放行。安装向导在密钥不安全时同样拒绝安装。`scripts/sync-sandadmin-demo.sh` 发现 demo 宿主 JWT 配置缺失、不可读或仍是占位符时改为非零退出。
- 行为变化：安装完成后 `/core/install` 返回 404，不再展示“重新检查”完成页；管理端入口检查仍在安装向导完成页内进行，之后直接访问后台地址。
- 升级须知：已有部署需执行 `db/update-0.2.7.pgsql`（幂等，增加 `must_change_password` 列；未执行前账号按“无需改密”处理，不会被锁定）；旧版 `.env` 已存在的宿主不受安装器影响。**仍使用旧默认口令 `admin/123456` 的部署应立即修改密码**，也可执行 `UPDATE sand_system_user SET must_change_password = 1 WHERE id = 1;` 强制其下次登录改密。宿主 `config/think-orm.php` 等由旧安装器生成的文件仍含旧默认值，建议改用新模板（缺少 `DB_NAME`/`DB_PASSWORD` 时报错）。需发布并构建匹配的管理端源码。
- 旧部署默认口令处置：`db/update-0.2.7.pgsql` 在加列后追加幂等 `UPDATE`，把口令哈希精确命中 0.2.7 之前种子默认哈希集合（admin、martin、allen、mark、dowson、timi_boss、dev_wang、dev_li 等账号的全部去重哈希）的现有账号标记为 `must_change_password = 1`，强制其下次登录改密；语句只置标志、不修改密码本身，可安全重复执行。无自动应用升级 SQL 的入口，仍需按升级须知在目标库手动执行一次。
- 自行填写密码不再强制改密：初始管理员仅当密码留空随机生成，或所填写密码哈希等值命中旧默认口令哈希集合时才置 `must_change_password = 1`；操作者自行填写符合强度要求的密码时置 0，首次登录不强制改密。安装接口返回的 `must_change_password`/`notice` 与安装页提示同步更新（哈希集合与 `update-0.2.7.pgsql` 共用 `InstallGuard::LEGACY_PASSWORD_HASHES`，测试校验一致）。
- 启动自检真正“拒绝启动”：JWT 密钥不安全时，worker 内的启动自检在输出错误后同时向 Workerman 主进程发送停止信号（等价于 `php start.php stop`），服务整体退出；此前只是 worker 抛错退出并被主进程无限重启（实测约每秒 780 行日志的重启风暴，且始终不提供服务）。`php webman <cmd>` 等 CLI 场景没有 worker，只抛错、不发信号。
- 文档修正：`docs/sandadmin-demo.md` 与现行为对齐——同步脚本发现 demo 宿主 JWT 配置缺失、不可读或仍是占位符时以退出码 4 拒绝继续（不再“同步仍可完成”），且应用启动、登录与鉴权均拒绝弱密钥或占位符密钥。

## 0.2.6（2026-11-02）

- Composer 发布生命周期加固：安装/更新先装后端载荷再发布前端，前端失败降级为 `[sandadmin]` 告警并附 `composer run-script sand:publish` 恢复命令，不再中止 Composer；无清单非空目标改为逐文件 sha256 收养判定；`.pending` 两阶段清单与中断重入；升级删除集与用户修改跳过；`uninstall` 清单驱动且 `config/` 仅首次写入；`extra.sandadmin.frontend-path` 显式目标路径与越界/符号链接校验；`SAND_FRONTEND_PUBLISH=skip|force` 逃生门。
- 前端 env 演进：按 example 键集补齐缺失键；`VITE_LOCK_ENCRYPT_KEY` 安装期随机生成、`VITE_VERSION` 取包版本；安装器写 `.env` 统一加引号转义。
- 发布面收敛：单一排除规则源（`PublishExclusions`）驱动 publisher/archive/export-ignore；载荷剥离 husky/commitlint/lint-staged/clean-dev/vscode 等开发物；新增 `assert-frontend-parity`、`assert-publish-exclusions` 门禁与 publisher/install/target 测试。

## 0.2.5（2026-10-31）

- 管理端白屏兜底：新增纯 HTML 静态错误边界（App.vue/main.ts/index.html 三层），渲染失败呈现可诊断错误页（重试/重载/重置本地状态）而非白屏；布局组件静态 import + 全局注册（`layout-components.ts`），目录被宿主升级/恢复 rename 交换后不再依赖 unplugin 内存映射；加载遮罩 20 秒看门狗；chunk 加载与 `vite:preloadError` 提供一键重载；`routeInitFailed` 可经"重试"清除、异常页按钮先重置初始化状态；动态路由兜底重定向可见化；持久化状态迁移做形状/版本校验、腐坏检测接入真实登录态；关键 env 缺失启动即报错；`ArtPageContent` 刷新/过渡闩锁以 finally/看门狗释放；`.gitignore` 与 sand-package 恢复指纹生成物清单注明同步维护。配套 SandPackage 变更见 `openspec/changes/archive/2026-10-31-harden-system-update-recovery/`。

## 0.2.4（2026-10-05）

- 修复插件安装后页面已恢复但全屏加载遮罩仍不退出的问题；加载状态随当前导航收尾，旧导航取消或失败不会关闭新导航的遮罩。
- 原生 Windows Chrome 实际安装 SandIAM 0.8.4、SandLicense 0.1.2，均无需手动刷新恢复插件仓库；两次恢复后持续观察超过 110 秒。Core 导航回归 36 项通过。
- 无数据库、宿主骨架或依赖变更。需发布并构建匹配的管理端源码；发布版本不会自动升级已有部署。建议配套 SandPackage 0.2.4 获得仓库读取的暂态恢复。


## 0.2.1（2026-10-04）

- 修复插件安装触发服务重载后，管理端核心初始化因短暂连接失败停留在 500 页的问题；初始化读取在共享 15 秒窗口内最多尝试六次，已成功请求不重复读取。
- 恢复期间不重复弹出暂态错误；权限拒绝、取消、超时、真实业务错误及持续不可用保留原失败边界。
- 已在原生 Windows Chrome 通过 SandIAM 0.8.4 标准市场安装、自动恢复、菜单及列表访问切片验收；持续故障和权限等负例按既定边界验证，不代表所有插件及平台已验收。
- 本版本无数据库或宿主骨架变更。升级需发布并构建匹配的管理端前端源码；仅更新后端 Composer 包不足以让旧管理端获得修复。

## 0.2.0

- 核心版本线升至 0.2.0，与 SandPackage 0.2.0 配套交付后台系统更新。
- 修正核心运行版本声明，使后台宿主版本与 Composer 发行版本一致，避免插件兼容检查读取旧版本。
- 提供与本发行提交绑定的系统更新契约；本版本不变更数据库结构或宿主骨架。后台更新功能由 SandPackage 0.2.0 提供，初次使用仍需管理员配置更新执行环境。

## 0.1.9

- 修复菜单初始化失败后，访问首页或重新登录仍进入 404 的问题；初始化错误保留为 500，权限拒绝和空授权菜单保留为 403。
- 登录时重新初始化路由，取消上次退出的延迟清理，并忽略旧会话的初始化结果，避免快速重新登录后新路由被移除。

## 0.1.8

- 安装时明确提示 PostgreSQL 数据库不存在、用户不存在、身份认证失败、权限不足、主机名解析失败、连接被拒绝、连接超时和 PDO 驱动缺失。认证失败时提示检查用户名和密码；只有数据库明确报告用户不存在时才单独提示用户名问题。
- 支持中文 UTF-8 和 Windows CP936/GBK 数据库诊断，安装失败返回有效 JSON 和固定提示，不向页面暴露原始连接异常或密码。
- 安装页区分服务器响应格式异常、HTTP 请求失败和无法连接服务，避免一概提示网络连接问题。

## 升级与记录范围

以上条目沿用 [Sand Core 0.1.9](https://github.com/supdger/sand-core/releases/tag/0.1.9) 源码标签中的更新记录，覆盖已核实的 0.1.8、0.1.9；更早版本未补写推测历史。更新后应核对 Composer 锁定版本，并通过包内工具发布匹配的管理端源码，再验证登录、首页和菜单加载。日志记录不代替目标宿主升级或业务验收。
