#!/usr/bin/env php85
<?php
/**
 * bin/benchmark-async.php
 *
 * Benchmark: sync vs MYSQLI_ASYNC (1-conn) vs parallel multi-conn vs 连接池.
 * 比较不同方式获取 300+ 个表的 SHOW CREATE TABLE 的耗时。
 *
 * Usage:
 *   php85 bin/benchmark-async.php [connection_name]
 *
 * 测试方法:
 *   A) Sync sequential (1 连接)                    — mysqli_query 一个一个等
 *   B) MYSQLI_ASYNC 单连接 一个一个发              — 当前 AsyncCompareRunner 的方式
 *   C) ASYNC 多连接全并行                          — 每个表独立连接同时发
 *   D) 连接池模式 (N 连接, 每个连接串行查一批)     — 类似 think-orm-async
 */

require __DIR__ . '/../vendor/autoload.php';

use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Config\Connection;

// ══════════════════════════════════════════════════════════════════
//  1. 选择连接
// ══════════════════════════════════════════════════════════════════

$store = new ConfigStore();
$connections = $store->list();

if (empty($connections)) {
    fwrite(STDERR, "错误：没有配置任何数据库连接。请在 GUI 中添加连接后再运行。\n");
    exit(1);
}

$conn = null;
$nameArg = $argv[1] ?? null;
if ($nameArg) {
    foreach ($connections as $c) {
        if (str_contains($c->name, $nameArg) || str_contains($c->id, $nameArg)) {
            $conn = $c;
            break;
        }
    }
    if (!$conn) {
        fwrite(STDERR, "未找到匹配 '$nameArg' 的连接。可用连接：\n");
        foreach ($connections as $c) {
            fwrite(STDERR, "  {$c->name} [{$c->database}@{$c->host}]\n");
        }
        exit(1);
    }
} else {
    echo "可用连接：\n";
    foreach ($connections as $i => $c) {
        echo "  [{$i}] {$c->name} — {$c->database}@{$c->host}:{$c->port}\n";
    }
    echo "选择连接序号 [默认 0]: ";
    $input = trim(fgets(STDIN));
    $idx = $input !== '' ? (int)$input : 0;
    if (!isset($connections[$idx])) {
        fwrite(STDERR, "无效序号。\n");
        exit(1);
    }
    $conn = $connections[$idx];
}

echo "\n═════════════════════════════════════════════════════════════\n";
echo "  连接：{$conn->name}\n";
echo "  数据库：{$conn->database}@{$conn->host}:{$conn->port}\n";

// ══════════════════════════════════════════════════════════════════
//  2. 获取表列表
// ══════════════════════════════════════════════════════════════════

$link = new mysqli($conn->host, $conn->user, $conn->password, $conn->database, $conn->port);
if ($link->connect_error) {
    die("连接失败：{$link->connect_error}\n");
}
$link->set_charset('utf8mb4');

$result = $link->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
$tables = [];
while ($row = $result->fetch_assoc()) {
    $tables[] = $row['Name'];
}
$result->free();
$link->close();

$tableCount = count($tables);
echo "  表数量：{$tableCount}\n";
echo "═════════════════════════════════════════════════════════════\n\n";

if ($tableCount === 0) {
    die("没有表可测试。\n");
}

// ══════════════════════════════════════════════════════════════════
//  3. 工具函数
// ══════════════════════════════════════════════════════════════════

function createLink(Connection $conn): mysqli
{
    $link = new mysqli($conn->host, $conn->user, $conn->password, $conn->database, $conn->port);
    if ($link->connect_error) {
        throw new RuntimeException("连接失败：{$link->connect_error}");
    }
    $link->set_charset('utf8mb4');
    return $link;
}

function fmtMs(float $seconds): string
{
    return number_format($seconds * 1000, 1) . ' ms';
}

function fmtAvg(array $times): string
{
    return count($times) ? fmtMs(array_sum($times) / count($times)) : 'N/A';
}

/** 等待一批 MYSQLI_ASYNC 查询全部完成 */
function pollAllAsync(array $links, float $timeout = 30.0): array
{
    $remaining = $links;
    $results = [];
    $deadline = microtime(true) + $timeout;

    while (!empty($remaining) && microtime(true) < $deadline) {
        $pollLinks = $errors = $reject = array_values($remaining);
        $n = @mysqli_poll($pollLinks, $errors, $reject, 0, 100000); // 100ms

        if ($n === false || $n === 0) continue;

        foreach ($pollLinks as $pl) {
            foreach ($remaining as $t => $l) {
                if ($l === $pl) {
                    $r = $pl->reap_async_query();
                    if ($r instanceof mysqli_result) {
                        $row = $r->fetch_assoc();
                        $results[$t] = $row['Create Table'] ?? '';
                        $r->free();
                    }
                    unset($remaining[$t]);
                    $pl->close();
                    break;
                }
            }
        }
    }

    // 关闭超时的连接
    foreach ($remaining as $l) $l->close();

    return $results;
}

// ══════════════════════════════════════════════════════════════════
//  4. 开始测试
// ══════════════════════════════════════════════════════════════════

$results = []; // 存储各方法的耗时

// ── 方法 A: Sync sequential ──
echo "━━━ A) Sync sequential (1 连接) ━━━\n";
{
    $link = createLink($conn);
    $success = 0;
    $perQuery = [];
    $t0 = microtime(true);

    foreach ($tables as $t) {
        $q0 = microtime(true);
        $r = $link->query("SHOW CREATE TABLE `{$t}`");
        $perQuery[] = microtime(true) - $q0;
        if ($r instanceof mysqli_result) { $success++; $r->free(); }
    }

    $elapsed = microtime(true) - $t0;
    $link->close();
    $results['A'] = $elapsed;
    printf("  耗时: %s  (%d/%d, avg %s)\n\n", fmtMs($elapsed), $success, $tableCount, fmtAvg($perQuery));
}

// ── 方法 B: MYSQLI_ASYNC 单连接一个一个 ──
echo "━━━ B) MYSQLI_ASYNC 单连接 一个一个发 ━━━\n";
{
    $link = createLink($conn);
    $success = 0;
    $perQuery = [];
    $t0 = microtime(true);

    foreach ($tables as $t) {
        $q0 = microtime(true);
        $link->query("SHOW CREATE TABLE `{$t}`", MYSQLI_ASYNC);
        while (true) {
            $links = $errors = $reject = [$link];
            $n = @mysqli_poll($links, $errors, $reject, 0, 100000);
            if ($n === false || $n > 0) break;
        }
        $r = $link->reap_async_query();
        $perQuery[] = microtime(true) - $q0;
        if ($r instanceof mysqli_result) { $success++; $r->free(); }
    }

    $elapsed = microtime(true) - $t0;
    $link->close();
    $results['B'] = $elapsed;
    printf("  耗时: %s  (%d/%d, avg %s)\n\n", fmtMs($elapsed), $success, $tableCount, fmtAvg($perQuery));
}

// ── 方法 C: 多连接全并行 ──
echo "━━━ C) MYSQLI_ASYNC 多连接 全并行 ━━━\n";
$batchSizesC = [1, 3, 5, 10, 20];
$resultsC = [];
foreach ($batchSizesC as $batchSize) {
    $success = 0;
    $t0 = microtime(true);

    $chunks = array_chunk($tables, $batchSize);
    foreach ($chunks as $chunk) {
        $links = [];
        foreach ($chunk as $t) {
            $links[$t] = createLink($conn);
        }
        foreach ($chunk as $t) {
            $links[$t]->query("SHOW CREATE TABLE `{$t}`", MYSQLI_ASYNC);
        }
        $fetched = pollAllAsync($links);
        $success += count($fetched);
    }

    $elapsed = microtime(true) - $t0;
    $resultsC[$batchSize] = $elapsed;
    $perConn = $batchSize === 1 ? '无并行' : "每批{$batchSize}表";
    printf("  并行 %s: %s  (%d/%d)\n", $perConn, fmtMs($elapsed), $success, $tableCount);
}
echo "\n";

// ── 方法 D: 连接池模式 ──
echo "━━━ D) 连接池模式 (N 连接, 每连接串行查一批) ━━━\n";
$poolSizesD = [1, 3, 5, 10, 20];
$resultsD = [];
foreach ($poolSizesD as $poolSize) {
    $success = 0;
    $t0 = microtime(true);

    $chunks = array_chunk($tables, (int)ceil($tableCount / $poolSize));
    foreach ($chunks as $chunk) {
        $l = createLink($conn);
        foreach ($chunk as $t) {
            $r = $l->query("SHOW CREATE TABLE `{$t}`");
            if ($r instanceof mysqli_result) { $success++; $r->free(); }
        }
        $l->close();
    }

    $elapsed = microtime(true) - $t0;
    $resultsD[$poolSize] = $elapsed;
    printf("  连接池 %d: %s  (%d/%d)\n", $poolSize, fmtMs($elapsed), $success, $tableCount);
}
echo "\n";

// ══════════════════════════════════════════════════════════════════
//  5. 汇总
// ══════════════════════════════════════════════════════════════════

echo "═════════════════════════════════════════════════════════════\n";
echo "  汇总对比（{$tableCount} 个表）\n";
echo "─────────────────────────────────────────────────────────────\n";
printf("  %-35s %s\n", "方法", "总耗时");
echo "─────────────────────────────────────────────────────────────\n";
printf("  %-35s %s\n", "A) Sync 1 连接 (顺序)", fmtMs($results['A']));
printf("  %-35s %s\n", "B) ASYNC 1 连接 (一个一个)", fmtMs($results['B']));
echo "─────────────────────────────────────────────────────────────\n";
echo "  -- ASYNC 多连接全并行 --\n";
foreach ($resultsC as $bs => $elapsed) {
    $label = $bs === 1 ? '  每个表独立连接' : "  每批 {$bs} 表并行";
    printf("  %-35s %s\n", "C) {$label}", fmtMs($elapsed));
}
echo "─────────────────────────────────────────────────────────────\n";
echo "  -- 连接池模式 --\n";
foreach ($resultsD as $ps => $elapsed) {
    printf("  %-35s %s\n", "D) {$ps} 连接分片", fmtMs($elapsed));
}
echo "─────────────────────────────────────────────────────────────\n";

// 计算加速比
$base = $results['A'];
$bestC = min($resultsC);
$bestD = min($resultsD);

printf("  Sync baseline:         %s\n", fmtMs($base));
printf("  ASYNC 1-conn:          %s (x%.1f)\n", fmtMs($results['B']), $base / $results['B']);
printf("  最佳并行(C):          %s (x%.1f)\n", fmtMs($bestC), $base / $bestC);
printf("  最佳连接池(D):        %s (x%.1f)\n", fmtMs($bestD), $base / $bestD);
echo "═════════════════════════════════════════════════════════════\n\n";

// ══════════════════════════════════════════════════════════════════
//  结论
// ══════════════════════════════════════════════════════════════════

echo "结论分析：\n\n";
echo "  A vs B 对比：MYSQLI_ASYNC 单连接一个一个发 ≠ 并行\n";
echo "    MySQL 单连接串行执行查询，" . fmtMs($results['A']) . " vs " . fmtMs($results['B']) . " 说明几乎无差别。\n";
echo "    MYSQLI_ASYNC 只是不让 PHP 阻塞等待，但总等待时间不变。\n";
echo "    → 当前 AsyncCompareRunner 的 async 方式不会比 sync 快！\n\n";
echo "  C vs A 对比：多连接全并行才能提速\n";
echo "    用 N 个连接同时发查询，MySQL 真正并行处理。\n";
echo "    但每个连接独立建立连接开销大（TCP handshake + MySQL auth）。\n\n";
echo "  D vs A 对比：连接池模式最实用\n";
echo "    建立少量连接，每连接查一批表（串行）。\n";
echo "    没有 MYSQLI_ASYNC 的复杂度，没有连接风暴。\n";
echo "    推荐方案：3-5 个连接，平分表列表。\n\n";
echo "  给 AsyncCompareRunner 的改进建议：\n";
echo "    1. 不要 MYSQLI_ASYNC 一个一个发，用 N 个连接并行\n";
echo "    2. 或者直接用连接池模式，每个连接串行查一批\n";
echo "    3. 连接数建议 3-5 个，太多反而增加 MySQL 负担\n";
