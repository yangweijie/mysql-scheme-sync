#!/usr/bin/env php85
<?php
/**
 * bin/compare-offline.php
 *
 * 离线对比两个数据库结构 dump 文件（无需连数据库）。
 * 对 dump-schema.php 导出的 JSON 进行对比，输出差异 SQL。
 *
 * Usage:
 *   php85 bin/compare-offline.php 源库_dump.json 目标库_dump.json
 *
 * 示例:
 *   php85 bin/dump-schema.php 阿里云 -o cloud_schema.json    # 导出云端结构
 *   php85 bin/dump-schema.php 本地                          # 导出本地结构
 *   php85 bin/compare-offline.php cloud_schema.json localhost_schema.json
 */

require __DIR__ . '/../vendor/autoload.php';

use MySqlSchemaSync\Config\Connection;
use MySqlSchemaSync\Diff\StructSyncAdapter;

// ══════════════════════════════════════════════════════════════════
//  解析参数
// ══════════════════════════════════════════════════════════════════

if ($argc < 3) {
    echo "用法: php85 bin/compare-offline.php <源库_dump.json> <目标库_dump.json>\n\n";
    echo "  第一步: 先用 dump-schema.php 导出两个库的结构\n";
    echo "    php85 bin/dump-schema.php 阿里云 -o cloud_schema.json\n";
    echo "    php85 bin/dump-schema.php 本地 -o local_schema.json\n\n";
    echo "  第二步: 离线对比\n";
    echo "    php85 bin/compare-offline.php cloud_schema.json local_schema.json\n";
    exit(1);
}

$srcPath = $argv[1];
$tgtPath = $argv[2];

if (!file_exists($srcPath)) die("源库 dump 文件不存在: {$srcPath}\n");
if (!file_exists($tgtPath)) die("目标库 dump 文件不存在: {$tgtPath}\n");

// ══════════════════════════════════════════════════════════════════
//  加载 dump
// ══════════════════════════════════════════════════════════════════

echo "加载源库 dump...\n";
$srcData = json_decode(file_get_contents($srcPath), true);
echo "  名称: {$srcData['name']}\n";
echo "  表数: {$srcData['table_count']}\n";

echo "加载目标库 dump...\n";
$tgtData = json_decode(file_get_contents($tgtPath), true);
echo "  名称: {$tgtData['name']}\n";
echo "  表数: {$tgtData['table_count']}\n";

// ══════════════════════════════════════════════════════════════════
//  建立对比（用虚拟 Connection 对象）
// ══════════════════════════════════════════════════════════════════

echo "\n离线对比中...\n";
$t0 = microtime(true);

$dummySrc = Connection::fromArray([
    'name'     => $srcData['name'] ?? 'source',
    'host'     => $srcData['host'] ?? 'offline',
    'port'     => $srcData['port'] ?? 3306,
    'user'     => '',
    'password' => '',
    'database' => $srcData['database'] ?? '',
]);

$dummyTgt = Connection::fromArray([
    'name'     => $tgtData['name'] ?? 'target',
    'host'     => $tgtData['host'] ?? 'offline',
    'port'     => $tgtData['port'] ?? 3306,
    'user'     => '',
    'password' => '',
    'database' => $tgtData['database'] ?? '',
]);

$adapter = new StructSyncAdapter($dummySrc, $dummyTgt);
$adapter->setFetchedStructs($srcData['struct'], $tgtData['struct']);
$adapter->setPrefetchedAdvance(
    $srcData['advanced'] ?? [],
    $tgtData['advanced'] ?? []
);

$diffResult = $adapter->compare();
$diffSql = $adapter->getDiffSql();
$elapsed = microtime(true) - $t0;

// ══════════════════════════════════════════════════════════════════
//  输出结果
// ══════════════════════════════════════════════════════════════════

echo "\n";
echo "═════════════════════════════════════════════════════════════\n";
echo "  对比结果\n";
echo "  耗时: " . number_format($elapsed * 1000, 0) . " ms\n";
echo "─────────────────────────────────────────────────────────────\n";

$anyDiff = false;

// 差异类型中文映射
$labels = [
    'ADD_TABLE'             => '新增表',
    'DROP_TABLE'            => '删除表',
    'MODIFY_FIELD'          => '修改字段',
    'ADD_FIELD'             => '新增字段',
    'DROP_FIELD'            => '删除字段',
    'ADD_CONSTRAINT'        => '新增约束',
    'DROP_CONSTRAINT'       => '删除约束',
    'ADD_VIEW'              => '新增视图',
    'DROP_VIEW'             => '删除视图',
    'MODIFY_VIEW'           => '修改视图',
    'ADD_TRIGGER'           => '新增触发器',
    'DROP_TRIGGER'          => '删除触发器',
    'MODIFY_TRIGGER'        => '修改触发器',
    'ADD_EVENT'             => '新增事件',
    'DROP_EVENT'            => '删除事件',
    'MODIFY_EVENT'          => '修改事件',
    'ADD_FUNCTION'          => '新增函数',
    'DROP_FUNCTION'         => '删除函数',
    'MODIFY_FUNCTION'       => '修改函数',
    'ADD_PROCEDURE'         => '新增存储过程',
    'DROP_PROCEDURE'        => '删除存储过程',
    'MODIFY_PROCEDURE'      => '修改存储过程',
];

foreach ($diffSql as $type => $sqls) {
    if (empty($sqls)) continue;
    $label = $labels[$type] ?? $type;
    $count = count($sqls);
    echo "  {$label}: {$count} 项\n";
    $anyDiff = true;
}

if (!$anyDiff) {
    echo "  ✅ 两个数据库结构完全一致！\n";
} else {
    echo "─────────────────────────────────────────────────────────────\n";
    echo "  是否输出详细 SQL? [y/N]: ";
    $showSql = strtolower(trim(fgets(STDIN))) === 'y';

    if ($showSql) {
        echo "\n";
        foreach ($diffSql as $type => $sqls) {
            if (empty($sqls)) continue;
            $label = $labels[$type] ?? $type;
            echo "━━━ {$label} ━━━\n";
            foreach ($sqls as $sql) {
                echo "  {$sql};\n";
            }
            echo "\n";
        }
    }
}

echo "═════════════════════════════════════════════════════════════\n";
