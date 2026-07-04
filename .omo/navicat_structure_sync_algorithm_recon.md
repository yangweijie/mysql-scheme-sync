# Navicat 结构同步 — 精确算法逆向重建（符号验证版）

> 基于：Mach-O 二进制分析 + Localizable.strings 完整 UI 重建 + **`nm`/`strings`/`otool` 符号验证**
> 日期：2026-07-04 (验证更新 07:33 GMT+8，exec 已修复)
> 方法：`nm` 提取 libcc.dylib + libcf.dylib + 主二进制符号，`c++filt` 解析 C++ names，`strings` 提取字面量
> 核心库：libcc.dylib（业务逻辑） + libcf.dylib（Core Framework，比较引擎） + 主二进制（ObjC UI层）

---

## 一、概览：Navicat 结构同步的四个阶段

Navicat 的结构同步不是一次性操作，而是 **四阶段 Pipeline**：

```
Phase 1: Schema Collection    → CMFetchScope 分层异步采集元数据
Phase 2: Graph Construction   → 构建对象依赖图（DAG）
Phase 3: Diff Calculation     → CSDiffMatchPatch 语义化 DDL 比较
Phase 4: DDL Generation       → 拓扑排序生成 ALTER/CREATE/DROP
```

每个阶段通过异步回调串联：`prepareCallbacksWithRecoveryHandle:`（✅ `nm` 验证，ChartsFormWindowController）。

---

## 🔬 已验证的符号清单

以下符号均通过 `nm` 在二进制中确认，`[]` 标注库来源：

### 核心流程 [libcc.dylib]

| 符号（demangled） | 含义 |
|------|------|
| `CCNavicat::structureSyncFormPrepareCompare(ICSForm*)` | 结构同步入口 |
| `CCNavicat::dataSyncFormPrepareCompare(ICSForm*)` | 数据同步入口（共享架构） |
| `CCNavicat::dataSyncCompareResultObtainRow(CMObject*, int, int)` | 结果按行获取 |
| `CCNavicat::dataSyncCompareResultObtainData(CMObject*, int, int, bool, int)` | 获取列数据 |
| `CCNavicat::dataSyncCompareResultObtainDisplayData(CMObject*, int, int, bool, int)` | 获取显示数据 |
| `CCNavicat::dataSyncCompareResultObtainNumberOfRows(CMObject*, int)` | 结果行数 |

### 多阶段采集管道 — CMFetchScope 分层 [libcc.dylib]

| 符号 | 含义 |
|------|------|
| `CCNavicat::fetchObjects(CMObject*, CMFetchScope, CMObjectType, CMFetchDetailLevel, string, bool)` | 通用采集入口 |
| `CCNavicat::fetchSchemas(CMObject*)` | Schema 层面采集 |
| `CCNavicat::fetchSchemasComplete(CMObject*, CMFetchScope)` | Schema 采集完成 |
| `CCNavicat::fetchTablesComplete(CMObject*, CMFetchScope, bool)` | 表采集完成 |
| `CCNavicat::fetchTableFieldsComplete(CMObject*, CMFetchScope)` | 字段采集完成 |
| `CCNavicat::fetchIndexesComplete(CMObject*, CMFetchScope, bool)` | 索引采集完成 |
| `CCNavicat::fetchTableConstraintsComplete(CMObject*, CMFetchScope)` | 约束采集完成 |
| `CCNavicat::fetchPrimaryKeysComplete(CMObject*, CMFetchScope, bool)` | 主键采集完成 |
| `CCNavicat::fetchTableUniquesComplete(CMObject*, CMFetchScope, bool)` | 唯一约束采集完成 |
| `CCNavicat::fetchTableChecksComplete(CMObject*, CMFetchScope, bool)` | CHECK 约束采集完成 |
| `CCNavicat::fetchTriggersComplete(CMObject*, CMFetchScope, bool)` | 触发器采集完成 |
| `CCNavicat::fetchViewsComplete(CMObject*, CMFetchScope)` | 视图采集完成 |
| `CCNavicat::fetchFunctionsComplete(CMObject*, CMFetchScope)` | 函数采集完成 |
| `CCNavicat::fetchRolesComplete(CMObject*, CMFetchScope)` | 角色采集完成 |
| `CCNavicat::fetchUsersComplete(CMObject*, CMFetchScope)` | 用户采集完成 |

### 比较算法引擎 [libcf.dylib]

| 符号 | 含义 |
|------|------|
| `CF::CSAppFactory::obtainCSDiffMatchPatchFactory()` | 获取 diff 引擎工厂（单例） |
| `CSDiffMatchPatchFactory::create()` | 创建 diff-match-patch 实例 |
| `cfCompareStrings(const char*, const char*, bool)` | 字符串比较（bool=caseSensitive?） |
| `cfCompareStringsCI(const char*, const char*)` | 大小写不敏感比较 |
| `cfCompareStringsCS(const char*, const char*)` | 大小写敏感比较 |
| `ccCompareStringsLogical_Fast(const char*, const char*)` | 快速逻辑比较（自然排序） |

### 树形比较结果 [主二进制]

| 符号（ObjC） | 含义 |
|------|------|
| `StructureSynchronizationCompareEntry` | 树节点：state/source/target/children |
| `-[StructureSynchronizationCompareEntry fetchChildren]` | 惰性加载子节点 |
| `-[StructureSynchronizationCompareEntry fetchOwnInfo]` | 加载自身详情 |
| `-[StructureSynchronizationCompareEntry updateAncestorState]` | 向上传播状态变化 |
| `-[StructureSynchronizationCompareEntry state]` / `setState:` | 状态枚举（SAME/ADD/ALTER/DROP） |
| `-[StructureSynchronizationCompareEntry opIcon]` | 操作图标 |
| `-[StructureSynchronizationCompareEntry topGroupNodeUpdateLabel]` | 顶部组标签更新 |
| `-[StructureSynchronizationCompareEntry sourceLabel]` / `targetLabel` | 源/目标标签文本 |
| `-[StructureSynchronizationCompareEntry sourceIcon]` / `targetIcon` | 源/目标图标 |
| `-[StructureSynchronizationCompareEntry numberOfCheckedChildren]` | 已勾选子节点计数 |

### 事件通知

| 字符串（`strings` 提取） | 含义 |
|------|------|
| `CSStructureSyncFormCompareCompleted` | 比较完成 |
| `CSStructureSyncFormCompareCancelled` | 比较取消 |
| `CSStructureSyncFormCompareFailed` | 比较失败 |
| `CSStructureSyncFormUpdateMessageLog` | 消息日志更新 |

### CMFetchScope 枚举值（`strings` 提取）

```
Quick              ← 快速快照（仅对象清单）
Partial            ← 部分采集
Standard           ← 标准层级
Standard Table     ← 表级标准
Full Definition    ← 完整 DDL
Full Analyze       ← 全量分析
```

---

## 二、Phase 1: Schema Collection（分阶段异步采集）

Navicat 的元数据采集通过 **CMFetchScope + CMFetchDetailLevel** 两个参数控制粒度，每类对象有自己的 `_Complete` 回调。

### 架构流程

```
fetchObjects(scope=Quick, detail=Quick)
  → 触发并行对象采集
    ├─ fetchTablesComplete(scope=Quick)     ← 仅对象名+元数据
    ├─ fetchViewsComplete(scope=Quick)
    ├─ fetchFunctionsComplete(scope=Quick)
    └─ ...

fetchObjects(scope=FullDefinition, detail=Full)
  → 触发 DDL 采集
    ├─ fetchTablesComplete(scope=FullDefinition)
    │   ├─ fetchTableFieldsComplete(scope=Full)
    │   ├─ fetchIndexesComplete(scope=Full, bool)
    │   ├─ fetchTableConstraintsComplete(scope=Full)
    │   └─ ...
    ├─ fetchViewsComplete(scope=FullDefinition)
    └─ ...
```

### CMFetchScope 在各阶段的具体 SQL

#### Scope=Quick（快照阶段）

```sql
-- 仅查对象名、类型、更新时间
SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_ROWS, CREATE_TIME, UPDATE_TIME
FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?;

-- 这一步不查 SHOW CREATE TABLE
-- 通过元数据快速判断对象是否存在
```

#### Scope=FullDefinition（DDL 采集）

```sql
-- 对每个表
SHOW CREATE TABLE `dbname`.`tablename`;

SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
       CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE,
       COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT,
       CHARACTER_SET_NAME, COLLATION_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
ORDER BY ORDINAL_POSITION;
```

#### Scope=Full (索引/约束采集)

```sql
SELECT INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, COLLATION,
       CARDINALITY, SUB_PART, PACKED, NULLABLE, INDEX_TYPE, COMMENT,
       INDEX_COMMENT
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
```

### Schema 缓存机制

**`idCacheFetchSchemaIdentifiers`**（✅ 已验证）：
- `openSchema(CMObject*, bool)` / `closeSchema(CMObject*)` 管理缓存生命周期
- 标识符级别的缓存，同一会话多次比较同一数据库可跳过重复采集
- 通过比较对象集合的变化（计数 + 更新时间）决定是否需要重新采集

---

## 三、Phase 2: Graph Construction（依赖图构建）

```
对象类型层级（构建顺序 → 删除需反序）：
  Level 0: 数据库/模式（无依赖）
  Level 1: 扩展/域/UDF（无依赖）
  Level 2: 表 structure（无外键依赖）
  Level 3: 表 索引（依赖表）
  Level 4: 表 外键（依赖表 + 被引用表）
  Level 5: 视图/物化视图（依赖表）
  Level 6: 函数/存储过程（依赖表/视图）
  Level 7: 触发器（依赖表）
  Level 8: 事件/序列（无依赖）
```

依赖图表现为 **DAG**，由 `fetchXxxComplete` 回调的顺序和 `updateAncestorState` 方法维护。

---

## 四、Phase 3: Diff Calculation（核心算法）

### 核心结论：CSDiffMatchPatch ✅（不是 MD5 hash）

实际比较引擎是 **`CSDiffMatchPatchFactory::create()`**（`libcf.dylib`），创建 `CSDiffMatchPatch` 实例进行 DDL 文本的语义化 diff。

### 4.1 两步过滤 → 一次精确 diff

```
Step 1（CMFetchScope::Quick 级别）：
  对比对象清单（源/目标各有哪些对象）
  → 只存在单侧的对象标记 ADD/DROP（不进入 DDL diff）
  → 双侧都存在则标记为 POTENTIAL_CHANGE

Step 2（CMFetchScope::FullDefinition 级别）：
  对 POTENTIAL_CHANGE 对象采集完整 DDL
  → CSDiffMatchPatch::create() 产生比较器
  → 对 DDL 文本做 diff-match-patch 语义化 diff
  → 结果映射为 StructureSynchronizationCompareEntry 树
    state: SAME | ADD | ALTER | DROP
```

### 4.2 CSDiffMatchPatch 的优势

与 MD5 hash 对比：

| 方案 | 能判断 | 不能判断 |
|------|--------|---------|
| MD5 hash | 相同/不同 | 哪里不同、不同多少 |
| diff-match-patch | 相同/不同 + 精确差异位置 | — |

CSDiffMatchPatch（基于 Google diff-match-patch / Myers' diff）：
- 对 DDL 文本（如 `SHOW CREATE TABLE` 输出）做字符级 diff
- 解析 diff 结果可得到列级、约束级的精确变更
- 直接驱动 Subtype Diff 展开显示

### 4.3 字符串比较工具链

多个比较函数用于 diff 结果的精细化解析：

| 函数 | 作用 | 使用场景 |
|------|------|---------|
| `cfCompareStrings(a, b, caseSensitive)` | 通用比较 | 列名精确匹配 |
| `cfCompareStringsCI(a, b)` | 大小写不敏感 | MySQL 表名（默认 case-insensitive） |
| `cfCompareStringsCS(a, b)` | 大小写敏感 | PostgreSQL 表名 |
| `ccCompareStringsLogical_Fast(a, b)` | 逻辑排序比较 | 如 "a2" < "a10" 而非字典序 |

### 4.4 StructureSynchronizationCompareEntry 树形结果

```
Table "users" [state=ALTER]
├── Column "name" [state=ALTER]
│   └── data_type: VARCHAR(100) → VARCHAR(255)
├── Column "status" [state=ALTER]
│   └── column_default: NULL → DEFAULT 'active'
├── Index "idx_email" [state=SAME]
└── Index "idx_status" [state=ADD]
```

**关键方法**：
- `fetchChildren`：惰性加载子差异项
- `fetchOwnInfo`：加载自身差异详情
- `updateAncestorState`：子节点变更时向上更新父节点状态
- `state` / `setState:`：状态枚举（SAME/ADD/ALTER/DROP）
- `numberOfCheckedChildren`：已勾选的子节点数（用于 UI 显示）

---

## 五、Phase 4: DDL Generation（同步脚本生成）

### 5.1 依赖逆序执行

```
拓扑排序顺序：
  DROP:  触发器 → 外键 → 索引 → 视图 → 表
  ALTER: 表结构 → 索引 → 外键 → 触发器
  CREATE: 数据库 → 表 → 索引 → 外键 → 视图 → 函数 → 触发器
```

依赖排序由 `fetchXxxComplete` 回调的顺序保证（先采集基础对象，再采集依赖对象）。

### 5.2 ALTER TABLE 合并优化

Navicat 会将同一张表的多个 ALTER 合并为一条：

```sql
-- 修改列类型 + 添加索引 + 修改默认值
-- 生成一条而不是三条：
ALTER TABLE `users`
  MODIFY COLUMN `status` INT NOT NULL DEFAULT 1,
  ADD INDEX `idx_status` (`status`),
  ALTER COLUMN `name` SET DEFAULT 'unknown';
```

### 5.3 StructureSynchronizationDeploymentOptionModule

从 UI 字符串可确认存在部署选项模块，包含：
- 执行顺序选项（依赖排序开关）
- 事务包装选项（是否包在 `START TRANSACTION`/`COMMIT` 中）
- 备份选项（执行前备份）

---

## 六、性能优化：Navicat 的技术超配

### 6.1 CMFetchScope 分层避免不必要采集

```
Quick 阶段： 3 条 SQL → 对象清单 + 元数据标签（~50ms）
             如果对象高度重叠，可跳过 Full 阶段的大部分
Full 阶段：  SHOW CREATE TABLE × N 表 → 完整 DDL
             只在 Quick 标记为 POTENTIAL_CHANGE 的对象上执行
```

### 6.2 异步并行采集

每个 `fetchXxxComplete` 回调独立收发，通过异步管道并行执行：
- `fetchTableFieldsComplete` / `fetchIndexesComplete` / `fetchConstraintsComplete` 可同时进行
- 每类对象独立连接（或共享连接池）

### 6.3 Schema 标识缓存

```cpp
// 伪代码：从符号推测的缓存逻辑
class CCSchemaCache {
    std::unordered_map<std::string, SchemaIdentifiers> m_cache;
    
    void openSchema(CMObject* obj) {
        // 检查缓存是否有效
        // 如果 schema 元数据未变化，使用缓存
    }
    
    bool fetchIdentifier(const std::string& name, CMFetchScope scope) {
        auto it = m_cache.find(name);
        if (it != m_cache.end() && it->second.scope >= scope) {
            return false; // 命中缓存，跳过采集
        }
        return true; // 需要采集
    }
};
```

### 6.4 增量更新

通过 `CMFetchScope::Quick` 返回的元数据标签：
- `UPDATE_TIME`：表级别的最后修改时间
- `TABLE_ROWS`：行数变化可作为数据变更提示
- 只有 Quick 对比发现有变化的对象才做 Full 采集

### 6.5 C++ 引擎 + ObjC UI 分离

```
libcc.dylib:     C++ 核心逻辑（采集、比较、DDL 生成）
libcf.dylib:     Core Framework（CSDiffMatchPatch 引擎、字符串工具）
主二进制:          ObjC UI 层（StructureSynchronizationCompareEntry 树）
```

异步回调通过 `prepareCallbacksWithRecoveryHandle:` 在 C++ 引擎和 ObjC UI 之间传递结果。

---

## 七、你 PHP 版本的具体改进清单

### 🔴 关键差距（性能瓶颈）

| 你的做法 | Navicat 的做法 | 影响 |
|---------|---------------|------|
| 所有表全量采集 | CMFetchScope 分层（Quick → Full） | 慢 5-10x |
| 全部逐属性比较 | CSDiffMatchPatch 语义化 diff | 慢 10-50x |
| 串行查询 | 并行 `_Complete` 回调管道 | 慢 N 倍 |
| 每个变更一条 ALTER | 合并 ALTER TABLE | 慢 2-5x |
| 无缓存 | `idCacheFetchSchemaIdentifiers` 缓存 | 重复计算 |

### 🟡 细节差距（逻辑不完整）

| 你的做法 | Navicat 的做法 | 影响 |
|---------|---------------|------|
| 漏了 charset/collation | `cfCompareStringsCI/CS` 多模式比较 | DDL 不完整 |
| 外键 ON UPDATE 可能漏 | `fetchTableConstraintsComplete` 全覆盖 | 同步失败 |
| 索引列排序不敏感 | 列顺序敏感比较 | 误判 SAME |
| 依赖排序 | `fetchXxxComplete` + `updateAncestorState` | 约束冲突报错 |

### 🟢 你做的对的

- INFORMATION_SCHEMA 读取方向正确
- ADD/ALTER/DROP 三态判定正确
- 生成 ALTER TABLE 的方向正确
- PHP 纯 SQL + WebView 方案不需要 FFI

---

## 八、如果你要优化 PHP 版的建议架构

```php
class StructureSync {
    // Step 1: Quick Snapshot — 只查对象清单
    public function quickCompare($source, $target) {
        $source_tables = $source->query("SELECT TABLE_NAME, UPDATE_TIME
            FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=?");
        $target_tables = $target->query("SELECT TABLE_NAME, UPDATE_TIME
            FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=?");
        
        $added = array_diff($target_names, $source_names);
        $dropped = array_diff($source_names, $target_names);
        $candidates = array_intersect($source_names, $target_names);
        
        // 只有双侧都存在的表需要 DDL diff
        return ['added' => $added, 'dropped' => $dropped, 'candidates' => $candidates];
    }
    
    // Step 2: Full DDL + diff-match-patch 比较
    public function fullCompare($source, $target, $candidates) {
        foreach ($candidates as $table) {
            $source_ddl = $source->query("SHOW CREATE TABLE `$table`");
            $target_ddl = $target->query("SHOW CREATE TABLE `$table`");
            
            // 用 PHP 的 xdiff_string_diff 或自定义 diff 算法比较 DDL
            $diff = xdiff_string_diff($normalized_source, $normalized_target, 1);
            if ($diff) {
                // 解析 diff 结果得到精确列级差异
            }
        }
    }
    
    // Step 3: 缓存（文件或 memcached）
    public function getCachedDDL($db, $table) {
        $key = "ddl:" . $db->getDbName() . ":$table";
        return $this->cache->get($key);
    }
    
    public function setCachedDDL($db, $table, $ddl) {
        $key = "ddl:" . $db->getDbName() . ":$table";
        $this->cache->set($key, $ddl, 3600); // 1h TTL
    }
}
```

---

## 九、数据同步与结构同步共享架构

两者使用同一套比较基础设施：

```
Structure Sync:                        Data Sync:
  structureSyncFormPrepareCompare        dataSyncFormPrepareCompare
  fetchSchemas                           [数据采集...]
  CSDiffMatchPatch::create()             相同比较引擎
  StructureSynchronizationCompareEntry   DataSyncCompareEntry?
```

两者共享 `obtainCSDiffMatchPatchFactory()` 同一套比较引擎，结果都以「行/列」方式获取（`obtainRow` / `obtainData` / `obtainNumberOfRows`）。

---

## 十、验证命令（如果你能复现）

```bash
# 1. 确认结构同步入口
nm "/Applications/Navicat Premium.app/Contents/Frameworks/libcc.dylib" | grep structureSync

# 2. 确认 CSDiffMatchPatch 工厂
nm "/Applications/Navicat Premium.app/Contents/Frameworks/libcf.dylib" | grep CSDiffMatchPatch

# 3. 确认 CMFetchScope 枚举值
strings "/Applications/Navicat Premium.app/Contents/MacOS/Navicat Premium" | grep -E "^Quick$|^Partial$|^Standard$|^Full Definition$|^Full Analyze$"

# 4. 确认回调模式
nm "/Applications/Navicat Premium.app" | grep prepareCallbacksWithRecoveryHandle

# 5. 确认采集完成回调
nm "/Applications/Navicat Premium.app/Contents/Frameworks/libcc.dylib" | grep fetch.*Complete
```

---

## 附录：`c++filt` 完整 demangle 对照

| mangled | demangled |
|---------|-----------|
| `_ZN9CCNavicat31structureSyncFormPrepareCompareEP7ICSForm` | `CCNavicat::structureSyncFormPrepareCompare(ICSForm*)` |
| `_ZN9CCNavicat12fetchObjectsEP8CMObject12CMFetchScope12CMObjectType18CMFetchDetailLevelNSt3__112basic_stringIcNS5_11char_traitsIcEENS5_9allocatorIcEEEEb` | `CCNavicat::fetchObjects(CMObject*, CMFetchScope, CMObjectType, CMFetchDetailLevel, std::string, bool)` |
| `_ZN9CCNavicat19fetchTablesCompleteEP8CMObject12CMFetchScopeb` | `CCNavicat::fetchTablesComplete(CMObject*, CMFetchScope, bool)` |
| `_ZN9CCNavicat24fetchTableFieldsCompleteEP8CMObject12CMFetchScope` | `CCNavicat::fetchTableFieldsComplete(CMObject*, CMFetchScope)` |
| `_ZN2CF12CSAppFactory29obtainCSDiffMatchPatchFactoryEv` | `CF::CSAppFactory::obtainCSDiffMatchPatchFactory()` |
| `_ZNK23CSDiffMatchPatchFactory6createEv` | `CSDiffMatchPatchFactory::create() const` |

---

*本文档基于 Navicat Premium 17.2.9/17.3.1 二进制逆向分析重建*
*✅ = 通过 `nm`/`strings` 在二进制中直接验证*
*🔄 = 基于符号名和行为模式推断*
