<?php
// src/Diff/AsyncCompareRunner.php

namespace MySqlSchemaSync\Diff;

use mysqli_result;
use MySqlSchemaSync\Config\Connection;
use Libui\Loop;
use MySqlSchemaSync\Diff\StructSyncAdapter;

/**
 * Non-blocking compare runner using synchronous queries + Loop::delay.
 *
 * Previously used MYSQLI_ASYNC state machine; reverted to sync queries
 * with a single Loop::delay(1) deferral to unblock UI construction/event processing.
 *
 * Flow:
 *   start() → Loop::delay(1) → runSync() → connect + fetch tables + fetch advanced
 *   → parse structures → compare → fire onComplete
 */
class AsyncCompareRunner
{
    private Connection $src;
    private Connection $tgt;
    private array $excludePatterns = [];
    private array $scope = [];

    // ─── Callbacks ───
    private ?\Closure $onPhase = null;       // fn(string $phase)
    private ?\Closure $onProgress = null;    // fn(int $pct, string $message)
    private ?\Closure $onComplete = null;    // fn(DiffResult, array $diffSql)

    public bool $cancelled = false;

    // ─── Connections ───
    private ?\mysqli $srcConn = null;
    private ?\mysqli $tgtConn = null;

    // ─── Table data ───
    private array $srcTables = [];
    private array $tgtTables = [];
    private array $srcCreateResults = [];
    private array $tgtCreateResults = [];

    // ─── Advanced objects ───
    private array $advanceSrcResults = [];
    private array $advanceTgtResults = [];

    // ─── Struct results ───
    private ?array $srcStruct = null;
    private ?array $tgtStruct = null;
    private ?DiffResult $diffResult = null;
    private ?array $diffSql = null;

    // ─── Dump file flags (skip DB fetch if set) ───
    private ?string $sourceDumpPath = null;
    private ?string $targetDumpPath = null;

    // ═══════════════════════════════════════════════
    //  Type → Scope mapping
    // ═══════════════════════════════════════════════

    private const SCOPE_MAP = [
        'VIEW'      => 'views',
        'TRIGGER'   => 'triggers',
        'EVENT'     => 'events',
        'FUNCTION'  => 'functions',
        'PROCEDURE' => 'procedures',
    ];

    private const ADVANCE_INFO = [
        'VIEW'      => ["SELECT TABLE_NAME as Name FROM information_schema.VIEWS WHERE TABLE_SCHEMA='#'", 'Create View'],
        'TRIGGER'   => ["SELECT TRIGGER_NAME as Name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='#'", 'SQL Original Statement'],
        'EVENT'     => ["SELECT EVENT_NAME  as Name FROM information_schema.EVENTS WHERE EVENT_SCHEMA='#'", 'Create Event'],
        'FUNCTION'  => ["SHOW FUNCTION STATUS  WHERE Db='#'", 'Create Function'],
        'PROCEDURE' => ["SHOW PROCEDURE STATUS WHERE Db='#'", 'Create Procedure'],
    ];

    // ═══════════════════════════════════════════════
    //  Public API
    // ═══════════════════════════════════════════════

    public function setOnPhase(\Closure $cb): void     { $this->onPhase = $cb; }
    public function setOnProgress(\Closure $cb): void  { $this->onProgress = $cb; }
    public function setOnComplete(\Closure $cb): void  { $this->onComplete = $cb; }

    /**
     * Load source schema from a dump JSON file instead of live DB.
     */
    public function setSourceDump(string $path): void
    {
        if (!file_exists($path)) throw new \InvalidArgumentException("源库 dump 文件不存在: {$path}");
        $this->sourceDumpPath = $path;
    }

    /**
     * Load target schema from a dump JSON file instead of live DB.
     */
    public function setTargetDump(string $path): void
    {
        if (!file_exists($path)) throw new \InvalidArgumentException("目标库 dump 文件不存在: {$path}");
        $this->targetDumpPath = $path;
    }

    /**
     * Start compare operation.
     * Accepts optional $scope array: ['tables', 'views', 'functions', 'procedures',
     * 'foreign_keys', 'triggers', 'events'].
     * Only enabled categories will be fetched and compared.
     */
    public function start(Loop $loop, Connection $src, Connection $tgt, array $excludePatterns = [], array $scope = []): void
    {
        $this->src = $src;
        $this->tgt = $tgt;
        $this->excludePatterns = $excludePatterns;
        $this->scope = $scope;

        // Load dump files if set
        if ($this->sourceDumpPath) {
            $data = $this->loadDumpFile($this->sourceDumpPath);
            $this->srcStruct = $data['struct'];
            $this->advanceSrcResults = $data['advanced'];
            $this->srcTables = $data['struct']['tables'] ?? [];
            $this->reportPhase('connecting');
            $this->reportProgress(0, "源库已加载 (dump: {$data['name']})");
        }

        if ($this->targetDumpPath) {
            $data = $this->loadDumpFile($this->targetDumpPath);
            $this->tgtStruct = $data['struct'];
            $this->advanceTgtResults = $data['advanced'];
            $this->tgtTables = $data['struct']['tables'] ?? [];
            $this->reportPhase('connecting');
            $this->reportProgress(0, "目标库已加载 (dump: {$data['name']})");
        }

        $this->reportPhase('connecting');
        $this->reportProgress(0, '正在准备...');

        // Defer sync work to next event loop tick — UI updates first
        $loop->delay(1, function () {
            $this->runSync();
        });
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function getDiffResult(): ?DiffResult { return $this->diffResult; }
    public function getDiffSql(): ?array         { return $this->diffSql; }

    // ═══════════════════════════════════════════════
    //  Sync execution (single deferred call)
    // ═══════════════════════════════════════════════

    private function runSync(): void
    {
        try {
            // ——— Source ———
            if ($this->cancelled) { $this->doCancelled(); return; }

            if (!$this->sourceDumpPath) {
                $needConn = $this->scopeEnabled('tables') || $this->anyAdvancedEnabled();
                if ($needConn) {
                    $this->syncConnect('src');
                }
                if ($this->scopeEnabled('tables') && !$this->cancelled) {
                    $this->syncFetchTables('src');
                }
                if (!$this->cancelled) {
                    $this->syncFetchAdvanced('src');
                }
            }

            // ——— Target ———
            if ($this->cancelled) { $this->doCancelled(); return; }

            if (!$this->targetDumpPath) {
                $needConn = $this->scopeEnabled('tables') || $this->anyAdvancedEnabled();
                if ($needConn) {
                    $this->syncConnect('tgt');
                }
                if ($this->scopeEnabled('tables') && !$this->cancelled) {
                    $this->syncFetchTables('tgt');
                }
                if (!$this->cancelled) {
                    $this->syncFetchAdvanced('tgt');
                }
            }

            if ($this->cancelled) { $this->doCancelled(); return; }

            // ——— Parse structs ———
            if ($this->scopeEnabled('tables')) {
                $this->parseStructs();
            } else {
                $this->srcStruct = ['tables' => [], 'columns' => [], 'show_create' => [], 'constraints' => []];
                $this->tgtStruct = ['tables' => [], 'columns' => [], 'show_create' => [], 'constraints' => []];
            }

            // ——— Compare ———
            $this->reportPhase('comparing');
            $this->reportProgress(0, '正在比对...');
            $this->doCompare();

            // ——— Done ———
            $this->cleanup();
            $this->reportPhase('done');
            $this->reportProgress(100, '比对完成');
            if ($this->onComplete) {
                ($this->onComplete)($this->diffResult, $this->diffSql ?? []);
            }
        } catch (\Throwable $e) {
            $this->cleanup();
            $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            file_put_contents(sys_get_temp_dir() . '/mss_runner_error.log',
                '[' . date('H:i:s') . '] ' . $msg . "\n" . $e->getTraceAsString() . "\n\n",
                FILE_APPEND);
            $this->reportPhase('error');
            $this->reportProgress(0, '错误：' . $e->getMessage());
            if ($this->onComplete) {
                ($this->onComplete)(null, []);
            }
        }
    }

    private function doCancelled(): void
    {
        $this->cleanup();
        $this->reportPhase('cancelled');
        $this->reportProgress(0, '已取消');
        if ($this->onComplete) {
            ($this->onComplete)(null, []);
        }
    }

    // ═══════════════════════════════════════════════
    //  Sync helpers: Source/Target tables
    // ═══════════════════════════════════════════════

    private function syncConnect(string $side): void
    {
        $cfg = $side === 'src' ? $this->src : $this->tgt;
        $conn = new \mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->database, $cfg->port);
        if ($conn->connect_error) {
            throw new \RuntimeException("连接失败 [{$cfg->host}:{$cfg->port}/{$cfg->database}]: {$conn->connect_error}");
        }
        $conn->set_charset('utf8mb4');

        if ($side === 'src') {
            $this->srcConn = $conn;
        } else {
            $this->tgtConn = $conn;
        }

        $this->reportPhase($side === 'src' ? 'fetch_source' : 'fetch_target');
        $this->reportProgress(0, "正在连接{$side}库...");
    }

    private function syncFetchTables(string $side): void
    {
        $conn = $side === 'src' ? $this->srcConn : $this->tgtConn;
        $label = $side === 'src' ? '源' : '目标';

        // List tables
        $result = $conn->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
        $tables = [];
        while ($row = $result->fetch_assoc()) {
            $tables[] = $row['Name'];
        }

        // Apply exclude patterns
        if (!empty($this->excludePatterns)) {
            $tables = array_values(array_filter($tables, function (string $name): bool {
                foreach ($this->excludePatterns as $p) {
                    if (fnmatch($p, $name)) return false;
                }
                return true;
            }));
        }

        // Fetch SHOW CREATE TABLE for each table (sync)
        $total = count($tables);
        $createResults = [];
        foreach ($tables as $i => $name) {
            if ($this->cancelled) return;

            $this->reportProgress(
                $total > 0 ? (int)(($i + 1) / $total * 100) : 0,
                "正在获取{$label}库表结构 ({$name}) {$i}/{$total}"
            );

            $r = $conn->query("SHOW CREATE TABLE `{$name}`");
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                $createResults[$name] = $row['Create Table'] ?? '';
                $r->free();
            }
        }

        // Assign results (variable variables with $this-> don't work in PHP)
        if ($side === 'src') {
            $this->srcTables = $tables;
            $this->srcCreateResults = $createResults;
        } else {
            $this->tgtTables = $tables;
            $this->tgtCreateResults = $createResults;
        }
    }

    // ═══════════════════════════════════════════════
    //  Advanced objects (sync)
    // ═══════════════════════════════════════════════

    private function syncFetchAdvanced(string $side): void
    {
        $conn = $side === 'src' ? $this->srcConn : $this->tgtConn;
        $db = $side === 'src' ? $this->src->database : $this->tgt->database;
        $label = $side === 'src' ? '源' : '目标';

        $this->reportPhase('fetch_advanced');

        $results = [];

        foreach (self::ADVANCE_INFO as $type => $info) {
            if ($this->cancelled) return;

            $scopeKey = self::SCOPE_MAP[$type];
            if (!in_array($scopeKey, $this->scope)) {
                $results[$type] = [];
                continue;
            }

            // List names
            $listSql = str_replace('#', $db, $info[0]);
            $r = $conn->query($listSql);
            $names = [];
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $names[] = $row['Name'];
                }
            }

            // Apply exclude patterns to advanced object names too
            if (!empty($this->excludePatterns)) {
                $names = array_values(array_filter($names, function (string $name): bool {
                    foreach ($this->excludePatterns as $p) {
                        if (fnmatch($p, $name)) return false;
                    }
                    return true;
                }));
            }

            // Fetch SHOW CREATE for each
            $objects = [];
            $total = count($names);
            foreach ($names as $i => $name) {
                if ($this->cancelled) return;

                $this->reportProgress(0, "获取{$label}库{$type}: " . ($i + 1) . "/{$total}");

                $qr = $conn->query("SHOW CREATE {$type} `{$name}`");
                $createSql = '';
                if ($qr instanceof mysqli_result) {
                    $row = $qr->fetch_assoc();
                    $createSql = $row[$info[1]] ?? '';
                    $qr->free();
                }
                $createSql = preg_replace('/DEFINER=[^\s]*/', '', $createSql);
                $objects[$name] = $createSql;
            }

            $results[$type] = $objects;
        }

        // Assign results (variable variables with $this-> don't work in PHP)
        if ($side === 'src') {
            $this->advanceSrcResults = $results;
        } else {
            $this->advanceTgtResults = $results;
        }
    }

    // ═══════════════════════════════════════════════
    //  Parse structs + Compare
    // ═══════════════════════════════════════════════

    private function parseStructs(): void
    {
        $this->srcStruct = $this->parseCreateResults($this->srcTables, $this->srcCreateResults);
        $this->tgtStruct = $this->parseCreateResults($this->tgtTables, $this->tgtCreateResults);
    }

    private function parseCreateResults(array $tables, array $createResults): array
    {
        $columns = [];
        $constraints = [];
        $showCreate = [];

        $pattern = '/(^[^`]\s*PRIMARY KEY .*[,]?$)|(^[^`]\s*KEY\s+(`.*`) .*[,]?$)|(^[^`]\s*CONSTRAINT\s+(`.*`) .*[,]?$)/m';

        foreach ($tables as $table) {
            $sql = $createResults[$table] ?? '';
            if (!$sql) continue;

            preg_match_all('/^\s+[`]([^`]*)`.*?$/m', $sql, $keyValue);
            $columns[$table] = array_combine(
                $keyValue[1],
                array_map(fn($item) => trim(rtrim($item, ',')), $keyValue[0])
            );

            preg_match_all($pattern, $sql, $matches);
            $constraints[$table] = array_map(fn($item) => trim(rtrim($item, ',')), $matches[0]);

            $showCreate[$table] = $sql;
        }

        ksort($columns);
        ksort($constraints);
        ksort($showCreate);
        ksort($tables);

        return [
            'tables'      => array_values($tables),
            'columns'     => $columns,
            'show_create' => $showCreate,
            'constraints' => $constraints,
        ];
    }

    private function doCompare(): void
    {
        $adapter = new StructSyncAdapter($this->src, $this->tgt);
        $adapter->setFetchedStructs($this->srcStruct, $this->tgtStruct);
        $adapter->setPrefetchedAdvance($this->advanceSrcResults, $this->advanceTgtResults);
        $adapter->setScope($this->scope);

        $this->diffResult = $adapter->compare();
        $this->diffSql = $adapter->getDiffSql();
    }

    // ═══════════════════════════════════════════════
    //  Cleanup
    // ═══════════════════════════════════════════════

    private function cleanup(): void
    {
        if ($this->srcConn) {
            @$this->srcConn->close();
            $this->srcConn = null;
        }
        if ($this->tgtConn) {
            @$this->tgtConn->close();
            $this->tgtConn = null;
        }
    }

    // ═══════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════

    private function scopeEnabled(string $key): bool
    {
        return in_array($key, $this->scope);
    }

    private function anyAdvancedEnabled(): bool
    {
        foreach (self::SCOPE_MAP as $scopeKey) {
            if (in_array($scopeKey, $this->scope)) return true;
        }
        return false;
    }

    private function loadDumpFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) throw new \RuntimeException("无法读取 dump 文件: {$path}");

        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException("dump 文件格式无效 (非 JSON): {$path}");

        $version = $data['version'] ?? 1;
        if ($version !== 1) throw new \RuntimeException("不支持的 dump 版本: {$version}");

        $struct = $data['struct'] ?? null;
        if (!$struct || !isset($struct['tables'], $struct['columns'])) {
            throw new \RuntimeException("dump 文件缺少 struct 数据: {$path}");
        }

        return [
            'struct'   => $struct,
            'advanced' => $data['advanced'] ?? [],
            'name'     => $data['name'] ?? basename($path),
        ];
    }

    private function reportPhase(string $phase): void
    {
        if ($this->onPhase) {
            ($this->onPhase)($phase);
        }
    }

    private function reportProgress(int $pct, string $message): void
    {
        if ($this->onProgress) {
            ($this->onProgress)($pct, $message);
        }
    }
}
