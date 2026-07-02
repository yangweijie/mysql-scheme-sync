<?php
namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;

class StructSyncAdapter
{
    private Connection $source;
    private Connection $target;
    private ?array $srcStruct = null;
    private ?array $tgtStruct = null;
    private array $diffSql = [];
    private bool $cancelled = false;
    private ?\Closure $onProgress = null;

    public function cancel(): void { $this->cancelled = true; }
    public function isCancelled(): bool { return $this->cancelled; }

    public function __construct(Connection $source, Connection $target)
    {
        // 保存连接配置，供 fetchStructures 使用
        $this->source = $source;
        $this->target = $target;
    }

    public function setOnProgress(\Closure $cb): void { $this->onProgress = $cb; }
    public function setOnPhase(\Closure $cb): void { /* not supported */ }

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
        $this->srcStruct = $fetcher->fetchStructure($this->source);
        $this->tgtStruct = $fetcher->fetchStructure($this->target);
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
        $src = $this->srcStruct;  // self (目标库，要被修改的)
        $tgt = $this->tgtStruct;  // refer (源库，参考结构)

        $res = [];

        // 1. 新增表（在源库有，目标库没有）
        $res['ADD_TABLE'] = array_diff($tgt['tables'] ?? [], $src['tables'] ?? []);
        // 2. 删除表（在目标库有，源库没有）
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
        $res['DROP_CONSTRAINT'] = self::arrayDiffAssocRecursive(
            $src['constraints'] ?? [], $tgt['constraints'] ?? []
        );
        $res['ADD_CONSTRAINT'] = self::arrayDiffAssocRecursive(
            $tgt['constraints'] ?? [], $src['constraints'] ?? []
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
     * 需要与数据库交互获取 SHOW CREATE，这里简化：跳过或串行获取
     */
    private function appendAdvanceDiffSql(array &$diffSql): void
    {
        // 高级对象暂时串行获取（这些对象数量少，影响不大）
        $advance = [
            'VIEW'      => ["SELECT TABLE_NAME as Name FROM information_schema.VIEWS WHERE TABLE_SCHEMA='#'", 'Create View'],
            'TRIGGER'   => ["SELECT TRIGGER_NAME as Name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='#'", 'SQL Original Statement'],
            'EVENT'     => ["SELECT EVENT_NAME  as Name FROM information_schema.EVENTS WHERE EVENT_SCHEMA='#'", 'Create Event'],
            'FUNCTION'  => ["SHOW FUNCTION STATUS  WHERE Db='#'", 'Create Function'],
            'PROCEDURE' => ["SHOW PROCEDURE STATUS WHERE Db='#'", 'Create Procedure'],
        ];

        $srcMysqli = $this->createMysqli($this->source);
        $tgtMysqli = $this->createMysqli($this->target);

        foreach ($advance as $type => $listSql) {
            $srcObjs = $this->fetchAdvanceObjects($srcMysqli, $this->source->database, $type, $listSql);
            $tgtObjs = $this->fetchAdvanceObjects($tgtMysqli, $this->target->database, $type, $listSql);

            $diffSql['ADD_' . $type] = self::arrayDiffAssocRecursive($tgtObjs, $srcObjs);
            $diffSql['DROP_' . $type] = self::arrayDiffAssocRecursive($srcObjs, $tgtObjs);
        }

        $srcMysqli->close();
        $tgtMysqli->close();
    }

    private function fetchAdvanceObjects(\mysqli $conn, string $dbName, string $type, array $listSql): array
    {
        $sql = str_replace('#', $dbName, $listSql[0]);
        $result = $conn->query($sql);
        if (!$result) return [];

        $objects = [];
        while ($row = $result->fetch_assoc()) {
            $name = $row['Name'];
            $showCreate = $conn->query("SHOW CREATE {$type} `{$name}`");
            if ($showCreate) {
                $createSql = $showCreate->fetch_assoc()[$listSql[1]] ?? '';
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
        foreach ($this->diffSql['MODIFY_FIELD'] ?? [] as $table => $fields) {
            $changes = [];
            foreach ($fields as $col => $def)
                $changes[] = ['kind' => 'MODIFY_COLUMN', 'risk' => DiffResult::RISK_WARN, 'column' => $col, 'detail' => $def];
            if ($changes) $d->changedTables[] = ['name' => $table, 'risk' => DiffResult::RISK_WARN, 'changes' => $changes];
        }
        foreach ($this->diffSql['ADD_FIELD'] ?? [] as $table => $fields) {
            $changes = [];
            foreach ($fields as $col => $def)
                $changes[] = ['kind' => 'ADD_COLUMN', 'risk' => DiffResult::RISK_SAFE, 'column' => $col, 'detail' => $def];
            if ($changes) {
                $merged = false;
                foreach ($d->changedTables as &$ct) {
                    if ($ct['name'] === $table) { $ct['changes'] = array_merge($ct['changes'], $changes); $merged = true; break; }
                }
                unset($ct);
                if (!$merged) $d->changedTables[] = ['name' => $table, 'risk' => DiffResult::RISK_SAFE, 'changes' => $changes];
            }
        }
        foreach ($this->diffSql['DROP_FIELD'] ?? [] as $table => $fields) {
            $changes = [];
            foreach ($fields as $col => $def)
                $changes[] = ['kind' => 'DROP_COLUMN', 'risk' => DiffResult::RISK_HIGH, 'column' => $col];
            if ($changes) {
                $merged = false;
                foreach ($d->changedTables as &$ct) {
                    if ($ct['name'] === $table) { $ct['changes'] = array_merge($ct['changes'], $changes); $merged = true; break; }
                }
                unset($ct);
                if (!$merged) $d->changedTables[] = ['name' => $table, 'risk' => DiffResult::RISK_HIGH, 'changes' => $changes];
            }
        }
        foreach (['ADD_VIEW'=>'newViews','DROP_VIEW'=>'removedViews','ADD_PROCEDURE'=>'newProcedures','DROP_PROCEDURE'=>'removedProcedures','ADD_FUNCTION'=>'newFunctions','DROP_FUNCTION'=>'removedFunctions','ADD_TRIGGER'=>'newTriggers','DROP_TRIGGER'=>'removedTriggers','ADD_EVENT'=>'newEvents','DROP_EVENT'=>'removedEvents'] as $type => $prop) {
            $risk = str_starts_with($type, 'ADD') ? DiffResult::RISK_SAFE : DiffResult::RISK_HIGH;
            foreach ($this->diffSql[$type] ?? [] as $sql) {
                $name = preg_match('/`(\w+)`/', $sql, $m) ? $m[1] : null;
                if ($name) $d->{$prop}[] = ['name' => $name, 'risk' => $risk];
            }
        }
        return $d;
    }

    private function createMysqli(Connection $dbConfig): \mysqli
    {
        $conn = new \mysqli($dbConfig->host, $dbConfig->user, $dbConfig->password, $dbConfig->database, $dbConfig->port);
        if ($conn->connect_error) throw new \RuntimeException("DB connection failed: " . $conn->connect_error);
        $conn->set_charset('utf8mb4');
        return $conn;
    }

    public function getDiffSql(): array { return $this->diffSql; }
}
