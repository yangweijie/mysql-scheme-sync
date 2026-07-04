# MySQL SchemaSync - 进度跟踪

## 目标
基于 `9raxdev/mysql-struct-sync` 库实现 MySQL 数据库结构对比与迁移 SQL 生成桌面工具。

## 阶段

### Phase 1: 基础修复 ✅
- [x] 修复 `$_SERVER['HOME']` Windows 兼容性
- [x] 修复连接管理保存覆盖问题（ID 生成逻辑）
- [x] 修复关闭主窗口进程不退出
- [x] 修复 Windows 复制到剪贴板（pbcopy → clip）
- [x] 排除表持久化保存到 config.json

### Phase 2: 功能优化 ✅
- [x] 新建表的索引/外键/触发器隐藏不显示（DiffTableModelDelegate）
- [x] 新建表索引合并到 CREATE TABLE 语句内部（Generator）
- [x] 删除表的子项不显示、不生成 SQL
- [x] 排除表支持视图/存储过程/函数/事件
- [x] MODIFY COLUMN 输出完整列定义（含 COLLATE）

### Phase 3: 库集成 ✅
- [x] 集成 `9raxdev/mysql-struct-sync` 库
- [x] 创建 StructSyncAdapter 适配器（包装库方法）
- [x] 交换 source/target 解决比对方向问题
- [x] 修复库的 `SHOW CREATE TABLE` 缺少反引号
- [x] 重写 Generator 使用库的 diffSql 输出
- [x] 进度条按阶段独立显示（查询表/视图/触发器等各自 0→100%）
- [x] 库的 getStructure/advanceDiff 加 SQL 层排除过滤（加速）
- [x] 库加 setExcludePatterns/matchesExclude/buildSqlExclude 方法
- [x] 库拆分 fetchBaseStructs/fetchAdvanceStructs + compareBaseStructs/compareAdvanceStructs
- [x] on_phase 回调按阶段更新进度条标签
- [x] on_progress 回调按查询项更新进度条百分比
- [x] fetchAll 传排除模式给库（SQL 层过滤生效）

### Phase 4: 待改进 ⬜
- [x] 比对期间 UI 完全不阻塞（子进程方案）
- [x] 取消按钮实际生效（子进程方案）

### Phase 8: UI 阻塞优化 ✅
- [x] AsyncCompareRunner 工作队列架构（每个 SHOW CREATE TABLE 独立步骤）
- [x] WebViewUI 非阻塞 compare bridge（立即返回 + JS 轮询）
- [x] JS 轮询 getCompareResult() 每 300ms 检查状态
- [ ] 取消按钮在库同步调用期间无法生效（需配合子进程方案）

### Phase 5: 文档 ✅
- [x] 创建 AGENTS.md — 为 OpenCode 会话编制的紧凑指令文件

### Phase 6: 死代码清理 ✅
- [x] `src/Diff/Schema.php` — 删除废弃的 244 行手工比对类（零引用）
- [x] `src/Gui/ConnectionWindow.php` — 删除废弃的 249 行连接管理窗口（new 了但 show() 从未调用）
- [x] `MainWindow::buildFilteredDiffFromDelegate()` — 删除 100 行死方法（零次调用）
- [x] `AsyncStructureFetcher::fetchStructuresInParallel()` — 删除死方法（零次调用）
- [x] `DiffResult::PHASE_FETCH_TARGET` — 删除未使用常量
- [x] `composer.json` — 移除并恢复 `yangweijie/think-orm-async` 依赖（清理 10 个传递依赖包）
- [x] `nunomaduro/collision` — 确认 `yangweijie/ui2/bootstrap.php` 运行时依赖，已恢复

### Phase 7: think-orm-async 性能优化 ✅
- [x] 重新安装 `yangweijie/think-orm-async` ^1.0
- [x] `AsyncStructureFetcher` — 用 `AsyncContext` 替换手工 `MYSQLI_ASYNC`（清理 58→22 行代码）
- [x] `StructSyncAdapter::appendAdvanceDiffSql()` — 用 `AsyncContext` 批量并行化高级对象 SHOW CREATE 查询
- [x] Vendor 修补：PHP 8.5 兼容性（`?string` 可空类型 + `:mixed` 协变返回类型）
- [x] `think\Collection` 轻量存根（移除 think-orm-async 对 ThinkPHP 的依赖）
- [x] `composer.json` — 添加 `classmap` autoload 加载 `think\Collection` 存根

### Phase 9: Navicat 算法重实现 ✅
- [x] 创建 `DDLDefinitionParser.php` — MySQL 列定义语义解析（type/nullable/default/extra/charset/collation/on_update/generated）
- [x] `parseColumnDef()` — 从 DDL 片段提取 10 个结构化字段
- [x] `compareColumnDefs()` — 字段级语义对比（处理 MySQL 格式变体）
- [x] `parseFullDDL()` — 解析完整 SHOW CREATE TABLE 为 columns/indexes/foreign_keys/table_options
- [x] `compareDDL()` — 两阶段比较：Quick（表名集）→ Full（DDL 语义 diff）
- [x] StructSyncAdapter 改用 DDLDefinitionParser 做字段级 diff（替代原始 `!==` 比较）
- [x] 结构化的 `$structuredDiffs` 属性 + `getStructuredDiffs()` 公开访问
- [x] `field_diffs` 数组 + `detail` 中文描述挂到 MODIFY_COLUMN 变更项
- [x] Generator ALTER TABLE 合并（同一表的 MODIFY/ADD/DROP 合并为一条 ALTER）
- [x] Generator 依赖排序输出：DROP（逆序）→ ALTER（合并）→ CREATE（正序）
- [x] MainWindow 两处 Generator 构造传入 `getStructuredDiffs()`

### Phase 10: 连接管理增强 ✅
- [x] 连接选择器 Combobox — 在弹窗顶部列出所有已有连接
- [x] 选择连接时自动填充表单（名称/主机/端口/用户/密码/数据库）
- [x] 保存区分新建 vs 更新：新建生成随机 ID，更新复用原有 ID
- [x] 删除按钮 + DialogConfirm 确认
- [x] 保存/删除后自动刷新连接列表并保持选中
- [x] 保存/删除后同步主窗口下拉框（refreshConnectionLists）
- [x] 弹窗相对父窗口居中（`Window::centeredOn()` 代替手动 buggy 计算）

### Phase 11: Generator 双分号修复 ✅
- [x] 修复 8 处 `rtrim($sql, ';')` 前未去除已有分号导致 `;;` 输出

### Phase 12: WebViewUI 重构 ✅
- [x] 提取内联 HTML/CSS/JS 到 `src/Gui/assets/` 独立文件
- [x] `app.css` (10KB) — 所有 CSS 样式
- [x] `app.html` (9KB) — HTML 模板（`{{CSS}}`, `{{INIT_JS}}`, `{{APP_JS}}` 占位符）
- [x] `app.js` (19KB) — 所有 JavaScript 应用代码
- [x] `init.js` (473B) — WebView bridge 初始化脚本
- [x] `WebViewUI.php` 改用 `file_get_contents()` 加载 4 个资源文件

### Phase 13: Schema Dump 文件位置修复 ✅
- [x] `ConfigStore::getDir()` 公开方法
- [x] `bin/dump-schema.php` 默认输出改为 `~/.mysql-schema-sync/dumps/`
- [x] 迁移已有 schema dump 文件到用户配置目录

### Phase 14: 调试输出 ✅
- [x] `debugLog()` / `debugLogException()` 静态方法
- [x] 默认启用调试，带时间戳输出到 STDOUT
- [x] 错误详情存入 `$compareError` 并通过 `getCompareResult` bridge 返回

### Phase 15: UI 阻塞修复（工作队列架构）✅
- [x] AsyncCompareRunner 重构为工作队列架构（`$workQueue` + `dequeueAndRun()`）
- [x] `listTables()` 查询表名列表，为每张表入队独立步骤
- [x] `fetchOneTable()` 单表 SHOW CREATE TABLE 查询
- [x] `listAdvanced()` 查询高级对象列表，为每个对象入队独立步骤
- [x] `fetchOneAdvanced()` 单对象 SHOW CREATE 查询
- [x] 步骤间 delay 1ms，每个步骤只查一条 SQL，事件循环可处理 WebView2 事件

## 技术决策
| 决策 | 原因 |
|------|------|
| 用 `9raxdev/mysql-struct-sync` 做核心对比 | 用户要求，库已安装 |
| 进度条用确定模式 + 库回调 | 库的调用是同步的，回调在每个 SHOW CREATE 间触发 |
| Generator 直接用库的 diffSql | 库生成的 SQL 已含完整定义 |
| 交换 source/target 传给库 | 库的 ADD/DROP 方向与用户预期相反 |
| SQL 层排除过滤 | 加速初始列表查询，减少无用 SHOW CREATE |
| 用 `AsyncContext` 替代手工 `MYSQLI_ASYNC` | 代码更简洁，复用库的重试/超时逻辑 |
| 高级对象 SHOW CREATE 用 AsyncContext 并行化 | 消除串行 N+1 查询，减小延迟 |
| `think\Collection` 轻量存根 | think-orm-async 运行时需要，但无 ThinkPHP 依赖 |
| DDLDefinitionParser 语义解析替代 `!==` | Navicat 算法要求字段级语义 diff（charset/collation/ON UPDATE 等） |
| ALTER TABLE 合并 | Navicat §5.2：同一表的多个变更合并为一条 ALTER，减少网络往返 |
| 工作队列架构替代固定步骤列表 | 每个 SHOW CREATE TABLE 独立步骤，事件循环可处理 WebView2 事件，UI 不卡死 |
| JS 轮询 getCompareResult 每 300ms | 比对结果通过桥接函数异步返回，JS 定时检查状态 |

## 错误记录
| 错误 | 原因 | 修复 |
|------|------|------|
| `$_SERVER['HOME']` undefined | Windows 无 HOME 环境变量 | 用 USERPROFILE 兜底 |
| 中文名连接 ID 冲突 | preg_replace 全变下划线 | 改用 random_bytes(8) ID |
| MODIFY COLUMN 为空 | 只取了 columnType 没取完整定义 | 改用库的 diffSql |
| 库 SHOW CREATE TABLE 报错 | 表名未加反引号 | 修改 vendor 代码 |
| preg_match_all Unknown modifier | PATTERNS 含多余 `/` 定界符 | 去掉定界符 |
| PDO 对象不能做数组 key | PHP 限制 | 改用 [['pdo'=>$p,'sk'=>$s]] 格式 |
| 自实现比较逻辑差异多 | 库的 _array_intersect_assoc 行为不同 | 直接调用库的 baseDiff/advanceDiff |
| 358/125/80 处差异 | 自己的比较逻辑与库不一致 | 放弃自实现，直接包装库方法 |
| Call to undefined method setExcludePatterns() | 库缺少排除过滤方法 | 在 MysqlStructSync 中添加 setExcludePatterns/matchesExclude/buildSqlExclude + 拆分 fetch/compare 方法 |
| Typed property $src must not be accessed before initialization | startStepByStep 未接收 Connection 参数 | 改为接收 $src/$tgt 参数 |
| 比对期间 UI 卡死 | syncFetchTables 在单个回调内跑 100+ 查询 | 工作队列架构，每个查询独立步骤 |
| 输出 SQL 双分号 `;;` | raw SQL 已含 `;`，Generator 再追加 | 8 处 `rtrim($sql, ';')` 修复 |
| Schema dump 文件在项目根目录 | `getcwd()` 指向项目根 | 改为 `$store->getDir() . '/dumps/'` |
