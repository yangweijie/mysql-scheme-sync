# 研究发现

## 9raxdev/mysql-struct-sync 库
- 命名空间 `linge`，类名 `MysqlStructSync`
- 构造函数参数顺序：`old_db_conf` = 目标库(self)，`develop_db_conf` = 源库(refer)
- `baseDiff()` 比较表/列/约束，`advanceDiff()` 比较视图/触发器/事件/函数/存储过程
- `getDiffSql()` 返回按类型分组的 SQL 数组
- 库使用 `mysqli` 扩展（非 PDO）
- 库有 `die()` 调用和 `$_POST` 检查，不适合 Web 环境
- 库的 `SHOW CREATE TABLE` 未加反引号，对保留字表名会报错

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
├── Diff/StructSyncAdapter.php — 库的适配器（比对逻辑）
├── Diff/DiffResult.php      — 差异结果数据结构
├── Gui/MainWindow.php       — 主窗口（libui）
├── Gui/ConnectionWindow.php — 连接管理窗口
├── Gui/DiffTableModelDelegate.php — 差异表格模型
└── SqlGen/Generator.php     — 迁移 SQL 生成
```
