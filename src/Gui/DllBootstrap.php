<?php
declare(strict_types=1);

namespace MySqlSchemaSync\Gui;

/**
 * Auto-restore WebView native DLLs from project bridge/ backup.
 *
 * After `composer update` or `git clean`, vendor DLLs may be wiped.
 * This class checks for missing DLLs and copies them from the project's
 * bridge/ backup directory.
 */
final class DllBootstrap
{
    private static string $projectRoot;
    private static string $bridgeDir;

    /**
     * Run the check and restore any missing native libraries.
     *
     * @return array{copied: list<string>, missing: list<string>}
     */
    public static function ensure(): array
    {
        self::$projectRoot = dirname(__DIR__, 2); // src/Gui → project root
        self::$bridgeDir   = self::$projectRoot . '/bridge';

        $copied = [];
        $missing = [];

        $map = self::getMapping();

        foreach ($map as $vendorPath => $bridgeFile) {
            $fullVendorPath = self::$projectRoot . '/' . $vendorPath;

            if (\file_exists($fullVendorPath)) {
                continue;
            }

            $srcPath = self::$bridgeDir . '/' . $bridgeFile;

            if (!\file_exists($srcPath)) {
                $missing[] = $bridgeFile;
                continue;
            }

            // Ensure target directory exists
            $dir = \dirname($fullVendorPath);
            if (!\is_dir($dir)) {
                \mkdir($dir, 0755, true);
            }

            if (\copy($srcPath, $fullVendorPath)) {
                $copied[] = $bridgeFile;
            } else {
                $missing[] = $bridgeFile;
            }
        }

        return ['copied' => $copied, 'missing' => $missing];
    }

    /**
     * Platform-specific mapping: vendor relative path → bridge/ backup filename.
     */
    private static function getMapping(): array
    {
        $osFamily = \PHP_OS_FAMILY;

        $map = [];

        // ── WebView bridge DLLs (yangweijie/ui2) ──
        $map['vendor/yangweijie/ui2/bridge/webview_bridge.' . self::libExt()] = 'webview_bridge.' . self::libExt();
        $map['vendor/yangweijie/ui2/bridge/hotkey.' . self::libExt()]        = 'hotkey.' . self::libExt();
        $map['vendor/yangweijie/ui2/bridge/context_menu.' . self::libExt()]  = 'context_menu.' . self::libExt();

        // ── PebView native library (kingbes/pebview) ──
        match ($osFamily) {
            'Windows' => $map['vendor/kingbes/pebview/lib/windows/PebView.dll'] = 'PebView.dll',
            'Darwin'  => $map['vendor/kingbes/pebview/lib/macos/arm64/PebView.dylib'] = 'PebView.dylib',
            'Linux'   => $map['vendor/kingbes/pebview/lib/linux/x86_64/libPebView.so'] = 'libPebView.so',
            default   => null,
        };

        // ── libui native library (helgesverre/libui) ──
        match ($osFamily) {
            'Windows' => $map['vendor/helgesverre/libui/lib/windows-x86_64/libui.dll'] = 'libui.dll',
            'Darwin'  => $map['vendor/helgesverre/libui/lib/darwin/libui.dylib'] = 'libui.dylib',
            'Linux'   => $map['vendor/helgesverre/libui/lib/linux-x86_64/libui.so'] = 'libui.so',
            default   => null,
        };

        return $map;
    }

    private static function libExt(): string
    {
        return match (\PHP_OS_FAMILY) {
            'Windows' => 'dll',
            'Darwin'  => 'dylib',
            'Linux'   => 'so',
            default   => '',
        };
    }
}
