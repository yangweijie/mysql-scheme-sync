# 研究发现

## 9raxdev/mysql-struct-sync 库
- 命名空间 `linge`，类名 `MysqlStructSync`
- 构造函数参数顺序：第一个 = 目标库(self)，第二个 = 源库(refer)
- `baseDiff()` 比较表/列/约束，`advanceDiff()` 比较视图/触发器/事件/函数/存储过程
- `getDiffSql()` 返回按类型分组的 SQL 数组
- 库使用 `mysqli` 扩展（非 PDO）
- 库的 `_array_intersect_assoc` 是自定义方法，不是 PHP 内置的
- 库的比较逻辑：先过滤表级差异，再比较列/约束，最后比较高级对象

## 库的排除过滤机制
- `setExcludePatterns(array)` 设置排除模式
- `matchesExclude(string)` 检查名称是否匹配（fnmatch）
- `buildSqlExclude(string $col)` 生成 SQL WHERE 子句
- SQL 层过滤：`*` → `%`，`?` → `_`，字面量用 `!=`
- PHP 层兜底：matchesExclude 仍在循环中作为备份

## 库的拆分架构
- `fetchBaseStructs()` — 查询两个库的表结构（SHOW CREATE TABLE × N）
- `fetchAdvanceStructs()` — 查询视图/触发器/事件/函数/存储过程
- `compareBaseStructs()` — 纯计算：表差异 + 列差异 + 约束差异
- `compareAdvanceStructs()` — 纯计算：高级对象差异
- `on_phase($label, $cur, $total)` — 阶段切换回调
- `on_progress($name, $cur, $total)` — 单项查询完成回调

## libui Loop 异步机制
- `Loop::defer()` — 下一个 tick 执行一次
- `Loop::repeat(ms, cb)` — 重复定时器，cb 返回 false 停止
- `Loop::delay(ms, cb)` — 一次性延迟
- PHP 单线程，同步调用期间 UI 仍会阻塞
- `setValue(-1)` 让进度条来回滚动（不确定模式）

## MySQL 行为差异
- MySQL 8.0+ 的 `SHOW CREATE TABLE` 不输出冗余的列级 CHARACTER SET/COLLATE
- mysqldump 输出包含完整列定义（含列级字符集、索引、AUTO_INCREMENT 等）
- 不同 MySQL 版本的 CREATE TABLE 输出格式可能不同

## yangweijie/think-orm-async
- `AsyncContext::start(null, $dbConfig)` → 开始异步上下文
- `AsyncContext::query($sql, $key)` → 排队原生 SQL 查询，返回 `AsyncResultPlaceholder`
- `AsyncContext::end()` → 并行执行所有排队查询，返回 `[key => [row...]]`
- 内部 `AsyncQuery::executeAsyncQueries()` 仍为 N 查询创建 N 个 mysqli 连接（同原模式）
- `AsyncResultPlaceholder` 延迟加载结果，但我们直接用 `end()` 返回值避免依赖 ThinkPHP
- 需 PHP 8.5+ 修补：`?string` 可空类型 + 协变返回类型 `:mixed`
- 依赖 `think\Collection`（ThinkPHP），需要轻量存根

## PHP 8.5 兼容性发现
- `string $key = null` → 必须改为 `?string $key = null`
- 子类覆盖方法返回类型必须与父类协变（`\Traversable` vs `\ArrayIterator`）
- `offsetGet`: 必须声明 `:mixed` 返回类型
- deprecation notice 被 `whoops`/`collision` 转为 fatal exception

## 死代码清理发现
- `src/Diff/Schema.php` — 244 行废弃 INFORMATION_SCHEMA 手工比对类，`DDZH\MysqlStructSync` 命名空间，零次引用 → 已删除
- `src/Gui/ConnectionWindow.php` — 249 行连接管理窗口类，`MainWindow::onManageConnections()` 中 new 了对象但从未调用 `show()`（实际使用内联 UI L656-729）→ 已删除
- `MainWindow::buildFilteredDiffFromDelegate()` — 100 行，与 `buildFilteredDiff` 功能高度重叠的死方法 → 已删除
- `AsyncStructureFetcher::fetchStructuresInParallel()` — 从未被调用的公开方法 → 已删除
- `DiffResult::PHASE_FETCH_TARGET` — 未使用的类常量 → 已删除
- `yangweijie/think-orm-async` — 零 `use` 引用，已移除，连带清理 10 个传递依赖包
- `nunomaduro/collision` — 代码中无 `use` 引用，但 `yangweijie/ui2/bootstrap.php` 在 runtime 通过 `class_exists()` 触发 autoload 需要它 → 已恢复

## DDLDefinitionParser（Navicat 算法核心）
- `parseColumnDef(string $def)` — 用单条正则从 MySQL 列 DDL 提取 10 个字段：
  - `type`（如 VARCHAR(255)），`nullable`（NOT NULL / NULL），`default`，`extra`（AUTO_INCREMENT 等）
  - `charset`，`collation`，`on_update`（CURRENT_TIMESTAMP 等），`generated`（AS (...)，STORED/VIRTUAL）
  - `comment`（列注释）
- `compareColumnDefs()` — 字段级语义对比，返回 `{"field":"type","from":"int","to":"varchar(255)"}` 格式的 diff 数组
- `columnDefEquals()` — 语义相等比较（处理 MySQL 格式变体如 `int(11)` vs `int`，`CHARACTER SET` vs `CHARSET`）
- `parseFullDDL(string $ddl)` — 解析完整 SHOW CREATE TABLE：
  - `columns`：按先后顺序的列定义数组
  - `indexes`：索引结构（PRIMARY/UNIQUE/INDEX，含列名+前缀+ASC/DESC）
  - `foreign_keys`：外键结构（含引用表+引用列+ON UPDATE/DELETE）
  - `table_options`：表选项（ENGINE, CHARSET, COLLATE, COMMENT 等）
- `compareDDL()` — 两阶段比较：
  - Phase 1（Quick）：仅对比列名/索引名/外键名集合，标记 ADD/DROP
  - Phase 2（Full）：对共同存在的列做 `compareColumnDefs()` 语义 diff
- `splitDefinitionLines()` — 处理 DDL 中的嵌套括号（函数调用、CHECK 约束等），避免 `preg_match` 因不匹配括号炸掉
- `parseIndexColumns()` — 提取索引定义中的列名、前缀长度、ASC/DESC 排序

### 实现要点
- 正则基于 Navicat 二进制逆向文档中 CMFetchScope 枚举和 CSDiffMatchPatch 语义化 diff 原理
- 字段级比较而非字符级 diff（PHP 无 Google diff-match-patch 原生绑定）
- `parseFullDDL` 用 `preg_match_all` + 嵌套栈计数器处理 `(...)` 嵌套
- `compareColumnDefs` 对 charset/collation 做归一化比较（`charset` 与 `character set` 视为等价）

## UI 阻塞优化方案（子进程）

### 核心思路
使用 `proc_open` 启动独立的 PHP CLI 子进程执行数据库比对，主进程通过 `Loop::delay` 轮询状态文件获取进度。消除 UI 阻塞，使取消按钮生效。

### IPC 协议
临时文件前缀：`{sys_get_temp_dir()}/mss_{pid}_`
- `status.json`: `{"status":"working|done|error|cancelled", "phase":"...", "progress_pct":50}`
- `cancel.flag`: 存在即取消（worker 在每次查询批次后检查）
- `result.bin`: PHP `serialize($diffResult)`

### Worker 流程
1. `PHP_BINARY` 定位当前 PHP 可执行文件
2. `proc_open(cmd, pipes, cwd)` 启动，`bypass_shell=true` Windows 兼容
3. Worker 解析 CLI 参数（JSON 连接信息 + 排除模式 + 文件路径）
4. Worker 设置 `setOnProgress` 回调写 status.json
5. Worker 执行 `fetchAll()` → `compare()`，每批次后检查取消标志
6. Worker 写入 `result.bin` + 更新 status="done" 后退出
7. 主进程轮询到 status="done" 后读取 result.bin, `unserialize()` 还原 DiffResult

### Loop::repeat vs Loop::delay 自递归
- 先用 `Loop::repeat(ms, cb)`（libui-ng 的 `uiTimer` 支持重复模式）
- 若 `Loop::repeat` 不可用，用 `Loop::delay` 自递归：
  ```php
  $poll = function() use (&$poll) { /* check */ Loop::delay(200, $poll); };
  Loop::delay(200, $poll);
  ```

### 取消机制
- 取消按钮：`file_put_contents(cancelFlagPath, '1')`
- Worker 每批查询后：`if (file_exists(cancelFlagPath)) { clean up; exit(1); }`
- 主进程：检测 worker 退出后清除状态文件

## 项目架构
```
src/
├── Config/ConfigStore.php       — 连接配置 + settings 持久化（AES-256-GCM）
├── Config/Connection.php        — 连接数据类
├── Diff/StructSyncAdapter.php   — 库的适配器 + AsyncContext 并行化高级对象
├── Diff/DiffResult.php          — 差异结果数据结构
├── Diff/AsyncStructureFetcher.php — think-orm-async AsyncContext 并发 SHOW CREATE TABLE
├── Gui/MainWindow.php           — 主窗口（libui + Loop 调度）
├── Gui/DiffTableModelDelegate.php — 差异表格模型
├── Worker/WorkerIPC.php         — IPC 状态文件工具类
├── SqlGen/Generator.php         — 迁移 SQL 生成（用库的 diffSql）
bin/
├── mysql-schema-sync.php        — GUI 入口
└── compare-worker.php           — CLI 比对子进程入口
```
