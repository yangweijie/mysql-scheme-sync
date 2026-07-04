# 任务栏图标修复 — 2026-07-04

## 最终方案

**根因：** Windows 任务栏图标由**窗口类小图标** `GCLP_HICONSM`（nIndex=-34）控制，不是 `WM_SETICON+ICON_SMALL`。

| API | 控制位置 |
|-----|---------|
| `SetClassLongPtrW(-34)` | 任务栏 |
| `SetClassLongPtrW(-14)` | Alt+Tab 后备 |
| `WM_SETICON + ICON_SMALL` | 标题栏 |
| `WM_SETICON + ICON_BIG` | Alt+Tab |

## 修改文件
`src/Gui/WebViewUI.php` — 新增 `setWindowIcons()` 方法：
1. `SetClassLongPtrW(hwnd, -34, hSmall)` → 任务栏图标
2. `SetClassLongPtrW(hwnd, -14, hLarge)` → Alt+Tab 后备
3. `WM_SETICON` → 标题栏 + Alt+Tab

PebView 的 `setWindowIcon()` 只调 `WM_SETICON+ICON_BIG`，不设置窗口类图标，所以任务栏一直显示缺省图标。

## 历史教训
旧代码 `SetWindowLongPtrW($h, -34, $i)` 概念正确（-34=GCLP_HICONSM），但用了错误的函数。`SetWindowLongPtrW` 的索引范围不包含 -34，-34 只在 `SetClassLongPtrW` 中有效。
