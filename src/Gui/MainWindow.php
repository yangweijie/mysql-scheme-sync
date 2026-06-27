<?php
// src/Gui/MainWindow.php

namespace MySqlSchemaSync\Gui;

use Libui\Box;
use Libui\Button;
use Libui\Combobox;
use Libui\Entry;
use Libui\Label;
use Libui\MultilineEntry;
use Libui\Window;
use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Config\Connection;
use MySqlSchemaSync\Diff\DiffResult;
use MySqlSchemaSync\Diff\Schema;
use MySqlSchemaSync\SqlGen\Generator;

class MainWindow
{
    private ConfigStore $store;
    private ?DiffResult $lastDiff = null;
    private ?Schema $sourceSchema = null;
    private ?Schema $targetSchema = null;

    private Window $window;
    private Combobox $srcCombo;
    private Combobox $tgtCombo;
    private Entry $filterEntry;
    private Label $statusLabel;
    private MultilineEntry $resultBox;
    private Button $generateBtn;

    public function __construct(ConfigStore $store)
    {
        $this->store = $store;
    }

    public function run(): void
    {
        $this->window = new Window('MySQL SchemaSync (PHP + libui)', 900, 650);
        $this->window->setMargined(true)->setResizeable(true);

        $root = new Box();
        $root->setPadded(true);

        // Header
        $header = Box::horizontal();
        $header->setPadded(true);

        $this->srcCombo = new Combobox();
        $this->tgtCombo = new Combobox();
        $this->refreshConnectionLists();

        $compareBtn = new Button('▶ 开始比对');
        $compareBtn->onClicked($this->onCompare(...));

        $manageBtn = new Button('⚙ 连接管理');
        $manageBtn->onClicked($this->onManageConnections(...));

        $header->append(new Label('源库:'), false);
        $header->append($this->srcCombo, true);
        $header->append(new Label('目标库:'), false);
        $header->append($this->tgtCombo, true);
        $header->append($compareBtn, false);
        $header->append($manageBtn, false);

        $root->append($header, false);

        // Filter
        $filterRow = Box::horizontal();
        $filterRow->setPadded(true);
        $this->filterEntry = new Entry();
        $this->filterEntry->setText('*_bak, *_backup*, tmp_*');
        $filterRow->append(new Label('排除表 (逗号分隔 glob):'), false);
        $filterRow->append($this->filterEntry, true);
        $root->append($filterRow, false);

        // Results
        $this->resultBox = new MultilineEntry();
        $this->resultBox->setReadOnly(true);
        $this->resultBox->setText("请选择源库和目标库，然后点击「开始比对」。\n\n说明：源库 = 新结构（如测试库），目标库 = 要同步到的旧结构（如生产库）。");
        $root->append($this->resultBox, true);

        // Footer
        $footer = Box::horizontal();
        $footer->setPadded(true);
        $this->statusLabel = new Label('就绪');
        $this->generateBtn = new Button('📋 生成迁移 SQL');
        $this->generateBtn->onClicked($this->onGenerateSql(...));
        $copyBtn = new Button('📋 复制结果');
        $copyBtn->onClicked($this->onCopyResults(...));

        $footer->append($this->statusLabel, true);
        $footer->append($copyBtn, false);
        $footer->append($this->generateBtn, false);

        $root->append($footer, false);

        $this->window->setChild($root);
        $this->window->run();
    }

    private function refreshConnectionLists(): void
    {
        $this->srcCombo->clear();
        $this->tgtCombo->clear();
        foreach ($this->store->list() as $conn) {
            $this->srcCombo->append("{$conn->name} ({$conn->host}:{$conn->port}/{$conn->database})");
            $this->tgtCombo->append("{$conn->name} ({$conn->host}:{$conn->port}/{$conn->database})");
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
            $this->resultBox->setText("❌ 请先配置并选择源库和目标库。");
            return;
        }
        if ($src->id === $tgt->id) {
            $this->resultBox->setText("❌ 源库和目标库不能相同。");
            return;
        }

        $this->statusLabel->setText('正在读取源库元数据...');
        $sourceSchema = Schema::fromConnection($src);

        $this->statusLabel->setText('正在读取目标库元数据...');
        $this->targetSchema = Schema::fromConnection($tgt);

        $this->sourceSchema = $sourceSchema;
        $this->lastDiff = DiffResult::compare($this->sourceSchema, $this->targetSchema, $this->getExcludePatterns());

        if ($this->lastDiff->error) {
            $this->statusLabel->setText('比对失败');
            $this->resultBox->setText("❌ 错误：{$this->lastDiff->error}");
            return;
        }

        $lines = [];
        $lines[] = "比对结果：{$src->name} → {$tgt->name}";
        $lines[] = "源库版本: {$sourceSchema->version} | 目标库版本: {$targetSchema->version}";
        $lines[] = "总差异数: {$this->lastDiff->total()}";
        $lines[] = str_repeat('-', 60);

        $sections = [
            ['新增表', $this->lastDiff->newTables, '+'],
            ['删除表', $this->lastDiff->removedTables, '-'],
            ['变更表', $this->lastDiff->changedTables, '~'],
            ['新增索引', $this->lastDiff->newIndexes, '+'],
            ['删除索引', $this->lastDiff->removedIndexes, '-'],
            ['新增外键', $this->lastDiff->newForeignKeys, '+'],
            ['删除外键', $this->lastDiff->removedForeignKeys, '-'],
            ['新增触发器', $this->lastDiff->newTriggers, '+'],
            ['删除触发器', $this->lastDiff->removedTriggers, '-'],
            ['新增视图', $this->lastDiff->newViews, '+'],
            ['删除视图', $this->lastDiff->removedViews, '-'],
            ['新增存储过程', $this->lastDiff->newProcedures, '+'],
            ['删除存储过程', $this->lastDiff->removedProcedures, '-'],
            ['新增函数', $this->lastDiff->newFunctions, '+'],
            ['删除函数', $this->lastDiff->removedFunctions, '-'],
            ['新增事件', $this->lastDiff->newEvents, '+'],
            ['删除事件', $this->lastDiff->removedEvents, '-'],
        ];

        foreach ($sections as [$title, $items, $icon]) {
            if (!$items) continue;
            $lines[] = "\n[$title] (" . count($items) . ")";
            foreach ($items as $item) {
                $name = $item['name'];
                $risk = $item['risk'];
                $extra = '';
                if (isset($item['table'])) $extra = " on {$item['table']}";
                $lines[] = "  $icon $name$extra [$risk]";
                if (isset($item['changes'])) {
                    foreach ($item['changes'] as $c) {
                        $lines[] = "      · {$c['kind']}: {$c['column']} [{$c['risk']}]";
                    }
                }
            }
        }

        $this->resultBox->setText(implode("\n", $lines));
        $this->statusLabel->setText("比对完成：{$this->lastDiff->total()} 处差异");
    }

    private function onGenerateSql(): void
    {
        if (!$this->lastDiff || $this->lastDiff->total() === 0) {
            $this->resultBox->setText($this->resultBox->text() . "\n\n⚠ 没有可生成的差异。");
            return;
        }
        $src = $this->getSelectedConnection($this->srcCombo);
        $tgt = $this->getSelectedConnection($this->tgtCombo);
        $gen = new Generator($src, $tgt, $this->sourceSchema, $this->targetSchema);
        $sql = $gen->generate($this->lastDiff);

        $win = new Window('生成的迁移 SQL', 800, 500);
        $win->setMargined(true);
        $win->onClosing(function () {
            return true; // 独立关闭，不影响主窗口
        });
        $box = new Box();
        $box->setPadded(true);

        $text = new MultilineEntry();
        $text->setReadOnly(true);
        $text->setText($sql);
        $box->append($text, true);

        $btnRow = Box::horizontal();
        $btnRow->setPadded(true);
        $copy = new Button('📋 复制到剪贴板');
        $copy->onClicked(function () use ($text, $win) {
            if (function_exists('shell_exec')) {
                $escaped = str_replace("'", "'\\''", $text->text());
                shell_exec("echo '" . $escaped . "' | pbcopy");
            }
            $win->dialogs()->msgBox('已复制', 'SQL 已复制到剪贴板。');
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

    private function onCopyResults(): void
    {
        $text = $this->resultBox->text();
        if (function_exists('shell_exec')) {
            $escaped = str_replace("'", "'\\''", $text);
            shell_exec("echo '" . $escaped . "' | pbcopy");
        }
        $this->window->dialogs()->msgBox('已复制', '比对结果已复制到剪贴板。');
    }

    private function onManageConnections(): void
    {
        $cw = new ConnectionWindow($this->store, function () {
            $this->refreshConnectionLists();
        });
        $cw->show($this->window);
    }
}
