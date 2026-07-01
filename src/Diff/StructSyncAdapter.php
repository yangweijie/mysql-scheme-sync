<?php
namespace MySqlSchemaSync\Diff;

use MySqlSchemaSync\Config\Connection;

class StructSyncAdapter
{
    private \linge\MysqlStructSync $sync;
    private array $diffSql = [];
    private bool $cancelled = false;
    public int $currentStep = 0;
    public int $totalSteps = 0;
    private ?\Closure $onProgress = null;

    public function cancel(): void { $this->cancelled = true; }
    public function isCancelled(): bool { return $this->cancelled; }

    public function __construct(Connection $source, Connection $target)
    {
        $this->sync = new \linge\MysqlStructSync(
            ['host' => $target->host, 'username' => $target->user, 'passwd' => $target->password, 'dbname' => $target->database, 'port' => $target->port],
            ['host' => $source->host, 'username' => $source->user, 'passwd' => $source->password, 'dbname' => $source->database, 'port' => $source->port]
        );
        $this->sync->on_progress = function () {
            $this->currentStep++;
            if ($this->onProgress) ($this->onProgress)();
        };
    }

    public function setOnProgress(\Closure $cb): void { $this->onProgress = $cb; }
    public function setTotalSteps(int $n): void { $this->totalSteps = $n; }

    public function compare(array $excludePatterns = []): DiffResult
    {
        $d = new DiffResult();
        if ($this->cancelled) { $d->error = '比对已取消'; return $d; }

        $this->sync->setExcludePatterns($excludePatterns);

        $lastError = '';
        set_error_handler(function ($errno, $errstr) use (&$lastError) { $lastError = $errstr; return true; }, E_WARNING);

        try { $this->sync->baseDiff(); } catch (\Throwable $e) { $d->error = $e->getMessage(); restore_error_handler(); return $d; }
        try { $this->sync->advanceDiff(); } catch (\Throwable $e) { $d->error = $e->getMessage(); restore_error_handler(); return $d; }

        restore_error_handler();
        if (!empty($lastError)) { $d->error = $lastError; return $d; }

        $this->diffSql = $this->sync->getDiffSql();
        return $this->buildDiffResult();
    }

    private function buildDiffResult(): DiffResult
    {
        $d = new DiffResult();
        foreach ($this->diffSql['ADD_TABLE'] ?? [] as $sql) {
            if (preg_match('/CREATE\s+TABLE\s+`?(\w+)`?/i', $sql, $m)) $d->newTables[] = ['name' => $m[1], 'risk' => DiffResult::RISK_SAFE, 'createSql' => $sql];
        }
        foreach ($this->diffSql['DROP_TABLE'] ?? [] as $sql) {
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) $d->removedTables[] = ['name' => $m[1], 'risk' => DiffResult::RISK_HIGH];
        }
        foreach ($this->diffSql['MODIFY_FIELD'] ?? [] as $table => $fields) {
            $changes = [];
            foreach ($fields as $col => $def) $changes[] = ['kind' => 'MODIFY_COLUMN', 'risk' => DiffResult::RISK_WARN, 'column' => $col, 'detail' => $def];
            if ($changes) $d->changedTables[] = ['name' => $table, 'risk' => DiffResult::RISK_WARN, 'changes' => $changes];
        }
        foreach ($this->diffSql['ADD_FIELD'] ?? [] as $table => $fields) {
            $changes = [];
            foreach ($fields as $col => $def) $changes[] = ['kind' => 'ADD_COLUMN', 'risk' => DiffResult::RISK_SAFE, 'column' => $col, 'detail' => $def];
            if ($changes) {
                $merged = false;
                foreach ($d->changedTables as &$ct) { if ($ct['name'] === $table) { $ct['changes'] = array_merge($ct['changes'], $changes); $merged = true; break; } }
                unset($ct);
                if (!$merged) $d->changedTables[] = ['name' => $table, 'risk' => DiffResult::RISK_SAFE, 'changes' => $changes];
            }
        }
        foreach ($this->diffSql['DROP_FIELD'] ?? [] as $table => $fields) {
            $changes = [];
            foreach ($fields as $col => $def) $changes[] = ['kind' => 'DROP_COLUMN', 'risk' => DiffResult::RISK_HIGH, 'column' => $col];
            if ($changes) {
                $merged = false;
                foreach ($d->changedTables as &$ct) { if ($ct['name'] === $table) { $ct['changes'] = array_merge($ct['changes'], $changes); $merged = true; break; } }
                unset($ct);
                if (!$merged) $d->changedTables[] = ['name' => $table, 'risk' => DiffResult::RISK_HIGH, 'changes' => $changes];
            }
        }
        foreach ($this->diffSql['ADD_CONSTRAINT'] ?? [] as $table => $constraints) {
            if (!is_array($constraints)) continue;
            foreach ($constraints as $c) { if (preg_match('/`(\w+)`/', $c, $m)) $d->newIndexes[] = ['name' => $m[1], 'table' => $table, 'risk' => DiffResult::RISK_SAFE]; }
        }
        foreach ($this->diffSql['DROP_CONSTRAINT'] ?? [] as $table => $constraints) {
            if (!is_array($constraints)) continue;
            foreach ($constraints as $c) { if (preg_match('/`(\w+)`/', $c, $m)) $d->removedIndexes[] = ['name' => $m[1], 'table' => $table, 'risk' => DiffResult::RISK_HIGH]; }
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

    public function getDiffSql(): array { return $this->diffSql; }
}
