# PaymentRouter — API 参考

## 基础信息

- **Base URL**: `https://your-controller.example.com`
- **Content-Type**: `application/json`
- **认证方式**: 
  - 外部 API: API Key + HMAC-SHA256 签名
  - 管理 API: Session（浏览器）/ Bearer Token（API）

## 认证

### 外部 API 认证（A 站 → 中控）

每个请求必须包含 HMAC 签名：

```bash
# 签名算法
TIMESTAMP=$(date +%s)
PAYLOAD='{"a_order_id":"ORDER-001","amount":"99.99","currency":"USD","timestamp":"'"$TIMESTAMP"'"}'
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$API_KEY" | awk '{print $2}')

curl -X POST https://controller.example.com/api/payment-router/dispatch \
  -H "Content-Type: application/json" \
  -H "X-Signature: $SIGNATURE" \
  -H "X-Api-Key: $API_KEY" \
  -d "{\"api_key\":\"$API_KEY\",\"signature\":\"$SIGNATURE\",\"a_order_id\":\"ORDER-001\",\"amount\":\"99.99\",\"currency\":\"USD\",\"timestamp\":\"$TIMESTAMP\"}"
```

### 错误响应

所有错误返回统一格式：

```json
{"error": "错误描述"}
```

| HTTP | 含义 |
|:---:|------|
| 200 | 成功 |
| 400 | 请求参数错误 |
| 401 | 认证失败（API Key 无效或签名错误） |
| 404 | 资源不存在 |
| 503 | 服务不可用（数据库断开、所有 B 站不可用） |

---

## 外部 API

### POST /api/payment-router/dispatch

A 站提交订单，中控选择一个 B 站并返回跳转 URL。

**请求体**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|:---:|------|
| `api_key` | string | ✅ | A 站 API Key |
| `signature` | string | ✅ | HMAC-SHA256 签名 |
| `a_order_id` | string | ✅ | A 站订单号（唯一） |
| `amount` | string | ✅ | 订单金额 |
| `currency` | string | | 货币（默认 USD） |
| `timestamp` | string | ✅ | Unix 时间戳 |
| `strategy` | string | | 策略覆盖（可选） |

**响应**:

```json
{
  "b_checkout_url": "https://pay1.example.com/index.php?route=extension/payment/ab_router/checkout&token=eyJ...",
  "b_order_reference": "B-A1B2C3D4E5F6",
  "b_site_domain": "pay1.example.com"
}
```

### POST /api/payment-router/webhook

B 站支付结果回调。由 OC 插件自动调用，无需手动触发。

**请求体**:

| 字段 | 类型 | 必填 | 说明 |
|------|------|:---:|------|
| `b_order_id` | string | ✅ | B 站订单引用 |
| `status` | string | ✅ | `paid` / `failed` / `refunded` |
| `transaction_id` | string | | 支付网关交易 ID |

**响应**:

```json
{"acknowledged": true, "mapping_status": "paid", "b_site_status": "recovered"}
```

---

## 管理 API

### A 站管理

```
GET    /api/payment-router/a-sites          列出所有 A 站
POST   /api/payment-router/a-sites          注册 A 站
DELETE /api/payment-router/a-sites/{id}     删除 A 站
```

**POST 请求体**: `{"tenant_id":0,"domain":"shop.example.com","platform":"woocommerce"}`

**POST 响应**: `{"id":1,"domain":"shop.example.com","apiKey":"ck_...","status":"active"}`

### B 站管理

```
GET    /api/payment-router/b-sites          列出所有 B 站
POST   /api/payment-router/b-sites          注册 B 站
```

**POST 请求体**: `{"tenant_id":0,"domain":"pay.example.com","payment_gateway":"paypal","weight":5,"max_daily_orders":100}`

**POST 响应**: `{"id":1,"domain":"pay.example.com","gateway":"paypal","status":"active"}`

### 仪表盘 & 查询

```
GET /api/payment-router/dashboard    仪表盘汇总 + B 站明细
GET /api/payment-router/mappings     订单映射列表 (A→B)
GET /api/payment-router/usage        租户用量 + 套餐限制
```

**Dashboard 响应**:

```json
{
  "summary": {
    "total_orders": 150,
    "paid_orders": 142,
    "failed_orders": 5,
    "pending_orders": 3,
    "total_revenue": 12500.50,
    "success_rate": 94.7
  },
  "b_sites": [
    {"domain":"pay1.example.com","total_mapped":80,"success_count":78,"fail_count":2}
  ]
}
```

### 策略配置

```
GET    /api/payment-router/strategy        获取当前策略
POST   /api/payment-router/strategy        应用预设模板
PATCH  /api/payment-router/strategy        自定义策略参数
GET    /api/payment-router/presets         列出可用预设
```

**POST 请求体**: `{"tenant_id":0,"preset":"safe_mode"}`

**PATCH 请求体**: `{"tenant_id":0,"cooling_threshold":2,"cooldown_minutes":15}`

**预设列表**:

| 预设 | 路由 | 冷却阈值 | 冷却时间 |
|------|:---:|:---:|:---:|
| `balanced` | weighted | 3 | 30 min |
| `weight_priority` | weighted | 5 | 60 min |
| `safe_mode` | round_robin | 1 | 15 min |
| `high_volume` | random | 10 | 120 min |

### 配置管理（专业版+）

```
GET  /api/payment-router/config/export    导出全量配置 JSON
POST /api/payment-router/config/import    导入配置 JSON
```

### 批量导入（企业版）

```
POST /api/payment-router/bulk/import/a-sites    批量导入 A 站
POST /api/payment-router/bulk/import/b-sites    批量导入 B 站
```

**请求体**: `{"tenant_id":0,"sites":[{"domain":"shop1.com"},{"domain":"shop2.com"}]}`

**响应**: `{"imported":2,"skipped":0,"errors":[]}`

### 路由脚本（企业版）

```
POST /api/payment-router/routing-script/validate   验证 DSL 规则
POST /api/payment-router/routing-script/evaluate   执行路由脚本
```

**DSL 语法**:

```json
{
  "rules": [
    {"condition": "amount_gt:100",    "action": "prefer:weight_gte:5"},
    {"condition": "gateway:stripe",   "action": "round_robin"},
    {"condition": "currency:EUR",     "action": "random"},
    {"condition": "default",          "action": "weighted"}
  ],
  "context": {"amount": "150.00", "gateway": "paypal", "currency": "USD"}
}
```

**支持的条件**: `amount_gt:N` / `amount_lte:N` / `gateway:X` / `currency:X` / `default`

**支持的动作**: `prefer:weight_gte:N` / `round_robin` / `random` / `weighted`

### 企业功能

```
GET /api/payment-router/oem                 获取 OEM 品牌配置
GET /api/payment-router/admin/tenants       多租户管理概览
POST /api/payment-router/health-check       手动触发健康检查
```

### 健康检查

```
GET /health     {"status":"ok","service":"payment-router","time":"..."}
```

---

## 客户端集成示例

### PHP (WordPress)

```php
$apiKey = get_option('abpr_api_key');
$ts = (string)time();
$payload = json_encode(['a_order_id'=>'42','amount'=>'99.99','currency'=>'USD','timestamp'=>$ts]);
$sig = hash_hmac('sha256', $payload, $apiKey);

$resp = wp_remote_post('https://controller.example.com/api/payment-router/dispatch', [
    'body' => json_encode([
        'api_key'   => $apiKey,
        'signature' => $sig,
        'a_order_id'=> '42',
        'amount'    => '99.99',
        'currency'  => 'USD',
        'timestamp' => $ts,
    ]),
    'headers' => ['Content-Type' => 'application/json'],
]);
$result = json_decode(wp_remote_retrieve_body($resp), true);
// 重定向用户到 $result['b_checkout_url']
```

### Python

```python
import hmac, hashlib, json, time, requests

api_key = "ck_..."
ts = str(int(time.time()))
payload = json.dumps({"a_order_id":"42","amount":"99.99","currency":"USD","timestamp":ts})
sig = hmac.new(api_key.encode(), payload.encode(), hashlib.sha256).hexdigest()

resp = requests.post("https://controller.example.com/api/payment-router/dispatch", json={
    "api_key": api_key, "signature": sig,
    "a_order_id": "42", "amount": "99.99", "currency": "USD", "timestamp": ts,
})
print(resp.json()["b_checkout_url"])
```

### cURL

```bash
TS=$(date +%s)
PAYLOAD='{"a_order_id":"42","amount":"99.99","currency":"USD","timestamp":"'$TS'"}'
SIG=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$API_KEY" | awk '{print $2}')

curl -X POST https://controller.example.com/api/payment-router/dispatch \
  -H "Content-Type: application/json" \
  -d '{"api_key":"'$API_KEY'","signature":"'$SIG'","a_order_id":"42","amount":"99.99","currency":"USD","timestamp":"'$TS'"}'
```
