# MySQL SchemaSync — PHP + libui 桌面版

基于 PHP 8.5 + FFI + [helgesverre/libui](https://github.com/helgesverre/libui) 实现的 MySQL 数据库结构对比与迁移 SQL 生成工具。

## 环境要求

- PHP 8.5+（带 FFI、PDO、openssl 扩展）
- macOS / Linux / Windows
- Composer

> macOS ARM 已内置 libui-ng 预编译二进制，开箱即用。

## 安装

```bash
cd mysql-schema-sync-php
composer85 install
# 或：php85 /path/to/composer.phar install
```

## 运行

```bash
php85 bin/mysql-schema-sync.php
```

或（若已通过 composer 全局安装 bin）：

```bash
mysql-schema-sync.php
```

## 功能

| 功能 | 状态 |
|------|------|
| 多连接配置管理（增删改查、测试连接） | ✅ |
| AES-256-GCM 加密存储密码 | ✅ |
| 配置 JSON 导入/导出 | ✅ |
| 表/列/索引/外键/触发器/视图/SP/函数/事件对比 | ✅ |
| glob 模式排除备份/临时表 | ✅ |
| 生成迁移 SQL（ALTER / CREATE / DROP） | ✅ |
| 风险等级标注（SAFE / WARN / HIGH） | ✅ |
| SQL 一键复制 / 保存为 .sql | ✅ |

## 项目结构

```
mysql-schema-sync-php/
├── bin/mysql-schema-sync.php      # GUI 入口
├── src/
│   ├── Config/                    # 加密连接配置
│   ├── Diff/                      # INFORMATION_SCHEMA 读取 + 差异计算
│   ├── SqlGen/                    # 迁移 SQL 生成
│   └── Gui/                       # libui 界面层
├── composer.json
└── README.md
```

## 已知限制

- 当前 SQL 生成器对 `CREATE TABLE` 完整列定义使用占位符，需后续根据源库列元数据补全。
- 选择性差异生成（勾选部分变更）在 libui 简单静态表格中实现较复杂，后续可用 TableModelDelegate 实现。

## 技术栈

- `helgesverre/libui`：PHP FFI 原生桌面 GUI
- `ext-pdo` + `ext-pdo_mysql`：MySQL 元数据读取
- `ext-openssl`：AES-256-GCM 配置加密
- `ext-ffi`：libui C 库绑定
