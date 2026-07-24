/**
 * PaymentRouter Strategy Controller — 轮询策略配置
 *
 * 保存默认策略、冷却阈值、冷却时间等参数。
 */
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["strategySelect", "coolingThreshold", "cooldownMinutes"];

    connect() {
        // 从 localStorage 加载上次配置
        const saved = localStorage.getItem("paymentRouterStrategy");
        if (saved) {
            try {
                const cfg = JSON.parse(saved);
                if (this.hasStrategySelectTarget && cfg.default_strategy) this.strategySelectTarget.value = cfg.default_strategy;
                if (this.hasCoolingThresholdTarget && cfg.cooling_threshold) this.coolingThresholdTarget.value = cfg.cooling_threshold;
                if (this.hasCooldownMinutesTarget && cfg.cooldown_minutes) this.cooldownMinutesTarget.value = cfg.cooldown_minutes;
            } catch (e) { /* ignore */ }
        }
    }

    async save(e) {
        e.preventDefault();
        const form = new FormData(e.target);
        const config = Object.fromEntries(form);

        try {
            localStorage.setItem("paymentRouterStrategy", JSON.stringify(config));
            const body = new URLSearchParams(config);
            const resp = await fetch("/api/payment-router/strategy", { method: "POST", body, credentials: "same-origin" });
            if (resp.ok) {
                this._flash("配置已保存", "success");
            } else {
                this._flash("保存失败", "error");
            }
        } catch (err) {
            this._flash("网络错误: " + err.message, "error");
        }
    }

    _flash(msg, type) {
        const el = document.createElement("div");
        el.className = `alert alert--${type}`;
        el.textContent = msg;
        el.style.cssText = "position:fixed;top:1rem;right:1rem;z-index:9999";
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3000);
    }
}
