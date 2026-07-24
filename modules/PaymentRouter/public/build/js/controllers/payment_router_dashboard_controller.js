/**
 * PaymentRouter Dashboard Controller — 仪表盘 + 订单映射双模式
 *
 * 三态完备: loading / error / empty / data
 * mode=dashboard: 加载仪表盘汇总 + B 站明细
 * mode=mappings: 加载订单映射列表
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["content", "loading", "error", "empty", "container", "tableBody", "totalOrders", "successRate", "totalRevenue", "pendingOrders"];
    static values = { state: String, mode: { type: String, default: "dashboard" } };

    connect() {
        this.stateValue = "idle";
        this._render();
        this.load();
    }

    async load() {
        this.stateValue = "loading"; this._render();
        try {
            const url = this.modeValue === "mappings"
                ? "/api/payment-router/mappings"
                : "/api/payment-router/dashboard";
            const resp = await fetch(url, { credentials: "same-origin" });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();

            if (this.modeValue === "mappings") {
                if (!data.items || !data.items.length) { this.stateValue = "empty"; }
                else { this.stateValue = "data"; this._renderMappings(data.items); }
            } else {
                if (!data.summary || !data.summary.total_orders) { this.stateValue = "empty"; }
                else { this.stateValue = "data"; this._renderDashboard(data); }
            }
        } catch (e) {
            this.stateValue = "error";
            if (this.hasErrorTarget) this.errorTarget.textContent = e.message || "加载失败";
        }
        this._render();
    }

    _renderDashboard(data) {
        const s = data.summary;
        if (this.hasTotalOrdersTarget) this.totalOrdersTarget.textContent = s.total_orders.toLocaleString();
        if (this.hasSuccessRateTarget) this.successRateTarget.textContent = s.success_rate + "%";
        if (this.hasTotalRevenueTarget) this.totalRevenueTarget.textContent = "$" + Number(s.total_revenue).toLocaleString();
        if (this.hasPendingOrdersTarget) this.pendingOrdersTarget.textContent = s.pending_orders.toLocaleString();

        if (this.hasTableBodyTarget && data.b_sites) {
            this.tableBodyTarget.innerHTML = data.b_sites.map(b => `
                <tr>
                    <td>${this._esc(b.domain)}</td>
                    <td><span class="badge">${this._esc(b.payment_gateway)}</span></td>
                    <td><span class="badge badge--${b.b_status === 'active' ? 'success' : b.b_status === 'cooling' ? 'warning' : 'danger'}">${b.b_status}</span></td>
                    <td>${b.total_mapped}</td>
                    <td>${b.success_count}</td>
                    <td>${b.fail_count}</td>
                </tr>
            `).join("");
        }
    }

    _renderMappings(items) {
        if (this.hasTableBodyTarget) {
            this.tableBodyTarget.innerHTML = items.map(m => `
                <tr>
                    <td><code>${this._esc(m.a_order_id)}</code></td>
                    <td><code>${this._esc(m.b_order_id || '—')}</code></td>
                    <td>${m.currency} ${Number(m.amount).toFixed(2)}</td>
                    <td><span class="badge badge--${m.status === 'paid' ? 'success' : m.status === 'failed' ? 'danger' : 'default'}">${m.status}</span></td>
                    <td><small>${this._esc(m.routing_reason || '—')}</small></td>
                    <td><small>${m.dispatched_at}</small></td>
                </tr>
            `).join("");
        }
    }

    _render() {
        const s = this.stateValue;
        if (this.hasLoadingTarget) this.loadingTarget.style.display = s === "loading" ? "" : "none";
        if (this.hasErrorTarget) this.errorTarget.style.display = s === "error" ? "" : "none";
        if (this.hasEmptyTarget) this.emptyTarget.style.display = s === "empty" ? "" : "none";
        if (this.hasContentTarget) this.contentTarget.style.display = s === "data" ? "" : "none";
    }

    _esc(str) {
        const div = document.createElement("div");
        div.textContent = str;
        return div.innerHTML;
    }
}
