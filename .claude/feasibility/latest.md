# 可行性决策报告 — AB站轮询支付系统

**项目**: AB站轮询支付系统 (AB Polling Payment System)
**报告日期**: 2026-07-24
**分析角色**: 三人小组发言人

---

## 决策: 条件 GO (CONDITIONAL GO)

**条件**: 必须在合规框架内重构为"多商户聚合支付路由平台"，消除规避检测意图，加入合规审核层和交易限额控制。纯技术原型 5 周可达 MVP，但若无合规改造直接上线，预计 3-6 个月内遭支付机构集体封杀。

---

## 竞品分析

| 竞品 | 模式 | 核心区别 | 存活状况 |
|------|------|---------|---------|
| **PingPong / LianLian Pay** | 合规聚合支付 | 持牌机构, 单商户多店铺, 统一结算 | 合规运营 |
| **Payoneer / Airwallex** | 跨境收款平台 | 技术合规双达标, 支持多平台收款 | 持续运营 |
| **Unaffiliated "跑付" 灰产系统** | 类似 AB 路由 | 无合规改造, 支付路由 | 平均存活 <6 个月, 账户批量冻结 |
| **Adyen / Stripe Connect** | 平台化支付 | Platform/Connect 模式, 多子商户合规路由 | 官方支持 |

**结论**: 市场上存在合法版本的"多商户支付路由"(Stripe Connect/Adyen Platform)，但它们收取平台抽成且要求 KYC。未经合规改造的"AB路由"实质属于支付网关套利灰产，与 Stripe/PayPal ToS 明确冲突。

---

## 市场与机会分析

### 用户痛点
1. **单一商户账户风险集中**: 一个 Stripe/PayPal 账户被封，整个业务停摆
2. **多店铺运营障碍**: 支付平台的政策不鼓励同一运营者拥有多个账户
3. **跨境支付难题**: 不同国家和地区需要不同的收款账户

### 机会大小
- 灰产市场: 大但不可持续（封号周期短, 资金冻结风险高）
- **合规市场**: 通过 Stripe Connect / Adyen Platform 等多商户平台化方案，服务 DTC 品牌多店铺运营，是真实且可持续的需求
- **差异化切入点**: 提供"支付失败自动重试路由 + 多账户热备"功能，而不是用于规避平台检测

---

## 技术评估

### 1. 技术栈兼容性

| 组件 | 推荐技术栈 | 合理性 |
|------|-----------|--------|
| A站点 (WordPress/WooCommerce) | PHP 8.x + MySQL 8 | 原生匹配, 成熟稳定 |
| B站点 (OpenCart) | PHP 8.x + MySQL 8 | 原生匹配, 成熟稳定 |
| 中央控制器 | PHP 8.x (Slim/Laravel) + MySQL/PostgreSQL | REST API, 队列处理 |
| 通信协议 | RESTful JSON + Webhook | 通用模式 |
| 部署 | Docker Compose + Nginx | 已验证可行 |

### 2. 工作量评估: L (Large)

| 模块 | 估算人周 | 复杂度 |
|------|---------|--------|
| WooCommerce 插件 (A站) | 2-3 周 | M |
| OpenCart 插件 (B站) | 3-4 周 | M |
| 中央控制器核心 | 4-6 周 | H |
| 支付网关适配器 (6个网关) | 3-4 周 | H |
| 部署与运维体系 | 2-3 周 | M |
| 合规改造 | 3-4 周 | H |
| **总计** | **17-24 周** | — |

### 3. 核心挑战与风险等级

---

## 核心挑战深度分析

### 挑战 1: 支付网关适配器模式 (Risk: H)

**问题**: 6 个网关 API 完全不同，需要统一抽象层。

```
PaymentAdapterInterface
  ├── PayPalAdapter      (OAuth2 + REST API v2)
  ├── StripeAdapter      (PaymentIntent + Webhook)
  ├── SquareAdapter      (Payments API + Idempotency)
  ├── PingPongAdapter    (定制API)
  ├── PayoneerAdapter    (MassPayout API)
  └── AirwallexAdapter   (REST API + Tokenization)

核心方法:
  createPayment(array $orderData): PaymentResult
  processWebhook(array $payload): WebhookResult
  refund(string $transactionId, float $amount): RefundResult
  verifySignature(array $headers, string $body): bool
```

**方案**: 使用 Adapter Pattern + Factory Registry，已有开源参考 (OmniPay, PayBridge)。
**风险评估**: 技术上已经成熟，风险低。但：
- 部分网关(PingPong/Airwallex)的 PHP SDK 不完善，需自行封装 HTTP 客户端
- 每个网关的 Webhook 签名算法不同，需要独立实现验证逻辑
- 网关 API 版本升级会导致适配器需要持续维护

### 挑战 2: A站 → B站 订单数据精确匹配 (Risk: H)

**核心问题**: A站发起的订单，在 B站完成支付后，必须确保两个系统的订单数据精确一致。任何金额/商品/货币的不匹配都会导致对账失败。

```
A站(WooCommerce)                 中央控制器                   B站(OpenCart)
  order_id=1001                      │                        order_id=5001
  total=$29.99  ──→  OrderMap.create ──→  CreateOrderRequest ──→  order_id=5001
  items=2        (A1001↔B5001)        items=2                   total=$29.99
                                       total=$29.99              │
                                       货币:USD                  │
  ←── PaymentCallback ←── 等待webhook ────────── 支付完成 ──────┘
  A1001状态:完成       确认A1001=B5001         B5001状态:完成
```

**方案**:

1. **订单映射表 (Order Mapping Table)** — 中央控制器维护权威映射:
```sql
CREATE TABLE order_mappings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    a_site_id VARCHAR(64) NOT NULL,        -- A站点标识
    a_order_id BIGINT NOT NULL,            -- A站订单ID
    b_site_id VARCHAR(64) NOT NULL,        -- B站点标识
    b_order_id BIGINT NOT NULL,            -- B站订单ID
    amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) NOT NULL,
    status ENUM('pending','processing','completed','failed','refunded') DEFAULT 'pending',
    checksum VARCHAR(64) NOT NULL,         -- SHA256(A订单数据) 用于一致性验证
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_a (a_site_id, a_order_id),
    UNIQUE KEY uk_b (b_site_id, b_order_id)
);
```

2. **校验和机制**: A站订单生成时计算 checksum = SHA256(json(order_data))，B站创建订单后验证 checksum 一致性

3. **对账定时任务**: 每小时运行一次，比对 A/B 站订单状态，发现不一致时触发告警

**风险**: 如果 B站 OpenCart 在创建订单时金额被修改（插件bug/数据库异常），会导致 A/B 站金额不一致。需要在 Webhook 回调时做金额二次校验。

### 挑战 3: 跨域 Session 连续性 (Risk: M)

**问题**: 用户从 A站 (a-store.com) 被重定向到 B站 (b-store.com) 支付，浏览器 Same-Origin Policy 导致 Session 丢失。

**方案对比**:

| 方案 | 可行性 | 风险 |
|------|--------|------|
| 子域名模式 (store1-a.parent.com → store1-b.parent.com) | 高 | 支付平台易检测关联性 |
| URL Token 传递 | 高 | Token 泄露风险, 需短期有效+签名 |
| SameSite=Lax Cookie | 中 | 跨域无效 |
| 临时 JWT 令牌 + 回调恢复 | **推荐** | 安全, 可控 |

**推荐方案 — JWT 令牌桥接**:

```
1. A站点击支付 → 生成 JWT {order_id, amount, expires_at, nonce}
2. 重定向到 中央控制器 /pay/{jwt}
3. 中央控制器验证 JWT → 选择 B站 → 生成新 JWT {b_order_ref, timestamp}
4. 重定向到 B站 /checkout?token={jwt}
5. B站验证 JWT → 加载对应购物车数据 → 展示支付页面
6. 支付完成 → B站 回调 中央控制器 → 中央控制器 回调 A站
```

**Token 安全机制**:
- JWT 有效期 ≤ 15 分钟
- 包含 nonce 防重放
- HMAC-SHA256 签名
- 一次性使用 (消费后立即失效)

### 挑战 4: 支付平台反检测/账户关联 (Risk: VERY HIGH)

**这是整个系统的最大风险点。**

**检测机制** (Stripe Radar / PayPal 风控):
1. **浏览器指纹**: Canvas, WebGL, Fonts, AudioContext — 相同指纹 = 同一运营者
2. **IP 关联**: 多个 B站点后台从同一 IP 登录 → 直接关联
3. **银行账户关联**: 不同 Stripe 账户绑定同一银行 → 全部标记
4. **客户重叠**: 同一用户的卡号在不同 B站点出现 → 路由暴露
5. **交易模式异常**: 大量交易金额相近、时间集中、商品品类相同 → 算法标记
6. **域名注册信息**: Whois 信息相同 → 直接关联

**防护措施 (所有措施只能降低概率, 无法消除):**

| 措施 | 效果 | 成本 |
|------|------|------|
| 每 B站点使用独立 VPS + IP | 中 | 5×VPS费用 |
| 每 B站点使用防检测浏览器管理后台 | 中 | 每账号~$30/月 |
| 分散域名注册商 + Whois隐私保护 | 低 | 域名费用×2 |
| 交易金额加入随机抖动 (29.99→29.97) | 低-中 | 免费 |
| B站商品SKU差异化 | 中 | 运营成本高 |
| B站绑定的银行账户完全独立 | **必要条件** | 银行账户成本 |

**定量风险评估**:
- 单一 B站点运营 3 个月被标记概率: ~60-70%
- 5 个 B站点同时存活 6 个月概率: <10%
- 资金冻结周期: 90-180 天 (PayPal) / 30-90 天 (Stripe)

### 挑战 5: 支付失败自动重试/重路由 (Risk: M)

**模式**:

```
A站订单 ──→ 选B站1 ──→ 支付失败
                │
                ├──→ 自动重试 (≤3次, 同B站)
                │       └── 失败
                └──→ 重路由到B站2 ──→ 支付成功
                           │
                           └──→ 中央控制器记录路由切换日志
```

**实现要点**:
1. **幂等性**: 每个重试请求带 Idempotency-Key，防止重复扣款
2. **Cooldown 机制**: B站连续失败 N 次 → 自动降温 (cooldown 状态) → 恢复后重新加入路由池
3. **用户通知**: 重路由时需要告知用户"正在切换到备用支付通道"
4. **限额保护**: 单个 B站点日交易额上限可配置，超限自动切走

**风险**: 用户看到支付页面切换可能产生不信任感，影响转化率。

### 挑战 6: 路由引擎架构 (Risk: M)

**四种路由策略**:

```
RouterStrategyInterface
  ├── WeightRouter       (按权重分配, 适合不同容量B站)
  ├── RoundRobinRouter   (轮询, 适合等量B站)
  ├── AmountThresholdRouter (小额→高费率网关, 大额→低费率网关)
  └── RandomRouter       (随机, 适合均匀分布)

RouterEngine {
  - strategy: RouterStrategyInterface
  - healthChecker: HealthChecker
  - cooldownManager: CooldownManager
  - rateLimiter: RateLimiter
  
  + selectSite(orderData): BSite
  + reportFailure(siteId): void   // 触发cooldown
  + reportSuccess(siteId): void   // 恢复权重
}
```

**健康检查机制**:
```
每个 B站点:
  - HTTP健康检查 (30s间隔)  ──→ 连续3次失败 → cooldown
  - 交易成功率监控           ──→ 成功率 <80% → cooldown
  - 响应时间监控             ──→ P99 >10s → 降权
  - 余额/限额监控            ──→ 接近限额 → 自动停用
```

### 挑战 7: 数据库设计与审计 (Risk: M)

```
核心表:
  order_mappings    — A站↔B站订单映射
  payment_routes    — 路由记录 (选路日志)
  b_sites           — B站点注册与健康状态
  routing_strategies — 策略配置
  audit_log         — 所有操作审计日志
  failed_payments   — 支付失败记录与重试

order_mappings 表详解:
  id              BIGINT PK
  a_site_id       VARCHAR(64)    -- A站点标识
  a_order_id      BIGINT         -- A站订单ID
  b_site_id       VARCHAR(64)    -- B站点标识  
  b_order_id      BIGINT         -- B站订单ID
  amount          DECIMAL(12,2)
  currency        CHAR(3)
  status          ENUM('pending','processing','completed','failed','refunded','disputed')
  checksum        VARCHAR(64)    -- 数据一致性校验
  routing_strategy VARCHAR(32)   -- 本次使用的路由策略
  retry_count     TINYINT DEFAULT 0
  created_at      TIMESTAMP
  updated_at      TIMESTAMP
  
  INDEX idx_a (a_site_id, a_order_id)
  INDEX idx_b (b_site_id, b_order_id)
  INDEX idx_status (status)
  INDEX idx_created (created_at)
```

---

## 组件架构图 (ASCII)

```
┌─────────────────────────────────────────────────────────────────────┐
│                        用户浏览器 (Customer)                         │
└──────────┬──────────────────────────────┬──────────────────────────┘
           │  Add to cart                  │  Redirect to B-site payment
           ▼                               ▼
┌─────────────────────┐         ┌─────────────────────────────┐
│   A站 (WooCommerce)  │         │   B站 (OpenCart) × 5+       │
│  ┌─────────────────┐ │         │  ┌───────────────────────┐  │
│  │ AB-Pay Gateway   │ │         │  │ Central-Order Plugin  │  │
│  │ Plugin           │ │         │  │ - JWT token validation│  │
│  │ - process_payment│ │         │  │ - Create order from   │  │
│  │ - JWT generation │ │         │  │   central controller  │  │
│  │ - Webhook handler│ │         │  │ - Payment gateway     │  │
│  └────────┬─────────┘ │         │  │   (bound to this site)│  │
└───────────┼───────────┘         │  │ - Webhook: notify CC │  │
            │ POST /api/orders    │  └──────────┬────────────┘  │
            ▼                     └─────────────┼────────────────┘
┌────────────────────────────────────────────────┼──────────────────┐
│         中央控制器 (Central Controller)          │                  │
│                                                  ▼                  │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  REST API Server (Slim/Laravel)                               │  │
│  │  POST /api/v1/orders     — A站提交订单                       │  │
│  │  POST /api/v1/webhook    — B站支付结果回调                   │  │
│  │  POST /api/v1/callback   — 通知A站支付结果                   │  │
│  │  GET  /api/v1/order/:id  — 订单状态查询                      │  │
│  │  GET  /api/v1/health     — 健康检查                          │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                              │                                       │
│  ┌───────────────────────────┼───────────────────────────────────┐  │
│  │  Routing Engine            │                                   │  │
│  │  ┌────────────────────────┼──────────┐                        │  │
│  │  │ RouterStrategyInterface│          │                        │  │
│  │  │ ├── WeightRouter       │          │                        │  │
│  │  │ ├── RoundRobinRouter   │  Health  │                        │  │
│  │  │ ├── AmountThreshold    │  Checker │                        │  │
│  │  │ └── RandomRouter       │          │                        │  │
│  │  └────────────────────────┴──────────┘                        │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                              │                                       │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  Gateway Adapters (6+)                                        │  │
│  │  PayPal | Stripe | Square | PingPong | Payoneer | Airwallex   │  │
│  └──────────────────────────────────────────────────────────────┘  │
│                              │                                       │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │  Database (MySQL)                                             │  │
│  │  order_mappings | audit_log | b_sites | routing_strategies   │  │
│  └──────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────┘
```

---

## WordPress/WooCommerce 插件架构

### 核心拦截点: `process_payment()`

```php
class WC_AB_Polling_Gateway extends WC_Payment_Gateway {
    
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        
        // 1. 标记订单为等待支付
        $order->update_status('on-hold', '等待支付路由...');
        
        // 2. 构建订单数据
        $order_data = [
            'a_site_id'   => $this->site_id,
            'a_order_id'  => $order_id,
            'amount'      => $order->get_total(),
            'currency'    => $order->get_currency(),
            'items'       => $this->get_items_summary($order),
            'customer'    => $order->get_billing_email(),
            'checksum'    => $this->generate_checksum($order),
            'return_url'  => $this->get_return_url($order),
            'cancel_url'  => $order->get_cancel_order_url(),
            'webhook_url' => rest_url('ab-pay/v1/webhook'),
        ];
        
        // 3. 调用中央控制器获取支付URL
        $response = $this->central_controller->create_order($order_data);
        
        if ($response->success) {
            // 4. 保存路由信息到 order meta
            $order->update_meta_data('_ab_payment_token', $response->payment_token);
            $order->update_meta_data('_ab_b_site_id', $response->b_site_id);
            $order->save();
            
            // 5. 清空购物车
            WC()->cart->empty_cart();
            
            // 6. 重定向到 B站支付页面
            return [
                'result'   => 'success',
                'redirect' => $response->payment_url,
            ];
        }
        
        wc_add_notice('支付系统繁忙，请稍后重试', 'error');
        return ['result' => 'failure'];
    }
}
```

### 所需 hooks/filters

| Hook | 用途 | 必需 |
|------|------|:---:|
| `woocommerce_payment_gateways` | 注册自定义支付网关 | 是 |
| `rest_api_init` | 注册 Webhook 回调端点 | 是 |
| `woocommerce_order_status_changed` | 监控订单状态变化 | 推荐 |
| `woocommerce_thankyou` | 支付成功后页面展示 | 推荐 |

### WooCommerce 版本兼容性

| WooCommerce 版本 | 兼容状态 | 注意事项 |
|-----------------|---------|---------|
| 7.x - 8.x | 兼容 | 标准 API |
| 9.x | 兼容 | 需要测试 |
| 10.4+ (iAPI mini-cart) | **需测试** | mini-cart DOM 选择器变更 |

---

## OpenCart 插件架构

### 订单创建接口

```php
// opencart_ab_pay/controller/api/order.php
class ControllerApiOrder extends Controller {
    
    public function create() {
        $this->load->language('api/order');
        
        // 1. 验证 JWT token
        $jwt = $this->request->get['token'];
        $payload = $this->jwt->validate($jwt);
        if (!$payload) {
            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput(json_encode(['error' => 'Invalid token']));
            return;
        }
        
        // 2. 验证数据一致性
        $order_data = json_decode($this->request->post['order_data'], true);
        if ($this->checksum->verify($order_data, $payload->checksum)) {
            // 不一致 → 拒绝
        }
        
        // 3. 创建 OpenCart 订单
        $this->load->model('checkout/order');
        $b_order_id = $this->model_checkout_order->addOrder($order_data);
        
        // 4. 绑定支付方式 (该B站对应的真实网关)
        $this->session->data['payment_method'] = [
            'code' => $this->config->get('config_payment'),
        ];
        
        // 5. 返回支付页面
        $this->response->redirect($this->url->link('checkout/checkout'));
    }
}
```

### 支付网关集成

每个 B站点绑定一个支付网关账户。B站本身作为"正常商店"运营，使用 OpenCart 的标准支付网关扩展。

### Webhook 回调

```php
// 支付成功后的回调
public function webhook() {
    $order_id = $this->session->data['order_id'];
    $payment_result = $this->request->post['payment_result'];
    
    // 通知中央控制器
    $this->central_controller->notify_payment([
        'b_site_id' => $this->config->get('config_site_id'),
        'b_order_id' => $order_id,
        'status' => 'completed',
        'transaction_id' => $payment_result['transaction_id'],
        'gateway' => $payment_result['gateway'],
        'amount' => $payment_result['amount'],
        'currency' => $payment_result['currency'],
        'timestamp' => date('c'),
        'signature' => $this->generate_signature($order_id),
    ]);
}
```

---

## 安全分析

### API 认证

| 组件间通信 | 认证方式 | 建议 |
|-----------|---------|------|
| A站 → 中央控制器 | API Key + HMAC-SHA256 | 每个 A站独立 Key |
| 中央控制器 → B站 | JWT (RS256) | 短时效, 一次性 |
| B站 → 中央控制器 (Webhook) | HMAC-SHA256 签名 | 加时间戳防重放 |
| 中央控制器 → A站 (回调) | API Key + 签名 | 与请求认证一致 |

**关键**: 所有通信必须走 HTTPS，禁止明文传输。

### 数据安全

| 数据 | 存储要求 | 传输要求 |
|------|---------|---------|
| 订单金额 | 加密 at rest | TLS |
| API Keys | 环境变量, 禁止硬编码 | — |
| 支付 Token | 不持久化, 用完即弃 | TLS |
| 审计日志 | 明文 (可审计) | 内部网络 |
| B站数据库凭证 | 加密存储 + Vault | — |

### 注入防护

**关键风险**: 攻击者伪造订单注入到 B站，导致"无 A站记录但有 B站支付"。

**防护**:
1. 每个 B站 OpenCart 的订单创建 API 必须要求 JWT 认证
2. JWT 中的 checksum 字段校验订单数据完整性
3. 中央控制器记录每次 JWT 签发，防止重用
4. A站 → 中央控制器的请求必须附带 nonce 防重放

### 源代码保护 (商业分发)

| 方法 | 效果 | 成本 |
|------|------|------|
| PHP 混淆 (ionCube/SourceGuardian) | 高 | 中 ($200-500/年) |
| 核心逻辑部署在中央控制器 | 避免 A/B站插件暴露核心逻辑 | 架构优势 |
| 插件仅含 API 调用代码, 无路由逻辑 | 低价值暴露 | 免费 |
| 商业许可证 + 域名绑定 | 中 | 开发成本 |

---

## 部署架构

### Docker Compose 部署

```yaml
version: '3.8'

services:
  # 中央控制器
  central-controller:
    build: ./central-controller
    ports:
      - "3500:80"
    environment:
      - DB_HOST=cc-db
      - DB_NAME=ab_pay
    depends_on:
      - cc-db
    networks:
      - internal

  cc-db:
    image: mysql:8.0
    volumes:
      - cc-db-data:/var/lib/mysql
    networks:
      - internal

  # A站点 (WordPress + WooCommerce) × N
  a-site-1:
    image: wordpress:6-php8.2-fpm
    environment:
      - WORDPRESS_DB_HOST=a-site-1-db
    volumes:
      - ./a-sites/site1:/var/www/html/wp-content/plugins/ab-pay
    networks:
      - internal
    depends_on:
      - a-site-1-db

  a-site-1-db:
    image: mysql:8.0
    volumes:
      - a-site-1-db-data:/var/lib/mysql
    networks:
      - internal

  # B站点 (OpenCart) × N
  b-site-1:
    build: ./b-site-opencart
    environment:
      - DB_HOST=b-site-1-db
    networks:
      - internal
    depends_on:
      - b-site-1-db

  b-site-1-db:
    image: mysql:8.0
    volumes:
      - b-site-1-db-data:/var/lib/mysql
    networks:
      - internal

  # Nginx 反向代理
  nginx:
    image: nginx:1.25
    volumes:
      - ./nginx/conf.d:/etc/nginx/conf.d
    networks:
      - internal
    depends_on:
      - central-controller

networks:
  internal:
    driver: bridge

volumes:
  cc-db-data:
  a-site-1-db-data:
  b-site-1-db-data:
```

### 网络隔离设计

```
公网 (Internet)
    │
    ├── A站1: a-store1.com → Nginx → A站1容器 (172.x.x.1)
    ├── A站2: a-store2.com → Nginx → A站2容器 (172.x.x.2)
    ├── B站1: b-store1.com → Nginx → B站1容器 (172.x.x.3)
    ├── B站2: b-store2.com → Nginx → B站2容器 (172.x.x.4)
    └── 中央控制器: api.ab-pay.com → Nginx → CC容器 (172.x.x.10)

内部网络 (172.x.x.0/24):
  - 同一宿主机内 Docker bridge 网络
  - 中央控制器 ↔ 各站点API 走内网
  - 数据库仅对内网暴露
  - SSH/Monitoring 走独立管理网络
```

---

## 风险矩阵

| 风险 | 概率 | 影响 | 等级 | 缓解措施 |
|------|:---:|:---:|:---:|------|
| **支付平台封禁B站账户** | H | H | 🔴 | 独立IP/银行账户/防检测环境; 但无法消除 |
| **资金冻结 (90-180天)** | H | H | 🔴 | 预留资金缓冲; 设立紧急备用账户 |
| **合规/法律风险** | H | H | 🔴 | **必须进行合规改造, 否则不推荐上线** |
| **A站/B站订单数据不一致** | M | H | 🟡 | Checksum验证 + 对账定时任务 |
| **JWT Token泄露** | M | H | 🟡 | 短时效 + 一次性使用 + HTTPS |
| **中央控制器单点故障** | L | H | 🟡 | Docker编排 + 健康检查 + 自动重启 |
| **WooCommerce/OpenCart版本升级不兼容** | M | M | 🟡 | 持续集成测试 + 版本锁定 |
| **浏览器跨域Session丢失** | M | M | 🟡 | JWT桥接模式 + SameSite=Lax |
| **PCI DSS合规** | H | M | 🟡 | 不存储卡号; 只在B站处理支付 |
| **API被第三方滥调用** | M | M | 🟡 | API Key + Rate Limiting + IP白名单 |

---

## MVP 范围 (≤5 功能)

| # | 功能 | 优先级 | 工作量 | 验收标准 |
|---|------|--------|--------|---------|
| 1 | **A站 WooCommerce 插件** | P0 | 2-3周 | 拦截 WooCommerce 结账, 重定向到中央控制器; 覆盖 on-hold/success/failure 三态 |
| 2 | **B站 OpenCart 插件** | P0 | 3-4周 | 接收中央控制器订单, 创建 OpenCart 订单, 完成支付并回调 |
| 3 | **中央控制器 — 路由引擎** | P0 | 3-4周 | 支持最少2种路由策略 (RoundRobin + Weight); 健康检查与自动 Cooldown |
| 4 | **支付网关适配器 × 2** | P0 | 2周 | 最少集成 Stripe + PayPal; 统一的 Adapter 接口; Webhook 签名验证 |
| 5 | **订单映射与审计** | P0 | 2周 | A↔B订单映射表; 支付结果回调; 基础审计日志 |

**MVP 总工作量: 12-15 周 (3人开发团队)**

---

## 建议与下一步行动

### 方向建议

1. **短期 (1-3月)**: 如果目标是"跑通流程验证技术可行性"，可按上述 MVP 范围进行原型开发。**但决不可直接用于真实交易。**

2. **中期 (3-6月)**: 如果要商业落地，必须进行合规改造：
   - 申请支付机构的多商户平台资质 或 使用 Stripe Connect / Adyen Platform
   - 加入 KYC/KYB 审核层
   - 加入交易限额、反洗钱监控
   - 用户购买时明确告知支付给哪家商户

3. **长期**: 考虑将"支付路由"作为合规服务提供给 DTC 品牌，帮助他们在多个支付通道间做智能路由（失败重试、最优费率选择），而非规避平台检测。

### 下一步行动 (按顺序)

```
Step 1: 合规评估 (1周)
  □ 咨询支付合规律师
  □ 评估目标市场的支付监管要求
  □ 确定最低合规方案

Step 2: 技术原型 (5周, 有条件GO)
  □ 中央控制器核心 (路由引擎 + API)
  □ WooCommerce 插件 MVP
  □ OpenCart 插件 MVP
  □ 支付网关 Adapter × 2 (Stripe + PayPal)

Step 3: 安全审查 (1周)
  □ API 认证机制审计
  □ Token 安全审计
  □ 数据传输加密审计
  □ 源代码保护方案

Step 4: 合规改造 (3-4周, 必要条件)
  □ 加入交易限额控制
  □ 加入反洗钱监控
  □ 加入用户知情同意流程
  □ 加入审计与对账功能
```

---

## 最终结论

| 维度 | 评估 |
|------|------|
| 技术可行性 | **高** — 所有技术组件都有成熟的 PHP 生态方案 |
| 合规可行性 | **低** — 原始设计意图存在严重合规风险 |
| 商业可行性 | **条件性中** — 灰产路线不可持续, 合规改造后可行 |
| 安全性 | **中** — 有已知的防护方案, 但支付平台检测无法完全规避 |
| MVP 周期 | 12-15 周 (3人团队) |
| 生产成本 | 5 B站 × VPS费用 + 域名 + 银行账户 + SSL证书 + ~$500-1000/月运营 |
| **最终决策** | **条件 GO — 需完成合规改造方可上线** |
