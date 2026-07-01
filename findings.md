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

## 项目架构
```
src/
├── Config/ConfigStore.php   — 连接配置 + settings 持久化（AES-256-GCM）
├── Config/Connection.php    — 连接数据类
├── Diff/StructSyncAdapter.php — 库的适配器（包装 baseDiff/advanceDiff）
├── Diff/DiffResult.php      — 差异结果数据结构
├── Diff/Schema.php          — 不再使用（保留）
├── Gui/MainWindow.php       — 主窗口（libui + Loop 调度）
├── Gui/ConnectionWindow.php — 连接管理窗口
├── Gui/DiffTableModelDelegate.php — 差异表格模型
└── SqlGen/Generator.php     — 迁移 SQL 生成（用库的 diffSql）
```
