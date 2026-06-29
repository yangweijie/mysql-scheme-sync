# 会话进度

## 当前状态
- 库集成完成，基础功能可用
- 比对方向已修正（source/target 交换）
- 进度条显示不确定动画
- 生成 SQL 使用库的完整输出

## 已修改文件
| 文件 | 改动 |
|------|------|
| `src/Config/ConfigStore.php` | Windows HOME 兼容 + settings 存储 |
| `src/Config/Connection.php` | 未改 |
| `src/Diff/StructSyncAdapter.php` | 新建，包装 9raxdev/mysql-struct-sync |
| `src/Diff/DiffResult.php` | 精简为纯数据结构 |
| `src/Diff/Schema.php` | 不再使用（保留） |
| `src/Gui/MainWindow.php` | 进度条 + Loop::defer() + 按钮状态管理 |
| `src/Gui/ConnectionWindow.php` | editingId 追踪 + random_bytes ID |
| `src/Gui/DiffTableModelDelegate.php` | 隐藏新建/删除表的子项 |
| `src/SqlGen/Generator.php` | 用库 diffSql 输出生成 SQL |
| `vendor/9raxdev/mysql-struct-sync/MysqlStructSync.php` | SHOW CREATE 加反引号 |
| `composer.json` | 添加 9raxdev/mysql-struct-sync 依赖 |

## 待解决问题
1. 比对期间 UI 阻塞（PHP 单线程限制）
2. 取消按钮在库同步调用期间无法生效
3. 变更表的 MODIFY COLUMN 列定义可能不完整
