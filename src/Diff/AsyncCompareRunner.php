<?php
// src/Diff/AsyncCompareRunner.php

namespace MySqlSchemaSync\Diff;

use mysqli_result;
use MySqlSchemaSync\Config\Connection;
use Libui\Loop;
use MySqlSchemaSync\Diff\StructSyncAdapter;

/**
 * MYSQLI_ASYNC-driven non-blocking compare runner.
 *
 * Uses a state machine on Loop::repeat(30ms) to perform DB queries asynchronously.
 * Each state tick either fires a MYSQLI_ASYNC query or polls for completion.
 * At the end, creates a StructSyncAdapter, injects fetched structs, and calls compare().
 *
 * States:
 *   init → connect_src → list_src → (fire SHOW CREATE TABLE for each src table async)
 *       → connect_tgt → list_tgt → (fire SHOW CREATE TABLE for each tgt table async)
 *       → advance_src → advance_tgt → compare → done/error
 */
class AsyncCompareRunner
{
    private const TICK_MS = 30;

    private Connection $src;
    private Connection $tgt;
    private array $excludePatterns;

    /** @var \mysqli[] Source/target connections */
    private ?\mysqli $srcConn = null;
    private ?\mysqli $tgtConn = null;

    // ─── Callbacks ───
    private ?\Closure $onPhase = null;       // fn(string $phase)
    private ?\Closure $onProgress = null;    // fn(int $pct, string $message)
    private ?\Closure $onComplete = null;    // fn(DiffResult, array $diffSql)

    // ─── State machine ───
    private string $state = 'init';
    private ?Loop $loop = null;
    private ?int $timerId = null;
    public bool $cancelled = false;
    private string $errorMsg = '';

    // ─── Table data ───
    private array $srcTables = [];
    private array $tgtTables = [];
    private array $srcCreateResults = [];
    private array $tgtCreateResults = [];

    // ─── SHOW CREATE TABLE queue (remaining names to fetch) ───
    private array $srcCreateQueue = [];
    private array $tgtCreateQueue = [];

    // ─── Progress bookkeeping ───
    private int $totalSteps = 0;
    private int $completedSteps = 0;

    // ─── Advanced object state ───
    private array $advanceTypes = ['VIEW', 'TRIGGER', 'EVENT', 'FUNCTION', 'PROCEDURE'];
    private int $advanceTypeIdx = 0;
    private array $advanceSrcResults = [];  // type => [name => createSql]
    private array $advanceTgtResults = [];
    private array $advanceSrcNames = [];
    private array $advanceTgtNames = [];
    private int $advanceSrcDone = 0;
    private int $advanceTgtDone = 0;
    private int $advanceSrcTotal = 0;
    private int $advanceTgtTotal = 0;
    // Per-type sub-state: 'listing_src', 'creating_src', 'polling_src', 'listing_tgt', 'creating_tgt', 'polling_tgt', 'next_type'
    private string $advanceSubState = 'listing_src';
    // Current advanced type being fetched
    private string $currentAdvanceType = '';
    // SQL and column for listing the current type
    private array $currentAdvanceListInfo = ['', ''];

    // ─── Struct results ───
    private ?array $srcStruct = null;
    private ?array $tgtStruct = null;
    private ?DiffResult $diffResult = null;
    private ?array $diffSql = null;

    // ─── Dump file flags (skip DB fetch if set) ───
    private ?string $sourceDumpPath = null;
    private ?string $targetDumpPath = null;

    // ═══════════════════════════════════════════════
    //  Public API
    // ═══════════════════════════════════════════════

    public function setOnPhase(\Closure $cb): void     { $this->onPhase = $cb; }
    public function setOnProgress(\Closure $cb): void  { $this->onProgress = $cb; }
    public function setOnComplete(\Closure $cb): void  { $this->onComplete = $cb; }

    /**
     * Load source schema from a dump JSON file instead of live DB.
     * Skip source DB connection + fetch phases during run.
     */
    public function setSourceDump(string $path): void
    {
        if (!file_exists($path)) throw new \InvalidArgumentException("源库 dump 文件不存在: {$path}");
        $this->sourceDumpPath = $path;
    }

    /**
     * Load target schema from a dump JSON file instead of live DB.
     * Skip target DB connection + fetch phases during run.
     */
    public function setTargetDump(string $path): void
    {
        if (!file_exists($path)) throw new \InvalidArgumentException("目标库 dump 文件不存在: {$path}");
        $this->targetDumpPath = $path;
    }

    public function start(Loop $loop, Connection $src, Connection $tgt, array $excludePatterns = []): void
    {
        $this->loop = $loop;
        $this->src = $src;
        $this->tgt = $tgt;
        $this->excludePatterns = $excludePatterns;

        // 如果设置了 dump 文件，直接加载跳过 DB 连接 + 获取阶段
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

        // 确定初始状态：如果源和目标都已加载 dump，直接进 compare
        if ($this->sourceDumpPath && $this->targetDumpPath) {
            $this->state = 'compare';
        } elseif ($this->sourceDumpPath) {
            $this->state = 'connect_tgt'; // 只需连目标库
        } elseif ($this->targetDumpPath) {
            $this->state = 'connect_src'; // 只需连源库
        } else {
            $this->state = 'connect_src'; // 默认：两边都要连
        }

        $this->reportPhase('connecting');
        $this->reportProgress(0, '正在准备...');

        $this->timerId = $loop->repeat(self::TICK_MS, function () {
            if ($this->cancelled) {
                $this->cleanup();
                $this->cancelTimer();
                $this->reportPhase('cancelled');
                return;
            }
            try {
                $this->tick();
            } catch (\Throwable $e) {
                $msg = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                $this->errorMsg = $msg;
                // Log to temp file for debugging
                file_put_contents(sys_get_temp_dir() . '/mss_runner_error.log', '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
                file_put_contents(sys_get_temp_dir() . '/mss_runner_error.log', $e->getTraceAsString() . "\n\n", FILE_APPEND);
                $this->state = '__error';
                $this->cleanup();
                $this->cancelTimer();
                $this->reportPhase('error');
                $this->reportProgress(0, '错误：' . $e->getMessage());
                if ($this->onComplete) {
                    ($this->onComplete)(null, []);
                }
            }
        });
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function getDiffResult(): ?DiffResult { return $this->diffResult; }
    public function getDiffSql(): ?array         { return $this->diffSql; }

    // ═══════════════════════════════════════════════
    //  State machine tick
    // ═══════════════════════════════════════════════

    private function tick(): void
    {
        switch ($this->state) {
            case 'connect_src':     $this->doConnectSrc(); break;
            case 'list_src':        $this->doListSrc(); break;
            case 'fire_src_create': $this->doFireSrcCreate(); break;
            case 'poll_src_create': $this->doPollSrcCreate(); break;

            case 'connect_tgt':     $this->doConnectTgt(); break;
            case 'list_tgt':        $this->doListTgt(); break;
            case 'fire_tgt_create': $this->doFireTgtCreate(); break;
            case 'poll_tgt_create': $this->doPollTgtCreate(); break;

            case 'advance_src':     $this->doAdvanceSrc(); break;
            case 'advance_tgt':     $this->doAdvanceTgt(); break;

            case 'compare':         $this->doCompare(); break;
            case '__done':          $this->doFinishDone(); break;
            case '__error':         /* stub — already handled in catch */ break;
        }
    }

    // ═══════════════════════════════════════════════
    //  Phase 1: Source tables
    // ═══════════════════════════════════════════════

    private function doConnectSrc(): void
    {
        $this->srcConn = $this->createConnection($this->src);
        $this->state = 'list_src';
        $this->reportPhase('fetch_source');
    }

    private function doListSrc(): void
    {
        $result = $this->srcConn->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
        $this->srcTables = [];
        while ($row = $result->fetch_assoc()) {
            $this->srcTables[] = $row['Name'];
        }
        // Apply exclude patterns
        if (!empty($this->excludePatterns)) {
            $this->srcTables = array_values(array_filter($this->srcTables, function (string $name): bool {
                foreach ($this->excludePatterns as $p) {
                    if (fnmatch($p, $name)) return false;
                }
                return true;
            }));
        }

        $this->srcCreateQueue = $this->srcTables;
        $this->totalSteps = count($this->srcTables);
        $this->completedSteps = 0;

        if (empty($this->srcCreateQueue)) {
            $this->state = 'connect_tgt';
            return;
        }

        $this->state = 'fire_src_create';
        $this->progressSrcCreate('fire');
    }

    private function doFireSrcCreate(): void
    {
        $this->progressSrcCreate('fire');
    }

    private function doPollSrcCreate(): void
    {
        $this->progressSrcCreate('poll');
    }

    private function progressSrcCreate(string $action): void
    {
        if ($action === 'fire') {
            $name = $this->srcCreateQueue[0];
            $sql = "SHOW CREATE TABLE `{$name}`";

            $asyncOk = $this->srcConn->query($sql, MYSQLI_ASYNC);
            if (!$asyncOk) {
                // Fallback: sync query
                $this->reapSrcCreate(true);
                return;
            }
            $this->state = 'poll_src_create';
            return;
        }

        // action === 'poll'
        $links = [$this->srcConn];
        $errors = [$this->srcConn];
        $reject = [$this->srcConn];
        $n = @mysqli_poll($links, $errors, $reject, 0, 0);

        if ($n === false) {
            // Poll error: fallback to sync
            $this->reapSrcCreate(true);
            return;
        }

        if ($n > 0) {
            $this->reapSrcCreate(false);
        }
        // else: not ready yet, wait for next tick
    }

    private function reapSrcCreate(bool $fallbackSync): void
    {
        $name = array_shift($this->srcCreateQueue);
        $fullName = $name; // name was already removed from queue

        if ($fallbackSync) {
            $r = $this->srcConn->query("SHOW CREATE TABLE `{$fullName}`");
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                $this->srcCreateResults[$fullName] = $row['Create Table'] ?? '';
                $r->free();
            }
        } else {
            $r = $this->srcConn->reap_async_query();
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                $this->srcCreateResults[$fullName] = $row['Create Table'] ?? '';
                $r->free();
            }
        }

        $this->completedSteps++;
        $this->reportProgress(
            (int)($this->completedSteps / max($this->totalSteps, 1) * 100),
            "获取源表结构 {$this->completedSteps}/{$this->totalSteps}"
        );

        if (empty($this->srcCreateQueue)) {
            // All source tables done
            $this->state = 'connect_tgt';
            return;
        }

        // Fire next query immediately
        $this->state = 'fire_src_create';
        // Don't call fire directly — let next tick handle it
    }

    // ═══════════════════════════════════════════════
    //  Phase 2: Target tables (mirrors Phase 1)
    // ═══════════════════════════════════════════════

    private function doConnectTgt(): void
    {
        $this->tgtConn = $this->createConnection($this->tgt);
        $this->state = 'list_tgt';
        $this->reportPhase('fetch_target');
    }

    private function doListTgt(): void
    {
        $result = $this->tgtConn->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
        $this->tgtTables = [];
        while ($row = $result->fetch_assoc()) {
            $this->tgtTables[] = $row['Name'];
        }
        if (!empty($this->excludePatterns)) {
            $this->tgtTables = array_values(array_filter($this->tgtTables, function (string $name): bool {
                foreach ($this->excludePatterns as $p) {
                    if (fnmatch($p, $name)) return false;
                }
                return true;
            }));
        }

        $this->tgtCreateQueue = $this->tgtTables;
        $this->totalSteps = count($this->tgtTables);
        $this->completedSteps = 0;

        if (empty($this->tgtCreateQueue)) {
            $this->state = 'advance_src';
            $this->initAdvanceState();
            return;
        }

        $this->state = 'fire_tgt_create';
    }

    private function doFireTgtCreate(): void
    {
        $this->progressTgtCreate('fire');
    }

    private function doPollTgtCreate(): void
    {
        $this->progressTgtCreate('poll');
    }

    private function progressTgtCreate(string $action): void
    {
        if ($action === 'fire') {
            $name = $this->tgtCreateQueue[0];
            $sql = "SHOW CREATE TABLE `{$name}`";
            $asyncOk = $this->tgtConn->query($sql, MYSQLI_ASYNC);
            if (!$asyncOk) {
                $this->reapTgtCreate(true);
                return;
            }
            $this->state = 'poll_tgt_create';
            return;
        }

        // poll
        $links = [$this->tgtConn];
        $errors = [$this->tgtConn];
        $reject = [$this->tgtConn];
        $n = @mysqli_poll($links, $errors, $reject, 0, 0);

        if ($n === false) {
            $this->reapTgtCreate(true);
            return;
        }
        if ($n > 0) {
            $this->reapTgtCreate(false);
        }
    }

    private function reapTgtCreate(bool $fallbackSync): void
    {
        $name = array_shift($this->tgtCreateQueue);

        if ($fallbackSync) {
            $r = $this->tgtConn->query("SHOW CREATE TABLE `{$name}`");
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                $this->tgtCreateResults[$name] = $row['Create Table'] ?? '';
                $r->free();
            }
        } else {
            $r = $this->tgtConn->reap_async_query();
            if ($r instanceof mysqli_result) {
                $row = $r->fetch_assoc();
                $this->tgtCreateResults[$name] = $row['Create Table'] ?? '';
                $r->free();
            }
        }

        $this->completedSteps++;
        $this->reportProgress(
            (int)($this->completedSteps / max($this->totalSteps, 1) * 100),
            "获取目标表结构 {$this->completedSteps}/{$this->totalSteps}"
        );

        if (empty($this->tgtCreateQueue)) {
            // All target tables done → move to advanced objects
            $this->state = 'advance_src';
            $this->initAdvanceState();
            return;
        }

        $this->state = 'fire_tgt_create';
    }

    // ═══════════════════════════════════════════════
    //  Phase 3: Advanced objects
    // ═══════════════════════════════════════════════

    private function initAdvanceState(): void
    {
        $this->reportPhase('fetch_advanced');
        $this->advanceTypeIdx = 0;
        $this->advanceSubState = 'listing_src';
        $this->advanceSrcResults = [];
        $this->advanceTgtResults = [];
    }

    /**
     * Advance listing/creation for one type at a time.
     * First do source, then target, then move to next type.
     */
    private function doAdvanceSrc(): void
    {
        if ($this->advanceTypeIdx >= count($this->advanceTypes)) {
            // All advanced types done
            $this->parseStructs();
            $this->state = 'compare';
            return;
        }

        $type = $this->advanceTypes[$this->advanceTypeIdx];
        $this->currentAdvanceType = $type;

        switch ($this->advanceSubState) {
            case 'listing_src':
                $this->advanceListSrc($type);
                break;
            case 'creating_src':
                $this->advanceFireSrc($type);
                break;
            case 'polling_src':
                $this->advancePollSrc($type);
                break;
        }
    }

    private function doAdvanceTgt(): void
    {
        $type = $this->currentAdvanceType;

        switch ($this->advanceSubState) {
            case 'listing_tgt':
                $this->advanceListTgt($type);
                break;
            case 'creating_tgt':
                $this->advanceFireTgt($type);
                break;
            case 'polling_tgt':
                $this->advancePollTgt($type);
                break;
            case 'next_type':
                $this->advanceTypeIdx++;
                $this->advanceSubState = 'listing_src';
                $this->state = 'advance_src';
                break;
        }
    }

    /**
     * Get the listing SQL and result column for a given type.
     */
    private function getAdvanceListInfo(string $type): array
    {
        $list = [
            'VIEW'      => ["SELECT TABLE_NAME as Name FROM information_schema.VIEWS WHERE TABLE_SCHEMA='#'", 'Create View'],
            'TRIGGER'   => ["SELECT TRIGGER_NAME as Name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='#'", 'SQL Original Statement'],
            'EVENT'     => ["SELECT EVENT_NAME  as Name FROM information_schema.EVENTS WHERE EVENT_SCHEMA='#'", 'Create Event'],
            'FUNCTION'  => ["SHOW FUNCTION STATUS  WHERE Db='#'", 'Create Function'],
            'PROCEDURE' => ["SHOW PROCEDURE STATUS WHERE Db='#'", 'Create Procedure'],
        ];
        return $list[$type] ?? ['', ''];
    }

    private function advanceListSrc(string $type): void
    {
        $info = $this->getAdvanceListInfo($type);
        $sql = str_replace('#', $this->src->database, $info[0]);
        $result = $this->srcConn->query($sql);
        $names = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
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

        $this->advanceSrcNames = $names;
        $this->advanceSrcTotal = count($names);
        $this->advanceSrcDone = 0;

        if (empty($names)) {
            $this->advanceSrcResults[$type] = [];
            $this->advanceSubState = 'listing_tgt';
            $this->state = 'advance_tgt';
            return;
        }

        $this->advanceSubState = 'creating_src';
        $this->currentAdvanceListInfo = $info;
        $this->state = 'advance_src'; // continue on next tick
    }

    private function advanceFireSrc(string $type): void
    {
        if ($this->advanceSrcDone >= $this->advanceSrcTotal) {
            $this->advanceSubState = 'listing_tgt';
            $this->state = 'advance_tgt';
            return;
        }

        $name = $this->advanceSrcNames[$this->advanceSrcDone];
        $sql = "SHOW CREATE {$type} `{$name}`";

        $asyncOk = $this->srcConn->query($sql, MYSQLI_ASYNC);
        if (!$asyncOk) {
            // Fallback sync
            $r = $this->srcConn->query($sql);
            $this->processAdvanceSrcResult($r, $name, $type);
            return;
        }

        $this->advanceSubState = 'polling_src';
    }

    private function advancePollSrc(string $type): void
    {
        $links = [$this->srcConn];
        $errors = [$this->srcConn];
        $reject = [$this->srcConn];
        $n = @mysqli_poll($links, $errors, $reject, 0, 0);

        if ($n === false) {
            // Poll error: fallback to sync (re-run query)
            $name = $this->advanceSrcNames[$this->advanceSrcDone];
            $sql = "SHOW CREATE {$type} `{$name}`";
            $r = $this->srcConn->query($sql);
            $this->processAdvanceSrcResult($r, $name, $type);
            return;
        }

        if ($n > 0) {
            $r = $this->srcConn->reap_async_query();
            $name = $this->advanceSrcNames[$this->advanceSrcDone];
            $this->processAdvanceSrcResult($r, $name, $type);
        }
        // else: not ready, wait for next tick
    }

    private function processAdvanceSrcResult($result, string $name, string $type): void
    {
        $col = $this->currentAdvanceListInfo[1];
        $createSql = '';
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $createSql = $row[$col] ?? '';
            $result->free();
        }
        $createSql = preg_replace('/DEFINER=[^\s]*/', '', $createSql);

        $this->advanceSrcResults[$type][$name] = $createSql;
        $this->advanceSrcDone++;

        $this->reportProgress(
            0,
            "获取源库{$type}: {$this->advanceSrcDone}/{$this->advanceSrcTotal}"
        );

        // Transition back to fire next query, or to target listing if done
        if ($this->advanceSrcDone >= $this->advanceSrcTotal) {
            $this->advanceSubState = 'listing_tgt';
            $this->state = 'advance_tgt';
        } else {
            $this->advanceSubState = 'creating_src';
        }
    }

    private function advanceListTgt(string $type): void
    {
        $info = $this->getAdvanceListInfo($type);
        $sql = str_replace('#', $this->tgt->database, $info[0]);
        $result = $this->tgtConn->query($sql);
        $names = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $names[] = $row['Name'];
            }
        }

        // Apply exclude patterns
        if (!empty($this->excludePatterns)) {
            $names = array_values(array_filter($names, function (string $name): bool {
                foreach ($this->excludePatterns as $p) {
                    if (fnmatch($p, $name)) return false;
                }
                return true;
            }));
        }

        $this->advanceTgtNames = $names;
        $this->advanceTgtTotal = count($names);
        $this->advanceTgtDone = 0;

        if (empty($names)) {
            $this->advanceTgtResults[$type] = [];
            $this->advanceSubState = 'next_type';
            $this->state = 'advance_tgt';
            return;
        }

        $this->advanceSubState = 'creating_tgt';
        $this->currentAdvanceListInfo = $info;
        $this->state = 'advance_tgt'; // continue on next tick
    }

    private function advanceFireTgt(string $type): void
    {
        if ($this->advanceTgtDone >= $this->advanceTgtTotal) {
            $this->advanceSubState = 'next_type';
            $this->state = 'advance_tgt';
            return;
        }

        $name = $this->advanceTgtNames[$this->advanceTgtDone];
        $sql = "SHOW CREATE {$type} `{$name}`";

        $asyncOk = $this->tgtConn->query($sql, MYSQLI_ASYNC);
        if (!$asyncOk) {
            $r = $this->tgtConn->query($sql);
            $this->processAdvanceTgtResult($r, $name, $type);
            return;
        }

        $this->advanceSubState = 'polling_tgt';
    }

    private function advancePollTgt(string $type): void
    {
        $links = [$this->tgtConn];
        $errors = [$this->tgtConn];
        $reject = [$this->tgtConn];
        $n = @mysqli_poll($links, $errors, $reject, 0, 0);

        if ($n === false) {
            $name = $this->advanceTgtNames[$this->advanceTgtDone];
            $sql = "SHOW CREATE {$type} `{$name}`";
            $r = $this->tgtConn->query($sql);
            $this->processAdvanceTgtResult($r, $name, $type);
            return;
        }

        if ($n > 0) {
            $r = $this->tgtConn->reap_async_query();
            $name = $this->advanceTgtNames[$this->advanceTgtDone];
            $this->processAdvanceTgtResult($r, $name, $type);
        }
    }

    private function processAdvanceTgtResult($result, string $name, string $type): void
    {
        $col = $this->currentAdvanceListInfo[1];
        $createSql = '';
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $createSql = $row[$col] ?? '';
            $result->free();
        }
        $createSql = preg_replace('/DEFINER=[^\s]*/', '', $createSql);

        $this->advanceTgtResults[$type][$name] = $createSql;
        $this->advanceTgtDone++;

        $this->reportProgress(
            0,
            "获取目标库{$type}: {$this->advanceTgtDone}/{$this->advanceTgtTotal}"
        );

        if ($this->advanceTgtDone >= $this->advanceTgtTotal) {
            $this->advanceSubState = 'next_type';
        } else {
            $this->advanceSubState = 'creating_tgt';
        }
    }

    // ═══════════════════════════════════════════════
    //  Phase 4: Parse structs + Compare
    // ═══════════════════════════════════════════════

    /**
     * Parse raw SHOW CREATE TABLE results into struct arrays
     * matching the format from AsyncStructureFetcher::parseStructureResults().
     */
    private function parseStructs(): void
    {
        $this->reportPhase('comparing');

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

        $this->diffResult = $adapter->compare();
        $this->diffSql = $adapter->getDiffSql();

        $this->state = '__done';
    }

    // ═══════════════════════════════════════════════
    //  Completion / cleanup
    // ═══════════════════════════════════════════════

    private function doFinishDone(): void
    {
        $this->reportPhase('done');
        $this->cleanup();
        $this->cancelTimer();

        if ($this->onComplete) {
            ($this->onComplete)($this->diffResult, $this->diffSql ?? []);
        }
    }

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

    private function cancelTimer(): void
    {
        if ($this->timerId !== null && $this->loop !== null) {
            $this->loop->cancel($this->timerId);
            $this->timerId = null;
        }
    }

    // ═══════════════════════════════════════════════
    //  Helpers
    // ═══════════════════════════════════════════════

    /**
     * Load a dump JSON file and return ['struct' => [...], 'advanced' => [...], 'name' => '...'].
     * Compatible with bin/dump-schema.php output format v1.
     */
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

    private function createConnection(Connection $cfg): \mysqli
    {
        $conn = new \mysqli($cfg->host, $cfg->user, $cfg->password, $cfg->database, $cfg->port);
        if ($conn->connect_error) {
            throw new \RuntimeException("连接失败 [{$cfg->host}:{$cfg->port}/{$cfg->database}]: {$conn->connect_error}");
        }
        $conn->set_charset('utf8mb4');
        return $conn;
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
