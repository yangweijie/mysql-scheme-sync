<?php
// src/Gui/WebViewUI.php
// Navicat-inspired WebView UI for MySQL SchemaSync

namespace MySqlSchemaSync\Gui;

use Libui\Window;
use Libui\Box;
use Libui\Loop;
use Yangweijie\Ui2\WebView;
use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Config\Connection;
use MySqlSchemaSync\Diff\StructSyncAdapter;
use MySqlSchemaSync\Diff\DiffResult;
use MySqlSchemaSync\Diff\AsyncCompareRunner;
use MySqlSchemaSync\SqlGen\Generator;

class WebViewUI
{
    private ConfigStore $store;
    private Window $window;
    private ?WebView $webview = null;

    // Compare state
    private ?StructSyncAdapter $adapter = null;
    private ?DiffResult $lastDiff = null;
    private ?array $lastDiffSql = null;
    private string $lastSrcId = "";
    private string $lastTgtId = "";

    // Step-by-step compare state
    private ?AsyncCompareRunner $currentRunner = null;
    private string $comparePhase = "idle";
    private string $compareError = "";

    // Stdout capture
    private static array $stdoutBuffer = [];
    private static int $stdoutOffset = 0;

    // Cached settings
    private string $excludePatterns = "";
    private array $compareScope = [];

    public function __construct(ConfigStore $store)
    {
        $this->store = $store;
        $this->excludePatterns = $store->getSetting(
            "excludePatterns",
            "*_bak, *_backup*, tmp_*",
        );
        $savedScope = $store->getSetting("compareScope", null);
        $this->compareScope = $savedScope ?? [
            "tables",
            "views",
            "functions",
            "procedures",
            "foreign_keys",
            "triggers",
            "events",
        ];
    }

    public function run(): void
    {
        self::debugLog("MySQL SchemaSync starting...");
        self::debugLog(
            "PHP " . PHP_VERSION . " | " . PHP_OS . " | " . php_sapi_name(),
        );
        self::debugLog("Debug output: ENABLED");

        $this->window = new Window("MySQL SchemaSync — WebView", 1100, 750);
        $this->window->setMargined(false)->setResizeable(true)->centered();

        $assetsDir = \dirname(__DIR__, 2) . "/src/Gui/assets";
        $iconPath =
            PHP_OS_FAMILY === "Windows"
                ? $assetsDir . "/icon.ico"
                : $assetsDir . "/icon.png";

        // Set icon before window is shown.
        if (\file_exists($iconPath)) {
            $this->window->setWindowIcon($iconPath);
            if (PHP_OS_FAMILY === "Windows") {
                $this->setWindowIcons($iconPath);
            }
        }

        // Minimal Box child (libui needs at least one control)
        $box = new Box();
        $this->window->setChild($box);

        // WebView fills entire content area
        [$cw, $ch] = $this->window->getContentSize();
        $this->webview = new WebView(
            $this->window,
            0,
            0,
            max(400, $cw),
            max(300, $ch),
            true,
        );
        $this->webview->autoResize($this->window, 0, 0);

        $this->registerBridgeHandlers();
        self::debugLog("Bridge handlers registered");
        $this->webview->setHtml($this->getAppHtml());
        self::debugLog("WebView HTML loaded");

        // Windows: re-apply icons after WebView2 settles.
        // setTaskbarIcon() handles both ICON_SMALL + ICON_BIG
        // via ExtractIconExW (proper multi-resolution ICO extraction).
        if (PHP_OS_FAMILY === "Windows" && \file_exists($iconPath)) {
            Loop::delay(500, function () use ($iconPath) {
                self::debugLog("setWinIcons (apply, delay 500ms)");
                try {
                    $this->setWindowIcons($iconPath);
                    self::debugLog("setWinIcons: done");
                } catch (\Throwable $e) {
                    self::debugLog("setWinIcons error: " . $e->getMessage());
                }
            });
        }

        $this->window->run();
    }

    // ═══════════════════════════════════════════════════════════
    //  Debug Output
    // ═══════════════════════════════════════════════════════════

    private static bool $debugEnabled = true;

    private static function debugLog(string $msg): void
    {
        if (!self::$debugEnabled) {
            return;
        }
        $ts =
            date("H:i:s.") .
            str_pad(
                (int) (microtime(true) * 1000) % 1000,
                3,
                "0",
                STR_PAD_LEFT,
            );
        $line = "[{$ts}] [DEBUG] {$msg}";
        fwrite(STDOUT, $line . "\n");
        self::$stdoutBuffer[] = $line;
    }

    private static function debugLogException(
        \Throwable $e,
        string $context = "",
    ): void {
        $prefix = $context ? "[{$context}] " : "";
        self::debugLog("{$prefix}EXCEPTION: " . $e->getMessage());
        self::debugLog("  File: " . $e->getFile() . ":" . $e->getLine());
        foreach (explode("\n", $e->getTraceAsString()) as $line) {
            self::debugLog("  {$line}");
        }
        if ($e->getPrevious()) {
            self::debugLog("  Previous: " . $e->getPrevious()->getMessage());
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  Windows Taskbar Icon (ICON_SMALL)
    //  PebView's set_icon() only sends WM_SETICON+ICON_BIG.
    //  Taskbar needs ICON_SMALL via a separate SendMessage.
    // ═══════════════════════════════════════════════════════════

    /**
     * Set window icons (title bar, taskbar, Alt+Tab).
     *
     * On Windows the taskbar icon comes from the *window class* small icon
     * (GCLP_HICONSM / nIndex=-34 via SetClassLongPtrW), NOT from WM_SETICON.
     *
     * WM_SETICON+ICON_BIG controls Alt+Tab; WM_SETICON+ICON_SMALL controls the
     * title bar.  We set all three to cover every surface.
     */
    private function setWindowIcons(string $iconPath): void
    {
        static $u32 = null;
        static $s32 = null;
        if ($u32 === null) {
            $u32 = \FFI::cdef(
                "void* SendMessageW(void* hWnd, unsigned int Msg, void* wParam, void* lParam);" .
                    "void* SetClassLongPtrW(void* hWnd, int nIndex, void* dwNewLong);" .
                    "int DestroyIcon(void* hIcon);",
                "user32.dll",
            );
            $s32 = \FFI::cdef(
                "unsigned int ExtractIconExW(void* lpszFile, int nIconIndex, void** phiconLarge, void** phiconSmall, unsigned int nIcons);",
                "shell32.dll",
            );
        }

        try {
            $libui = \Libui\Ffi::get();
            $hwnd = \FFI::cast(
                "void*",
                $libui->uiControlHandle($this->window->asControl()),
            );
        } catch (\Throwable $e) {
            self::debugLog(
                "setWindowIcons: uiControlHandle failed: " . $e->getMessage(),
            );
            return;
        }

        // Convert path to wchar_t*
        $wide = \mb_convert_encoding($iconPath . "\0", "UTF-16LE");
        $pathPtr = \FFI::new("uint16_t[" . ((int) (\strlen($wide) / 2)) . "]");
        \FFI::memcpy($pathPtr, $wide, \strlen($wide));

        // ExtractIconExW extracts large+small icons with proper resolution matching.
        $phLarge = \FFI::new("void*[1]");
        $phSmall = \FFI::new("void*[1]");
        $n = $s32->ExtractIconExW($pathPtr, 0, $phLarge, $phSmall, 1);
        if ($n === 0) {
            self::debugLog("setWindowIcons: ExtractIconExW returned 0");
            return;
        }

        // 1. Set CLASS small icon → taskbar (GCLP_HICONSM = -34)
        if (!\FFI::isNull($phSmall[0])) {
            $u32->SetClassLongPtrW($hwnd, -34, $phSmall[0]);
            self::debugLog("setWindowIcons: GCLP_HICONSM (-34) set → taskbar");
        }

        // 2. Set CLASS large icon → Alt+Tab fallback (GCLP_HICON = -14)
        if (!\FFI::isNull($phLarge[0])) {
            $u32->SetClassLongPtrW($hwnd, -14, $phLarge[0]);
            self::debugLog("setWindowIcons: GCLP_HICON (-14) set");
        }

        // 3. WM_SETICON: ICON_SMALL → title bar, ICON_BIG → Alt+Tab
        if (!\FFI::isNull($phSmall[0])) {
            // WM_SETICON=0x0080, ICON_SMALL=null (0)
            $u32->SendMessageW($hwnd, 0x0080, null, $phSmall[0]);
        }
        if (!\FFI::isNull($phLarge[0])) {
            $u32->SendMessageW(
                $hwnd,
                0x0080,
                \FFI::cast("void*", 1),
                $phLarge[0],
            );
            self::debugLog("setWindowIcons: WM_SETICON done");
        }
    }

    public static function captureStdout(string $line): void
    {
        self::$stdoutBuffer[] = $line;
    }

    public static function getStdoutLines(): array
    {
        $lines = array_slice(self::$stdoutBuffer, self::$stdoutOffset);
        self::$stdoutOffset = count(self::$stdoutBuffer);
        return $lines;
    }

    // ═══════════════════════════════════════════════════════════
    //  Bridge Handlers (PHP ↔ JS)
    // ═══════════════════════════════════════════════════════════

    private function registerBridgeHandlers(): void
    {
        $wv = $this->webview;

        // ─── Connection Management ─────────────────────────────────

        $wv->bind("getConnections", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $list = [];
                foreach ($this->store->list() as $c) {
                    $list[] = [
                        "id" => $c->id,
                        "name" => $c->name,
                        "host" => $c->host,
                        "port" => $c->port,
                        "user" => $c->user,
                        "password" => $c->password,
                        "database" => $c->database,
                    ];
                }
                $wv->return($id, 0, json_encode($list));
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        $wv->bind("saveConnection", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $data = is_array($params) ? $params[0] ?? $params : [];
                $connId = $data["id"] ?? bin2hex(random_bytes(8));
                $conn = new Connection(
                    id: $connId,
                    name: trim($data["name"] ?? ""),
                    host: trim($data["host"] ?? ""),
                    port: (int) ($data["port"] ?? 3306),
                    user: trim($data["user"] ?? ""),
                    password: $data["password"] ?? "",
                    database: trim($data["database"] ?? ""),
                );
                $this->store->add($conn);
                $wv->return($id, 0, json_encode(["id" => $connId]));
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        $wv->bind("deleteConnection", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $connId = is_array($params) ? $params[0] ?? "" : "";
                $this->store->remove($connId);
                $wv->return($id, 0, json_encode(["ok" => true]));
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        $wv->bind("testConnection", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $data = is_array($params) ? $params[0] ?? $params : [];
                $conn = new Connection(
                    id: "",
                    name: "",
                    host: trim($data["host"] ?? ""),
                    port: (int) ($data["port"] ?? 3306),
                    user: trim($data["user"] ?? ""),
                    password: $data["password"] ?? "",
                    database: trim($data["database"] ?? ""),
                );
                $result = $this->store->test($conn);
                $wv->return($id, 0, json_encode($result));
            } catch (\Throwable $e) {
                $wv->return(
                    $id,
                    0,
                    json_encode(["ok" => false, "error" => $e->getMessage()]),
                );
            }
        });

        // ─── Quick Compare Configs ────────────────────────────────

        $wv->bind("getQuickCompares", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $wv->return(
                    $id,
                    0,
                    json_encode($this->store->listQuickCompares()),
                );
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        $wv->bind("saveQuickCompare", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $data = is_array($params) ? $params[0] ?? $params : [];
                $name = trim($data["name"] ?? "");
                $srcId = $data["srcId"] ?? "";
                $tgtId = $data["tgtId"] ?? "";
                if (!$name || !$srcId || !$tgtId) {
                    $wv->return(
                        $id,
                        1,
                        json_encode([
                            "error" => "请填写名称并选择源库和目标库",
                        ]),
                    );
                    return;
                }
                $newId = $this->store->saveQuickCompare($name, $srcId, $tgtId);
                $wv->return(
                    $id,
                    0,
                    json_encode(["ok" => true, "id" => $newId]),
                );
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        $wv->bind("deleteQuickCompare", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $qcId = is_array($params) ? $params[0] ?? "" : "";
                $ok = $this->store->deleteQuickCompare($qcId);
                $wv->return($id, 0, json_encode(["ok" => $ok]));
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        // ─── Settings ──────────────────────────────────────────────

        $wv->bind("getSettings", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $wv->return(
                    $id,
                    0,
                    json_encode([
                        "excludePatterns" => $this->excludePatterns,
                        "compareScope" => $this->compareScope,
                    ]),
                );
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        $wv->bind("saveSettings", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $data = is_array($params) ? $params[0] ?? $params : [];
                $patterns = $data["excludePatterns"] ?? $this->excludePatterns;
                $scope = $data["compareScope"] ?? $this->compareScope;

                $this->excludePatterns = $patterns;
                $this->compareScope = $scope;

                $this->store->setSetting("excludePatterns", $patterns);
                $this->store->setSetting("compareScope", $scope);
                $wv->return($id, 0, json_encode(["ok" => true]));
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        // ─── Compare (step-by-step, non-blocking) ──────────────────

        $wv->bind("compare", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $data = is_array($params) ? $params[0] ?? $params : [];

                $srcId = $data["srcId"] ?? "";
                $tgtId = $data["tgtId"] ?? "";
                $patterns = $data["excludePatterns"] ?? $this->excludePatterns;
                $scope = $data["compareScope"] ?? $this->compareScope;

                self::debugLog(
                    "compare: src={$srcId} tgt={$tgtId} patterns={$patterns}",
                );
                self::debugLog("compare: scope=" . json_encode($scope));

                if (!$srcId || !$tgtId) {
                    $wv->return(
                        $id,
                        1,
                        json_encode(["error" => "请选择源库和目标库"]),
                    );
                    return;
                }
                if ($srcId === $tgtId) {
                    $wv->return(
                        $id,
                        1,
                        json_encode(["error" => "源库和目标库不能相同"]),
                    );
                    return;
                }

                $src = $this->store->get($srcId);
                $tgt = $this->store->get($tgtId);
                if (!$src || !$tgt) {
                    self::debugLog(
                        "compare: ERROR — 连接不存在 src=" .
                            ($src ? "ok" : "null") .
                            " tgt=" .
                            ($tgt ? "ok" : "null"),
                    );
                    $wv->return($id, 1, json_encode(["error" => "连接不存在"]));
                    return;
                }

                self::debugLog(
                    "compare: src={$src->host}:{$src->port}/{$src->database}",
                );
                self::debugLog(
                    "compare: tgt={$tgt->host}:{$tgt->port}/{$tgt->database}",
                );

                // Parse exclude patterns
                $pats = [];
                if (is_string($patterns) && trim($patterns) !== "") {
                    $pats = array_map("trim", explode(",", $patterns));
                } elseif (is_array($patterns)) {
                    $pats = $patterns;
                }

                // Save settings
                if (is_string($patterns)) {
                    $this->excludePatterns = $patterns;
                    $this->store->setSetting("excludePatterns", $patterns);
                }
                $this->compareScope = $scope;
                $this->store->setSetting("compareScope", $scope);

                // Return immediately to unblock WebView2 event processing
                $wv->return($id, 0, json_encode(["status" => "started"]));
                self::debugLog("compare: 返回 started, 启动 step-by-step");

                // Start step-by-step compare asynchronously
                $this->lastSrcId = $srcId;
                $this->lastTgtId = $tgtId;
                $this->comparePhase = "running";
                $this->compareError = "";

                $runner = new AsyncCompareRunner();
                $this->currentRunner = $runner;

                $runner->setOnPhase(function (string $phase): void {
                    self::debugLog("compare phase: {$phase}");
                    $this->comparePhase = $phase;
                });
                $runner->setOnProgress(function (int $pct, string $msg): void {
                    self::debugLog("compare progress: {$pct}% {$msg}");
                });
                $runner->setOnComplete(function (
                    ?DiffResult $result,
                    array $diffSql,
                ) use ($srcId, $tgtId): void {
                    if ($result && !$result->error) {
                        self::debugLog(
                            "compare done: " . count($diffSql) . " diff items",
                        );
                        $this->lastDiff = $result;
                        $this->lastDiffSql = $diffSql;
                        $this->adapter = $this->currentRunner?->getLastAdapter();
                        $this->lastSrcId = $srcId;
                        $this->lastTgtId = $tgtId;
                        $this->comparePhase = "done";
                        $this->compareError = "";
                    } else {
                        $errMsg = $result?->error ?? "比对返回空结果";
                        self::debugLog("compare ERROR: {$errMsg}");
                        $this->comparePhase = "error";
                        $this->compareError = $errMsg;
                    }
                    $this->currentRunner = null;
                });

                $runner->startStepByStep(new Loop(), $src, $tgt, $pats, $scope);
            } catch (\Throwable $e) {
                self::debugLogException($e, "compare");
                $this->comparePhase = "error";
                $this->compareError = $e->getMessage();
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        // ─── Poll Compare Result ──────────────────────────────────

        $wv->bind("getCompareResult", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                if ($this->comparePhase === "done") {
                    $result = $this->lastDiff;
                    if ($result) {
                        $data = $this->diffResultToArray($result);
                        $data["phase"] = "done";
                        $wv->return($id, 0, json_encode($data));
                    } else {
                        $wv->return(
                            $id,
                            0,
                            json_encode([
                                "phase" => "done",
                                "total" => 0,
                                "items" => [],
                            ]),
                        );
                    }
                } elseif ($this->comparePhase === "error") {
                    $wv->return(
                        $id,
                        0,
                        json_encode([
                            "phase" => "error",
                            "error" => $this->compareError ?: "比对失败",
                        ]),
                    );
                } else {
                    $wv->return(
                        $id,
                        0,
                        json_encode(["phase" => $this->comparePhase]),
                    );
                }
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        // ─── Cancel Compare ──────────────────────────────────────────

        $wv->bind("cancelCompare", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                if ($this->currentRunner) {
                    $this->currentRunner->cancel();
                    self::debugLog("compare: 用户取消比对");
                }
                $this->comparePhase = "idle";
                $this->currentRunner = null;
                $wv->return($id, 0, json_encode(["status" => "cancelled"]));
            } catch (\Throwable $e) {
                $wv->return($id, 1, json_encode(["error" => $e->getMessage()]));
            }
        });

        // ─── Generate SQL ──────────────────────────────────────────

        $wv->bind("generateSql", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $selected = is_array($params) ? $params[0] ?? [] : [];

                if (!$this->lastDiff) {
                    $wv->return(
                        $id,
                        1,
                        json_encode(["error" => "没有比对结果，请先执行比对"]),
                    );
                    return;
                }

                $filtered = new DiffResult();
                $src = null;
                $tgt = null;

                foreach ($selected as $item) {
                    $type = $item["type"] ?? "";
                    $name = $item["name"] ?? "";

                    if ($type === "新增表") {
                        foreach ($this->lastDiff->newTables as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newTables[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除表") {
                        foreach ($this->lastDiff->removedTables as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedTables[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "变更表") {
                        foreach ($this->lastDiff->changedTables as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->changedTables[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增索引") {
                        foreach ($this->lastDiff->newIndexes as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newIndexes[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除索引") {
                        foreach ($this->lastDiff->removedIndexes as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedIndexes[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增外键") {
                        foreach ($this->lastDiff->newForeignKeys as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newForeignKeys[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除外键") {
                        foreach ($this->lastDiff->removedForeignKeys as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedForeignKeys[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增触发器") {
                        foreach ($this->lastDiff->newTriggers as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newTriggers[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除触发器") {
                        foreach ($this->lastDiff->removedTriggers as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedTriggers[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "变更触发器") {
                        foreach ($this->lastDiff->changedTriggers as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->changedTriggers[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增视图") {
                        foreach ($this->lastDiff->newViews as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newViews[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除视图") {
                        foreach ($this->lastDiff->removedViews as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedViews[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "变更视图") {
                        foreach ($this->lastDiff->changedViews as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->changedViews[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增存储过程") {
                        foreach ($this->lastDiff->newProcedures as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newProcedures[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除存储过程") {
                        foreach ($this->lastDiff->removedProcedures as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedProcedures[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "变更存储过程") {
                        foreach ($this->lastDiff->changedProcedures as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->changedProcedures[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增函数") {
                        foreach ($this->lastDiff->newFunctions as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newFunctions[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除函数") {
                        foreach ($this->lastDiff->removedFunctions as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedFunctions[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "变更函数") {
                        foreach ($this->lastDiff->changedFunctions as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->changedFunctions[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "新增事件") {
                        foreach ($this->lastDiff->newEvents as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->newEvents[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "删除事件") {
                        foreach ($this->lastDiff->removedEvents as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->removedEvents[] = $t;
                                break;
                            }
                        }
                    } elseif ($type === "变更事件") {
                        foreach ($this->lastDiff->changedEvents as $t) {
                            if (($t["name"] ?? "") === $name) {
                                $filtered->changedEvents[] = $t;
                                break;
                            }
                        }
                    }
                }

                // Get src/tgt connections (from adapter or fallback to stored IDs)
                if ($this->adapter) {
                    $src = $this->adapter->getSource();
                    $tgt = $this->adapter->getTarget();
                } else {
                    $src = $this->lastSrcId
                        ? $this->store->get($this->lastSrcId)
                        : null;
                    $tgt = $this->lastTgtId
                        ? $this->store->get($this->lastTgtId)
                        : null;
                }

                if (!$src || !$tgt) {
                    $wv->return(
                        $id,
                        1,
                        json_encode(["error" => "缺少源库/目标库信息"]),
                    );
                    return;
                }

                $structuredDiffs = $this->adapter
                    ? $this->adapter->getStructuredDiffs()
                    : [];
                $gen = new Generator(
                    $src,
                    $tgt,
                    $this->adapter,
                    $this->lastDiffSql ?? [],
                    $structuredDiffs,
                );
                $sql = $gen->generate($filtered);

                $wv->return($id, 0, json_encode(["sql" => $sql]));
            } catch (\Throwable $e) {
                $wv->return(
                    $id,
                    1,
                    json_encode([
                        "error" => "生成 SQL 失败: " . $e->getMessage(),
                    ]),
                );
            }
        });

        // ─── Clipboard ─────────────────────────────────────────────

        $wv->bind("copyToClipboard", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $text = is_array($params) ? $params[0] ?? "" : "";

                $ok = false;
                if (DIRECTORY_SEPARATOR === "\\") {
                    $tmp = tempnam(sys_get_temp_dir(), "mss_");
                    file_put_contents($tmp, $text);
                    shell_exec('clip < "' . $tmp . '"');
                    @unlink($tmp);
                    $ok = true;
                } elseif (function_exists("shell_exec")) {
                    $escaped = str_replace("'", "'\\''", $text);
                    shell_exec("echo '{$escaped}' | pbcopy");
                    $ok = true;
                }

                $wv->return($id, 0, json_encode(["ok" => $ok]));
            } catch (\Throwable $e) {
                $wv->return(
                    $id,
                    0,
                    json_encode(["ok" => false, "error" => $e->getMessage()]),
                );
            }
        });

        // ─── Save SQL File ──────────────────────────────────────────

        $wv->bind("saveSqlFile", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $content = is_array($params) ? $params[0] ?? "" : "";

                $home = $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? getenv('HOME') ?: getenv('USERPROFILE');
                $dir = $home . '/.mysql-schema-sync/dumps';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }

                $filename = "migration_" . date("Y-m-d") . ".sql";
                $path = $dir . "/" . $filename;
                file_put_contents($path, $content);

                $wv->return($id, 0, json_encode([
                    "ok" => true,
                    "path" => $path,
                    "filename" => $filename,
                ]));
            } catch (\Throwable $e) {
                $wv->return(
                    $id,
                    0,
                    json_encode(["ok" => false, "error" => $e->getMessage()]),
                );
            }
        });

        // ─── Open Directory ───────────────────────────────────────

        $wv->bind("openDirectory", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $params = json_decode($req, true);
                $path = is_array($params) ? $params[0] ?? "" : "";
                $dir = $path !== "" ? dirname($path) : "";
                if ($dir !== "" && is_dir($dir)) {
                    if (DIRECTORY_SEPARATOR === "\\") {
                        shell_exec("explorer " . \escapeshellarg($dir));
                    } else {
                        shell_exec("open " . \escapeshellarg($dir));
                    }
                }
                $wv->return($id, 0, json_encode(["ok" => true]));
            } catch (\Throwable $e) {
                $wv->return(
                    $id,
                    0,
                    json_encode(["ok" => false, "error" => $e->getMessage()]),
                );
            }
        });

        // ─── Stdout Capture ───────────────────────────────────────

        $wv->bind("getStdout", function (string $id, string $req) use (
            $wv,
        ): void {
            try {
                $lines = self::getStdoutLines();
                $wv->return($id, 0, json_encode(["lines" => $lines]));
            } catch (\Throwable $e) {
                $wv->return(
                    $id,
                    0,
                    json_encode(["lines" => [], "error" => $e->getMessage()]),
                );
            }
        });

        $wv->bind("quitApp", function (string $id, string $req) use ($wv): void {
            $wv->return($id, 0, json_encode(["ok" => true]));
            \Libui\Ffi::quit();
        });
    }

    // ═══════════════════════════════════════════════════════════
    //  DiffResult Json Conversion
    // ═══════════════════════════════════════════════════════════

    private function diffResultToArray(?DiffResult $result): array
    {
        if (!$result) {
            return ["total" => 0, "items" => []];
        }

        $items = [];
        $typeMap = [
            "newTables" => ["label" => "新增表", "risk" => "SAFE"],
            "removedTables" => ["label" => "删除表", "risk" => "HIGH"],
            "changedTables" => ["label" => "变更表", "risk" => "WARN"],
            "newIndexes" => ["label" => "新增索引", "risk" => "SAFE"],
            "removedIndexes" => ["label" => "删除索引", "risk" => "WARN"],
            "newForeignKeys" => ["label" => "新增外键", "risk" => "SAFE"],
            "removedForeignKeys" => ["label" => "删除外键", "risk" => "WARN"],
            "newTriggers" => ["label" => "新增触发器", "risk" => "SAFE"],
            "removedTriggers" => ["label" => "删除触发器", "risk" => "WARN"],
            "changedTriggers" => ["label" => "变更触发器", "risk" => "WARN"],
            "newViews" => ["label" => "新增视图", "risk" => "SAFE"],
            "removedViews" => ["label" => "删除视图", "risk" => "HIGH"],
            "changedViews" => ["label" => "变更视图", "risk" => "WARN"],
            "newProcedures" => ["label" => "新增存储过程", "risk" => "SAFE"],
            "removedProcedures" => [
                "label" => "删除存储过程",
                "risk" => "WARN",
            ],
            "changedProcedures" => [
                "label" => "变更存储过程",
                "risk" => "WARN",
            ],
            "newFunctions" => ["label" => "新增函数", "risk" => "SAFE"],
            "removedFunctions" => ["label" => "删除函数", "risk" => "WARN"],
            "changedFunctions" => ["label" => "变更函数", "risk" => "WARN"],
            "newEvents" => ["label" => "新增事件", "risk" => "SAFE"],
            "removedEvents" => ["label" => "删除事件", "risk" => "WARN"],
            "changedEvents" => ["label" => "变更事件", "risk" => "WARN"],
        ];

        foreach ($typeMap as $prop => $info) {
            $entries = $result->$prop ?? [];
            foreach ($entries as $entry) {
                $name = $entry["name"] ?? "";
                $risk = $entry["risk"] ?? $info["risk"];
                $detail = $entry["detail"] ?? "";
                $items[] = [
                    "type" => $info["label"],
                    "name" => $name,
                    "risk" => $risk,
                    "detail" => $detail,
                    "checked" => true,
                ];
            }
        }

        // Sort: HIGH first, then WARN, then SAFE
        $riskOrder = ["HIGH" => 0, "WARN" => 1, "SAFE" => 2];
        usort($items, function (array $a, array $b) use ($riskOrder) {
            return ($riskOrder[$a["risk"]] ?? 9) -
                ($riskOrder[$b["risk"]] ?? 9);
        });

        return [
            "total" => count($items),
            "srcName" => $result->srcName ?? "",
            "tgtName" => $result->tgtName ?? "",
            "items" => $items,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  Embedded HTML/CSS/JS Application (loaded from files)
    // ═══════════════════════════════════════════════════════════

    private function getAppHtml(): string
    {
        $assetDir = __DIR__ . "/assets";

        $html  = file_get_contents($assetDir . "/app.html");
        $pico  = file_get_contents($assetDir . "/pico.min.css");
        $css   = file_get_contents($assetDir . "/app.css");
        $initJs = file_get_contents($assetDir . "/init.js");
        $appJs = file_get_contents($assetDir . "/app.js");

        if (
            $html === false ||
            $pico === false ||
            $css === false ||
            $initJs === false ||
            $appJs === false
        ) {
            throw new \RuntimeException(
                "Failed to load WebView assets from " . $assetDir,
            );
        }

        return str_replace(
            ["{{PICO_CSS}}", "{{CSS}}", "{{INIT_JS}}", "{{APP_JS}}"],
            [$pico, $css, $initJs, $appJs],
            $html,
        );
    }

    // (getInitScript removed — now loaded from assets/init.js)

    // (getAppCss removed — now loaded from assets/app.css)

    // (getAppJs removed — now loaded from assets/app.js)
}
