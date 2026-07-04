#!/usr/bin/env php85
<?php
require __DIR__ . '/../vendor/autoload.php';

use Libui\Ffi;
use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Gui\DllBootstrap;
use MySqlSchemaSync\Gui\WebViewUI;

if (!class_exists('FFI')) {
    fwrite(STDERR, "PHP FFI 扩展未启用。请使用 PHP CLI 并启用 ext-ffi。\n");
    exit(1);
}

$restore = DllBootstrap::ensure();
if (!empty($restore['copied'])) {
    fwrite(STDOUT, "已恢复缺失的原生库: " . implode(', ', $restore['copied']) . "\n");
}
if (!empty($restore['missing'])) {
    fwrite(STDERR, "警告：以下原生库缺失且 bridge/ 中无备份: " . implode(', ', $restore['missing']) . "\n");
}

$origStdout = fopen('php://stdout', 'w');
stream_filter_register('stdout_capture', StdoutCaptureFilter::class);
stream_filter_append($origStdout, 'stdout_capture');

try {
    Ffi::init();
    $store = new ConfigStore();
    $app = new WebViewUI($store);
    $app->run();
} catch (\Throwable $e) {
    fwrite(STDERR, "启动失败：" . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

class StdoutCaptureFilter extends php_user_filter
{
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $line = rtrim($bucket->data, "\n");
            if ($line !== '') {
                WebViewUI::captureStdout($line);
            }
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
