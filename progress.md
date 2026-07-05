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
- **连接管理增强完成：弹窗支持选择/编辑/删除现有连接 + 父窗口居中**
- **Generator 双分号修复完成：8 处 rtrim 修复**
- **WebViewUI 重构完成：HTML/CSS/JS 提取到 src/Gui/assets/ 独立文件**
- **Schema dump 文件位置修复：默认输出改为 ~/.mysql-schema-sync/dumps/**
- **调试输出完成：debugLog/debugLogException + 默认启用**
- **UI 阻塞修复完成：工作队列架构（每个查询独立步骤）**
- **Windows 任务栏图标修复完成：PHP FFI 调用 user32.dll（跨进程 HICON 无效，需同进程加载）**
- **AGENTS.md 更新完成：移除废弃的 9raxdev/mysql-struct-sync 引用、MainWindow 引用；补充新文件文档**

## 最新改动（Phase 18 - UI 对齐/关于弹窗）
| 改动 | 文件 |
|------|------|
| 比较选择器 4 元素统一 box-model 对齐（select/swap/save 统一 font-size/line-height/padding/height） | `src/Gui/assets/app.css` |
| `&nbsp;` 占位改为 `<label class="sr-only">` 真实文字占位 + `visibility:hidden` 保证高度一致 | `src/Gui/assets/app.html`, `app.css` |
| 侧栏版本号点击弹出关于弹窗（showAboutDialog + overlay 复用） | `src/Gui/assets/app.html`, `app.css`, `app.js` |
| `.sidebar-version { cursor:pointer; }` + `.about-link` 样式 | `src/Gui/assets/app.css` |
| `window.showAboutDialog()` — 复用 confirm-overlay/confirm-box 模式 | `src/Gui/assets/app.js` |

## 最新改动（Phase 15 - 工作队列架构）
| 改动 | 文件 |
|------|------|
| 添加 `$workQueue` / `$workLoop` 属性 | `src/Diff/AsyncCompareRunner.php` |
| 重写 `startStepByStep()` 使用工作队列 | `src/Diff/AsyncCompareRunner.php` |
| 新增 `dequeueAndRun()` 替代 `executeStep()` | `src/Diff/AsyncCompareRunner.php` |
| 新增 `buildWorkQueue()` 替代 `buildSteps()` | `src/Diff/AsyncCompareRunner.php` |
| 新增 `listTables()` 查询表名列表 + 入队 | `src/Diff/AsyncCompareRunner.php` |
| 新增 `fetchOneTable()` 单表查询 | `src/Diff/AsyncCompareRunner.php` |
| 新增 `listAdvanced()` 查询高级对象列表 + 入队 | `src/Diff/AsyncCompareRunner.php` |
| 新增 `fetchOneAdvanced()` 单对象查询 | `src/Diff/AsyncCompareRunner.php` |

## 最新改动（Windows 任务栏图标修复 + AGENTS.md 更新）
| 改动 | 文件 |
|------|------|
| PowerShell 方案替换为 PHP FFI 调用 user32.dll（跨进程 HICON 无效） | `src/Gui/WebViewUI.php` |
| 新增 `setWinIconsFfi()` — 同进程 FFI 加载 icon.ico + WM_SETICON | `src/Gui/WebViewUI.php` |
| 新增 `$kernel32` 静态属性（GetLastError 从 kernel32.dll 加载） | `src/Gui/WebViewUI.php` |
| 删除旧的 `setWinIcons()` PowerShell/proc_open 方案 | `src/Gui/WebViewUI.php` |
| 移除 9raxdev/mysql-struct-sync 依赖引用、MainWindow 引用 | `AGENTS.md` |
| 补充 AsyncCompareRunner、DDLDefinitionParser、DllBootstrap、CLI 工具文档 | `AGENTS.md` |

## 最近改动（Phase 11-14）
| 改动 | 文件 |
|------|------|
| 修复 8 处双分号输出（rtrim） | `src/SqlGen/Generator.php` |
| 提取 HTML/CSS/JS 到独立文件 | `src/Gui/assets/app.css`, `app.html`, `app.js`, `init.js` |
| WebViewUI 改用 file_get_contents 加载资源 | `src/Gui/WebViewUI.php` |
| ConfigStore 添加 getDir() 公开方法 | `src/Config/ConfigStore.php` |
| dump-schema.php 默认输出改为用户目录 | `bin/dump-schema.php` |
| 添加 debugLog/debugLogException 静态方法 | `src/Gui/WebViewUI.php` |
| 比对桥接返回立即 started + JS 轮询 | `src/Gui/WebViewUI.php`, `src/Gui/assets/app.js` |
| 添加 getLastAdapter() getter | `src/Diff/AsyncCompareRunner.php` |

## 已修改文件
| 文件 | 改动 |
|------|------|
| `src/Config/ConfigStore.php` | Windows HOME 兼容 + settings 存储 + getDir() 方法 |
| `src/Diff/StructSyncAdapter.php` | 包装库方法 + fetchAll/compare 分离；DDLDefinitionParser 字段级 diff + 两阶段比较 + `$structuredDiffs` + `getStructuredDiffs()` |
| `src/Diff/DiffResult.php` | 精简为纯数据结构；删除 `PHASE_FETCH_TARGET` 常量 |
| `src/Diff/AsyncCompareRunner.php` | **工作队列架构**（$workQueue + dequeueAndRun + listTables + fetchOneTable + listAdvanced + fetchOneAdvanced + getLastAdapter） |
| `src/Gui/MainWindow.php` | on_phase + on_progress 回调驱动进度条；删除 `buildFilteredDiffFromDelegate()` 死方法；传入 `getStructuredDiffs()` |
| `src/Gui/DiffTableModelDelegate.php` | 隐藏新建/删除表的子项 |
| `src/SqlGen/Generator.php` | 用库 diffSql 输出生成 SQL；ALTER TABLE 合并 + 依赖排序输出；8 处 rtrim 修复双分号 |
| `src/Diff/AsyncStructureFetcher.php` | 删除 `fetchStructuresInParallel()` 死方法；AsyncContext 替代 MYSQLI_ASYNC |
| `src/Diff/Schema.php` | **整文件删除**——244 行废弃手工比对类 |
| `src/Gui/WebViewUI.php` | **重构**：file_get_contents 加载资源 + 非阻塞 compare bridge + debugLog + getCompareResult 轮询 |
| `src/Gui/assets/app.css` | **新建**——所有 CSS 样式 |
| `src/Gui/assets/app.html` | **新建**——HTML 模板（占位符） |
| `src/Gui/assets/app.js` | **新建**——所有 JavaScript 应用代码 + 轮询逻辑 |
| `src/Gui/assets/init.js` | **新建**——WebView bridge 初始化脚本 |
| `vendor/9raxdev/mysql-struct-sync/MysqlStructSync.php` | 拆分方法 + 排除过滤 + 进度回调 |
| `composer.json` | 移除+恢复 `yangweijie/think-orm-async`；恢复 `nunomaduro/collision`；添加 `classmap` autoload |
| `AGENTS.md` | 新建——OpenCode 新会话的快速入门指令 |
| `vendor/yangweijie/think-orm-async/src/AsyncContext.php` | 修补 `?string` 可空类型（PHP 8.5 兼容） |
| `vendor/yangweijie/think-orm-async/src/AsyncResultPlaceholder.php` | 修补 `:mixed` 协变返回类型（PHP 8.5 兼容） |
| `vendor/yangweijie/think-orm-async/src/think/Collection.php` | **新建**——`think\Collection` 轻量存根 |
| **`src/Diff/DDLDefinitionParser.php`** | **新建**——MySQL 列定义语义解析器（10 字段提取、字段级对比、完整 DDL 解析） |
| `bin/dump-schema.php` | 默认输出改为 `~/.mysql-schema-sync/dumps/` |

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
| AsyncCompareRunner 重构 | ~+200 行（工作队列架构 + 单表/单对象查询） |
| WebViewUI 重构 | ~-300 行内联代码 → 4 个独立资源文件 |
| **总计** | **~-582 行** |

## 待解决问题
1. 取消按钮在库同步调用期间无法生效（需配合子进程方案）
2. `MainWindow` 中两套剪贴板复制代码仍有重复
3. 两套过滤/SQL 生成路径逻辑重叠
4. 无自动化测试（需 mock 数据库连接）
5. DDLDefinitionParser 的 `splitDefinitionLines()` 对极端嵌套 CASE 表达式可能误分割

## 已解决问题
| 问题 | 解决版本 |
|------|----------|
| 连接管理只能新建不能编辑已有连接 | Phase 10: 增加 Combobox 选择器 + 表单填充 + update/create 区分 |
| 连接管理弹窗位置不对 | Phase 10: 替换为 `Window::centeredOn()` |
| 输出 SQL 双分号 `;;` | Phase 11: 8 处 `rtrim($sql, ';')` 修复 |
| WebViewUI 内联代码过长 | Phase 12: 提取到 4 个独立资源文件 |
| Schema dump 文件在项目根目录 | Phase 13: 默认输出改为用户配置目录 |
| 比对失败无错误详情 | Phase 14: debugLog + compareError 桥接返回 |
| 比对期间 UI 卡死 | Phase 15: 工作队列架构，每个查询独立步骤 |
