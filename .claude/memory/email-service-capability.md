---
name: email-service-capability
description: EmailService 统一邮件发送 — PHPMailer + mail() 双通道·SMTP 环境变量自动检测·3发信点统一
metadata: 
  node_type: memory
  type: reference
  originSessionId: 3f0ca7da-a3a6-4073-99c0-4fc74ccbdc4a
---

# EmailService — 统一邮件发送能力

## 调用方式

```php
use Converge\Services\EmailService;

$email = new EmailService($db);
$email->send($to, $subject, $htmlBody);                          // 通用发送
$email->sendPasswordResetEmail($email, $token, $resetUrl);       // 密码重置专用
```

## SMTP 配置

5 个环境变量（全部可选，未配则走 `mail()` 降级）:

| 常量 | 环境变量 | 默认值 |
|------|------|------|
| `SMTP_HOST` | `SMTP_HOST` | `''` (走 mail()) |
| `SMTP_PORT` | `SMTP_PORT` | `587` |
| `SMTP_USER` | `SMTP_USER` | `''` |
| `SMTP_PASS` | `SMTP_PASS` | `''` |
| `SMTP_FROM` | `SMTP_FROM` | `''` (自动: noreply@domain) |

只要 `SMTP_HOST` 非空，`use_smtp` 即自动启用。

## 发信点 (3 处)

| 发信点 | 方法 | 文件 |
|------|------|------|
| 密码重置 | `sendPasswordResetEmail()` | `public/forgot-password.php` |
| 欢迎邮件 | `send()` | `src/SaaS/Provisioner.php` |
| 告警通知 | `AlertNotifier::sendEmail()` (独立 PHPMailer) | `src/Observability/AlertNotifier.php` |

## 通道降级链

```
PHPMailer→SMTP (配了SMTP_HOST)
    ↓ 失败
PHPMailer→PHP mail() (PHPMailer 已安装但 SMTP 未配)
    ↓ 未安装
PHP mail() 裸发
```

开发模式 (`APP_ENV !== 'production'`): 仅记日志不真发。

## 依赖

- `phpmailer/phpmailer` (composer, 已安装)
- `config.php` — `SMTP_HOST`/`SMTP_PORT`/`SMTP_USER`/`SMTP_PASS`/`SMTP_FROM`/`SMTP_ENCRYPTION`
- `mysqli $db` (构造函数注入, 但通用 send() 不用 DB)

## 相关记忆

- [[converge-session-summary]] — 创建背景
- [[converge-deployment]] — 部署时 SMTP 配置
