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
└── SqlGen/Generator.php         — 迁移 SQL 生成（用库的 diffSql）
```
