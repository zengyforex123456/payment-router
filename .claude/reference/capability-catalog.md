# converge-core 完整能力目录

> 写业务代码前查此表——以下模块 converge-core 已提供，无需重复实现。
> 从 CLAUDE.md 引用: "需要X时查 `.claude/reference/capability-catalog.md`"

## Contracts 层 — 六边形端口

| 接口 | 命名空间 | 何时使用 |
|------|------|------|
| `HookInterface` | `Converge\Contracts` | 需自定义 Hook 引擎时（通常直接用 `Hooks` 静态类） |
| `ModuleInterface` | `Converge\Contracts` | 需自定义模块加载逻辑时 |
| `CacheInterface` | `Converge\Contracts` | 需换缓存后端时（默认 ArrayCache） |
| `DatabaseInterface` | `Converge\Contracts` | **所有数据访问必须通过此接口** |
| `AuthInterface` | `Converge\Contracts` | 需换认证方案时（默认用 Auth 类） |
| `EventDispatcherInterface` | `Converge\Contracts` | 模块间事件通信 |

## Core 层 — 引擎

| 类 | 命名空间 | 功能 | 使用场景 |
|------|------|------|------|
| `Hooks` | `Converge\Core\Hook` | Action + Filter 插件系统 | 跨模块通信、功能扩展 |
| `HookPoints` | `Converge\Core\Hook` | 20+ 预定义钩子点常量 | 查找系统预留钩子 |
| `EventDispatcher` | `Converge\Core\Hook` | 类型化事件分发 | 领域事件发布 |
| `ModuleLoader` | `Converge\Core\Module` | 拓扑排序模块加载 | 启动时加载所有模块 |
| `FabricManager` | `Converge\Core\Module` | 子系统注册表 | 系统健康检查 |
| `DataEncoder` | `Converge\Core\Helper` | XSS 安全编码 (JSON + HTML 上下文感知) | 数据注入到 Stimulus/TDA |
| `Sanitizer` | `Converge\Core\Helper` | 用户输入净化 | 表单/查询参数 |

## Security 层 — 认证授权

| 类 | 命名空间 | 功能 |
|------|------|------|
| `Auth` | `Converge\Security` | 完整认证：Argon2ID/速率限制/记住我/密码重置 |
| `AdminGate` | `Converge\Security` | 管理员 IP 白名单（5层防御） |
| `ApiAuth` | `Converge\Security` | API 端点权限守卫 |
| `Permission` | `Converge\Security` | RBAC：4角色 / 22权限 |
| `RoleCapability` | `Converge\Security` | 细粒度能力系统（30+权限） |
| `DualAuth` | `Converge\Security` | 双通道鉴权（API Key + Session） |
| `Csrf` | `Converge\Security` | CSRF Token 生成/验证 |
| `AuditLogger` | `Converge\Security` | 操作审计日志 |
| `SingleAdminMode` | `Converge\Security` | 自托管单管理员模式 |

## Foundation 层 — 基础设施

### Resilience（弹性）
| 类 | 命名空间 | 功能 |
|------|------|------|
| `CircuitBreaker` | `Converge\Foundation\Resilience` | 三态断路器 CLOSED→OPEN→HALF_OPEN |
| `RetryHandler` | `Converge\Foundation\Resilience` | 指数退避+抖动+死信队列 |
| `FallbackManager` | `Converge\Foundation\Resilience` | 多 Provider 降级链 |

### Observability（可观测）
| 类 | 命名空间 | 功能 |
|------|------|------|
| `StructuredLogger` | `Converge\Foundation\Observability` | PSR-3 JSON 结构化日志 |
| `HealthChecker` | `Converge\Foundation\Observability` | 4维健康检查 DB/Redis/GeoIP/磁盘 |
| `AlertNotifier` | `Converge\Foundation\Observability` | 三通道告警 Telegram/Webhook/Email |
| `RequestIdMiddleware` | `Converge\Foundation\Observability` | UUID v4 请求追踪 |

### System（系统工具）
| 类 | 命名空间 | 功能 |
|------|------|------|
| `DeployMode` | `Converge\Foundation\System` | 三模式中枢 自托管/SaaS/企业 |
| `FeatureRegistry` | `Converge\Foundation\System` | 运行时功能开关+计划限制 |
| `LicenseManager` | `Converge\Foundation\System` | 三重防破解许可证 |
| `SnapshotGenerator` | `Converge\Foundation\System` | 仪表盘快照 每5分钟 |
| `SnapshotLoader` | `Converge\Foundation\System` | 四级降级快照读取 永不白屏 |
| `HumanError` | `Converge\Foundation\System` | 技术错误→中文翻译 |
| `LlmKeyResolver` | `Converge\Foundation\System` | LLM API Key 三级降级 |
| `MysqlAdapter` | `Converge\Foundation\System` | DatabaseInterface 实现 |
| `ArrayCache` | `Converge\Foundation\System\Cache` | 内存缓存 CacheInterface 实现 |

## I18n + Verify

| 类/函数 | 命名空间 | 功能 |
|------|------|------|
| `Locale` | `Converge\I18n` | 语言检测链 URL→Session→Cookie→Header |
| `__()` | 全局函数 | 翻译函数，所有用户可见文案 |
| `lang()` | 全局函数 | 当前语言代码 |
| `AssertionEngine` | `Converge\Verify` | 6层22项生产运行时断言 |

## 快速选择指南

```
需要用户登录？           → Auth (Security)
需要权限控制？           → Permission + RoleCapability (Security)
需要 CSRF 保护？         → Csrf (Security)
需要调用外部 API？       → CircuitBreaker + RetryHandler (Resilience)
需要记录日志？           → StructuredLogger (Observability)
需要系统健康检查？       → HealthChecker (Observability)
需要发告警？             → AlertNotifier (Observability)
需要模块间通信？         → Hooks (Core)
需要按套餐控制功能？     → FeatureRegistry (System)
需要多语言？             → __() + Locale (I18n)
需要部署后自动验证？     → AssertionEngine (Verify)
需要输出 JSON 到前端？→ DataEncoder (Core)
```
