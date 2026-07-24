<?php
/**
 * AB Payment Router — OpenCart Admin Controller
 *
 * 管理面板: 配置中控 URL、共享密钥、默认支付网关、伪装商品 ID。
 * 放在 admin/controller/extension/payment/ab_router.php
 */
class ControllerExtensionPaymentAbRouter extends Controller
{
    private array $error = [];

    public function index(): void
    {
        $this->load->language('extension/payment/ab_router');
        $this->document->setTitle($this->language->get('heading_title'));

        // 保存设置
        if ($this->request->server['REQUEST_METHOD'] === 'POST' && $this->validate()) {
            $this->load->model('setting/setting');
            $this->model_setting_setting->editSetting('payment_ab_router', $this->request->post);
            $this->session->data['success'] = $this->language->get('text_success');
            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        // 错误信息
        $data['error_warning'] = $this->error['warning'] ?? '';
        $data['error_controller_url'] = $this->error['controller_url'] ?? '';

        // 面包屑
        $data['breadcrumbs'] = [
            ['text' => $this->language->get('text_home'),      'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)],
            ['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)],
            ['text' => $this->language->get('heading_title'),  'href' => $this->url->link('extension/payment/ab_router', 'user_token=' . $this->session->data['user_token'], true)],
        ];

        // 表单 action
        $data['action'] = $this->url->link('extension/payment/ab_router', 'user_token=' . $this->session->data['user_token'], true);
        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        // 配置字段
        $data['payment_ab_router_controller_url'] = $this->request->post['payment_ab_router_controller_url'] ?? $this->config->get('payment_ab_router_controller_url') ?? '';
        $data['payment_ab_router_secret']         = $this->request->post['payment_ab_router_secret']         ?? $this->config->get('payment_ab_router_secret')         ?? '';
        $data['payment_ab_router_gateway']        = $this->request->post['payment_ab_router_gateway']        ?? $this->config->get('payment_ab_router_gateway')        ?? 'paypal';
        $data['payment_ab_router_product_id']     = $this->request->post['payment_ab_router_product_id']     ?? $this->config->get('payment_ab_router_product_id')     ?? '1';
        $data['payment_ab_router_status']         = $this->request->post['payment_ab_router_status']         ?? $this->config->get('payment_ab_router_status')         ?? '0';
        $data['payment_ab_router_sort_order']     = $this->request->post['payment_ab_router_sort_order']     ?? $this->config->get('payment_ab_router_sort_order')     ?? '0';

        // 回调 URL（只读展示）
        $data['callback_url'] = HTTP_CATALOG . 'index.php?route=extension/payment/ab_router/callback';

        // 头部
        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/ab_router', $data));
    }

    public function install(): void
    {
        // 安装映射表
        $this->load->model('extension/payment/ab_router');
        $this->model_extension_payment_ab_router->install();
    }

    public function uninstall(): void
    {
        $this->load->model('extension/payment/ab_router');
        $this->model_extension_payment_ab_router->uninstall();
    }

    private function validate(): bool
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/ab_router')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }
        if (empty($this->request->post['payment_ab_router_controller_url'])) {
            $this->error['controller_url'] = $this->language->get('error_controller_url');
        }
        return !$this->error;
    }
}
