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
| 生成迁移 SQL（ALTER / CREATE / DROP，完整列定义） | ✅ |
| 风险等级标注（SAFE / WARN / HIGH，颜色标记） | ✅ |
| SQL 一键复制 / 保存为 .sql | ✅ |
| 可勾选差异表格（按行点选/全选/按风险筛选） | ✅ |
| 源/目标库互换 | ✅ |
| 已选差异计数（已选 N/M） | ✅ |
| 分类摘要（新增 N 表 / 变更 N 表 / 删除 N 表） | ✅ |

## 项目结构

```
mysql-schema-sync-php/
├── bin/mysql-schema-sync.php      # GUI 入口
├── src/
│   ├── Config/                    # 加密连接配置
│   ├── Diff/                      # INFORMATION_SCHEMA 读取 + 差异计算
│   ├── SqlGen/                    # 迁移 SQL 生成（基于源库完整元数据）
│   └── Gui/                       # libui 界面层（可勾选 Table 表格）
├── composer.json
└── README.md
```

## 技术栈

- `helgesverre/libui`：PHP FFI 原生桌面 GUI
- `ext-pdo` + `ext-pdo_mysql`：MySQL 元数据读取
- `ext-openssl`：AES-256-GCM 配置加密
- `ext-ffi`：libui C 库绑定
