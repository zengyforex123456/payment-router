# 安全编码规范 — OWASP Top 5 防御

> 层: L2 工程规范 | 版本: v1.0 | 来源: 提取自 01-implement.md OWASP段

## Trigger

P2 编码阶段 / P4 审查阶段 / "安全" "审计" "漏洞" 关键字

## Input

- 代码上下文（语言、框架、数据库类型）
- 依赖列表（npm audit / pip audit 输出）

## Action

### OWASP 防御规则

| 风险 | 防御 | 验证方式 |
|------|------|------|
| SQL 注入 | 参数化查询（禁止字符串拼接 SQL） | 检查所有 DB 调用 |
| XSS | 用户输入转义、CSP 头 | 检查模板/JSX |
| 目录遍历 | 文件路径验证（拒绝 `../`） | 检查所有文件操作 |
| 硬编码密钥 | 环境变量（`process.env.X`） | grep 搜索 API_KEY/SECRET |
| 日志泄露 | 不记录密码/token/身份证号 | 检查日志语句 |

### 审查检查清单

```
□ 所有 SQL 使用参数化查询
□ 用户输入经过转义
□ 文件路径经过验证
□ 无硬编码密钥/Token
□ 日志不包含敏感信息
□ npm audit / pip audit 0 high/critical
```

## Output

- 安全检查清单（pass/fail 每项）
- 安全漏洞列表（如有）

## Interface Contract

- **消费者**: `06-review-process.md`（P4 安全维度审查）、`06-coding-standards.md`（P2 编码引用）
- **依赖**: 无
- **输出格式**: 检查清单 + 漏洞列表
- **约定**: 0 high/critical 漏洞 → 通过；任一高危 → 阻塞上线
