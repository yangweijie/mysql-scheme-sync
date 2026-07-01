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

    /** A dedicated box in the layout that gets replaced with results or placeholder. */
    private Box $resultArea;

    /** Current diff table state */
    private ?DiffTableModelDelegate $diffDelegate = null;
    private ?Label $summaryLabel = null;

    public function __construct(ConfigStore $store)
    {
        $this->store = $store;
    }

    public function run(): void
    {
        $this->window = new Window('MySQL SchemaSync (PHP + libui)', 1000, 700);
        $this->window->setMargined(true)->setResizeable(true)
        // ->centered()
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

        // Result area — dedicated box, contents are swapped on compare
        $this->resultArea = new Box();
        $this->resultArea->setPadded(true);
        $placeholder = new Label("请选择源库和目标库，然后点击「开始比对」。\n说明：源库 = 新结构（如测试库），目标库 = 要同步到的旧结构（如生产库）。");
        $this->resultArea->appendStretchy($placeholder);
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
            $this->adapter->setTotalSteps(400);
            $total = 400;

            $this->progressBar->setValue(0);
            $this->progressBar->show();
            $this->cancelBtn->show();

            $this->adapter->setOnProgress(function () use ($total) {
                $pct = min(100, (int)(($this->adapter->currentStep / $total) * 100));
                $this->progressBar->setValue($pct);
                $this->statusLabel->setText("正在读取结构 ({$this->adapter->currentStep}/{$total})...");
            });

            $this->lastDiff = $this->adapter->compare($patterns);

            $this->progressBar->hide();
            $this->cancelBtn->hide();

            if ($this->lastDiff->error) {
                $this->statusLabel->setText('比对失败');
                $this->showPlaceholder("❌ 错误：{$this->lastDiff->error}");
            } else {
                $this->statusLabel->setText('比对完成');
                $this->buildDiffTable($src, $tgt);
            }

            $this->compareBtn->enable();
            $this->manageBtn->enable();
        });
    }

    /** Clear the result area and rebuild with table/toolbar. */
    private function buildDiffTable(Connection $src, Connection $tgt): void
    {
        $this->generateBtn->enable();
        // Clear previous result area children
        $this->clearResultArea();

        // Build category breakdown summary
        $parts = [];
        if ($this->lastDiff->newTables) $parts[] = '新增表 ' . count($this->lastDiff->newTables);
        if ($this->lastDiff->changedTables) $parts[] = '变更表 ' . count($this->lastDiff->changedTables);
        if ($this->lastDiff->removedTables) $parts[] = '删除表 ' . count($this->lastDiff->removedTables);
        $extraCount = $this->lastDiff->total();
        foreach (['newIndexes','removedIndexes','newForeignKeys','removedForeignKeys','newTriggers','removedTriggers','newViews','removedViews','newProcedures','removedProcedures','newFunctions','removedFunctions','newEvents','removedEvents'] as $prop) {
            $extraCount -= count($this->lastDiff->$prop);
        }
        if ($extraCount > 0) $parts[] = "其他 {$extraCount}";

        $summaryText =  "{$src->name} → {$tgt->name}  |  " . implode(' / ', $parts);
        $this->summaryLabel = new Label($summaryText);
        $this->resultArea->append($this->summaryLabel, false);

        // Toolbar
        $toolBar = Box::horizontal();
        $toolBar->setPadded(true);

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

        $toolBar->append($selectAllBtn, false);
        $toolBar->append($deselectAllBtn, false);
        $toolBar->append($safeOnlyBtn, false);
        $toolBar->append($warnOnlyBtn, false);
        $toolBar->append($highOnlyBtn, false);

        $this->selectCountLabel = new Label('');
        $toolBar->append($this->selectCountLabel, true);

        $this->resultArea->append($toolBar, false);

        // Delegate + Table
        $delegate = new DiffTableModelDelegate();
        $delegate->loadDiffs(
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

        $model = new TableModel($delegate);
        $delegate->setModel($model);

        $this->diffDelegate = $delegate;

        // Button + count label update on checkbox toggle
        $delegate->onToggle(function () use ($delegate) {
            $sel = $delegate->selectedCount();
            $total = $delegate->totalCount();
            $this->generateBtn->setText(
                $sel === $total
                    ? "📋 生成迁移 SQL ({$sel})"
                    : "📋 生成迁移 SQL（已选 {$sel}/{$total}）"
            );
            $this->selectCountLabel->setText("已选 {$sel}/{$total}");
        });

        $table = Table::fromModel($model);
        $table->appendCheckboxColumn('✓', 0, 0);
        $table->appendTextColumn('类型', 1);
        $table->appendTextColumn('名称', 2);
        $table->appendTextColumn('风险', 3, null, 5);   // text colour from model col 5
        $table->appendTextColumn('详情', 4);
        $table->setColumnWidth(0, 40);
        $table->setColumnWidth(1, 120);
        $table->setColumnWidth(2, 250);
        $table->setColumnWidth(3, 80);
        $table->setColumnWidth(4, 300);

        // Click anywhere on a row toggles the checkbox
        $table->onRowClicked(function (Table $t, int $row) use ($delegate) {
            $delegate->toggleRowChecked($row);
            $this->updateGenerateBtnText();
        });
        $this->resultArea->appendStretchy($table);

        // Update status
        $total = $delegate->totalCount();
        $this->statusLabel->setText("比对完成：{$total} 处差异");
        $this->generateBtn->setText("📋 生成迁移 SQL ({$total})");
        $this->selectCountLabel->setText("已选 {$total}/{$total}");
    }

    private function clearResultArea(): void
    {
        while ($this->resultArea->numChildren() > 0) {
            $this->resultArea->delete(0);
        }
    }

    private function showPlaceholder(string $text): void
    {
        $this->clearResultArea();
        $label = new Label($text);
        $this->resultArea->appendStretchy($label);
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
            $win->dialogs()->msgBox('已复制', $ok ? 'SQL 已复制到剪贴板。' : '复制失败，请手动复制。');
        });
        $save = new Button('💾 保存为 .sql');
        $save->onClicked(function () use ($text, $win) {
            $path = $win->dialogs()->saveFile();
            if ($path) {
                file_put_contents($path, $text->text());
                $win->dialogs()->msgBox('已保存', "保存到：$path");
            }
        });
        $btnRow->append($copy, false);
        $btnRow->append($save, false);
        $box->append($btnRow, false);

        // Center on main window
        [$px, $py] = $this->window->getPosition();
        [$pw, $ph] = $this->window->getContentSize();
        [$w, $h]   = $win->getContentSize();
        $win->setPosition(max(0, (int)($px + ($pw - $w) / 2)), max(0, (int)($py + ($ph - $h) / 2)));

        $win->setChild($box);
        $win->show();
    }

    private function buildFilteredDiff(array $selected): DiffResult
    {
        $d = new DiffResult();
        if (!$this->lastDiff) return $d;

        $selectedMap = [];
        foreach ($selected as $s) {
            $selectedMap[$s['type'] . "\x00" . $s['name']] = true;
        }

        $filter = function (string $type, array $list) use ($selectedMap): array {
            return array_values(array_filter($list, fn($item) => isset($selectedMap[$type . "\x00" . ($item['name'] ?? '')])));
        };

        $d->newTables               = $filter('新增表',       $this->lastDiff->newTables);
        $d->changedTables           = $filter('变更表',       $this->lastDiff->changedTables);
        $d->removedTables           = $filter('删除表',       $this->lastDiff->removedTables);
        $d->newIndexes              = $filter('新增索引',     $this->lastDiff->newIndexes);
        $d->removedIndexes          = $filter('删除索引',     $this->lastDiff->removedIndexes);
        $d->newForeignKeys          = $filter('新增外键',     $this->lastDiff->newForeignKeys);
        $d->removedForeignKeys      = $filter('删除外键',     $this->lastDiff->removedForeignKeys);
        $d->newTriggers             = $filter('新增触发器',   $this->lastDiff->newTriggers);
        $d->removedTriggers         = $filter('删除触发器',   $this->lastDiff->removedTriggers);
        $d->newViews                = $filter('新增视图',     $this->lastDiff->newViews);
        $d->removedViews            = $filter('删除视图',     $this->lastDiff->removedViews);
        $d->newProcedures           = $filter('新增存储过程', $this->lastDiff->newProcedures);
        $d->removedProcedures       = $filter('删除存储过程', $this->lastDiff->removedProcedures);
        $d->newFunctions            = $filter('新增函数',     $this->lastDiff->newFunctions);
        $d->removedFunctions        = $filter('删除函数',     $this->lastDiff->removedFunctions);
        $d->newEvents               = $filter('新增事件',     $this->lastDiff->newEvents);
        $d->removedEvents           = $filter('删除事件',     $this->lastDiff->removedEvents);

        return $d;
    }

    private function onManageConnections(): void
    {
        $cw = new ConnectionWindow($this->store, function () {
            $this->refreshConnectionLists();
        });
        $cw->show($this->window);
    }
}
