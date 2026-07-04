# 会话进度

## 当前状态
- 库集成完成，拆分为 fetch + compare 两阶段
- 进度条按阶段独立显示（查询表 → 查询视图 → ... → 比较中）
- SQL 层排除过滤已启用
- fetchAll 传排除模式给库
- 创建了 AGENTS.md（针对新 OpenCode 会话的紧凑指令文件）
- **死代码清理完成：删除 2 个废弃文件 + 3 个死方法 + 1 个未使用常量 + 1 个未使用依赖**
- **think-orm-async 优化完成：AsyncContext 替换手工 MYSQLI_ASYNC + 高级对象 SHOW CREATE 并行化**
- **Navicat 算法重实现完成：DDLDefinitionParser 语义解析 + StructSyncAdapter 字段级 diff + Generator ALTER TABLE 合并 + 依赖排序**

## 已修改文件
| 文件 | 改动 |
|------|------|
| `src/Config/ConfigStore.php` | Windows HOME 兼容 + settings 存储 |
| `src/Diff/StructSyncAdapter.php` | 包装库方法 + fetchAll/compare 分离；DDLDefinitionParser 字段级 diff + 两阶段比较 + `$structuredDiffs` + `getStructuredDiffs()` |
| `src/Diff/DiffResult.php` | 精简为纯数据结构；删除 `PHASE_FETCH_TARGET` 常量 |
| `src/Gui/MainWindow.php` | on_phase + on_progress 回调驱动进度条；删除 `buildFilteredDiffFromDelegate()` 死方法；传入 `getStructuredDiffs()` |
| `src/Gui/ConnectionWindow.php` | editingId 追踪 + random_bytes ID（文件已删除——废弃窗口） |
| `src/Gui/DiffTableModelDelegate.php` | 隐藏新建/删除表的子项 |
| `src/SqlGen/Generator.php` | 用库 diffSql 输出生成 SQL；ALTER TABLE 合并 + 依赖排序输出 |
| `src/Diff/AsyncStructureFetcher.php` | 删除 `fetchStructuresInParallel()` 死方法；AsyncContext 替代 MYSQLI_ASYNC |
| `src/Diff/Schema.php` | **整文件删除**——244 行废弃手工比对类 |
| `vendor/9raxdev/mysql-struct-sync/MysqlStructSync.php` | 拆分方法 + 排除过滤 + 进度回调 |
| `composer.json` | 移除+恢复 `yangweijie/think-orm-async`；恢复 `nunomaduro/collision`；添加 `classmap` autoload |
| `AGENTS.md` | 新建——OpenCode 新会话的快速入门指令 |
| `vendor/yangweijie/think-orm-async/src/AsyncContext.php` | 修补 `?string` 可空类型（PHP 8.5 兼容） |
| `vendor/yangweijie/think-orm-async/src/AsyncResultPlaceholder.php` | 修补 `:mixed` 协变返回类型（PHP 8.5 兼容） |
| `vendor/yangweijie/think-orm-async/src/think/Collection.php` | **新建**——`think\Collection` 轻量存根 |
| **`src/Diff/DDLDefinitionParser.php`** | **新建**——MySQL 列定义语义解析器（10 字段提取、字段级对比、完整 DDL 解析） |

## 删除统计
| 清理项 | 行数 |
|--------|------|
| `src/Diff/Schema.php` | -244 |
| `src/Gui/ConnectionWindow.php` | -249 |
| `MainWindow::buildFilteredDiffFromDelegate()` | -99 |
| `AsyncStructureFetcher::fetchStructuresInParallel()` | -13 |
| `DiffResult::PHASE_FETCH_TARGET` | -1 |
| `composer.lock`（传递依赖） | ~-1000 |
| **总计代码** | **-606 行（源文件）** |

## 净变更统计
| 类别 | 变更 |
|------|------|
| 源文件删除 | -493 行（Schema.php + ConnectionWindow.php） |
| 死方法删除 | -113 行（buildFilteredDiffFromDelegate + fetchStructuresInParallel + PHASE_FETCH_TARGET） |
| AsyncStructureFetcher 重写 | ~-36 行（手工 MYSQLI_ASYNC → AsyncContext） |
| StructSyncAdapter 重写 | ~-10 行（删除 createMysqli, 优化 appendAdvanceDiffSql） |
| vendor/think-orm-async 修补 | +70 行（PHP 8.5 兼容 + think\Collection 存根） |
| **总计** | **~-582 行** |

## 待解决问题
1. 比对期间 UI 阻塞（PHP 单线程 + 库同步调用限制）
2. 取消按钮在库同步调用期间无法生效
3. `MainWindow` 中两套剪贴板复制代码仍有重复
4. 两套过滤/SQL 生成路径逻辑重叠
5. 无自动化测试（需 mock 数据库连接）
6. DDLDefinitionParser 的 `splitDefinitionLines()` 对极端嵌套 CASE 表达式可能误分割
