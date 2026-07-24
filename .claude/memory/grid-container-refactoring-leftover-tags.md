---
name: grid-container-refactoring-leftover-tags
description: Grid::container() refactored to raw <main> left stray </div> and missing </main>
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Grid::container() 重构为裸 `<main>` 标签：遗留关闭标签不匹配

**检测模式**: 页面源代码中有 `<!-- end Grid::container -->` 注释但前后找不到 `Grid::container()` 调用或对应的 `<div class="max-w-6xl...">`

**根因**: landing.php 从 PHP `Grid::container()` 调用重构为裸 HTML `<main class="grid grid-cols-12...">` 时：
1. 忘记加 `</main>` 关闭标签（PHP 函数自动输出关闭 `</div>`）
2. 遗留了多余的 `</div>` 和一个无意义的注释

**修复**:
1. 删除 `<!-- end Grid::container -->` 注释
2. 删除 stray `</div>`
3. 添加 `</main>` 在 footer include 之前

**验证**: `php -l` 通过，HTML 结构 `<main>...</main><footer>...</footer>` 正确

**检测方法**: `grep -n '</\?main\|Grid::container\|end Grid' *.php` 检查容器开闭标签匹配

**相关知识**: [[main-grid-unclosed-footer-trapped]]
