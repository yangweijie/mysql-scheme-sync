#!/usr/bin/env php85
<?php
/**
 * bin/dump-schema.php
 *
 * 一句话导出数据库结构（表 + 视图 + 触发器 + 事件 + 函数 + 存储过程）。
 * 输出 JSON 文件，可用于离线对比（无需重复连远程库）。
 *
 * Usage:
 *   php85 bin/dump-schema.php [connection_name] [-o output.json]
 *
 * 示例:
 *   php85 bin/dump-schema.php              # 交互选择连接
 *   php85 bin/dump-schema.php 阿里云       # 按名称匹配
 *   php85 bin/dump-schema.php 阿里云 -o cloud_schema.json
 *
 * 输出格式（可直接传给 AsyncCompareRunner::setSourceDump/setTargetDump）:
 *   { tables, columns, show_create, constraints, advanced: { VIEW, TRIGGER, ... } }
 */

require __DIR__ . '/../vendor/autoload.php';

use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Config\Connection;

// ══════════════════════════════════════════════════════════════════
//  解析参数
// ══════════════════════════════════════════════════════════════════

$args = $argv;
array_shift($args); // remove script name

$connName = null;
$outputFile = null;
$connectName = null;

while (count($args) > 0) {
    $arg = array_shift($args);
    if ($arg === '-o' && count($args) > 0) {
        $outputFile = array_shift($args);
    } elseif ($connectName === null) {
        $connectName = $arg;
    }
}

// ══════════════════════════════════════════════════════════════════
//  选择连接
// ══════════════════════════════════════════════════════════════════

$store = new ConfigStore();
$connections = $store->list();

if (empty($connections)) {
    fwrite(STDERR, "错误：没有配置任何数据库连接。请在 GUI 中添加连接后再运行。\n");
    exit(1);
}

$conn = null;
if ($connectName) {
    foreach ($connections as $c) {
        if (str_contains($c->name, $connectName) || str_contains($c->id, $connectName)) {
            $conn = $c;
            break;
        }
    }
    if (!$conn) {
        fwrite(STDERR, "未找到匹配 '$connectName' 的连接。可用连接：\n");
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

if (!$outputFile) {
    $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $conn->database);
    $dumpsDir = $store->getDir() . '/dumps';
    if (!is_dir($dumpsDir)) {
        mkdir($dumpsDir, 0755, true);
    }
    $outputFile = $dumpsDir . DIRECTORY_SEPARATOR . "{$safeName}_schema.json";
}

echo "\n═════════════════════════════════════════════════════════════\n";
echo "  连接: {$conn->name}\n";
echo "  数据库: {$conn->database}@{$conn->host}:{$conn->port}\n";
echo "  输出: {$outputFile}\n";
echo "═════════════════════════════════════════════════════════════\n\n";

// ══════════════════════════════════════════════════════════════════
//  连接 + 获取表列表
// ══════════════════════════════════════════════════════════════════

$link = new mysqli($conn->host, $conn->user, $conn->password, $conn->database, $conn->port);
if ($link->connect_error) {
    die("连接失败: {$link->connect_error}\n");
}
$link->set_charset('utf8mb4');
$db = $link->real_escape_string($conn->database);

$t0 = microtime(true);

// ── 表 ──
echo "📋 正在获取表列表...\n";
$result = $link->query("SHOW TABLE STATUS WHERE Comment!='VIEW'");
$tables = [];
while ($row = $result->fetch_assoc()) {
    $tables[] = $row['Name'];
}
$result->free();
$tableCount = count($tables);
echo "  共 {$tableCount} 个表\n";

// ── SHOW CREATE TABLE (并行获取) ──
echo "🔍 正在获取表结构...\n";
$columns = [];
$constraints = [];
$showCreate = [];
$pattern = '/(^[^`]\s*PRIMARY KEY .*[,]?$)|(^[^`]\s*KEY\s+(`.*`) .*[,]?$)|(^[^`]\s*CONSTRAINT\s+(`.*`) .*[,]?$)/m';

// 用连接池模式加速: 5 个连接平分表列表
$poolSize = min(5, $tableCount);
$chunks = array_chunk($tables, (int)ceil($tableCount / max($poolSize, 1)));
$tableDone = 0;

foreach ($chunks as $chunkIdx => $chunk) {
    $clink = new mysqli($conn->host, $conn->user, $conn->password, $conn->database, $conn->port);
    $clink->set_charset('utf8mb4');

    foreach ($chunk as $t) {
        $r = $clink->query("SHOW CREATE TABLE `{$t}`");
        if ($r instanceof mysqli_result) {
            $row = $r->fetch_assoc();
            $sql = $row['Create Table'] ?? '';
            $r->free();

            if ($sql) {
                preg_match_all('/^\s+[`]([^`]*)`.*?$/m', $sql, $kv);
                $columns[$t] = array_combine(
                    $kv[1],
                    array_map(fn($item) => trim(rtrim($item, ',')), $kv[0])
                );
                preg_match_all($pattern, $sql, $matches);
                $constraints[$t] = array_map(fn($item) => trim(rtrim($item, ',')), $matches[0]);
                $showCreate[$t] = $sql;
            }
        }
        $tableDone++;
        if ($tableDone % 50 === 0 || $tableDone === $tableCount) {
            echo "  表结构 {$tableDone}/{$tableCount}\r";
        }
    }
    $clink->close();
}
echo "\n";

ksort($columns);
ksort($constraints);
ksort($showCreate);
sort($tables);

$struct = [
    'tables'      => array_values($tables),
    'columns'     => $columns,
    'show_create' => $showCreate,
    'constraints' => $constraints,
];

// ── 高级对象 ──
echo "🔍 正在获取高级对象 (VIEW/TRIGGER/EVENT/FUNCTION/PROCEDURE)...\n";
$advanced = [];

// VIEW
echo "  VIEW...\n";
$r = $link->query("SELECT TABLE_NAME as Name FROM information_schema.VIEWS WHERE TABLE_SCHEMA='{$db}'");
$views = [];
while ($row = $r->fetch_assoc()) $views[] = $row['Name'];
$r->free();
$viewResults = [];
foreach ($views as $v) {
    $rr = $link->query("SHOW CREATE VIEW `{$v}`");
    if ($rr instanceof mysqli_result) {
        $row = $rr->fetch_assoc();
        $viewResults[$v] = preg_replace('/DEFINER=[^\s]*\s/', '', $row['Create View'] ?? '');
        $rr->free();
    }
}
$advanced['VIEW'] = $viewResults;
echo "    找到 " . count($views) . " 个\n";

// TRIGGER
echo "  TRIGGER...\n";
$r = $link->query("SELECT TRIGGER_NAME as Name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{$db}'");
$triggers = [];
while ($row = $r->fetch_assoc()) $triggers[] = $row['Name'];
$r->free();
$triggerResults = [];
foreach ($triggers as $tr) {
    $rr = $link->query("SHOW CREATE TRIGGER `{$tr}`");
    if ($rr instanceof mysqli_result) {
        $row = $rr->fetch_assoc();
        $triggerResults[$tr] = preg_replace('/DEFINER=[^\s]*\s/', '', $row['SQL Original Statement'] ?? '');
        $rr->free();
    }
}
$advanced['TRIGGER'] = $triggerResults;

// EVENT
echo "  EVENT...\n";
$r = $link->query("SELECT EVENT_NAME as Name FROM information_schema.EVENTS WHERE EVENT_SCHEMA='{$db}'");
$events = [];
while ($row = $r->fetch_assoc()) $events[] = $row['Name'];
$r->free();
$eventResults = [];
foreach ($events as $ev) {
    $rr = $link->query("SHOW CREATE EVENT `{$ev}`");
    if ($rr instanceof mysqli_result) {
        $row = $rr->fetch_assoc();
        $eventResults[$ev] = preg_replace('/DEFINER=[^\s]*\s/', '', $row['Create Event'] ?? '');
        $rr->free();
    }
}
$advanced['EVENT'] = $eventResults;

// FUNCTION
echo "  FUNCTION...\n";
$r = $link->query("SHOW FUNCTION STATUS WHERE Db='{$db}'");
$funcs = [];
while ($row = $r->fetch_assoc()) $funcs[] = $row['Name'];
$r->free();
$funcResults = [];
foreach ($funcs as $fn) {
    $rr = $link->query("SHOW CREATE FUNCTION `{$fn}`");
    if ($rr instanceof mysqli_result) {
        $row = $rr->fetch_assoc();
        $funcResults[$fn] = preg_replace('/DEFINER=[^\s]*\s/', '', $row['Create Function'] ?? '');
        $rr->free();
    }
}
$advanced['FUNCTION'] = $funcResults;

// PROCEDURE
echo "  PROCEDURE...\n";
$r = $link->query("SHOW PROCEDURE STATUS WHERE Db='{$db}'");
$procs = [];
while ($row = $r->fetch_assoc()) $procs[] = $row['Name'];
$r->free();
$procResults = [];
foreach ($procs as $pr) {
    $rr = $link->query("SHOW CREATE PROCEDURE `{$pr}`");
    if ($rr instanceof mysqli_result) {
        $row = $rr->fetch_assoc();
        $procResults[$pr] = preg_replace('/DEFINER=[^\s]*\s/', '', $row['Create Procedure'] ?? '');
        $rr->free();
    }
}
$advanced['PROCEDURE'] = $procResults;

$link->close();

$elapsed = microtime(true) - $t0;

// ══════════════════════════════════════════════════════════════════
//  组装 + 写入
// ══════════════════════════════════════════════════════════════════

$dump = [
    'version'    => 1,
    'name'       => $conn->name,
    'database'   => $conn->database,
    'host'       => $conn->host,
    'port'       => $conn->port,
    'dumped_at'  => date('c'),
    'table_count' => $tableCount,
    'struct'     => $struct,
    'advanced'   => $advanced,
];

$json = json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$ok = file_put_contents($outputFile, $json);

if ($ok === false) {
    die("写入失败: {$outputFile}\n");
}

$size = strlen($json);
$sizeStr = $size > 1024 * 1024
    ? number_format($size / 1024 / 1024, 1) . ' MB'
    : number_format($size / 1024, 1) . ' KB';

echo "\n═════════════════════════════════════════════════════════════\n";
echo "  ✅ 导出完成！\n";
echo "  耗时: " . number_format($elapsed, 1) . " 秒\n";
echo "  大小: {$sizeStr}\n";
echo "  文件: {$outputFile}\n";
echo "═════════════════════════════════════════════════════════════\n";
echo "\n";
echo "离线对比用法:\n";
echo "  php85 bin/compare-offline.php 源库dump.json 目标库dump.json\n";
echo "  (需要先创建 bin/compare-offline.php，或用 GUI 加载 dump)\n";
