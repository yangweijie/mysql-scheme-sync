<?php
// src/Gui/ConnectionWindow.php

namespace MySqlSchemaSync\Gui;

use Libui\Box;
use Libui\Button;
use Libui\Combobox;
use Libui\Dialogs;
use Libui\Entry;
use Libui\Form;
use Libui\Label;
use Libui\Window;
use MySqlSchemaSync\Config\ConfigStore;
use MySqlSchemaSync\Config\Connection;

class ConnectionWindow
{
    private ConfigStore $store;
    private ?\Closure $onChange;
    private ?Window $window = null;
    private Form $form;
    private Label $status;

    public function __construct(ConfigStore $store, ?callable $onChange = null)
    {
        $this->store = $store;
        $this->onChange = $onChange ? $onChange(...) : null;
    }

    public function show(Window $parent): void
    {
        $this->window = new Window('连接管理', 500, 420);
        $this->window->setMargined(true);
        $this->window->onClosing(function () {
            return true; // 允许独立关闭，不退出主窗口
        });
        $this->centerOnParent($parent);

        $root = new Box();
        $root->setPadded(true);

        $this->form = new Form();
        $this->form->setPadded(true);
        $this->form->append('连接名称', new Entry());
        $this->form->append('主机', new Entry());
        $this->form->append('端口', new Entry());
        $this->form->append('用户名', new Entry());
        $this->form->append('密码', Entry::password());
        $this->form->append('默认数据库', new Entry());
        $root->append($this->form, false);

        $this->status = new Label('');
        $root->append($this->status, false);

        $btnRow = Box::horizontal();
        $btnRow->setPadded(true);

        $testBtn = new Button('🔌 测试连接');
        $testBtn->onClicked($this->onTest(...));

        $saveBtn = new Button('💾 保存');
        $saveBtn->onClicked($this->onSave(...));

        $loadBtn = new Button('📂 加载已有');
        $loadBtn->onClicked($this->onLoad(...));

        $delBtn = new Button('🗑 删除');
        $delBtn->onClicked($this->onDelete(...));

        $importBtn = new Button('📥 导入');
        $importBtn->onClicked($this->onImport(...));

        $exportBtn = new Button('📤 导出');
        $exportBtn->onClicked($this->onExport(...));

        $btnRow->append($testBtn, false);
        $btnRow->append($saveBtn, false);
        $btnRow->append($loadBtn, false);
        $btnRow->append($delBtn, false);
        $btnRow->append($importBtn, false);
        $btnRow->append($exportBtn, false);

        $root->append($btnRow, false);

        $this->window->setChild($root);
        $this->window->show();
    }

    private function centerOnParent(Window $parent): void
    {
        [$px, $py] = $parent->getPosition();
        [$pw, $ph] = $parent->getContentSize();
        [$w, $h]   = $this->window->getContentSize();

        $x = (int) ($px + ($pw - $w) / 2);
        $y = (int) ($py + ($ph - $h) / 2);

        $this->window->setPosition(max(0, $x), max(0, $y));
    }

    private function readForm(): array
    {
        $v = $this->form->values();
        return [
            'name'     => trim($v['连接名称'] ?? ''),
            'host'     => trim($v['主机'] ?? ''),
            'port'     => (int)trim($v['端口'] ?: '3306'),
            'user'     => trim($v['用户名'] ?? ''),
            'password' => $v['密码'] ?? '',
            'database' => trim($v['默认数据库'] ?? ''),
        ];
    }

    private function onTest(): void
    {
        $data = $this->readForm();
        $conn = new Connection(
            id: '',
            name: $data['name'],
            host: $data['host'],
            port: $data['port'],
            user: $data['user'],
            password: $data['password'],
            database: $data['database'],
        );
        $result = $this->store->test($conn);
        if ($result['ok']) {
            $this->status->setText("✅ 连接成功 | MySQL {$result['version']}");
        } else {
            $this->status->setText("❌ 连接失败：{$result['error']}");
        }
    }

    private function onSave(): void
    {
        $data = $this->readForm();
        if ($data['name'] === '' || $data['host'] === '' || $data['database'] === '') {
            $this->status->setText('❌ 名称、主机、默认数据库不能为空');
            return;
        }
        $id = preg_replace('/[^a-z0-9_-]/i', '_', $data['name']);
        $conn = new Connection(
            id: $id,
            name: $data['name'],
            host: $data['host'],
            port: $data['port'],
            user: $data['user'],
            password: $data['password'],
            database: $data['database'],
        );
        $this->store->add($conn);
        $this->status->setText("✅ 已保存：{$data['name']}");
        if ($this->onChange) {
            ($this->onChange)();
        }
    }

    private function onLoad(): void
    {
        $connections = array_values($this->store->list());
        if (!$connections) {
            $this->status->setText('没有已保存的连接');
            return;
        }
        $dlg = new Window('选择连接', 380, 160);
        $dlg->setMargined(true);
        $dlg->onClosing(function () {
            return true;
        });
        if ($this->window) {
            [$px, $py] = $this->window->getPosition();
            [$pw, $ph] = $this->window->getContentSize();
            [$w, $h]   = $dlg->getContentSize();
            $dlg->setPosition(max(0, (int)($px + ($pw - $w) / 2)), max(0, (int)($py + ($ph - $h) / 2)));
        }
        $box = new Box();
        $box->setPadded(true);

        $box->append(new Label('选择一个连接配置：'), false);

        $combo = new Combobox();
        $connList = [];
        foreach ($connections as $c) {
            $label = "{$c->name} ({$c->host}:{$c->port}/{$c->database})";
            $combo->append($label);
            $connList[] = $c;
        }
        $combo->setSelected(0);
        $box->append($combo, false);

        $btn = new Button('加载');
        $btn->onClicked(function () use ($combo, $connList, $dlg) {
            $idx = $combo->selected();
            if ($idx < 0 || !isset($connList[$idx])) return;
            $c = $connList[$idx];
            $this->form->setValues([
                '连接名称'  => $c->name,
                '主机'      => $c->host,
                '端口'      => (string)$c->port,
                '用户名'    => $c->user,
                '密码'      => $c->password,
                '默认数据库'=> $c->database,
            ]);
            $this->status->setText("已加载：{$c->name}");
            $dlg->hide();
        });
        $box->append($btn, false);

        $dlg->setChild($box);
        $dlg->show();
    }

    private function onDelete(): void
    {
        $data = $this->readForm();
        $id = preg_replace('/[^a-z0-9_-]/i', '_', $data['name']);
        if (!$this->store->get($id)) {
            $this->status->setText('连接不存在');
            return;
        }
        $this->store->remove($id);
        $this->form->setValues([]);
        $this->status->setText("已删除：{$data['name']}");
        if ($this->onChange) {
            ($this->onChange)();
        }
    }

    private function onImport(): void
    {
        $path = $this->window->dialogs()->openFile();
        if (!$path || !file_exists($path)) return;
        $json = file_get_contents($path);
        $count = $this->store->importJson($json);
        $this->status->setText("已导入 {$count} 条连接");
        if ($this->onChange) {
            ($this->onChange)();
        }
    }

    private function onExport(): void
    {
        $path = $this->window->dialogs()->saveFile('mysql-schema-sync-config.json');
        if (!$path) return;
        file_put_contents($path, $this->store->exportJson());
        $this->status->setText("已导出到：$path");
    }
}
