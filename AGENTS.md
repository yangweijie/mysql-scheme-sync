# MySQL SchemaSync — AGENTS.md

> PHP 8.5+ FFI/libui 原生桌面 GUI：MySQL 数据库结构对比 + 迁移 SQL 生成。

## 快速命令

```bash
composer85 install              # 安装依赖
php85 bin/mysql-schema-sync.php  # 运行 GUI
```

PHP 8.5 路径：`/Users/jay/Library/PhpWebStudy/alias/php85` （macOS Homebrew 安装）。

## 技术栈

- **GUI**: `helgesverre/libui` (dev-main) — PHP FFI 绑定 libui-ng 原生桌面控件
- **UI 扩展**: `yangweijie/ui2` — MessageBox、DialogConfirm 等对话框
- **核心比对**: `9raxdev/mysql-struct-sync` (v1.0.3) — 基于 `mysqli` 的 MySQL 结构差异比较库（命名空间 `DDZH\`，类名 `MysqlStructSync`）
- **异步查询**: `yangweijie/think-orm-async` — 异步 MySQLi 查询包装
- **PHP 扩展依赖**: `ext-ffi`, `ext-pdo`, `ext-pdo_mysql`, `ext-openssl`

## 项目结构

```
bin/mysql-schema-sync.php       # 入口：初始化 FFI + ConfigStore → MainWindow
src/
  Config/Connection.php          # 连接数据类（id/name/host/port/user/password/database）
  Config/ConfigStore.php         # 连接管理 + AES-256-GCM 加密持久化到 ~/.mysql-schema-sync/
  Diff/DiffResult.php            # 差异结果纯数据结构（17 种差异类型的 array）
  Diff/StructSyncAdapter.php     # 包装 9raxdev/mysql-struct-sync 库（fetchAll → compare）
  Diff/AsyncStructureFetcher.php # 基于 MYSQLI_ASYNC + mysqli_poll 的并发 SHOW CREATE TABLE
  Diff/Schema.php                # 旧的 INFORMATION_SCHEMA 手工比对（已废弃，未删除）
  Gui/MainWindow.php             # 主窗口：连接选择 + 比对触发 + 迁移 SQL 生成
  Gui/ConnectionWindow.php       # 连接管理弹窗（增删改查、导入导出、测试连接）
  Gui/DiffTableModelDelegate.php # 差异表格的 TableModelDelegate（可勾选行）
  SqlGen/Generator.php           # 基于 diffSql 生成最终迁移 SQL
```

## 关键架构事实

### 比对流程（StructSyncAdapter）
1. `fetchAll(excludePatterns)` → `AsyncStructureFetcher` 并发获取两张库的 `SHOW CREATE TABLE`
2. `compare(excludePatterns)` → 调用 `buildDiffSql()` 生成 `ADD_TABLE/DROP_TABLE/MODIFY_FIELD/ADD_FIELD/DROP_FIELD/ADD_CONSTRAINT/DROP_CONSTRAINT/ADD_VIEW/...` 分组的 SQL 数组
3. 结果包装为 `DiffResult`（17 种差异类型各自独立 `array`）

### 重要：库的方向问题
`5raxdev/mysql-struct-sync` 的构造函数参数顺序是 `(self=目标库, refer=源库)`，但生成 SQL 的 ADD/DROP 方向与用户预期相反。**StructSyncAdapter 内部已 swap source/target 解决此问题**。

### macOS libui Table 坑
`Libui\Table` 必须在 `Window::show()` 之前创建，否则 macOS NSTableView 的 `cellValue()` 永远不会被调用。`MainWindow::run()` 中在 `show()` 之前调用了 `createResultAreaControls()` 创建空 Table。

### 异步与 UI 阻塞
- `Loop::delay(ms, cb)` 用于将耗时操作推迟到下一个事件循环 tick
- PHP 单线程：同步数据库查询期间 UI 完全阻塞
- 进度条通过库的 `on_phase`/`on_progress` 回调更新
- `ProgressBar::setValue(-1)` = 不确定模式（来回滚动）
- 取消按钮在同步比对期间无效（库调用无法中断）

### 异步并发查询（AsyncStructureFetcher）
- 每张库内部的 `SHOW CREATE TABLE` 通过 `MYSQLI_ASYNC` + `mysqli_poll` 并发执行
- 默认每批 50 个表，每批内所有表并发
- 两个数据库之间的查询是顺序的（不是真正的双库并发）

### 排除表过滤
- 用户输入逗号分隔的 glob 模式（如 `*_bak, *_backup*, tmp_*`）
- 存储到 `config.json` 的 `settings.excludePatterns`
- 传递给 `9raxdev/mysql-struct-sync` 库做 SQL 层 `WHERE` 过滤 + PHP 层 `fnmatch` 兜底

### 配置存储
- 目录：`~/.mysql-schema-sync/`
- 加密密钥：`~/.mysql-schema-sync/.key`（32 bytes random，自动生成）
- 配置：`~/.mysql-schema-sync/config.json`（密码用 AES-256-GCM 加密）
- 密码加密：`random_bytes(12) IV + openssl_encrypt('aes-256-gcm')`，存储格式 `base64(IV(12) + tag(16) + ciphertext)`

### 剪贴板复制
- macOS：`shell_exec("echo '{$escaped}' | pbcopy")`
- Windows：`shell_exec('clip < "' . $tmp . '"')`
- 代码中有两套几乎相同的复制实现，都在 `MainWindow` 里

## 常见陷阱

| 问题 | 原因 | 解决 |
|------|------|------|
| 比对方向反了 | 库的 ADD/DROP 语义与直觉相反 | StructSyncAdapter 已处理，勿再手动 swap |
| Table 不显示数据 | macOS 上 Table 在 show() 后创建 | 确保所有 Table 都在 show() 前创建 |
| UI 卡死 | 同步数据库查询阻塞主线程 | 已知限制，无法完全解决 |
| 连接 ID 冲突 | 之前用 name 做 ID | 已改为 `random_bytes(8)` hex |
| 库方法缺失 | 1.0.3 原始版无排除过滤 | 修改了 `vendor/9raxdev/mysql-struct-sync/MysqlStructSync.php` 添加方法 |
| Schema.php 中的代码 | 旧的自实现比对，已废弃 | 保留未删除 |
| MainWindow 有大量重复代码 | `buildFilteredDiff` 和 `generateSqlFromDelegate` 做类似的事 | 已用 `buildFilteredDiffFromDelegate` 但仍有重复 |

## 依赖库

- `9raxdev/mysql-struct-sync` 的 vendor 代码被修改过（添加了排除过滤、拆分 fetch/compare 方法、进度回调）
- `helgesverre/libui` 很新（2026-06），API 可能变化
- `yangweijie/ui2` 提供原生对话框包装（MessageBox::info/error/warning, DialogConfirm::ask）

## 测试

无自动化测试。手动测试方法：
1. 准备两个 MySQL 数据库（一个代表源结构，一个代表目标结构）
2. 修改源库的表结构
3. 运行 `php85 bin/mysql-schema-sync.php` 比对
4. 检查差异表格和生成的 SQL 是否符合预期

## 技术债务

- 废弃的 `src/Diff/Schema.php` 应清理
- `Generator` 主要依赖 `adapter->getDiffSql()` 做 SQL 生成，间接依赖多
- `MainWindow` 两个不同路径（主窗口内嵌 table 和弹出新窗口）有大量重复逻辑
- 无法测试：没有 mock 数据库连接的测试设施
