<?php
// src/Gui/MainWindow.php

namespace MySqlSchemaSync\Gui;

use Libui\Box;
use Libui\Button;
use Libui\Combobox;
use Libui\Entry;
use Libui\Label;
use Libui\MultilineEntry;
use Libui\ProgressBar;
use Libui\Table;
use Libui\TableModel;
use Libui\Window;
use Libui\Loop;
use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Config\Connection;
use MySqlSchemaSync\Diff\StructSyncAdapter;
use MySqlSchemaSync\Diff\DiffResult;
use MySqlSchemaSync\SqlGen\Generator;
use Yangweijie\Ui2\Dialogs\MessageBox;
use Libui\Form;

class MainWindow
{
    private ConfigStore $store;
    private ?DiffResult $lastDiff = null;
    private ?StructSyncAdapter $adapter = null;

    private Window $window;
    private Combobox $srcCombo;
    private Combobox $tgtCombo;
    private Entry $filterEntry;
    private Label $statusLabel;
    private Button $generateBtn;
    private Button $compareBtn;
    private Button $manageBtn;
    private Button $cancelBtn;
    private ProgressBar $progressBar;
    private Label $selectCountLabel;

    /** Result area controls - ALL created in run() BEFORE show() */
    private Box $resultArea;
    private ?Label $placeholderLabel = null;
    private ?Label $summaryLabel = null;
    private ?Box $toolbarBox = null;
    private ?Table $diffTable = null;
    private ?TableModel $diffModel = null;

    /** Current diff table state */
    private ?DiffTableModelDelegate $diffDelegate = null;

    /** Keep result window models alive (prevent uiFreeTableModel bug) */
    private array $resultModels = [];

    public function __construct(ConfigStore $store)
    {
        $this->store = $store;
    }

    public function run(): void
    {
        $this->window = new Window('MySQL SchemaSync (PHP + libui)', 1000, 700);
        $this->window->setMargined(true)->setResizeable(true)
        ->centered()
        ;

        $root = new Box();
        $root->setPadded(true);

        // Header
        $header = Box::horizontal();
        $header->setPadded(true);

        $this->srcCombo = new Combobox();
        $this->tgtCombo = new Combobox();
        $this->refreshConnectionLists();

        $swapBtn = new Button('⇄');
        $swapBtn->onClicked(function () {
            $srcIdx = $this->srcCombo->selected();
            $tgtIdx = $this->tgtCombo->selected();
            $this->srcCombo->setSelected($tgtIdx);
            $this->tgtCombo->setSelected($srcIdx);
        });

        $this->compareBtn = new Button('▶ 开始比对');
        $this->compareBtn->onClicked($this->onCompare(...));

        $this->manageBtn = new Button('⚙ 连接管理');
        $this->manageBtn->onClicked($this->onManageConnections(...));

        $header->append(new Label('源库:'), false);
        $header->append($this->srcCombo, true);
        $header->append($swapBtn, false);
        $header->append(new Label('目标库:'), false);
        $header->append($this->tgtCombo, true);
        $header->append($this->compareBtn, false);
        $header->append($this->manageBtn, false);

        $root->append($header, false);

        // Filter
        $filterRow = Box::horizontal();
        $filterRow->setPadded(true);
        $this->filterEntry = new Entry();
        $saved = $this->store->getSetting('excludePatterns', '*_bak, *_backup*, tmp_*');
        $this->filterEntry->setText($saved);
        $filterRow->append(new Label('排除表 (逗号分隔 glob):'), false);
        $filterRow->append($this->filterEntry, true);
        $root->append($filterRow, false);

        // Result area — create ALL controls BEFORE show()
        $this->resultArea = new Box();
        $this->resultArea->setPadded(true);
        
        // Placeholder (shown initially)
        $this->placeholderLabel = new Label("请选择源库和目标库，然后点击「开始比对」。\n说明：源库 = 新结构（如测试库），目标库 = 要同步到的旧结构（如生产库）。");
        $this->resultArea->appendStretchy($this->placeholderLabel);
        
        // Create EMPTY Table + toolbar (before show() to avoid macOS NSTableView bug)
        $this->createResultAreaControls();
        
        $root->appendStretchy($this->resultArea);

        // Progress bar (hidden by default)
        $progressRow = Box::horizontal();
        $progressRow->setPadded(true);
        $this->progressBar = new ProgressBar();
        $this->progressBar->setValue(0);
        $this->progressBar->hide();
        $this->cancelBtn = new Button('取消');
        $this->cancelBtn->hide();
        $this->cancelBtn->onClicked(function () {
            if ($this->adapter) {
                $this->adapter->cancel();
            }
        });
        $progressRow->append($this->progressBar, true);
        $progressRow->append($this->cancelBtn, false);
        $root->append($progressRow, false);

        // Footer
        $footer = Box::horizontal();
        $footer->setPadded(true);
        $this->statusLabel = new Label('就绪');
        $this->generateBtn = new Button('📋 生成迁移 SQL (0)');
        $this->generateBtn->onClicked($this->onGenerateSql(...));

        $footer->append($this->statusLabel, true);
        $footer->append($this->generateBtn, false);

        $root->append($footer, false);

        $this->window->onClosing(function () {
            return true;
        });
        $this->window->setChild($root);
        $this->window->run();
    }

    /**
     * Create result area controls (Table + toolbar) BEFORE show()
     * This avoids the macOS NSTableView bug where cellValue() is never called
     * if the Table is created after show().
     */
    private function createResultAreaControls(): void
    {
        // Summary label (hidden initially)
        $this->summaryLabel = new Label('');
        $this->summaryLabel->hide();
        $this->resultArea->append($this->summaryLabel, false);

        // Toolbar (hidden initially)
        $this->toolbarBox = Box::horizontal();
        $this->toolbarBox->setPadded(true);
        $this->toolbarBox->hide();

        $selectAllBtn = new Button('☑ 全选');
        $selectAllBtn->onClicked(fn() => $this->onSelectAll(true));

        $deselectAllBtn = new Button('☐ 取消');
        $deselectAllBtn->onClicked(fn() => $this->onSelectAll(false));

        $safeOnlyBtn = new Button('🟢 仅 SAFE');
        $safeOnlyBtn->onClicked(fn() => $this->onSelectByRisk('SAFE'));

        $warnOnlyBtn = new Button('🟡 仅 WARN');
        $warnOnlyBtn->onClicked(fn() => $this->onSelectByRisk('WARN'));

        $highOnlyBtn = new Button('🔴 仅 HIGH');
        $highOnlyBtn->onClicked(fn() => $this->onSelectByRisk('HIGH'));

        $this->toolbarBox->append($selectAllBtn, false);
        $this->toolbarBox->append($deselectAllBtn, false);
        $this->toolbarBox->append($safeOnlyBtn, false);
        $this->toolbarBox->append($warnOnlyBtn, false);
        $this->toolbarBox->append($highOnlyBtn, false);

        $this->selectCountLabel = new Label('');
        $this->toolbarBox->append($this->selectCountLabel, true);

        $this->resultArea->append($this->toolbarBox, false);

        // Create EMPTY delegate + model + table
        $this->diffDelegate = new DiffTableModelDelegate();
        $this->diffModel = new TableModel($this->diffDelegate);
        $this->diffDelegate->setModel($this->diffModel);

        $this->diffTable = Table::fromModel($this->diffModel);
        $this->diffTable->appendCheckboxColumn('✓', 0, 0);
        $this->diffTable->appendTextColumn('类型', 1);
        $this->diffTable->appendTextColumn('名称', 2);
        $this->diffTable->appendTextColumn('风险', 3);
        $this->diffTable->appendTextColumn('详情', 4);
        $this->diffTable->setColumnWidth(0, 40);
        $this->diffTable->setColumnWidth(1, 120);
        $this->diffTable->setColumnWidth(2, 250);
        $this->diffTable->setColumnWidth(3, 100);
        $this->diffTable->setColumnWidth(4, 300);

        // Click anywhere on a row toggles the checkbox
        $this->diffTable->onRowClicked(function (Table $t, int $row) {
            $this->diffDelegate->toggleRowChecked($row);
            $this->updateGenerateBtnText();
        });

        // Button + count label update on checkbox toggle
        $this->diffDelegate->onToggle(function () {
            $sel = $this->diffDelegate->selectedCount();
            $total = $this->diffDelegate->totalCount();
            $this->generateBtn->setText(
                $sel === $total
                    ? "📋 生成迁移 SQL ({$sel})"
                    : "📋 生成迁移 SQL（已选 {$sel}/{$total}）"
            );
            $this->selectCountLabel->setText("已选 {$sel}/{$total}");
        });

        // Add table to layout (hidden initially, will show after comparison)
        $this->diffTable->hide();
        $this->resultArea->appendStretchy($this->diffTable);
    }

    private function refreshConnectionLists(): void
    {
        $this->srcCombo->clear();
        $this->tgtCombo->clear();
        foreach ($this->store->list() as $conn) {
            $label = "{$conn->name} ({$conn->host}:{$conn->port}/{$conn->database})";
            $this->srcCombo->append($label);
            $this->tgtCombo->append($label);
        }
        if (count($this->store->list()) > 0) {
            $this->srcCombo->setSelected(0);
        }
        if (count($this->store->list()) > 1) {
            $this->tgtCombo->setSelected(1);
        }
    }

    private function getSelectedConnection(Combobox $combo): ?Connection
    {
        $idx = $combo->selected();
        $list = $this->store->list();
        return $list[$idx] ?? null;
    }

    private function getExcludePatterns(): array
    {
        $text = trim($this->filterEntry->text());
        if ($text === '') return [];
        return array_map('trim', explode(',', $text));
    }

    private function onCompare(): void
    {
        $src = $this->getSelectedConnection($this->srcCombo);
        $tgt = $this->getSelectedConnection($this->tgtCombo);

        if (!$src || !$tgt) {
            $this->showPlaceholder("❌ 请先配置并选择源库和目标库。");
            return;
        }
        if ($src->id === $tgt->id) {
            $this->showPlaceholder("❌ 源库和目标库不能相同。");
            return;
        }

        $this->store->setSetting('excludePatterns', trim($this->filterEntry->text()));
        $patterns = $this->getExcludePatterns();

        $this->compareBtn->disable();
        $this->manageBtn->disable();
        $this->generateBtn->disable();

        $this->statusLabel->setText('正在连接数据库...');
        $this->showPlaceholder("正在连接数据库...");

        Loop::delay(10, function () use ($src, $tgt, $patterns) {
            $this->adapter = new StructSyncAdapter($src, $tgt);

            $this->progressBar->setValue(0);
            $this->progressBar->show();
            $this->cancelBtn->show();
            $this->statusLabel->setText('正在查询...');

            $this->adapter->setOnPhase(function ($label, $cur, $total) {
                $pct = $total > 0 ? (int)(($cur / $total) * 100) : 0;
                $this->progressBar->setValue($pct);
                $this->statusLabel->setText("{$label} ({$cur}/{$total})...");
            });

            $this->adapter->setOnProgress(function ($name, $cur, $total) {
                $pct = $total > 0 ? (int)(($cur / $total) * 100) : 0;
                $this->progressBar->setValue($pct);
            });

            $this->adapter->fetchAll($patterns);

            $this->progressBar->setValue(-1);
            $this->statusLabel->setText('比较中...');

            $this->lastDiff = $this->adapter->compare($patterns);

            $this->progressBar->hide();
            $this->cancelBtn->hide();

            if ($this->lastDiff->error) {
                $this->statusLabel->setText('比对失败');
                $this->showPlaceholder("❌ 错误：{$this->lastDiff->error}");
            } else {
                $this->statusLabel->setText('比对完成');
                // ✅ FIX: Update data in-place (Table was created before show())
                $this->updateDiffTable($src, $tgt);
            }

            $this->compareBtn->enable();
            $this->manageBtn->enable();
        });
    }

    /**
     * Update diff table data (Table already exists from run())
     * This works on macOS because we're not recreating the Table.
     */
    /**
     * Show diff results in a NEW window.
     * The Table is created WITH data BEFORE show() — this is the only
     * way to make cellValue() work on macOS NSTableView.
     */
    private function updateDiffTable(Connection $src, Connection $tgt): void
    {
        $this->generateBtn->enable();

        $total = $this->lastDiff->total();
        if ($total === 0) {
            \Yangweijie\Ui2\Dialogs\MessageBox::info($this->window, '无差异', '源库和目标库结构完全一致，无需迁移。');
            $this->statusLabel->setText('比对完成：无差异');
            return;
        }

        $win = new \Libui\Window("比对结果 — {$src->name} → {$tgt->name}", 1000, 650, true);
        $win->setMargined(true);
        $win->centered();
        $win->onClosing(function () { return true; });

        $vbox = new \Libui\Box(true);
        $vbox->setPadded(true);

        // Summary text
        $parts = [];
        if ($this->lastDiff->newTables)      $parts[] = '新增表 ' . count($this->lastDiff->newTables);
        if ($this->lastDiff->changedTables)   $parts[] = '变更表 ' . count($this->lastDiff->changedTables);
        if ($this->lastDiff->removedTables)   $parts[] = '删除表 ' . count($this->lastDiff->removedTables);
        $extra = $this->lastDiff->total();
        foreach (['newIndexes','removedIndexes','newForeignKeys','removedForeignKeys',
                     'newTriggers','removedTriggers','newViews','removedViews',
                     'newProcedures','removedProcedures','newFunctions','removedFunctions',
                     'newEvents','removedEvents'] as $p) {
            $extra -= count($this->lastDiff->$p);
        }
        if ($extra > 0) $parts[] = "其他 {$extra}";
        $summaryText = "{$src->name} → {$tgt->name}  |  " . implode(' / ', $parts);

        // ✅ Create delegate WITH data FIRST (before button callbacks)
        $resultDelegate = new DiffTableModelDelegate();
        $resultDelegate->loadDiffs(
            $this->lastDiff->newTables,
            $this->lastDiff->changedTables,
            $this->lastDiff->removedTables,
            $this->lastDiff->newIndexes,
            $this->lastDiff->removedIndexes,
            $this->lastDiff->newForeignKeys,
            $this->lastDiff->removedForeignKeys,
            $this->lastDiff->newTriggers,
            $this->lastDiff->removedTriggers,
            $this->lastDiff->newViews,
            $this->lastDiff->removedViews,
            $this->lastDiff->newProcedures,
            $this->lastDiff->removedProcedures,
            $this->lastDiff->newFunctions,
            $this->lastDiff->removedFunctions,
            $this->lastDiff->newEvents,
            $this->lastDiff->removedEvents,
        );
        $resultModel = new \Libui\TableModel($resultDelegate);
        $resultDelegate->setModel($resultModel);
        $this->resultModels[] = [$resultModel, $resultDelegate, $win];

        // === Top bar: summary (left) + action buttons (right), same row, no stretch ===
        $topBar = new \Libui\Box(false);
        $topBar->setPadded(true);

        $summaryLabel = new \Libui\Label($summaryText);
        $topBar->append($summaryLabel, false);  // label: auto width, not stretchy

        $selectAllBtn = new \Libui\Button('☑ 全选');
        $selectAllBtn->onClicked(function () use ($resultDelegate) {
            $resultDelegate->setAllChecked(true);
        });
        $deselectAllBtn = new \Libui\Button('☐ 取消');
        $deselectAllBtn->onClicked(function () use ($resultDelegate) {
            $resultDelegate->setAllChecked(false);
        });
        $safeOnlyBtn = new \Libui\Button('🟢 仅SAFE');
        $safeOnlyBtn->onClicked(function () use ($resultDelegate) {
            $resultDelegate->setCheckedByRisk('SAFE', true);
            foreach (['WARN', 'HIGH'] as $r) $resultDelegate->setCheckedByRisk($r, false);
        });

        $topBar->append($selectAllBtn, false);
        $topBar->append($deselectAllBtn, false);
        $topBar->append($safeOnlyBtn, false);
        $vbox->append($topBar, false);

        // === Table ===
        $table = \Libui\Table::fromModel($resultModel);
        $table->appendCheckboxColumn('✓', 0, 0);
        $table->appendTextColumn('类型', 1);
        $table->appendTextColumn('名称', 2);
        $table->appendTextColumn('风险', 3);
        $table->appendTextColumn('详情', 4);
        $table->setColumnWidth(0, 40);
        $table->setColumnWidth(1, 120);
        $table->setColumnWidth(2, 250);
        $table->setColumnWidth(3, 100);
        $table->setColumnWidth(4, 350);
        $vbox->appendStretchy($table);

        // === Bottom bar: close + generate ===
        $bottomBar = new \Libui\Box(false);
        $bottomBar->setPadded(true);
        $closeBtn = new \Libui\Button('✖ 关闭窗口');
        $closeBtn->onClicked(function () use ($win) { $win->hide(); });
        $genBtn = new \Libui\Button("📋 生成迁移 SQL ({$total})");
        $genBtn->onClicked(function () use ($resultDelegate, $src, $tgt, $win) {
            $this->generateSqlFromDelegate($resultDelegate, $src, $tgt, $win);
        });
        $bottomBar->append($closeBtn, false);
        $bottomBar->append(new \Libui\Label('  '), false);
        $bottomBar->append($genBtn, false);
        $vbox->append($bottomBar, false);

        $win->setChild($vbox);
        $win->show();

        $this->statusLabel->setText("比对完成：{$total} 处差异（新窗口已打开）");
    }

        /**
     * Show placeholder (hide table + summary + toolbar)
     */
    private function showPlaceholder(string $text): void
    {
        $this->placeholderLabel->setText($text);
        $this->placeholderLabel->show();
        if ($this->summaryLabel) {
            $this->summaryLabel->hide();
        }
        if ($this->toolbarBox) {
            $this->toolbarBox->hide();
        }
        if ($this->diffTable) {
            $this->diffTable->hide();
        }
    }

    private function onSelectAll(bool $checked): void
    {
        if (!$this->diffDelegate) return;
        $this->diffDelegate->setAllChecked($checked);
        $this->updateGenerateBtnText();
    }

    private function onSelectByRisk(string $risk): void
    {
        if (!$this->diffDelegate) return;
        $this->diffDelegate->setCheckedByRisk($risk, true);
        foreach (['SAFE', 'WARN', 'HIGH'] as $r) {
            if ($r !== $risk) {
                $this->diffDelegate->setCheckedByRisk($r, false);
            }
        }
        $this->updateGenerateBtnText();
    }

    private function updateGenerateBtnText(): void
    {
        if (!$this->diffDelegate) return;
        $sel = $this->diffDelegate->selectedCount();
        $total = $this->diffDelegate->totalCount();
        $this->generateBtn->setText(
            $sel === $total
                ? "📋 生成迁移 SQL ({$sel})"
                : "📋 生成迁移 SQL（已选 {$sel}/{$total}）"
        );
        $this->selectCountLabel->setText("已选 {$sel}/{$total}");
    }

    private function onGenerateSql(): void
    {
        if (!$this->lastDiff || $this->lastDiff->total() === 0) {
            $this->statusLabel->setText('⚠ 没有可生成的差异');
            return;
        }

        $selected = $this->diffDelegate ? $this->diffDelegate->selectedRows() : [];
        if ($this->diffDelegate && count($selected) === 0) {
            $this->statusLabel->setText('⚠ 请先勾选需要迁移的差异项');
            return;
        }

        $filtered = $this->buildFilteredDiff($selected);

        $src = $this->getSelectedConnection($this->srcCombo);
        $tgt = $this->getSelectedConnection($this->tgtCombo);
        $gen = new Generator($src, $tgt, $this->adapter);
        $sql = $gen->generate($filtered);

        $win = new Window('生成的迁移 SQL', 800, 500);
        $win->setMargined(true);
        $win->onClosing(fn() => true);

        $box = new Box();
        $box->setPadded(true);

        $text = new MultilineEntry();
        $text->setReadOnly(true);
        $text->setText($sql);
        $box->appendStretchy($text);

        $btnRow = Box::horizontal();
        $btnRow->setPadded(true);
        $copy = new Button('📋 复制到剪贴板');
        $copy->onClicked(function () use ($text, $win) {
            $content = $text->text();
            $ok = false;
            if (DIRECTORY_SEPARATOR === '\\') {
                $tmp = tempnam(sys_get_temp_dir(), 'mss_');
                file_put_contents($tmp, $content);
                shell_exec('clip < "' . $tmp . '"');
                @unlink($tmp);
                $ok = true;
            } elseif (function_exists('shell_exec')) {
                $escaped = str_replace("'", "'\\''", $content);
                shell_exec("echo '{$escaped}' | pbcopy");
                $ok = true;
            }
            MessageBox::info($win, '已复制', $ok ? 'SQL 已复制到剪贴板。' : '复制失败，请手动复制。');
        });
        $save = new Button('💾 保存为 .sql');
        $save->onClicked(function () use ($text, $win) {
            $path = $win->dialogs()->saveFile();
            if ($path) {
                file_put_contents($path, $text->text());
                MessageBox::info($win, '已保存', "保存到：$path");
            }
        });
        $btnRow->append($copy, false);
        $btnRow->append($save, false);
        $box->append($btnRow, false);

        // Center on main window (manual positioning)
        [$px, $py] = $this->window->getPosition();
        [$pw, $ph] = $this->window->getContentSize();
        [$w, $h]   = $win->getContentSize();
        $win->setPosition(max(0, (int)($px + ($pw - $w) / 2)), max(0, (int)($py + ($ph - $h) / 2)));
        
        $win->setChild($box);
        $win->show();
    }

    private function buildFilteredDiff(array $selected): array
    {
        $result = [
            'ADD_TABLE' => [],
            'DROP_TABLE' => [],
            'MODIFY_FIELD' => [],
            'ADD_FIELD' => [],
            'DROP_FIELD' => [],
            'ADD_CONSTRAINT' => [],
            'DROP_CONSTRAINT' => [],
        ];

        foreach ($selected as $item) {
            $type = $item['type'];
            $name = $item['name'];
            $detail = $item['detail'] ?? null;

            if ($type === 'ADD_TABLE') {
                foreach ($this->lastDiff->newTables as $t) {
                    if ($t['name'] === $name) {
                        $result['ADD_TABLE'][] = $t['createSql'];
                        break;
                    }
                }
            } elseif ($type === 'DROP_TABLE') {
                foreach ($this->lastDiff->removedTables as $t) {
                    if ($t['name'] === $name) {
                        $result['DROP_TABLE'][] = "DROP TABLE IF EXISTS `{$name}`";
                        break;
                    }
                }
            } elseif ($type === 'CHANGED_TABLE') {
                foreach ($this->lastDiff->changedTables as $t) {
                    if ($t['name'] === $name) {
                        foreach ($t['changes'] as $c) {
                            if ($c['kind'] === 'ADD_COLUMN') {
                                $result['ADD_FIELD'][$name][$c['column']] = $c['detail'];
                            } elseif ($c['kind'] === 'MODIFY_COLUMN') {
                                $result['MODIFY_FIELD'][$name][$c['column']] = $c['detail'];
                            } elseif ($c['kind'] === 'DROP_COLUMN') {
                                $result['DROP_FIELD'][$name][$c['column']] = '';
                            }
                        }
                        break;
                    }
                }
            }
        }

        return $result;
    }

    private function onManageConnections(): void
    {
        $dlg = new ConnectionWindow($this->store, function () {
            $this->refreshConnectionLists();
        });
        // Center on parent window (manual positioning)
        [$px, $py] = $this->window->getPosition();
        [$pw, $ph] = $this->window->getContentSize();
        [$w, $h]   = $this->window->getContentSize();
        $dlgWin = new Window('连接管理', 500, 420);
        $dlgWin->setMargined(true);
        $dlgWin->onClosing(function () { return true; });
        $dlgWin->setPosition(max(0, (int)($px + ($pw - $w) / 2)), max(0, (int)($py + ($ph - $h) / 2)));
        
        // Rebuild the connection window content manually
        $root = new Box(true); // vertical box
        $root->setPadded(true);

        $form = new Form();
        $form->setPadded(true);
        $form->append('连接名称', new Entry());
        $form->append('主机', new Entry());
        $portEntry = new Entry();
        $portEntry->setText('3306');
        $form->append('端口', $portEntry);
        $form->append('用户名', new Entry());
        $form->append('密码', Entry::password());
        $form->append('默认数据库', new Entry());
        $root->append($form, false);

        $status = new Label('');
        $root->append($status, false);

        $btnRow = Box::horizontal();
        $btnRow->setPadded(true);
        
        $testBtn = new Button('🔌 测试连接');
        $testBtn->onClicked(function () use ($form, $status) {
            $v = $form->values();
            $conn = new Connection(
                id: '',
                name: trim($v['连接名称'] ?? ''),
                host: trim($v['主机'] ?? ''),
                port: (int)trim($v['端口'] ?: '3306'),
                user: trim($v['用户名'] ?? ''),
                password: $v['密码'] ?? '',
                database: trim($v['默认数据库'] ?? ''),
            );
            $result = $this->store->test($conn);
            if ($result['ok']) {
                $status->setText("✅ 连接成功 | MySQL {$result['version']}");
            } else {
                $status->setText("❌ 连接失败：{$result['error']}");
            }
        });
        
        $saveBtn = new Button('💾 保存');
        $saveBtn->onClicked(function () use ($form, $status) {
            $v = $form->values();
            if (trim($v['连接名称'] ?? '') === '' || trim($v['主机'] ?? '') === '' || trim($v['默认数据库'] ?? '') === '') {
                $status->setText('❌ 名称、主机、默认数据库不能为空');
                return;
            }
            $conn = new Connection(
                id: bin2hex(random_bytes(8)),
                name: trim($v['连接名称']),
                host: trim($v['主机']),
                port: (int)trim($v['端口'] ?: '3306'),
                user: trim($v['用户名'] ?? ''),
                password: $v['密码'] ?? '',
                database: trim($v['默认数据库']),
            );
            $this->store->add($conn);
            $status->setText("✅ 已保存：{$conn->name}");
            $this->refreshConnectionLists();
        });
        
        $btnRow->append($testBtn, false);
        $btnRow->append($saveBtn, false);
        $root->append($btnRow, false);
        
        $dlgWin->setChild($root);
        $dlgWin->show();
    }

    private function generateSqlFromDelegate(DiffTableModelDelegate $delegate, Connection $src, Connection $tgt, $win): void
    {
        $selected = $delegate->selectedRows();
        if (count($selected) === 0) {
            \Yangweijie\Ui2\Dialogs\MessageBox::info($win, '提示', '请先勾选需要迁移的差异项。');
            return;
        }

        try {
            $filtered = new \MySqlSchemaSync\Diff\DiffResult();

            foreach ($selected as $item) {
                $type = $item['type'];
                $name = $item['name'];

                if ($type === '新增表') {
                    foreach ($this->lastDiff->newTables as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newTables[] = $t; break; }
                    }
                } elseif ($type === '删除表') {
                    foreach ($this->lastDiff->removedTables as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedTables[] = $t; break; }
                    }
                } elseif ($type === '变更表') {
                    foreach ($this->lastDiff->changedTables as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->changedTables[] = $t; break; }
                    }
                } elseif ($type === '新增索引') {
                    foreach ($this->lastDiff->newIndexes as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newIndexes[] = $t; break; }
                    }
                } elseif ($type === '删除索引') {
                    foreach ($this->lastDiff->removedIndexes as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedIndexes[] = $t; break; }
                    }
                } elseif ($type === '新增外键') {
                    foreach ($this->lastDiff->newForeignKeys as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newForeignKeys[] = $t; break; }
                    }
                } elseif ($type === '删除外键') {
                    foreach ($this->lastDiff->removedForeignKeys as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedForeignKeys[] = $t; break; }
                    }
                } elseif ($type === '新增触发器') {
                    foreach ($this->lastDiff->newTriggers as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newTriggers[] = $t; break; }
                    }
                } elseif ($type === '删除触发器') {
                    foreach ($this->lastDiff->removedTriggers as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedTriggers[] = $t; break; }
                    }
                } elseif ($type === '新增视图') {
                    foreach ($this->lastDiff->newViews as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newViews[] = $t; break; }
                    }
                } elseif ($type === '删除视图') {
                    foreach ($this->lastDiff->removedViews as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedViews[] = $t; break; }
                    }
                } elseif ($type === '新增存储过程') {
                    foreach ($this->lastDiff->newProcedures as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newProcedures[] = $t; break; }
                    }
                } elseif ($type === '删除存储过程') {
                    foreach ($this->lastDiff->removedProcedures as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedProcedures[] = $t; break; }
                    }
                } elseif ($type === '新增函数') {
                    foreach ($this->lastDiff->newFunctions as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newFunctions[] = $t; break; }
                    }
                } elseif ($type === '删除函数') {
                    foreach ($this->lastDiff->removedFunctions as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedFunctions[] = $t; break; }
                    }
                } elseif ($type === '新增事件') {
                    foreach ($this->lastDiff->newEvents as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->newEvents[] = $t; break; }
                    }
                } elseif ($type === '删除事件') {
                    foreach ($this->lastDiff->removedEvents as $t) {
                        if (($t['name'] ?? '') === $name) { $filtered->removedEvents[] = $t; break; }
                    }
                }
            }

            $gen = new \MySqlSchemaSync\SqlGen\Generator($src, $tgt, $this->adapter);
            $sql = $gen->generate($filtered);
        } catch (\Throwable $e) {
            \Yangweijie\Ui2\Dialogs\MessageBox::error($win, '错误', '生成 SQL 失败：' . $e->getMessage());
            return;
        }

        $sqlWin = new \Libui\Window('生成的迁移 SQL', 800, 500);
        $sqlWin->setMargined(true);
        $sqlWin->centered();
        $sqlWin->onClosing(function () { return true; });

        $sqlBox = new \Libui\Box(true);
        $sqlBox->setPadded(true);

        $text = new \Libui\MultilineEntry();
        $text->setReadOnly(true);
        $text->setText($sql);
        $sqlBox->appendStretchy($text);

        $btnRow = new \Libui\Box(false);
        $btnRow->setPadded(true);
        $copyBtn = new \Libui\Button('📋 复制到剪贴板');
        $copyBtn->onClicked(function () use ($text) {
            $content = $text->getText();
            $tmpFile = tempnam(sys_get_temp_dir(), 'mss_');
            file_put_contents($tmpFile, $content);
            shell_exec("pbcopy < " . escapeshellarg($tmpFile));
            unlink($tmpFile);
        });
        $btnRow->append($copyBtn, false);
        $sqlBox->append($btnRow, false);

        $sqlWin->setChild($sqlBox);
        $sqlWin->show();
    }

    private function buildFilteredDiffFromDelegate(array $selected): array
    {
        $result = [
            'newTables'      => [],
            'removedTables'  => [],
            'changedTables'  => [],
            'newIndexes'     => [],
            'removedIndexes' => [],
            'newForeignKeys' => [],
            'removedForeignKeys' => [],
            'newTriggers'    => [],
            'removedTriggers' => [],
            'newViews'       => [],
            'removedViews'   => [],
            'newProcedures'  => [],
            'removedProcedures' => [],
            'newFunctions'   => [],
            'removedFunctions' => [],
            'newEvents'      => [],
            'removedEvents'  => [],
        ];

        foreach ($selected as $item) {
            $type = $item['type'];
            $name = $item['name'];

            if ($type === '新增表') {
                foreach ($this->lastDiff->newTables as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newTables'][] = $t; break; }
                }
            } elseif ($type === '删除表') {
                foreach ($this->lastDiff->removedTables as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedTables'][] = $t; break; }
                }
            } elseif ($type === '变更表') {
                foreach ($this->lastDiff->changedTables as $t) {
                    if (($t['name'] ?? '') === $name) { $result['changedTables'][] = $t; break; }
                }
            } elseif ($type === '新增索引') {
                foreach ($this->lastDiff->newIndexes as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newIndexes'][] = $t; break; }
                }
            } elseif ($type === '删除索引') {
                foreach ($this->lastDiff->removedIndexes as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedIndexes'][] = $t; break; }
                }
            } elseif ($type === '新增外键') {
                foreach ($this->lastDiff->newForeignKeys as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newForeignKeys'][] = $t; break; }
                }
            } elseif ($type === '删除外键') {
                foreach ($this->lastDiff->removedForeignKeys as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedForeignKeys'][] = $t; break; }
                }
            } elseif ($type === '新增触发器') {
                foreach ($this->lastDiff->newTriggers as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newTriggers'][] = $t; break; }
                }
            } elseif ($type === '删除触发器') {
                foreach ($this->lastDiff->removedTriggers as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedTriggers'][] = $t; break; }
                }
            } elseif ($type === '新增视图') {
                foreach ($this->lastDiff->newViews as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newViews'][] = $t; break; }
                }
            } elseif ($type === '删除视图') {
                foreach ($this->lastDiff->removedViews as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedViews'][] = $t; break; }
                }
            } elseif ($type === '新增存储过程') {
                foreach ($this->lastDiff->newProcedures as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newProcedures'][] = $t; break; }
                }
            } elseif ($type === '删除存储过程') {
                foreach ($this->lastDiff->removedProcedures as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedProcedures'][] = $t; break; }
                }
            } elseif ($type === '新增函数') {
                foreach ($this->lastDiff->newFunctions as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newFunctions'][] = $t; break; }
                }
            } elseif ($type === '删除函数') {
                foreach ($this->lastDiff->removedFunctions as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedFunctions'][] = $t; break; }
                }
            } elseif ($type === '新增事件') {
                foreach ($this->lastDiff->newEvents as $t) {
                    if (($t['name'] ?? '') === $name) { $result['newEvents'][] = $t; break; }
                }
            } elseif ($type === '删除事件') {
                foreach ($this->lastDiff->removedEvents as $t) {
                    if (($t['name'] ?? '') === $name) { $result['removedEvents'][] = $t; break; }
                }
            }
        }

        return $result;
    }
}