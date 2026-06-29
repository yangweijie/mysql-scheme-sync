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
- [x] 创建 StructSyncAdapter 适配器
- [x] 交换 source/target 解决比对方向问题
- [x] 修复库的 `SHOW CREATE TABLE` 缺少反引号
- [x] 重写 Generator 使用库的 diffSql 输出
- [x] 进度条（不确定模式）+ Loop::defer() 刷新 UI

### Phase 4: 待改进 ⬜
- [ ] 比对期间 UI 完全不阻塞（需要 pcntl_fork 或其他异步方案）
- [ ] 取消按钮实际生效（库的同步调用期间无法中断）
- [ ] 变更表的 MODIFY COLUMN 列定义来自库的输出而非 SHOW CREATE TABLE

## 技术决策
| 决策 | 原因 |
|------|------|
| 用 `9raxdev/mysql-struct-sync` 做核心对比 | 用户要求，库已安装 |
| 进度条用 -1 不确定模式 | 库的调用是同步的，无法分步 |
| Generator 直接用库的 diffSql | 库生成的 SQL 已含完整定义 |
| 交换 source/target 传给库 | 库的 ADD/DROP 方向与用户预期相反 |

## 错误记录
| 错误 | 原因 | 修复 |
|------|------|------|
| `$_SERVER['HOME']` undefined | Windows 无 HOME 环境变量 | 用 USERPROFILE 兜底 |
| 中文名连接 ID 冲突 | preg_replace 全变下划线 | 改用 random_bytes(8) ID |
| MODIFY COLUMN 为空 | 只取了 columnType 没取完整定义 | 改用库的 diffSql |
| 库 SHOW CREATE TABLE 报错 | 表名未加反引号 | 修改 vendor 代码 |
| preg_match_all Unknown modifier | PATTERNS 含多余 `/` 定界符 | 去掉定界符 |
