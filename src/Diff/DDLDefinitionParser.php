<?php
// src/Diff/DDLDefinitionParser.php
//
// Parses MySQL SHOW CREATE TABLE output into structured definitions (Phase 2 of Navicat-style diff).
// Provides field-level comparison between two definitions — the foundation for CSDiffMatchPatch-style
// semantic DDL diff and precise column/constraint-level change detection.
//
// Architecture note (per Navicat reverse-engineering):
//   CSDiffMatchPatch in libcf.dylib operates on full DDL text, but the practical approach for
//   PHP is to parse DDL into structured parts and compare each field. This gives us identical
//   semantic precision while avoiding character-level diff parsing complexity.
//
// Reference: .omo/navicat_structure_sync_algorithm_recon.md §4

namespace MySqlSchemaSync\Diff;

class DDLDefinitionParser
{
    /**
     * Parse a column definition line from SHOW CREATE TABLE into structured components.
     *
     * Input:  `col_name` type(size) UNSIGNED ZEROFILL CHARACTER SET cs COLLATE collation
     *         NOT NULL DEFAULT value AUTO_INCREMENT COMMENT 'text' ON UPDATE ... 
     *         GENERATED ALWAYS AS (...) VIRTUAL|STORED
     *
     * Output: ['name' => 'col_name', 'type' => 'int(10) unsigned', 'nullable' => false,
     *          'default' => null, 'extra' => 'auto_increment', 'comment' => '',
     *          'charset' => null, 'collation' => null, 'on_update' => null,
     *          'generated' => null, 'raw' => 'full definition string']
     */
    public static function parseColumnDef(string $colName, string $colDef): array
    {
        $result = [
            'name'       => $colName,
            'raw'        => $colDef,
            'type'       => '',
            'nullable'   => true,
            'default'    => null,
            'extra'      => '',
            'comment'    => '',
            'charset'    => null,
            'collation'  => null,
            'on_update'  => null,
            'generated'  => null,
        ];

        $def = trim($colDef);

        // Strip leading backtick-quoted column name if present
        // The column name is already extracted; work with the rest after backtick+space
        if (preg_match('/^`[^`]+`\s+(.*)$/s', $def, $m)) {
            $def = trim($m[1]);
        }

        // --- 1. Strip trailing comma ---
        $def = rtrim($def, ',');

        // --- 2. COLLATE (must be before CHARSET because it's more specific) ---
        if (preg_match('/\bCOLLATE\s+(\S+)/i', $def, $m)) {
            $result['collation'] = $m[1];
            $def = preg_replace('/\s*COLLATE\s+\S+/i', '', $def);
        }

        // --- 3. CHARACTER SET ---
        if (preg_match('/\bCHARACTER\s+SET\s+(\S+)/i', $def, $m)) {
            $result['charset'] = $m[1];
            $def = preg_replace('/\s*CHARACTER\s+SET\s+\S+/i', '', $def);
        }

        // --- 4. GENERATED ALWAYS AS (...) [VIRTUAL|STORED] ---
        if (preg_match('/\bGENERATED\s+ALWAYS\s+AS\s+(.+?)\s*(?:VIRTUAL|STORED|PERSISTENT)?\s*(.*)$/is', $def, $m)) {
            $result['generated'] = trim($m[1]);
            $def = substr($def, 0, strpos($def, $m[0])) . ' ' . ($m[2] ?? '');
            $def = trim($def);
        }

        // --- 5. ON UPDATE (for timestamp/datetime columns) ---
        if (preg_match('/\bON\s+UPDATE\s+(\S+(?:\s+\S+)*?)(?:\s+(?:NOT\s+)?NULL|DEFAULT|AUTO_INCREMENT|COMMENT|$)/i', $def, $m)) {
            $result['on_update'] = $m[1];
            $def = preg_replace('/\s*ON\s+UPDATE\s+\S+(?:\s+\S+)*?\s*/i', ' ', $def);
        }

        // --- 6. NOT NULL | NULL ---
        if (preg_match('/\bNOT\s+NULL\b/i', $def)) {
            $result['nullable'] = false;
            $def = preg_replace('/\s*NOT\s+NULL\s*/i', ' ', $def);
        } elseif (preg_match('/\bNULL\b/i', $def)) {
            $result['nullable'] = true;
            $def = preg_replace('/\bNULL\b/i', ' ', $def);
        }

        // --- 7. DEFAULT ---
        // Handles: DEFAULT NULL, DEFAULT 'value', DEFAULT 123, DEFAULT CURRENT_TIMESTAMP,
        //          DEFAULT CURRENT_TIMESTAMP(6), DEFAULT (expr) (MySQL 8.0.13+)
        if (preg_match('/\bDEFAULT\s+(\S+(?:\s*\S+)*?)(?=\s*(?:AUTO_INCREMENT|COMMENT|ON\s+UPDATE|$))/i', $def, $m)) {
            $result['default'] = trim($m[1]);
            $def = preg_replace('/\s*DEFAULT\s+\S+(?:\s*\S+)*?\s*/i', ' ', $def);
        }

        // --- 8. AUTO_INCREMENT ---
        if (preg_match('/\bAUTO_INCREMENT\b/i', $def)) {
            $result['extra'] = 'auto_increment';
            $def = preg_replace('/\s*AUTO_INCREMENT\s*/i', ' ', $def);
        }

        // --- 9. COMMENT ---
        // Handles: COMMENT 'text'
        if (preg_match('/\bCOMMENT\s+(\'.*?(?:\'\'\')?\'|\S+)/is', $def, $m)) {
            $result['comment'] = $m[1];
            $def = preg_replace('/\s*COMMENT\s+\'.*?(?:\'\'\')?\'\s*/is', ' ', $def);
        }

        // --- 10. SRID (spatial) ---
        $def = preg_replace('/\bSRID\s+\d+\s*/i', '', $def);

        // --- 11. What remains is the data type + UNSIGNED/ZEROFILL ---
        $result['type'] = trim($def);

        return $result;
    }

    /**
     * Compare two column definitions and return field-level diffs.
     *
     * @return array List of ['field' => string, 'old' => mixed, 'new' => mixed]
     */
    public static function compareColumnDefs(array $oldDef, array $newDef): array
    {
        $diffs = [];
        $compareFields = ['type', 'nullable', 'default', 'extra', 'comment', 'charset', 'collation', 'on_update', 'generated'];

        foreach ($compareFields as $field) {
            $oldVal = $oldDef[$field] ?? null;
            $newVal = $newDef[$field] ?? null;

            // Normalize to string for comparison
            $oldStr = $oldVal === null ? '' : (is_bool($oldVal) ? ($oldVal ? '1' : '') : (string)$oldVal);
            $newStr = $newVal === null ? '' : (is_bool($newVal) ? ($newVal ? '1' : '') : (string)$newVal);

            // Normalize: NULL vs '' for default
            if ($field === 'default' && $oldStr === '' && $oldVal === null) $oldStr = '';
            if ($field === 'default' && $newStr === '' && $newVal === null) $newStr = '';

            if ($oldStr !== $newStr) {
                $diffs[] = [
                    'field' => $field,
                    'old'   => $oldVal,
                    'new'   => $newVal,
                ];
            }
        }

        return $diffs;
    }

    /**
     * Reconstruct a column definition DDL string from structured definition.
     * This produces canonical output that matches MySQL's SHOW CREATE TABLE format.
     */
    public static function formatColumnDef(array $def): string
    {
        $parts = [];

        // Column name
        $parts[] = "`{$def['name']}`";

        // Data type
        $parts[] = $def['type'];

        // Character set (only if non-standard and differs from table default)
        if (!empty($def['charset'])) {
            $parts[] = "CHARACTER SET {$def['charset']}";
        }

        // Collation
        if (!empty($def['collation'])) {
            $parts[] = "COLLATE {$def['collation']}";
        }

        // Generated
        if (!empty($def['generated'])) {
            $parts[] = 'GENERATED ALWAYS AS ' . $def['generated'] . ' STORED';
        }

        // Nullable
        $parts[] = $def['nullable'] ? 'NULL' : 'NOT NULL';

        // Default value
        if (array_key_exists('default', $def) && $def['default'] !== null) {
            $parts[] = 'DEFAULT ' . $def['default'];
        }

        // On update
        if (!empty($def['on_update'])) {
            $parts[] = 'ON UPDATE ' . $def['on_update'];
        }

        // Extra (AUTO_INCREMENT)
        if ($def['extra'] === 'auto_increment') {
            $parts[] = 'AUTO_INCREMENT';
        }

        // Comment
        if (!empty($def['comment'])) {
            $parts[] = 'COMMENT ' . $def['comment'];
        }

        return implode(' ', $parts);
    }

    /**
     * Parse a FULL SHOW CREATE TABLE output for a table and return structured representation.
     *
     * Output: [
     *   'columns'     => [colName => structuredDef],
     *   'indexes'     => [indexName => ['type' => 'PRIMARY|KEY|UNIQUE|FULLTEXT|SPATIAL', 'columns' => [colName => prefix]]],
     *   'foreign_keys' => [fkName => ['columns' => [localCol], 'ref_table' => 't', 'ref_columns' => [refCol], 'on_delete' => '...', 'on_update' => '...']],
     *   'table_options' => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
     *   'raw'         => 'original DDL text',
     * ]
     */
    public static function parseFullDDL(string $ddl): array
    {
        $result = [
            'columns'      => [],
            'indexes'      => [],
            'foreign_keys' => [],
            'table_options' => '',
            'raw'          => $ddl,
        ];

        // Extract body between the first ( and the last )
        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.+)\)\s*([^;]*)/is', trim($ddl), $m)) {
            return $result;
        }

        $tableName = $m[1];
        $body = $m[2];
        $result['table_options'] = trim($m[3]);

        // Split body into lines, handling parenthesized expressions
        $lines = self::splitDefinitionLines($body);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Column definition: starts with backtick
            if (preg_match('/^`([^`]+)`\s+(.+)/s', $line, $cm)) {
                $result['columns'][$cm[1]] = self::parseColumnDef($cm[1], $line);
                continue;
            }

            // PRIMARY KEY
            if (preg_match('/^\s*PRIMARY\s+KEY\s+(?:USING\s+\w+\s+)?\((.+?)\)/i', $line, $pm)) {
                $cols = self::parseIndexColumns($pm[1]);
                $result['indexes']['PRIMARY'] = [
                    'type'    => 'PRIMARY',
                    'columns' => $cols,
                ];
                continue;
            }

            // KEY/INDEX/UNIQUE/FULLTEXT/SPATIAL `name` (columns) [USING BTREE]
            if (preg_match('/^\s*(?:UNIQUE\s+(?:KEY\s+)?|KEY|INDEX|FULLTEXT|SPATIAL)\s+(?:`([^`]+)`|(\w+))\s+(?:USING\s+\w+\s+)?\((.+?)\)/i', $line, $im)) {
                $idxName = !empty($im[1]) ? $im[1] : (!empty($im[2]) ? $im[2] : '');
                $idxType = 'KEY';
                if (preg_match('/^\s*UNIQUE/i', $line)) $idxType = 'UNIQUE';
                elseif (preg_match('/^\s*FULLTEXT/i', $line)) $idxType = 'FULLTEXT';
                elseif (preg_match('/^\s*SPATIAL/i', $line)) $idxType = 'SPATIAL';

                $result['indexes'][$idxName] = [
                    'type'    => $idxType,
                    'columns' => self::parseIndexColumns($im[3]),
                ];
                continue;
            }

            // CONSTRAINT `name` FOREIGN KEY (`col`) REFERENCES `table` (`ref_col`) [ON DELETE ...] [ON UPDATE ...]
            if (preg_match('/^\s*CONSTRAINT\s+`([^`]+)`\s+FOREIGN\s+KEY\s+\((.+?)\)\s+REFERENCES\s+`([^`]+)`\s+\((.+?)\)\s*(.*)$/i', $line, $fm)) {
                $onDelete = '';
                $onUpdate = '';
                if (preg_match('/ON\s+DELETE\s+(\S+(?:\s+\S+)*?)(?:\s+ON\s+UPDATE|$)/i', $fm[5], $odm)) {
                    $onDelete = $odm[1];
                }
                if (preg_match('/ON\s+UPDATE\s+(\S+(?:\s+\S+)*?)$/i', $fm[5], $oum)) {
                    $onUpdate = $oum[1];
                }
                $result['foreign_keys'][$fm[1]] = [
                    'columns'     => self::parseIndexColumns($fm[2]),
                    'ref_table'   => $fm[3],
                    'ref_columns' => self::parseIndexColumns($fm[4]),
                    'on_delete'   => $onDelete,
                    'on_update'   => $onUpdate,
                ];
                continue;
            }

            // CONSTRAINT `name` CHECK (expr) — capture the full text for SQL generation
            if (preg_match('/^\s*CONSTRAINT\s+`([^`]+)`\s+(.*)$/i', $line, $chm)) {
                $result['indexes'][$chm[1]] = [
                    'type'       => 'CHECK',
                    'definition' => $chm[2],
                ];
                continue;
            }
        }

        return $result;
    }

    /**
     * Compare two full DDL structures and return categorized changes.
     *
     * @return array ['ADD_COLUMN' => [...], 'DROP_COLUMN' => [...], 'MODIFY_COLUMN' => [...],
     *                'ADD_INDEX' => [...], 'DROP_INDEX' => [...], 'MODIFY_INDEX' => [...],
     *                'ADD_FOREIGN_KEY' => [...], 'DROP_FOREIGN_KEY' => [...],
     *                'MODIFY_TABLE_OPTIONS' => null|string]
     */
    public static function compareDDL(array $srcDDL, array $tgtDDL): array
    {
        $changes = [];

        // --- Column comparison ---
        $srcCols = $srcDDL['columns'] ?? [];
        $tgtCols = $tgtDDL['columns'] ?? [];

        // ADD: in target (new) not in source (old)
        foreach ($tgtCols as $name => $def) {
            if (!isset($srcCols[$name])) {
                $changes['ADD_COLUMN'][$name] = $def;
            } else {
                $diffs = self::compareColumnDefs($srcCols[$name], $def);
                if (!empty($diffs)) {
                    $changes['MODIFY_COLUMN'][$name] = [
                        'new_def' => $def,
                        'diffs'   => $diffs,
                    ];
                }
            }
        }
        // DROP: in source (old) not in target (new)
        foreach ($srcCols as $name => $def) {
            if (!isset($tgtCols[$name])) {
                $changes['DROP_COLUMN'][$name] = $def;
            }
        }

        // --- Index comparison ---
        $srcIdx = $srcDDL['indexes'] ?? [];
        $tgtIdx = $tgtDDL['indexes'] ?? [];

        $idxKeys = array_unique(array_merge(array_keys($srcIdx), array_keys($tgtIdx)));
        foreach ($idxKeys as $name) {
            $hasSrc = isset($srcIdx[$name]);
            $hasTgt = isset($tgtIdx[$name]);
            if ($hasSrc && !$hasTgt) {
                $changes['DROP_INDEX'][$name] = $srcIdx[$name];
            } elseif (!$hasSrc && $hasTgt) {
                $changes['ADD_INDEX'][$name] = $tgtIdx[$name];
            } elseif (self::indexDefToString($srcIdx[$name]) !== self::indexDefToString($tgtIdx[$name])) {
                $changes['MODIFY_INDEX'][$name] = [
                    'old' => $srcIdx[$name],
                    'new' => $tgtIdx[$name],
                ];
            }
        }

        // --- Foreign key comparison ---
        $srcFK = $srcDDL['foreign_keys'] ?? [];
        $tgtFK = $tgtDDL['foreign_keys'] ?? [];

        $fkKeys = array_unique(array_merge(array_keys($srcFK), array_keys($tgtFK)));
        foreach ($fkKeys as $name) {
            $hasSrc = isset($srcFK[$name]);
            $hasTgt = isset($tgtFK[$name]);
            if ($hasSrc && !$hasTgt) {
                $changes['DROP_FOREIGN_KEY'][$name] = $srcFK[$name];
            } elseif (!$hasSrc && $hasTgt) {
                $changes['ADD_FOREIGN_KEY'][$name] = $tgtFK[$name];
            } elseif (self::fkDefToString($srcFK[$name]) !== self::fkDefToString($tgtFK[$name])) {
                $changes['DROP_FOREIGN_KEY'][$name] = $srcFK[$name];
                $changes['ADD_FOREIGN_KEY'][$name] = $tgtFK[$name];
            }
        }

        // --- Table options comparison ---
        if (self::normalizeTableOptions($srcDDL['table_options'] ?? '') !==
            self::normalizeTableOptions($tgtDDL['table_options'] ?? '')) {
            $changes['MODIFY_TABLE_OPTIONS'] = $tgtDDL['table_options'] ?? '';
        }

        return $changes;
    }

    /**
     * Format an index definition to a string for comparison
     */
    private static function indexDefToString(array $idx): string
    {
        $type = $idx['type'] ?? 'KEY';
        if ($type === 'CHECK') {
            return 'CHECK:' . ($idx['definition'] ?? '');
        }
        $cols = '';
        if (isset($idx['columns'])) {
            $colParts = [];
            foreach ($idx['columns'] as $c => $p) {
                $colParts[] = $p ? "{$c}({$p})" : $c;
            }
            $cols = implode(',', $colParts);
        }
        return "{$type}:{$cols}";
    }

    /**
     * Format a foreign key definition to a string for comparison
     */
    private static function fkDefToString(array $fk): string
    {
        $localCols = implode(',', array_keys($fk['columns'] ?? []));
        $refCols = implode(',', array_keys($fk['ref_columns'] ?? []));
        $onDelete = $fk['on_delete'] ?? '';
        $onUpdate = $fk['on_update'] ?? '';
        return "{$localCols}->{$fk['ref_table']}({$refCols}) DEL:{$onDelete} UPD:{$onUpdate}";
    }

    /**
     * Normalize table options string for comparison
     */
    private static function normalizeTableOptions(string $options): string
    {
        // Remove AUTO_INCREMENT (changes frequently, not a structural difference)
        $options = preg_replace('/\bAUTO_INCREMENT\s*=\s*\d+\b/i', '', $options);
        // Normalize whitespace
        return trim(preg_replace('/\s+/', ' ', $options));
    }

    /**
     * Split the body of a CREATE TABLE statement into individual definition lines.
     * Handles nested parentheses (e.g., DEFAULT (expr), CHECK constraints).
     */
    private static function splitDefinitionLines(string $body): array
    {
        $lines = [];
        $current = '';
        $depth = 0;
        $len = strlen($body);

        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
            } elseif ($ch === ')') {
                $depth--;
                $current .= $ch;
            } elseif ($ch === ',' && $depth === 0) {
                $lines[] = trim($current);
                $current = '';
            } else {
                $current .= $ch;
            }
        }
        if (trim($current) !== '') {
            $lines[] = trim($current);
        }

        return $lines;
    }

    /**
     * Parse an index column list "(col1, col2 ASC, col3 (prefix))" into
     * [colName => prefixLength] or [colName => sortOrder].
     */
    private static function parseIndexColumns(string $colList): array
    {
        $cols = [];
        // Split by comma, respecting nested parens
        $items = self::splitDefinitionLines($colList);
        foreach ($items as $item) {
            $item = trim($item);
            $colName = $item;
            $prefix = null;
            $sortOrder = null;

            // Extract ASC/DESC
            if (preg_match('/^(.*?)\s+(ASC|DESC)$/i', $colName, $m)) {
                $colName = $m[1];
                $sortOrder = strtoupper($m[2]);
            }

            // Extract prefix length: `col` (N) or col (N)
            if (preg_match('/^`?([^`(]+)`?\s*\((\d+)\)/', $colName, $m)) {
                $colName = $m[1];
                $prefix = (int)$m[2];
            }

            // Remove backticks
            $colName = trim($colName, '`');
            $cols[$colName] = $prefix ?: ($sortOrder ?: null);
        }
        return $cols;
    }

    /**
     * Build a constraint SQL line suitable for inclusion in a SHOW CREATE TABLE body,
     * matching the format that StructSyncAdapter expects.
     */
    public static function buildConstraintLine(string $table, array $idxDef, string $indexName): string
    {
        $type = $idxDef['type'] ?? 'KEY';
        $colParts = [];
        foreach (($idxDef['columns'] ?? []) as $col => $prefix) {
            $colParts[] = $prefix ? "`{$col}` ({$prefix})" : "`{$col}`";
        }
        $colStr = implode(', ', $colParts);

        switch ($type) {
            case 'PRIMARY':
                return "  PRIMARY KEY ({$colStr})";
            case 'UNIQUE':
                return "  UNIQUE KEY `{$indexName}` ({$colStr})";
            case 'FULLTEXT':
                return "  FULLTEXT KEY `{$indexName}` ({$colStr})";
            case 'SPATIAL':
                return "  SPATIAL KEY `{$indexName}` ({$colStr})";
            case 'CHECK':
                return '  ' . ($idxDef['definition'] ?? '');
            default:
                return "  KEY `{$indexName}` ({$colStr})";
        }
    }

    /**
     * Build a foreign key constraint SQL line.
     */
    public static function buildForeignKeyLine(string $table, string $fkName, array $fkDef): string
    {
        $localCols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($fkDef['columns'] ?? [])));
        $refCols = implode(', ', array_map(fn($c) => "`{$c}`", array_keys($fkDef['ref_columns'] ?? [])));
        $sql = "  CONSTRAINT `{$fkName}` FOREIGN KEY ({$localCols}) REFERENCES `{$fkDef['ref_table']}` ({$refCols})";
        if (!empty($fkDef['on_delete'])) {
            $sql .= " ON DELETE {$fkDef['on_delete']}";
        }
        if (!empty($fkDef['on_update'])) {
            $sql .= " ON UPDATE {$fkDef['on_update']}";
        }
        return $sql;
    }

    /**
     * Check whether a column definition actually changed (semantic comparison, 
     * handling MySQL's default expression variations).
     */
    public static function columnDefEquals(array $a, array $b): bool
    {
        return empty(self::compareColumnDefs($a, $b));
    }
}
