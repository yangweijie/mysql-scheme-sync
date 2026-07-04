<?php
namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;
use Yangweijie\ThinkOrmAsync\AsyncContext;

/**
 * StructSyncAdapter — MySQL structure comparison engine (Phase 2&3 of Navicat-style algorithm).
 *
 * Algorithm (ref: .omo/navicat_structure_sync_algorithm_recon.md):
 *   Phase 1: Schema Collection   — Fetch SHOW CREATE TABLE for source & target
 *   Phase 2: Graph Construction   — Implicit DAG through structure traversal order
 *   Phase 3: Diff Calculation     — DDLDefinitionParser semantic column comparison
 *   Phase 4: DDL Generation       — Convert to flat SQL array → Generator merges ALTERs
 *
 * Key improvements over older MysqlStructSync approach:
 *   - DDLDefinitionParser field-level column comparison (charset, collation, ON UPDATE, etc.)
 *   - Semantic equality: catches formatting differences MySQL introduces
 *   - Structured diff storage for precise change descriptions
 */
class StructSyncAdapter
{
    private Connection $source;
    private Connection $target;
    private ?array $srcStruct = null;
    private ?array $tgtStruct = null;
    private array $diffSql = [];
    private bool $cancelled = false;
    private ?\Closure $onProgress = null;

    /** @var ?array<string, array<string, string>> Pre-fetched advanced objects [type][name => sql] */
    private ?array $prefetchedAdvanceSrc = null;
    private ?array $prefetchedAdvanceTgt = null;

    /** @var string[] Enabled compare scope categories */
    private array $scope = ['tables', 'views', 'functions', 'procedures', 'foreign_keys', 'triggers', 'events'];

    /** @var string[] Table names that will be newly created (constraint diffs for these skipped) */
    private array $newTableNames = [];
    /** @var string[] Table names that will be dropped (constraint diffs for these skipped) */
    private array $droppedTableNames = [];

    /**
     * Structured per-table diffs from DDLDefinitionParser field-level comparison.
     * Used by Generator for enriched ALTER TABLE with field-level change descriptions.
     *
     * @var array<string, array> [tableName => ['modify' => [colName => [['field'=>,'old'=>,'new'=>]]], ...]]
     */
    private array $structuredDiffs = [];

    public function cancel(): void { $this->cancelled = true; }
    public function isCancelled(): bool { return $this->cancelled; }

    public function setScope(array $scope): void { $this->scope = $scope; }

    public function __construct(Connection $source, Connection $target)
    {
        $this->source = $source;
        $this->target = $target;
    }

    public function setOnProgress(\Closure $cb): void { $this->onProgress = $cb; }
    public function setOnPhase(\Closure $cb): void { /* not supported */ }

    /**
     * Inject pre-fetched table structures (bypasses fetchAll database queries).
     */
    public function setFetchedStructs(array $src, array $tgt): void
    {
        $this->srcStruct = $src;
        $this->tgtStruct = $tgt;
    }

    /**
     * Inject pre-fetched advanced object results (VIEW/TRIGGER/EVENT/FUNCTION/PROCEDURE).
     * When set, appendAdvanceDiffSql uses these instead of querying the database.
     *
     * Format: ['VIEW' => ['name' => 'CREATE SQL ...'], 'TRIGGER' => [...], ...]
     */
    public function setPrefetchedAdvance(array $src, array $tgt): void
    {
        $this->prefetchedAdvanceSrc = $src;
        $this->prefetchedAdvanceTgt = $tgt;
    }

    /**
     * Get structured field-level diffs for enhanced change descriptions.
     * @return array<string, array>
     */
    public function getStructuredDiffs(): array
    {
        return $this->structuredDiffs;
    }

    /**
     * 异步获取两张库的结构并比对
     */
    public function fetchAll(array $excludePatterns = []): void
    {
        $fetcher = new AsyncStructureFetcher();

        if ($this->onProgress) {
            ($this->onProgress)(\MySqlSchemaSync\Diff\DiffResult::PHASE_FETCH_SOURCE, 0, 0);
        }

        // 并发获取两张库的结构（每张库内部并发，两张库顺序执行）
        $this->srcStruct = $fetcher->fetchStructure($this->source, $excludePatterns);
        $this->tgtStruct = $fetcher->fetchStructure($this->target, $excludePatterns);
    }

    public function compare(array $excludePatterns = []): DiffResult
    {
        $d = new DiffResult();
        if ($this->cancelled) { $d->error = '比对已取消'; return $d; }

        // 如果还没获取结构，先获取
        if ($this->srcStruct === null || $this->tgtStruct === null) {
            $this->fetchAll($excludePatterns);
        }

        if ($this->onProgress) {
            ($this->onProgress)(\MySqlSchemaSync\Diff\DiffResult::PHASE_COMPARE, 0, 0);
        }

        $this->diffSql = $this->buildDiffSql();
        return $this->buildDiffResult();
    }

    /**
     * 核心比对逻辑 — Navicat-style two-phase diff with DDLDefinitionParser.
     *
     * Phase 1 (Quick Snapshot): table name sets → ADD, DROP, CANDIDATE
     * Phase 2 (Full DDL Compare): semantic column/constraint diff via DDLDefinitionParser
     *
     * Direction: source (new) → target (old), produce SQL to make old = new.
     */
    private function buildDiffSql(): array
    {
        // $this->srcStruct = srcCombo（用户选的「源库」= 新结构）
        // $this->tgtStruct = tgtCombo（用户选的「目标库」= 要同步的旧结构）
        // 方向：让旧结构匹配新结构
        $src = $this->tgtStruct;  // 旧结构（要被修改的）
        $tgt = $this->srcStruct;  // 新结构（参考基准）

        $res = [];

        $tablesEnabled = in_array('tables', $this->scope);
        $fkEnabled = in_array('foreign_keys', $this->scope);

        // 1-4. 表/列/约束差异（仅当 tables scope 开启时）
        if ($tablesEnabled) {
            // ─── Phase 1: Quick Snapshot — table name sets ───
            // 1. 新增表（新结构有、旧结构没有 → 添加）
            $res['ADD_TABLE'] = array_diff($tgt['tables'] ?? [], $src['tables'] ?? []);
            // 2. 删除表（旧结构有、新结构没有 → 删除）
            $res['DROP_TABLE'] = array_diff($src['tables'] ?? [], $tgt['tables'] ?? []);

            // Remember new/dropped table names for buildDiffResult() safety filter
            $this->newTableNames = $res['ADD_TABLE'];
            $this->droppedTableNames = $res['DROP_TABLE'];

            $srcCols = $src['columns'] ?? [];
            $tgtCols = $tgt['columns'] ?? [];

            // ─── Phase 2: Full DDL Compare — column/constraint diff ───
            // 3. 查找公共表（CANDIDATE 表），对比列差异（CSDiffMatchPatch-style）
            $commonTables = array_intersect($src['tables'] ?? [], $tgt['tables'] ?? []);
            $this->structuredDiffs = [];

            foreach ($commonTables as $table) {
                $srcTableCols = $srcCols[$table] ?? [];
                $tgtTableCols = $tgtCols[$table] ?? [];
                $tableDiffs = [];

                // 目标有、源没有 → ADD
                foreach ($tgtTableCols as $field => $def) {
                    if (!isset($srcTableCols[$field])) {
                        $res['ADD_FIELD'][$table][$field] = $def;
                        $tableDiffs['add'][] = $field;
                    } else {
                        // Use DDLDefinitionParser for semantic field-level diff
                        // (analogous to Navicat's CSDiffMatchPatch on DDL text,
                        // but operating on parsed column definitions for precision)
                        $oldParsed = DDLDefinitionParser::parseColumnDef($field, $srcTableCols[$field]);
                        $newParsed = DDLDefinitionParser::parseColumnDef($field, $def);

                        if (!DDLDefinitionParser::columnDefEquals($oldParsed, $newParsed)) {
                            $res['MODIFY_FIELD'][$table][$field] = $def;
                            $fieldDiffs = DDLDefinitionParser::compareColumnDefs($oldParsed, $newParsed);
                            $tableDiffs['modify'][$field] = $fieldDiffs;
                        }
                    }
                }

                // 源有、目标没有 → DROP
                foreach ($srcTableCols as $field => $def) {
                    if (!isset($tgtTableCols[$field])) {
                        $res['DROP_FIELD'][$table][$field] = $def;
                        $tableDiffs['drop'][] = $field;
                    }
                }

                if (!empty($tableDiffs)) {
                    $this->structuredDiffs[$table] = $tableDiffs;
                }
            }

            // 4. 约束差异（index + foreign key comparison）
            // 排除新增/删除表的约束，它们已包含在 CREATE/DROP TABLE 中
            $addTableKeys = array_flip($res['ADD_TABLE'] ?? []);
            $dropTableKeys = array_flip($res['DROP_TABLE'] ?? []);
            $srcConstraints = array_diff_key($src['constraints'] ?? [], $dropTableKeys);
            $tgtConstraints = array_diff_key($tgt['constraints'] ?? [], $addTableKeys);

            // 外键 scope 关闭时，过滤掉 FOREIGN KEY 约束行
            if (!$fkEnabled) {
                $srcConstraints = $this->stripForeignKeys($srcConstraints);
                $tgtConstraints = $this->stripForeignKeys($tgtConstraints);
            }

            // 按约束名逐条比较（而非按数组位置），避免位置偏移导致整表约束都出现在两侧
            // 比较时忽略 USING BTREE/HASH（InnoDB 默认值，不影响语义）
            foreach ($commonTables as $table) {
                $srcLines = $srcConstraints[$table] ?? [];
                $tgtLines = $tgtConstraints[$table] ?? [];
                if (!$srcLines && !$tgtLines) continue;

                $srcByName = [];
                $tgtByName = [];
                foreach ($srcLines as $line) {
                    $srcByName[self::constraintName($line)] = $line;
                }
                foreach ($tgtLines as $line) {
                    $tgtByName[self::constraintName($line)] = $line;
                }

                // DROP: src有tgt没有，或定义不同的
                foreach ($srcByName as $name => $line) {
                    if (!isset($tgtByName[$name])) {
                        $res['DROP_CONSTRAINT'][$table][] = $line;
                    } elseif (self::normalizeConstraint($tgtByName[$name]) !== self::normalizeConstraint($line)) {
                        $res['DROP_CONSTRAINT'][$table][] = $line;
                    }
                }
                // ADD: tgt有src没有，或定义不同的
                foreach ($tgtByName as $name => $line) {
                    if (!isset($srcByName[$name])) {
                        $res['ADD_CONSTRAINT'][$table][] = $line;
                    } elseif (self::normalizeConstraint($srcByName[$name]) !== self::normalizeConstraint($line)) {
                        $res['ADD_CONSTRAINT'][$table][] = $line;
                    }
                }
            }
        }

        // 5. 生成差异 SQL
        $diffSql = [];
        foreach (array_filter($res) as $type => $data) {
            self::appendDiffSql($diffSql, $data, $type, $tgt, $src);
        }

        // 6. 高级对象差异（VIEW/TRIGGER/EVENT/FUNCTION/PROCEDURE）
        $this->appendAdvanceDiffSql($diffSql);

        return $diffSql;
    }

    /**
     * Remove FOREIGN KEY constraint lines from constraint arrays.
     * Keeps PRIMARY KEY and KEY (index) entries.
     */
    private function stripForeignKeys(array $constraints): array
    {
        $filtered = [];
        foreach ($constraints as $table => $lines) {
            $filtered[$table] = array_values(
                array_filter($lines, fn(string $line) => !preg_match('/\bFOREIGN\s+KEY\b/i', $line))
            );
        }
        return $filtered;
    }

    /**
     * 获取高级对象差异（VIEW/TRIGGER 等）
     * 如果有预获取数据则直接使用，否则查询数据库（think-orm-async 并行化）
     */
    private function appendAdvanceDiffSql(array &$diffSql): void
    {
        if ($this->prefetchedAdvanceSrc !== null && $this->prefetchedAdvanceTgt !== null) {
            $this->appendAdvanceFromPrefetched($diffSql);
            return;
        }

        $typeScopeMap = [
            'VIEW'      => 'views',
            'TRIGGER'   => 'triggers',
            'EVENT'     => 'events',
            'FUNCTION'  => 'functions',
            'PROCEDURE' => 'procedures',
        ];

        $advance = [
            'VIEW'      => ["SELECT TABLE_NAME as Name FROM information_schema.VIEWS WHERE TABLE_SCHEMA='#'", 'Create View'],
            'TRIGGER'   => ["SELECT TRIGGER_NAME as Name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='#'", 'SQL Original Statement'],
            'EVENT'     => ["SELECT EVENT_NAME  as Name FROM information_schema.EVENTS WHERE EVENT_SCHEMA='#'", 'Create Event'],
            'FUNCTION'  => ["SHOW FUNCTION STATUS  WHERE Db='#'", 'Create Function'],
            'PROCEDURE' => ["SHOW PROCEDURE STATUS WHERE Db='#'", 'Create Procedure'],
        ];

        $srcConfig = AsyncStructureFetcher::buildAsyncConfig($this->source);
        $tgtConfig = AsyncStructureFetcher::buildAsyncConfig($this->target);

        foreach ($advance as $type => $listSql) {
            $scopeKey = $typeScopeMap[$type];
            if (!in_array($scopeKey, $this->scope)) continue;

            $srcObjs = $this->fetchAdvanceObjectsAsync($srcConfig, $this->source->database, $type, $listSql);
            $tgtObjs = $this->fetchAdvanceObjectsAsync($tgtConfig, $this->target->database, $type, $listSql);

            $common = array_intersect_key($srcObjs, $tgtObjs);
            $added  = array_diff_key($srcObjs, $tgtObjs);
            $dropped = array_diff_key($tgtObjs, $srcObjs);

            $modify = [];
            foreach ($common as $name => $sql) {
                if (($tgtObjs[$name] ?? '') !== $sql) {
                    $modify[$name] = $sql;
                }
            }

            $diffSql['ADD_' . $type]    = $added;
            $diffSql['DROP_' . $type]   = $dropped;
            if ($modify) $diffSql['MODIFY_' . $type] = $modify;
        }
    }

    /**
     * Diff advanced objects from pre-fetched data (no DB queries).
     * Properly separates: truly added, truly dropped, and modified (exists in both but definition differs).
     */
    private function appendAdvanceFromPrefetched(array &$diffSql): void
    {
        $typeScopeMap = [
            'VIEW'      => 'views',
            'TRIGGER'   => 'triggers',
            'EVENT'     => 'events',
            'FUNCTION'  => 'functions',
            'PROCEDURE' => 'procedures',
        ];

        foreach ($typeScopeMap as $type => $scopeKey) {
            if (!in_array($scopeKey, $this->scope)) continue;

            $srcObjs = $this->prefetchedAdvanceSrc[$type] ?? [];
            $tgtObjs = $this->prefetchedAdvanceTgt[$type] ?? [];

            $common = array_intersect_key($srcObjs, $tgtObjs);
            $added  = array_diff_key($srcObjs, $tgtObjs);
            $dropped = array_diff_key($tgtObjs, $srcObjs);

            $modify = [];
            foreach ($common as $name => $sql) {
                if (($tgtObjs[$name] ?? '') !== $sql) {
                    $modify[$name] = $sql;
                }
            }

            $diffSql['ADD_' . $type]    = $added;
            $diffSql['DROP_' . $type]   = $dropped;
            if ($modify) $diffSql['MODIFY_' . $type] = $modify;
        }
    }

    private function fetchAdvanceObjectsAsync(array $config, string $dbName, string $type, array $listSql): array
    {
        $conn = new \mysqli(
            $config['hostname'], $config['username'], $config['password'],
            $config['database'], $config['hostport']
        );
        if ($conn->connect_error) return [];
        $conn->set_charset($config['charset']);

        $sql = str_replace('#', $dbName, $listSql[0]);
        $result = $conn->query($sql);
        $names = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $names[] = $row['Name'];
            }
        }
        $conn->close();

        if (empty($names)) return [];

        AsyncContext::start(null, $config);
        foreach ($names as $name) {
            AsyncContext::query("SHOW CREATE {$type} `{$name}`", $name);
        }
        $rawResults = AsyncContext::end();

        $objects = [];
        foreach ($names as $name) {
            $data = $rawResults[$name] ?? [];
            $createSql = $data[0][$listSql[1]] ?? '';
            if ($createSql) {
                $objects[$name] = preg_replace('/DEFINER=[^\s]*/', '', $createSql);
            }
        }
        return $objects;
    }

    private static function appendDiffSql(array &$diffSql, array $arr, string $type, array $tgtStruct, array $srcStruct): void
    {
        foreach ($arr as $table => $rows) {
            if (in_array($type, ['ADD_TABLE', 'DROP_TABLE'])) {
                $sql = $type == 'ADD_TABLE'
                    ? $tgtStruct['show_create'][$rows] ?? ''
                    : "DROP TABLE IF EXISTS `{$rows}`";
                if ($sql) $diffSql[$type][] = $sql;
                continue;
            }
            if (is_array($rows)) {
                foreach ($rows as $key => $val) {
                    switch ($type) {
                        case 'MODIFY_FIELD': $sql = "ALTER TABLE `{$table}` MODIFY {$val}"; break;
                        case 'DROP_FIELD':  $sql = "ALTER TABLE `{$table}` DROP `{$key}`"; break;
                        case 'ADD_FIELD':   $sql = "ALTER TABLE `{$table}` ADD {$val}"; break;
                        case 'ADD_CONSTRAINT':
                            $sql = self::getConstraintQuery($val, $table)['add'] ?? ''; break;
                        case 'DROP_CONSTRAINT':
                            $sql = self::getConstraintQuery($val, $table)['drop'] ?? ''; break;
                        default: $sql = '';
                    }
                    if ($sql) $diffSql[$type][] = $sql;
                }
            }
        }
    }

    private static function getConstraintQuery(string $constraint, string $table): array
    {
        $patterns = [
            'primary'    => '(^[^`]\s*PRIMARY KEY .*[,]?$)',
            'key'        => '(^[^`]\s*KEY\s+(`.*`) .*[,]?$)',
            'constraint' => '(^[^`]\s*CONSTRAINT\s+(`.*`) .*[,]?$)',
        ];
        foreach ($patterns as $key => $pattern) {
            if (preg_match("/" . str_replace('^[^`]', '', $pattern) . "$/m", $constraint, $matches)) {
                switch ($key) {
                    case 'primary':
                        return [
                            'drop' => "ALTER TABLE `{$table}` DROP PRIMARY KEY;",
                            'add'  => "ALTER TABLE `{$table}` ADD " . rtrim($constraint, ','),
                        ];
                    case 'key':
                        return [
                            'drop' => "ALTER TABLE `{$table}` DROP KEY {$matches[2]};",
                            'add'  => "ALTER TABLE `{$table}` ADD " . rtrim($constraint, ','),
                        ];
                    case 'constraint':
                        return [
                            'drop' => "ALTER TABLE `{$table}` DROP CONSTRAINT {$matches[2]};",
                            'add'  => "ALTER TABLE `{$table}` ADD " . rtrim($constraint, ','),
                        ];
                }
            }
        }
        return ['drop' => '', 'add' => ''];
    }

    private static function arrayDiffAssocRecursive(array $array1, array $array2): array
    {
        $ret = [];
        if (!$array1) return $ret;
        foreach ($array1 as $k => $v) {
            if (!isset($array2[$k])) {
                $ret[$k] = $v;
            } elseif (is_array($v) && is_array($array2[$k])) {
                $r = self::arrayDiffAssocRecursive($v, $array2[$k]);
                if ($r) $ret[$k] = $r;
            } elseif ($v != $array2[$k]) {
                $ret[$k] = $v;
            }
        }
        return array_filter($ret);
    }

    private function buildDiffResult(): DiffResult
    {
        $d = new DiffResult();
        foreach ($this->diffSql['ADD_TABLE'] ?? [] as $sql) {
            if (preg_match('/CREATE\s+TABLE\s+`?(\w+)`?/i', $sql, $m))
                $d->newTables[] = ['name' => $m[1], 'risk' => DiffResult::RISK_SAFE, 'createSql' => $sql];
        }
        foreach ($this->diffSql['DROP_TABLE'] ?? [] as $sql) {
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m))
                $d->removedTables[] = ['name' => $m[1], 'risk' => DiffResult::RISK_HIGH];
        }
        // diffSql contains flat SQL strings: parse them back to structured data
        // MODIFY_FIELD: ALTER TABLE `t` MODIFY `col` definition
        foreach ($this->diffSql['MODIFY_FIELD'] ?? [] as $sql) {
            if (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+MODIFY\s+`(\w+)`\s+(.*)/is", $sql, $m)) {
                $table = $m[1]; $col = $m[2]; $def = $m[3];
                $change = ['kind' => 'MODIFY_COLUMN', 'risk' => DiffResult::RISK_WARN, 'column' => $col, 'detail' => $def];

                // Attach field-level diffs from DDLDefinitionParser for enriched UI display
                if (isset($this->structuredDiffs[$table]['modify'][$col])) {
                    $change['field_diffs'] = $this->structuredDiffs[$table]['modify'][$col];
                    $change['detail'] = self::formatFieldDiffs($this->structuredDiffs[$table]['modify'][$col]);
                }

                $d->changedTables[$table]['name'] = $table;
                $d->changedTables[$table]['risk'] = DiffResult::RISK_WARN;
                $d->changedTables[$table]['changes'][] = $change;
            }
        }
        // ADD_FIELD: ALTER TABLE `t` ADD `col` definition
        foreach ($this->diffSql['ADD_FIELD'] ?? [] as $sql) {
            if (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+ADD\s+`(\w+)`\s+(.*)/is", $sql, $m)) {
                $table = $m[1]; $col = $m[2]; $def = $m[3];
                $d->changedTables[$table]['name'] = $table;
                $d->changedTables[$table]['risk'] = DiffResult::RISK_SAFE;
                $d->changedTables[$table]['changes'][] = ['kind' => 'ADD_COLUMN', 'risk' => DiffResult::RISK_SAFE, 'column' => $col, 'detail' => $def];
            }
        }
        // DROP_FIELD: ALTER TABLE `t` DROP `col`
        foreach ($this->diffSql['DROP_FIELD'] ?? [] as $sql) {
            if (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+DROP\s+`(\w+)`/is", $sql, $m)) {
                $table = $m[1]; $col = $m[2];
                $d->changedTables[$table]['name'] = $table;
                $d->changedTables[$table]['risk'] = DiffResult::RISK_HIGH;
                $d->changedTables[$table]['changes'][] = ['kind' => 'DROP_COLUMN', 'risk' => DiffResult::RISK_HIGH, 'column' => $col];
            }
        }
        // ADD_CONSTRAINT: ALTER TABLE `t` ADD PRIMARY KEY / KEY / CONSTRAINT FOREIGN KEY
        $newFKTableSkip = array_flip($this->newTableNames);
        foreach ($this->diffSql['ADD_CONSTRAINT'] ?? [] as $sql) {
            if (!preg_match("/^ALTER\s+TABLE\s+`(\w+)`/is", $sql, $m)) continue;
            $table = $m[1];
            // Skip constraints for newly added tables — already in CREATE TABLE SQL
            if (isset($newFKTableSkip[$table])) continue;

            if (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+ADD\s+(?:PRIMARY\s+KEY|KEY\s+`(\w+)`)/is", $sql, $m)) {
                $idxName = (isset($m[2]) && $m[2] !== '') ? $m[2] : 'PRIMARY';
                $d->newIndexes[] = ['name' => $idxName, 'risk' => DiffResult::RISK_SAFE, 'table' => $table];
            } elseif (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+ADD\s+CONSTRAINT\s+`(\w+)`\s+FOREIGN\s+KEY/is", $sql, $m)) {
                $d->newForeignKeys[] = ['name' => $m[2], 'risk' => DiffResult::RISK_SAFE, 'table' => $m[1]];
            }
        }
        // DROP_CONSTRAINT: ALTER TABLE `t` DROP PRIMARY KEY / KEY / CONSTRAINT
        $dropFKTableSkip = array_flip($this->droppedTableNames);
        foreach ($this->diffSql['DROP_CONSTRAINT'] ?? [] as $sql) {
            if (!preg_match("/^ALTER\s+TABLE\s+`(\w+)`/is", $sql, $m)) continue;
            $table = $m[1];
            // Skip constraints for dropped tables — already covered by DROP TABLE
            if (isset($dropFKTableSkip[$table])) continue;

            if (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+DROP\s+(?:PRIMARY\s+KEY|KEY\s+`(\w+)`)/is", $sql, $m)) {
                $idxName = (isset($m[2]) && $m[2] !== '') ? $m[2] : 'PRIMARY';
                $d->removedIndexes[] = ['name' => $idxName, 'risk' => DiffResult::RISK_HIGH, 'table' => $table];
            } elseif (preg_match("/^ALTER\s+TABLE\s+`(\w+)`\s+DROP\s+CONSTRAINT\s+`(\w+)`/is", $sql, $m)) {
                $d->removedForeignKeys[] = ['name' => $m[2], 'risk' => DiffResult::RISK_HIGH, 'table' => $m[1]];
            }
        }
        // Normalize changedTables from temp keyed array to sequential
        $d->changedTables = array_values($d->changedTables);
        foreach (['ADD_VIEW'=>'newViews','MODIFY_VIEW'=>'changedViews','DROP_VIEW'=>'removedViews','ADD_PROCEDURE'=>'newProcedures','MODIFY_PROCEDURE'=>'changedProcedures','DROP_PROCEDURE'=>'removedProcedures','ADD_FUNCTION'=>'newFunctions','MODIFY_FUNCTION'=>'changedFunctions','DROP_FUNCTION'=>'removedFunctions','ADD_TRIGGER'=>'newTriggers','MODIFY_TRIGGER'=>'changedTriggers','DROP_TRIGGER'=>'removedTriggers','ADD_EVENT'=>'newEvents','MODIFY_EVENT'=>'changedEvents','DROP_EVENT'=>'removedEvents'] as $type => $prop) {
            $risk = str_starts_with($type, 'ADD') ? DiffResult::RISK_SAFE : (str_starts_with($type, 'MODIFY') ? DiffResult::RISK_WARN : DiffResult::RISK_HIGH);
            foreach ($this->diffSql[$type] ?? [] as $sql) {
                $name = preg_match('/`(\w+)`/', $sql, $m) ? $m[1] : null;
                if ($name) $d->{$prop}[] = ['name' => $name, 'risk' => $risk];
            }
        }
        return $d;
    }

    /**
     * Format field-level diffs from DDLDefinitionParser into a human-readable string.
     */
    private static function formatFieldDiffs(array $diffs): string
    {
        $parts = [];
        foreach ($diffs as $diff) {
            $field = $diff['field'];
            $old = $diff['old'];
            $new = $diff['new'];

            // Format boolean/null values
            if ($field === 'nullable') {
                $oldStr = $old ? 'NULL' : 'NOT NULL';
                $newStr = $new ? 'NULL' : 'NOT NULL';
            } else {
                $oldStr = $old === null ? '(none)' : (string)$old;
                $newStr = $new === null ? '(none)' : (string)$new;
            }

            $fieldLabel = [
                'type' => '数据类型', 'nullable' => '可为空', 'default' => '默认值',
                'extra' => '额外属性', 'comment' => '注释', 'charset' => '字符集',
                'collation' => '排序规则', 'on_update' => 'ON UPDATE', 'generated' => '生成列',
            ][$field] ?? $field;

            $parts[] = "{$fieldLabel}: {$oldStr} → {$newStr}";
        }
        return implode('; ', $parts);
    }

    public function getDiffSql(): array { return $this->diffSql; }

    /**
     * Extract a constraint name from a SHOW CREATE TABLE constraint line.
     * Returns 'PRIMARY' for PRIMARY KEY, or the backtick-quoted name for KEY/CONSTRAINT/etc.
     */
    private static function constraintName(string $line): string
    {
        // PRIMARY KEY
        if (preg_match('/^\s*PRIMARY\s+KEY/i', $line)) {
            return 'PRIMARY';
        }
        // KEY `name`, INDEX `name`, UNIQUE `name`, UNIQUE KEY `name`, FULLTEXT `name`, SPATIAL `name`
        if (preg_match('/^\s*(?:KEY|INDEX|UNIQUE(?:\s+KEY)?|FULLTEXT|SPATIAL)\s+`([^`]+)`/i', $line, $m)) {
            return $m[1];
        }
        // CONSTRAINT `name`
        if (preg_match('/^\s*CONSTRAINT\s+`([^`]+)`/i', $line, $m)) {
            return $m[1];
        }
        // Fallback: use the entire line as-is (should not normally happen)
        return trim(rtrim($line, ','));
    }

    /**
     * Normalize a constraint line for comparison purposes.
     * Strips USING BTREE/USING HASH since these are storage engine hints
     * that don't change the logical index definition.
     * MySQL may or may not include them in SHOW CREATE TABLE output.
     */
    private static function normalizeConstraint(string $line): string
    {
        return preg_replace('/\s+USING\s+(?:BTREE|HASH)\b/i', '', $line);
    }
}
