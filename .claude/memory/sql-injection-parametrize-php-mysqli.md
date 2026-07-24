---
name: sql-injection-parametrize-php-mysqli
description: PHP mysqli 字符串拼接 SQL → prepare+bind_param 的标准转换模式
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# PHP mysqli SQL 注入修复模式

**检测模式**: `->query("...{$var}...")` 或 `->query("...' . $var . '...")`

**根因**: 字符串拼接 SQL，用户输入可直接注入

**修复（标准转换）**:
```php
// ❌ 拼接
$this->db->query("INSERT INTO t (a, b) VALUES ({$a}, '{$b}')");

// ✅ 参数化
$stmt = $this->db->prepare("INSERT INTO t (a, b) VALUES (?, ?)");
$stmt->bind_param('is', $a, $b);  // i=int, s=string, d=double
$stmt->execute();
$stmt->close();
```

**bind_param 类型码**:
| 码 | 类型 |
|:--:|------|
| i | integer |
| s | string |
| d | double/float |
| b | blob |

**SELECT 结果读取**:
```php
$stmt = $this->db->prepare("SELECT x FROM t WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
```

**算术注入特殊处理**：SQL 里的 `{$percent}/100` 不能在 bind_param 里做——先在 PHP 里算好，再 bind：
```php
// ❌ "UPDATE t SET v = v * (1 + {$percent}/100) WHERE id = {$id}"
// ✅
$factor = 1 + $percent / 100;
$stmt = $this->db->prepare("UPDATE t SET v = v * ? WHERE id = ?");
$stmt->bind_param('di', $factor, $id);
```

**验证**: `grep -rn '\->query(".*\$' src/ --include="*.php"` 扫零残留

**Why**: 拼接 SQL 是 OWASP #1 风险。参数化查询是唯一正确的修复方式——转义函数 (`real_escape_string`) 不够，边缘情况仍有注入可能。

**How to apply**: 新项目初始化即禁 `->query()` 拼接，CI 加 grep 阻断。
