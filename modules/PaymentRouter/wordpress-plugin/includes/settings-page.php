<?php
/**
 * WooCommerce Settings Page — AB Payment Router 配置
 */
declare(strict_types=1);

if (!defined('ABSPATH')) { exit; }

return new class extends \WC_Settings_Page {
    public function __construct()
    {
        $this->id    = 'ab_payment_router';
        $this->label = __('AB 轮询支付', 'ab-payment-router');
        parent::__construct();
    }

    public function get_sections(): array
    {
        return ['' => __('中控配置', 'ab-payment-router')];
    }

    public function get_settings_for_default_section(): array
    {
        $webhookUrl = rest_url('abpr/v1/webhook');

        return [
            [
                'title' => __('AB Payment Router — A 站配置', 'ab-payment-router'),
                'type'  => 'title',
                'desc'  => __('连接到 AB 轮询支付中控。结账时自动将订单路由到 B 站完成收款。', 'ab-payment-router'),
                'id'    => 'abpr_settings_start',
            ],
            [
                'title'    => __('中控地址', 'ab-payment-router'),
                'desc'     => __('PaymentRouter 中控的完整 URL（不含尾部斜杠）。', 'ab-payment-router'),
                'id'       => 'abpr_controller_url',
                'type'     => 'text',
                'default'  => 'https://your-controller.example.com',
                'css'      => 'width: 400px;',
                'desc_tip' => true,
            ],
            [
                'title'    => __('API Key', 'ab-payment-router'),
                'desc'     => __('A 站身份密钥（ck_ 前缀）。由中控注册 A 站时生成，用于 HMAC 签名。', 'ab-payment-router'),
                'id'       => 'abpr_api_key',
                'type'     => 'text',
                'default'  => '',
                'css'      => 'width: 500px; font-family: monospace;',
                'desc_tip' => true,
            ],
            [
                'title'    => __('Webhook 端点', 'ab-payment-router'),
                'desc'     => sprintf(
                    '<code>%s</code><br><small>%s</small>',
                    esc_html($webhookUrl),
                    __('将此 URL 配置到中控的 A 站 webhook_url 字段。', 'ab-payment-router')
                ),
                'id'       => 'abpr_webhook_info',
                'type'     => 'info',
            ],
            [
                'title'    => __('测试连接', 'ab-payment-router'),
                'desc'     => __('点击测试与中控的连接。', 'ab-payment-router'),
                'id'       => 'abpr_test_connection',
                'type'     => 'button',
                'desc_tip' => false,
            ],
            ['type' => 'sectionend', 'id' => 'abpr_settings_end'],
        ];
    }

    public function output(): void
    {
        // 渲染自定义字段类型 'info' 和 'button'
        add_action('woocommerce_admin_field_info', function (array $value): void {
            echo '<tr><th scope="row">' . esc_html($value['title']) . '</th>';
            echo '<td>' . wp_kses_post($value['desc']) . '</td></tr>';
        });

        add_action('woocommerce_admin_field_button', function (array $value): void {
            echo '<tr><th scope="row">' . esc_html($value['title']) . '</th>';
            echo '<td><button type="button" class="button" id="abpr-test-btn">' .
                 esc_html__('测试连接', 'ab-payment-router') .
                 '</button><span id="abpr-test-result" style="margin-left:12px;"></span>';
            echo '<script>
                document.getElementById("abpr-test-btn").addEventListener("click", async function() {
                    const r = document.getElementById("abpr-test-result");
                    r.textContent = "测试中…";
                    try {
                        const resp = await fetch(document.getElementById("abpr_controller_url").value + "/health");
                        const data = await resp.json();
                        r.textContent = data.status === "ok" ? "✅ 连接成功" : "⚠️ " + JSON.stringify(data);
                        r.style.color = data.status === "ok" ? "green" : "orange";
                    } catch(e) { r.textContent = "❌ 连接失败: " + e.message; r.style.color = "red"; }
                });
            </script></td></tr>';
        });

        parent::output();
    }
};
