# 会话进度

## 当前状态
- 库集成完成，拆分为 fetch + compare 两阶段
- 进度条按阶段独立显示（查询表 → 查询视图 → ... → 比较中）
- SQL 层排除过滤已启用
- fetchAll 传排除模式给库
- 创建了 AGENTS.md（针对新 OpenCode 会话的紧凑指令文件）

## 已修改文件
| 文件 | 改动 |
|------|------|
| `src/Config/ConfigStore.php` | Windows HOME 兼容 + settings 存储 |
| `src/Diff/StructSyncAdapter.php` | 包装库方法 + fetchAll/compare 分离 |
| `src/Diff/DiffResult.php` | 精简为纯数据结构 |
| `src/Gui/MainWindow.php` | on_phase + on_progress 回调驱动进度条 |
| `src/Gui/ConnectionWindow.php` | editingId 追踪 + random_bytes ID |
| `src/Gui/DiffTableModelDelegate.php` | 隐藏新建/删除表的子项 |
| `src/SqlGen/Generator.php` | 用库 diffSql 输出生成 SQL |
| `vendor/9raxdev/mysql-struct-sync/MysqlStructSync.php` | 拆分方法 + 排除过滤 + 进度回调 |
| `AGENTS.md` | 新建——OpenCode 新会话的快速入门指令 |

## 待解决问题
1. 比对期间 UI 阻塞（PHP 单线程 + 库同步调用限制）
2. 取消按钮在库同步调用期间无法生效
