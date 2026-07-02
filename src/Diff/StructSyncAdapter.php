<?php
namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;
use Yangweijie\ThinkOrmAsync\AsyncContext;

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

    public function cancel(): void { $this->cancelled = true; }
    public function isCancelled(): bool { return $this->cancelled; }

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
     * 核心比对逻辑：对比两张库的结构，生成差异 SQL
     * 逻辑复刻自 DDZH\MysqlStructSync::baseDiff() 和 advanceDiff()
     */
    private function buildDiffSql(): array
    {
        // $this->srcStruct = srcCombo（用户选的「源库」= 新结构）
        // $this->tgtStruct = tgtCombo（用户选的「目标库」= 要同步的旧结构）
        // 方向：让旧结构匹配新结构
        $src = $this->tgtStruct;  // 旧结构（要被修改的）
        $tgt = $this->srcStruct;  // 新结构（参考基准）

        $res = [];

        // 1. 新增表（新结构有、旧结构没有 → 添加）
        $res['ADD_TABLE'] = array_diff($tgt['tables'] ?? [], $src['tables'] ?? []);
        // 2. 删除表（旧结构有、新结构没有 → 删除）
        $res['DROP_TABLE'] = array_diff($src['tables'] ?? [], $tgt['tables'] ?? []);

        $srcCols = $src['columns'] ?? [];
        $tgtCols = $tgt['columns'] ?? [];

        // 3. 查找公共表，对比列差异
        $commonTables = array_intersect($src['tables'] ?? [], $tgt['tables'] ?? []);

        foreach ($commonTables as $table) {
            $srcTableCols = $srcCols[$table] ?? [];
            $tgtTableCols = $tgtCols[$table] ?? [];

            // 目标有、源没有 → ADD
            foreach ($tgtTableCols as $field => $def) {
                if (!isset($srcTableCols[$field])) {
                    $res['ADD_FIELD'][$table][$field] = $def;
                } elseif ($srcTableCols[$field] !== $def) {
                    $res['MODIFY_FIELD'][$table][$field] = $def;
                }
            }

            // 源有、目标没有 → DROP
            foreach ($srcTableCols as $field => $def) {
                if (!isset($tgtTableCols[$field])) {
                    $res['DROP_FIELD'][$table][$field] = $def;
                }
            }
        }

        // 4. 约束差异
        // 排除新增/删除表的约束，它们已包含在 CREATE/DROP TABLE 中
        $addTableKeys = array_flip($res['ADD_TABLE'] ?? []);
        $dropTableKeys = array_flip($res['DROP_TABLE'] ?? []);
        $srcConstraints = array_diff_key($src['constraints'] ?? [], $dropTableKeys);
        $tgtConstraints = array_diff_key($tgt['constraints'] ?? [], $addTableKeys);

        $res['DROP_CONSTRAINT'] = self::arrayDiffAssocRecursive(
            $srcConstraints, $tgt['constraints'] ?? []
        );
        $res['ADD_CONSTRAINT'] = self::arrayDiffAssocRecursive(
            $tgtConstraints, $src['constraints'] ?? []
        );

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
     * 获取高级对象差异（VIEW/TRIGGER 等）
     * 如果有预获取数据则直接使用，否则查询数据库（think-orm-async 并行化）
     */
    private function appendAdvanceDiffSql(array &$diffSql): void
    {
        if ($this->prefetchedAdvanceSrc !== null && $this->prefetchedAdvanceTgt !== null) {
            $this->appendAdvanceFromPrefetched($diffSql);
            return;
        }

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
        foreach (['VIEW', 'TRIGGER', 'EVENT', 'FUNCTION', 'PROCEDURE'] as $type) {
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
                $d->changedTables[$table]['name'] = $table;
                $d->changedTables[$table]['risk'] = DiffResult::RISK_WARN;
                $d->changedTables[$table]['changes'][] = ['kind' => 'MODIFY_COLUMN', 'risk' => DiffResult::RISK_WARN, 'column' => $col, 'detail' => $def];
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

    public function getDiffSql(): array { return $this->diffSql; }
}
