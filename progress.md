# 会话进度

## 当前状态
- 库集成完成，直接调用库的 baseDiff/advanceDiff 方法
- 比对结果与库一致（~28 处差异）
- 进度条通过库的 on_progress 回调更新
- SQL 层排除过滤已启用，加速初始查询

## 已修改文件
| 文件 | 改动 |
|------|------|
| `src/Config/ConfigStore.php` | Windows HOME 兼容 + settings 存储 |
| `src/Config/Connection.php` | 未改 |
| `src/Diff/StructSyncAdapter.php` | 包装库的 baseDiff/advanceDiff + 进度回调 |
| `src/Diff/DiffResult.php` | 精简为纯数据结构 |
| `src/Diff/Schema.php` | 不再使用（保留） |
| `src/Gui/MainWindow.php` | 进度条 + Loop::delay + 按钮状态管理 |
| `src/Gui/ConnectionWindow.php` | editingId 追踪 + random_bytes ID |
| `src/Gui/DiffTableModelDelegate.php` | 隐藏新建/删除表的子项 |
| `src/SqlGen/Generator.php` | 用库 diffSql 输出生成 SQL |
| `vendor/9raxdev/mysql-struct-sync/MysqlStructSync.php` | SHOW CREATE 加反引号 + 排除过滤 + 进度回调 |
| `composer.json` | 添加 9raxdev/mysql-struct-sync 依赖 |

## 待解决问题
1. 比对期间 UI 阻塞（PHP 单线程 + 库同步调用限制）
2. 取消按钮在库同步调用期间无法生效
