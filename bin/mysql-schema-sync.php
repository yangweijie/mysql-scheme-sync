#!/usr/bin/env php85
<?php
// bin/mysql-schema-sync.php
// Entry point for MySQL SchemaSync PHP GUI edition.

require __DIR__ . '/../vendor/autoload.php';

use Libui\Ffi;
use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Gui\MainWindow;

if (!class_exists('FFI')) {
    fwrite(STDERR, "PHP FFI 扩展未启用。请使用 PHP CLI 并启用 ext-ffi。\n");
    exit(1);
}

try {
    Ffi::init();
    $store = new ConfigStore();
    $app = new MainWindow($store);
    $app->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "启动失败：" . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
