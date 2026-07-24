/**
 * PaymentRouter Site Controller — A/B 站管理（双模式）
 *
 * mode=A: 管理 A 站（展示站）
 * mode=B: 管理 B 站（收款站）
 * 三态: loading / error / data
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["content", "loading", "error", "tableBody", "modal"];
    static values = { state: String, mode: String };

    connect() {
        this.stateValue = "idle";
        this._render();
        this.load();
    }

    async load() {
        this.stateValue = "loading"; this._render();
        try {
            const url = this.modeValue === "A"
                ? "/api/payment-router/a-sites"
                : "/api/payment-router/b-sites";
            const resp = await fetch(url, { credentials: "same-origin" });
            if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
            const data = await resp.json();
            this.stateValue = "data";
            this._renderSites(data);
        } catch (e) {
            this.stateValue = "error";
            if (this.hasErrorTarget) this.errorTarget.textContent = e.message || "加载失败";
        }
        this._render();
    }

    _renderSites(sites) {
        if (!this.hasTableBodyTarget) return;
        if (!sites || !sites.length) {
            this.tableBodyTarget.innerHTML = '<tr><td colspan="7" class="text--center">暂无站点</td></tr>';
            return;
        }
        if (this.modeValue === "A") {
            this.tableBodyTarget.innerHTML = sites.map(s => `
                <tr>
                    <td>${this._esc(s.domain)}</td>
                    <td>${this._esc(s.platform)}</td>
                    <td><code>${this._esc(s.apiKey).substring(0, 12)}…</code></td>
                    <td><span class="badge badge--${s.status === 'active' ? 'success' : 'warning'}">${s.status}</span></td>
                    <td><button class="btn btn--sm btn--danger" data-action="click->payment-router-site#delete" data-id="${s.id}">删除</button></td>
                </tr>
            `).join("");
        } else {
            this.tableBodyTarget.innerHTML = sites.map(s => `
                <tr>
                    <td>${this._esc(s.domain)}</td>
                    <td><span class="badge">${this._esc(s.paymentGateway)}</span></td>
                    <td>${s.weight}</td>
                    <td>${s.maxDailyOrders}</td>
                    <td><span class="badge badge--${s.status === 'active' ? 'success' : s.status === 'cooling' ? 'warning' : 'danger'}">${s.status}</span></td>
                    <td>${s.dailyOrderCount}</td>
                    <td><button class="btn btn--sm btn--danger" data-action="click->payment-router-site#delete" data-id="${s.id}">删除</button></td>
                </tr>
            `).join("");
        }
    }

    openCreateModal() { if (this.hasModalTarget) this.modalTarget.showModal(); }
    closeModal() { if (this.hasModalTarget) this.modalTarget.close(); }

    async createASite(e) {
        e.preventDefault();
        const form = new FormData(e.target);
        const body = new URLSearchParams();
        for (const [k, v] of form) body.append(k, v);
        await fetch("/api/payment-router/a-sites", { method: "POST", body, credentials: "same-origin" });
        this.closeModal();
        this.load();
    }

    async createBSite(e) {
        e.preventDefault();
        const form = new FormData(e.target);
        const body = new URLSearchParams();
        for (const [k, v] of form) body.append(k, v);
        await fetch("/api/payment-router/b-sites", { method: "POST", body, credentials: "same-origin" });
        this.closeModal();
        this.load();
    }

    async delete(e) {
        const id = e.target.dataset.id;
        if (!confirm("确认删除？")) return;
        const url = this.modeValue === "A"
            ? `/api/payment-router/a-sites/${id}`
            : `/api/payment-router/b-sites/${id}`;
        await fetch(url, { method: "DELETE", credentials: "same-origin" });
        this.load();
    }

    _render() {
        const s = this.stateValue;
        if (this.hasLoadingTarget) this.loadingTarget.style.display = s === "loading" ? "" : "none";
        if (this.hasErrorTarget) this.errorTarget.style.display = s === "error" ? "" : "none";
        if (this.hasContentTarget) this.contentTarget.style.display = s === "data" ? "" : "none";
    }

    _esc(str) {
        const div = document.createElement("div");
        div.textContent = String(str);
        return div.innerHTML;
    }
}
