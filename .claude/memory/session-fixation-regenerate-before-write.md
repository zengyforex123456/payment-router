---
name: session-fixation-regenerate-before-write
description: session_regenerate_id 必须在写 $_SESSION 之前调用，不是之后
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# Session 固定漏洞：regenerate_id 顺序

**检测模式**: `session_regenerate_id(true)` 出现在 `$_SESSION['user_id'] = ...` 之后

**根因**: 先写敏感数据（user_id/tenant_id）再换 Session ID → 旧 Session ID 在短暂窗口期内拥有登录态数据。

**正确顺序**:
```php
// ✅ 正确
session_start();
// 验证密码...
session_regenerate_id(true);  // ← 先销毁旧 session，创建新 ID
$_SESSION['user_id'] = $user['id'];  // ← 在新 session 上写敏感数据
$_SESSION['logged_in'] = true;

// ❌ 错误（Converge Auth.php 原始代码）
$_SESSION['user_id'] = $user['id'];  // ← 先写敏感数据
$_SESSION['logged_in'] = true;
session_regenerate_id(true);  // ← 后换 ID（窗口期旧 ID 已有登录态）
```

**原理**:
- `session_regenerate_id(true)` 的 `true` 参数 = 删除旧 session 文件
- 当前 `$_SESSION` 数据保留在内存 → 写入新 session 文件
- 如果在 regenerate 之前设置了 `$_SESSION`，旧 session 文件也存在一份（虽然会被删）
- 窗口期极短（毫秒级），但竞争条件理论上存在

**验证**:
```bash
grep -n 'session_regenerate_id' src/Auth/Auth.php
# 确认行号在 $_SESSION['user_id'] 赋值之前
```

**Why**: Session fixation 被 OWASP 列为 A2 级风险。修复只需调整调用顺序，零成本。

**How to apply**: 所有登录处理文件都检查这 3 行的相对顺序：`session_regenerate_id` 必须在 `$_SESSION['user_id']` 之前。
