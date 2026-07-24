=== AB Payment Router — WooCommerce A-Site Connector ===
Contributors: converge
Tags: payment, woocommerce, routing, ab-payment, paypal, stripe
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

将 WooCommerce 订单自动路由到 AB 轮询支付中控（PaymentRouter），实现多 B 站收款分发。
A 站（展示站）安装此插件后，顾客下单时自动将支付请求发送到中控，
中控选择一个可用的 B 站（收款站）完成实际支付，从而分散单一支付账户被冻结的风险。

== Features ==

* 下单后自动将订单推送到 PaymentRouter 中控
* HMAC-SHA256 签名认证，确保通信安全
* 用户无感知跳转到 B 站支付页面
* 支付结果通过 Webhook 自动同步回 WooCommerce
* WooCommerce 设置面板集成（一键测试连接）
* 详细的订单备注（路由目标、B 站信息）

== Requirements ==

* WordPress 6.0+
* WooCommerce 8.0+
* PHP 8.0+
* PaymentRouter 中控已部署并配置了对应的 A 站 API Key

== Installation ==

1. 上传 `ab-payment-router` 目录到 `/wp-content/plugins/`
2. 在 WordPress 后台激活插件
3. 进入 WooCommerce → 设置 → AB 轮询支付
4. 填写中控地址和 API Key（从中控管理面板获取）
5. 点击"测试连接"确认与中控通信正常
6. 将显示的 Webhook 端点 URL 配置到中控的 A 站设置中

== Configuration ==

= 中控地址 =
PaymentRouter 中控的完整 URL，不含尾部斜杠。
示例: https://payment-controller.example.com

= API Key =
由中控注册 A 站时自动生成的密钥（ck_ 前缀），用于 HMAC 签名认证。
每个 A 站有独立的 API Key。

= Webhook 端点 =
中控支付成功后回调此 URL 更新 WooCommerce 订单状态。
URL 格式: https://your-site.com/wp-json/abpr/v1/webhook

== Order Flow ==

1. 顾客在 WooCommerce A 站下单 → 订单状态: pending
2. 插件 POST 订单到中控 `/api/payment-router/dispatch`
3. 中控选择 B 站 → 返回 checkout URL
4. 顾客无感知跳转到 B 站支付页面
5. 支付成功/失败 → 中控 POST 到 Webhook 端点
6. 插件更新 WooCommerce 订单状态: processing / failed

== Changelog ==

= 0.1.0 =
* 初始版本
* WooCommerce 结账拦截
* HMAC 签名认证
* Webhook 回调处理
* 管理面板集成
