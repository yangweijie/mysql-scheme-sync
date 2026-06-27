<?php
// src/Diff/DiffResult.php

namespace MySqlSchemaSync\Diff;

class DiffResult
{
    public const RISK_SAFE = 'SAFE';
    public const RISK_WARN = 'WARN';
    public const RISK_HIGH = 'HIGH';

    /** @var array<int, array> */
    public array $newTables = [];
    /** @var array<int, array> */
    public array $removedTables = [];
    /** @var array<int, array> */
    public array $changedTables = [];
    /** @var array<int, array> */
    public array $newIndexes = [];
    /** @var array<int, array> */
    public array $removedIndexes = [];
    /** @var array<int, array> */
    public array $newForeignKeys = [];
    /** @var array<int, array> */
    public array $removedForeignKeys = [];
    /** @var array<int, array> */
    public array $newTriggers = [];
    /** @var array<int, array> */
    public array $removedTriggers = [];
    /** @var array<int, array> */
    public array $newViews = [];
    /** @var array<int, array> */
    public array $removedViews = [];
    /** @var array<int, array> */
    public array $newProcedures = [];
    /** @var array<int, array> */
    public array $removedProcedures = [];
    /** @var array<int, array> */
    public array $newFunctions = [];
    /** @var array<int, array> */
    public array $removedFunctions = [];
    /** @var array<int, array> */
    public array $newEvents = [];
    /** @var array<int, array> */
    public array $removedEvents = [];

    public ?string $error = null;

    public function total(): int
    {
        return count($this->newTables) + count($this->removedTables) + count($this->changedTables)
            + count($this->newIndexes) + count($this->removedIndexes)
            + count($this->newForeignKeys) + count($this->removedForeignKeys)
            + count($this->newTriggers) + count($this->removedTriggers)
            + count($this->newViews) + count($this->removedViews)
            + count($this->newProcedures) + count($this->removedProcedures)
            + count($this->newFunctions) + count($this->removedFunctions)
            + count($this->newEvents) + count($this->removedEvents);
    }

    public static function compare(Schema $source, Schema $target, array $excludePatterns = []): self
    {
        $d = new self();

        if ($source->error) { $d->error = "Source: {$source->error}"; return $d; }
        if ($target->error) { $d->error = "Target: {$target->error}"; return $d; }

        $filter = function (string $name) use ($excludePatterns): bool {
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $name)) return true;
            }
            return false;
        };

        // Tables
        foreach ($source->tables as $name => $table) {
            if ($filter($name)) continue;
            if (!isset($target->tables[$name])) {
                $d->newTables[] = ['name' => $name, 'risk' => self::RISK_SAFE];
                continue;
            }
            $changes = [];
            $tgt = $target->tables[$name];
            foreach ($table['columns'] as $cname => $col) {
                if (!isset($tgt['columns'][$cname])) {
                    $changes[] = ['kind' => 'ADD_COLUMN', 'risk' => self::RISK_SAFE, 'column' => $cname, 'detail' => $col];
                } else {
                    $tcol = $tgt['columns'][$cname];
                    $diff = self::columnDiff($col, $tcol);
                    if ($diff) {
                        $diff['column'] = $cname;
                        $changes[] = $diff;
                    }
                }
            }
            foreach ($tgt['columns'] as $cname => $tcol) {
                if (!isset($table['columns'][$cname])) {
                    $changes[] = ['kind' => 'DROP_COLUMN', 'risk' => self::RISK_HIGH, 'column' => $cname];
                }
            }
            if ($changes) {
                $d->changedTables[] = ['name' => $name, 'risk' => self::maxRisk($changes), 'changes' => $changes];
            }
        }
        foreach ($target->tables as $name => $table) {
            if ($filter($name)) continue;
            if (!isset($source->tables[$name])) {
                $d->removedTables[] = ['name' => $name, 'risk' => self::RISK_HIGH];
            }
        }

        // Indexes
        foreach ($source->indexes as $key => $idx) {
            if ($filter($idx['table'])) continue;
            if (!isset($target->indexes[$key])) {
                $d->newIndexes[] = ['name' => $idx['name'], 'table' => $idx['table'], 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->indexes as $key => $idx) {
            if ($filter($idx['table'])) continue;
            if (!isset($source->indexes[$key])) {
                $d->removedIndexes[] = ['name' => $idx['name'], 'table' => $idx['table'], 'risk' => self::RISK_HIGH];
            }
        }

        // Foreign keys
        foreach ($source->foreignKeys as $key => $fk) {
            if ($filter($fk['table'])) continue;
            if (!isset($target->foreignKeys[$key])) {
                $d->newForeignKeys[] = ['name' => $fk['name'], 'table' => $fk['table'], 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->foreignKeys as $key => $fk) {
            if ($filter($fk['table'])) continue;
            if (!isset($source->foreignKeys[$key])) {
                $d->removedForeignKeys[] = ['name' => $fk['name'], 'table' => $fk['table'], 'risk' => self::RISK_HIGH];
            }
        }

        // Triggers
        foreach ($source->triggers as $name => $tr) {
            if ($filter($tr['table'])) continue;
            if (!isset($target->triggers[$name])) {
                $d->newTriggers[] = ['name' => $name, 'table' => $tr['table'], 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->triggers as $name => $tr) {
            if ($filter($tr['table'])) continue;
            if (!isset($source->triggers[$name])) {
                $d->removedTriggers[] = ['name' => $name, 'table' => $tr['table'], 'risk' => self::RISK_HIGH];
            }
        }

        // Views / SP / Functions / Events
        foreach ($source->views as $name => $v) {
            if (!isset($target->views[$name])) {
                $d->newViews[] = ['name' => $name, 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->views as $name => $v) {
            if (!isset($source->views[$name])) {
                $d->removedViews[] = ['name' => $name, 'risk' => self::RISK_HIGH];
            }
        }

        foreach ($source->procedures as $name => $p) {
            if (!isset($target->procedures[$name])) {
                $d->newProcedures[] = ['name' => $name, 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->procedures as $name => $p) {
            if (!isset($source->procedures[$name])) {
                $d->removedProcedures[] = ['name' => $name, 'risk' => self::RISK_HIGH];
            }
        }

        foreach ($source->functions as $name => $f) {
            if (!isset($target->functions[$name])) {
                $d->newFunctions[] = ['name' => $name, 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->functions as $name => $f) {
            if (!isset($source->functions[$name])) {
                $d->removedFunctions[] = ['name' => $name, 'risk' => self::RISK_HIGH];
            }
        }

        foreach ($source->events as $name => $e) {
            if (!isset($target->events[$name])) {
                $d->newEvents[] = ['name' => $name, 'risk' => self::RISK_SAFE];
            }
        }
        foreach ($target->events as $name => $e) {
            if (!isset($source->events[$name])) {
                $d->removedEvents[] = ['name' => $name, 'risk' => self::RISK_HIGH];
            }
        }

        return $d;
    }

    private static function columnDiff(array $a, array $b): ?array
    {
        $attrs = ['columnType', 'nullable', 'default', 'collation', 'extra', 'comment'];
        $changes = [];
        foreach ($attrs as $attr) {
            if (($a[$attr] ?? null) != ($b[$attr] ?? null)) {
                $changes[$attr] = ['from' => $b[$attr] ?? null, 'to' => $a[$attr] ?? null];
            }
        }
        if (!$changes) return null;

        $risk = self::RISK_WARN;
        if (isset($changes['nullable']) && $changes['nullable']['to'] === false) {
            $risk = self::RISK_HIGH;
        }
        if (isset($changes['columnType']) && self::isTypeShrink($changes['columnType']['from'], $changes['columnType']['to'])) {
            $risk = self::RISK_HIGH;
        }

        return ['kind' => 'MODIFY_COLUMN', 'risk' => $risk, 'changes' => $changes];
    }

    private static function isTypeShrink(string $from, string $to): bool
    {
        preg_match('/\((\d+)\)/', $from, $fm);
        preg_match('/\((\d+)\)/', $to, $tm);
        if ($fm && $tm && (int)$tm[1] < (int)$fm[1]) return true;
        return false;
    }

    private static function maxRisk(array $changes): string
    {
        $risk = self::RISK_SAFE;
        foreach ($changes as $c) {
            if ($c['risk'] === self::RISK_HIGH) return self::RISK_HIGH;
            if ($c['risk'] === self::RISK_WARN) $risk = self::RISK_WARN;
        }
        return $risk;
    }
}
