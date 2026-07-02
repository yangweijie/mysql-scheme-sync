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
- [ ] 比对期间 UI 完全不阻塞（需要 pcntl_fork 或其他异步方案）
- [ ] 取消按钮实际生效（库的同步调用期间无法中断）

### Phase 5: 文档 ✅
- [x] 创建 AGENTS.md — 为 OpenCode 会话编制的紧凑指令文件

## 技术决策
| 决策 | 原因 |
|------|------|
| 用 `9raxdev/mysql-struct-sync` 做核心对比 | 用户要求，库已安装 |
| 进度条用确定模式 + 库回调 | 库的调用是同步的，回调在每个 SHOW CREATE 间触发 |
| Generator 直接用库的 diffSql | 库生成的 SQL 已含完整定义 |
| 交换 source/target 传给库 | 库的 ADD/DROP 方向与用户预期相反 |
| SQL 层排除过滤 | 加速初始列表查询，减少无用 SHOW CREATE |

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
