<?php
// src/Gui/DiffTableModelDelegate.php

namespace MySqlSchemaSync\Gui;

use Libui\Color;
use Libui\Generated\Enum\TableValueType;
use Libui\TableModel;
use Libui\TableModelDelegate;

/**
 * Table model delegate that renders diff results as a checkable grid.
 *
 * Columns:
 *   0 – Checkbox (bool, editable)
 *   1 – Type      (string: "新增表" / "变更表" / "删除表" / …)
 *   2 – Name      (string)
 *   3 – Risk      (string: SAFE / WARN / HIGH) — coloured via col 5
 *   4 – Details   (string)
 *   5 – RiskColor (Color, referenced by column 3 for text colour)
 */
class DiffTableModelDelegate extends TableModelDelegate
{
    /** @var list<array{checked:bool, type:string, name:string, risk:string, detail:string}> */
    private array $rows = [];

    /** @var callable(int):void|null fired when a row checkbox changes */
    private $onToggle = null;

    private ?TableModel $model = null;

    private static ?Color $riskColorSafe = null;
    private static ?Color $riskColorWarn = null;
    private static ?Color $riskColorHigh = null;

    public function setModel(TableModel $m): void
    {
        $this->model = $m;
    }

    public function loadDiffs(
        array $newTables,
        array $changedTables,
        array $removedTables,
        array $newIndexes,
        array $removedIndexes,
        array $newForeignKeys,
        array $removedForeignKeys,
        array $newTriggers,
        array $removedTriggers,
        array $newViews,
        array $removedViews,
        array $newProcedures,
        array $removedProcedures,
        array $newFunctions,
        array $removedFunctions,
        array $newEvents,
        array $removedEvents,
    ): void {
        $this->rows = [];

        $newTableNames = [];
        foreach ($newTables as $t) {
            $newTableNames[$t['name'] ?? ''] = true;
        }
        $removedTableNames = [];
        foreach ($removedTables as $t) {
            $removedTableNames[$t['name'] ?? ''] = true;
        }

        $sections = [
            ['type' => '新增表',       'items' => $newTables,         'detailFn' => fn($i) => ''],
            ['type' => '变更表',       'items' => $changedTables,    'detailFn' => fn($i) => $this->changesSummary($i['changes'] ?? [])],
            ['type' => '删除表',       'items' => $removedTables,    'detailFn' => fn($i) => ''],
            ['type' => '新增索引',     'items' => $newIndexes,       'detailFn' => fn($i) => 'on ' . ($i['table'] ?? ''), 'childOf' => fn($i) => $i['table'] ?? '', 'parentSet' => $newTableNames],
            ['type' => '删除索引',     'items' => $removedIndexes,   'detailFn' => fn($i) => 'on ' . ($i['table'] ?? ''), 'childOf' => fn($i) => $i['table'] ?? '', 'parentSet' => $removedTableNames],
            ['type' => '新增外键',     'items' => $newForeignKeys,   'detailFn' => fn($i) => 'on ' . ($i['table'] ?? ''), 'childOf' => fn($i) => $i['table'] ?? '', 'parentSet' => $newTableNames],
            ['type' => '删除外键',     'items' => $removedForeignKeys,'detailFn' => fn($i) => 'on ' . ($i['table'] ?? ''), 'childOf' => fn($i) => $i['table'] ?? '', 'parentSet' => $removedTableNames],
            ['type' => '新增触发器',   'items' => $newTriggers,      'detailFn' => fn($i) => 'on ' . ($i['table'] ?? ''), 'childOf' => fn($i) => $i['table'] ?? '', 'parentSet' => $newTableNames],
            ['type' => '删除触发器',   'items' => $removedTriggers,  'detailFn' => fn($i) => 'on ' . ($i['table'] ?? ''), 'childOf' => fn($i) => $i['table'] ?? '', 'parentSet' => $removedTableNames],
            ['type' => '新增视图',     'items' => $newViews,         'detailFn' => fn($i) => ''],
            ['type' => '删除视图',     'items' => $removedViews,     'detailFn' => fn($i) => ''],
            ['type' => '新增存储过程', 'items' => $newProcedures,    'detailFn' => fn($i) => ''],
            ['type' => '删除存储过程', 'items' => $removedProcedures,'detailFn' => fn($i) => ''],
            ['type' => '新增函数',     'items' => $newFunctions,     'detailFn' => fn($i) => ''],
            ['type' => '删除函数',     'items' => $removedFunctions, 'detailFn' => fn($i) => ''],
            ['type' => '新增事件',     'items' => $newEvents,        'detailFn' => fn($i) => ''],
            ['type' => '删除事件',     'items' => $removedEvents,    'detailFn' => fn($i) => ''],
        ];

        foreach ($sections as $sec) {
            foreach ($sec['items'] as $item) {
                if (isset($sec['childOf']) && isset($sec['parentSet'][$sec['childOf']($item)])) {
                    continue;
                }
                $this->rows[] = [
                    'checked' => true,
                    'type'    => $sec['type'],
                    'name'    => $item['name'] ?? '',
                    'risk'    => $item['risk'] ?? 'SAFE',
                    'detail'  => $sec['detailFn']($item),
                ];
            }
        }
    }

    public function selectedRows(): array
    {
        $sel = [];
        foreach ($this->rows as $r) {
            if ($r['checked']) {
                $sel[] = ['type' => $r['type'], 'name' => $r['name'], 'risk' => $r['risk']];
            }
        }
        return $sel;
    }

    public function totalCount(): int { return count($this->rows); }

    public function selectedCount(): int
    {
        $c = 0;
        foreach ($this->rows as $r) { if ($r['checked']) $c++; }
        return $c;
    }

    public function setAllChecked(bool $checked): void
    {
        foreach ($this->rows as $i => &$r) {
            $r['checked'] = $checked;
            if ($this->model) $this->model->rowChanged($i);
        }
    }

    public function setCheckedByRisk(string $risk, bool $checked): void
    {
        foreach ($this->rows as $i => &$r) {
            if ($r['risk'] === $risk) {
                $r['checked'] = $checked;
                if ($this->model) $this->model->rowChanged($i);
            }
        }
    }

    public function onToggle(callable $cb): void { $this->onToggle = $cb; }

    public function isRowChecked(int $row): bool
    {
        return isset($this->rows[$row]) && $this->rows[$row]['checked'];
    }

    public function toggleRowChecked(int $row): void
    {
        if (!isset($this->rows[$row])) return;
        $this->rows[$row]['checked'] = !$this->rows[$row]['checked'];
        if ($this->model) $this->model->rowChanged($row);
        if ($this->onToggle) ($this->onToggle)($row);
    }

    // --- TableModelDelegate interface ---

    public function numColumns(): int { return 6; }

    public function numRows(): int { return count($this->rows); }

    public function columnType(int $column): TableValueType
    {
        return match ($column) {
            0 => TableValueType::Int,
            5 => TableValueType::Color,
            default => TableValueType::String,
        };
    }

    public function cellValue(int $row, int $column): string|int|bool|Color|null
    {
        if (!isset($this->rows[$row])) return '';
        $d = $this->rows[$row];

        if ($column === 5) {
            return match ($d['risk']) {
                'SAFE' => self::colorSafe(),
                'WARN' => self::colorWarn(),
                'HIGH' => self::colorHigh(),
                default => null,
            };
        }

        return match ($column) {
            0 => $d['checked'],
            1 => $d['type'],
            2 => $d['name'],
            3 => $d['risk'],
            4 => $d['detail'],
            default => '',
        };
    }

    public function setCellValue(int $row, int $column, mixed $value): void
    {
        if ($column === 0 && isset($this->rows[$row])) {
            $this->rows[$row]['checked'] = (bool)$value;
            if ($this->onToggle) ($this->onToggle)($row);
        }
    }

    public function cellEditable(int $row, int $column): ?bool
    {
        return $column === 0 ? true : null;
    }

    // ── Colour helpers (lazily created) ──────────────────────

    private static function colorSafe(): Color
    {
        return self::$riskColorSafe ??= Color::rgba(0.2, 0.7, 0.2, 1.0);
    }

    private static function colorWarn(): Color
    {
        return self::$riskColorWarn ??= Color::rgba(0.85, 0.65, 0.0, 1.0);
    }

    private static function colorHigh(): Color
    {
        return self::$riskColorHigh ??= Color::rgba(0.85, 0.2, 0.2, 1.0);
    }

    private function changesSummary(array $changes): string
    {
        $parts = [];
        foreach ($changes as $c) {
            $parts[] = ($c['kind'] ?? '') . ':' . ($c['column'] ?? '');
        }
        return implode(', ', $parts);
    }
}
