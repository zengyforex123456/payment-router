/**
 * command_palette_controller.js — Cmd+K Global Command Palette
 *
 * Inspired by VS Code Ctrl+Shift+P, Linear Cmd+K, Superhuman Cmd+K.
 * Responsibilities:
 *   - Open/close palette (Ctrl+K / Cmd+K toggle, Escape close)
 *   - Fuzzy search through categorized commands
 *   - Keyboard navigation (↑↓ Enter)
 *   - Command execution (navigate, dispatch, or call action)
 *   - Recent commands history (localStorage, last 5)
 *
 * Boundary with search bar:
 *   Cmd+K (this) = global navigation + quick task execution
 *   Search bar    = data query (find conversion by click_id, etc.)
 */
import { Controller } from "@hotwired/stimulus";

// ── Command Definitions ──
const COMMANDS = [
    // Tasks
    { cat: "Tasks", label: "New Campaign", desc: "Set up tracking for a new traffic source", icon: "📋", action: "navigate", url: "/setup-wizard.php" },
    { cat: "Tasks", label: "Find Losers", desc: "Campaigns with negative ROI — stop the bleeding", icon: "📉", action: "navigate", url: "/campaigns.php?filter=low_roi" },
    { cat: "Tasks", label: "Quick Refund", desc: "Send refund signal back to ad platforms via CAPI", icon: "💸", action: "navigate", url: "/admin-panel.php#refund" },
    { cat: "Tasks", label: "Check Bot Traffic", desc: "Review and block fraudulent click activity", icon: "🛡️", action: "navigate", url: "/bot-monitor.php" },
    { cat: "Tasks", label: "AI Build LP", desc: "Generate a landing page with AI (3 variants)", icon: "🤖", action: "navigate", url: "/builder.php" },
    // Navigate — Campaigns
    { cat: "Campaigns", label: "All Campaigns", desc: "View and manage all ad campaigns", icon: "📋", action: "navigate", url: "/campaigns.php" },
    { cat: "Campaigns", label: "A/B Tests", desc: "Manage split-test experiments", icon: "🧪", action: "navigate", url: "/experiments.php" },
    { cat: "Campaigns", label: "Auto Rules", desc: "Configure automatic optimization rules", icon: "🤖", action: "navigate", url: "/auto-rules.php" },
    { cat: "Campaigns", label: "Smart Rotation", desc: "AI-powered traffic allocation", icon: "🎯", action: "navigate", url: "/smart-rotation.php" },
    // Navigate — Analytics
    { cat: "Analytics", label: "Dashboard", desc: "KPI overview with AI insights", icon: "📊", action: "navigate", url: "/admin-panel.php" },
    { cat: "Analytics", label: "Reports", desc: "Cross-campaign performance reports", icon: "📈", action: "navigate", url: "/reports.php" },
    { cat: "Analytics", label: "Funnel", desc: "Click-to-conversion drop-off analysis", icon: "🔀", action: "navigate", url: "/funnel.php" },
    { cat: "Analytics", label: "Attribution", desc: "Multi-touch attribution modeling", icon: "🔗", action: "navigate", url: "/attribution.php" },
    { cat: "Analytics", label: "Conversions", desc: "Browse and search conversion log", icon: "✅", action: "navigate", url: "/conversions.php" },
    { cat: "Analytics", label: "Click Lookup", desc: "Trace a click by ID through the funnel", icon: "🔍", action: "navigate", url: "/click-lookup.php" },
    // Navigate — Resources
    { cat: "Resources", label: "Traffic Sources", desc: "Manage traffic platforms and tokens", icon: "🌍", action: "navigate", url: "/traffic-sources.php" },
    { cat: "Resources", label: "Offers", desc: "Manage CPA/CPL/CPS offers", icon: "💰", action: "navigate", url: "/offers.php" },
    { cat: "Resources", label: "Landing Pages", desc: "Manage landing page library", icon: "📄", action: "navigate", url: "/landing-pages.php" },
    { cat: "Resources", label: "LP Builder", desc: "Visual drag-and-drop page builder", icon: "🎨", action: "navigate", url: "/builder.php" },
    { cat: "Resources", label: "Short Links", desc: "Create tracking short links", icon: "🔗", action: "navigate", url: "/short-links.php" },
    { cat: "Resources", label: "Aff Networks", desc: "Affiliate network integrations", icon: "🤝", action: "navigate", url: "/networks.php" },
    { cat: "Resources", label: "Commissions", desc: "Affiliate commission dashboard", icon: "💳", action: "navigate", url: "/affiliate.php" },
    // Navigate — Integrations
    { cat: "Integrations", label: "Data Sources", desc: "Configure Meta/TikTok/Google CAPI", icon: "🔌", action: "navigate", url: "/integrations.php" },
    { cat: "Integrations", label: "Postbacks", desc: "S2S conversion postback URLs", icon: "📨", action: "navigate", url: "/postback-urls.php" },
    { cat: "Integrations", label: "Webhooks", desc: "Outbound event webhooks", icon: "🔔", action: "navigate", url: "/webhooks.php" },
    // Navigate — Admin
    { cat: "Admin", label: "Team", desc: "Invite members, manage roles", icon: "👥", action: "navigate", url: "/team.php" },
    { cat: "Admin", label: "Billing", desc: "Subscription and invoices", icon: "💳", action: "navigate", url: "/billing.php" },
    { cat: "Admin", label: "Audit Trail", desc: "Full event audit log", icon: "📝", action: "navigate", url: "/audit-trail.php" },
    { cat: "Admin", label: "API Keys", desc: "Generate and manage API tokens", icon: "🔑", action: "navigate", url: "/settings-api.php" },
    { cat: "Admin", label: "Settings", desc: "System configuration", icon: "⚙️", action: "navigate", url: "/settings.php" },
    { cat: "Admin", label: "Self-Check", desc: "System integrity verification", icon: "✅", action: "navigate", url: "/proof.php" },
    { cat: "Admin", label: "Docs", desc: "Product documentation", icon: "📖", action: "navigate", url: "/docs.php" },
    // Actions
    { cat: "Actions", label: "Toggle Theme", desc: "Switch light/dark mode", icon: "🌓", action: "dispatch", event: "theme:toggle" },
];

const RECENT_KEY = "cmd_palette_recent";
const MAX_RECENT = 5;

export default class extends Controller {
    static targets = ["overlay", "input", "list", "empty"];
    static values = { open: Boolean };

    connect() {
        this.openValue = false;
        this._activeIndex = -1;
        this._recent = this._loadRecent();
        this._bindGlobalKeys();
    }

    // Public API — callable from sidebar button or external code
    open() { this._open(); }
    close() { this._close(); }
    toggle() { this.openValue ? this._close() : this._open(); }

    disconnect() {
        this._unbindGlobalKeys();
    }

    // ── Open / Close ──
    _bindGlobalKeys() {
        this._keydownHandler = (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === "k") {
                e.preventDefault();
                this.openValue = !this.openValue;
                if (this.openValue) this._open();
                else this._close();
            }
            if (e.key === "Escape" && this.openValue) {
                e.preventDefault();
                this._close();
            }
        };
        document.addEventListener("keydown", this._keydownHandler);
    }

    _unbindGlobalKeys() {
        if (this._keydownHandler) document.removeEventListener("keydown", this._keydownHandler);
    }

    _open() {
        this.hasOverlayTarget && (this.overlayTarget.style.display = "");
        this._activeIndex = -1;
        // Show recent commands and top tasks when empty
        this._filter("");
        setTimeout(() => { if (this.hasInputTarget) this.inputTarget.focus(); }, 50);
    }

    _close() {
        this.openValue = false;
        this.hasOverlayTarget && (this.overlayTarget.style.display = "none");
    }

    // ── Search / Filter ──
    filter() {
        const q = (this.hasInputTarget ? this.inputTarget.value : "").trim().toLowerCase();
        this._filter(q);
    }

    _filter(q) {
        if (this.hasEmptyTarget) this.emptyTarget.style.display = "none";
        if (!this.hasListTarget) return;

        let results;
        if (!q) {
            // Default: recent + top tasks
            const recentIds = new Set(this._recent);
            const recent = recentIds.size > 0
                ? COMMANDS.filter(c => recentIds.has(c.label)).map(c => ({ ...c, cat: "Recent" }))
                : [];
            const tasks = COMMANDS.filter(c => c.cat === "Tasks" && !recentIds.has(c.label));
            results = [...recent, ...tasks];
        } else {
            // Fuzzy: match label or desc or category
            results = COMMANDS.filter(c =>
                c.label.toLowerCase().includes(q) ||
                (c.desc && c.desc.toLowerCase().includes(q)) ||
                c.cat.toLowerCase().includes(q)
            );
        }

        if (results.length === 0) {
            if (this.hasEmptyTarget) this.emptyTarget.style.display = "";
            this.listTarget.innerHTML = "";
            return;
        }

        this._activeIndex = 0;
        this._render(results);
    }

    _render(results) {
        if (!this.hasListTarget) return;

        // Group by category
        const groups = {};
        for (const c of results) {
            if (!groups[c.cat]) groups[c.cat] = [];
            groups[c.cat].push(c);
        }

        let html = "";
        for (const [cat, cmds] of Object.entries(groups)) {
            html += `<div class="cmd-palette-group"><div class="cmd-palette-group-label">${this._esc(cat)}</div>`;
            for (let i = 0; i < cmds.length; i++) {
                const idx = this.listTarget.querySelectorAll(".cmd-palette-item").length + i;
                html += `<div class="cmd-palette-item" data-cmd-palette-index="${idx}" data-url="${this._esc(cmds[i].url || "")}" data-action-type="${cmds[i].action}" data-event="${cmds[i].event || ""}">
                    <span class="cmd-palette-icon">${cmds[i].icon || ""}</span>
                    <span class="cmd-palette-label">${this._esc(cmds[i].label)}</span>
                    <span class="cmd-palette-desc">${this._esc(cmds[i].desc || "")}</span>
                    ${cmds[i].keys ? `<kbd>${this._esc(cmds[i].keys)}</kbd>` : ""}
                </div>`;
            }
            html += "</div>";
        }
        this.listTarget.innerHTML = html;
        this._highlightActive();

        // Click handlers
        this.listTarget.querySelectorAll(".cmd-palette-item").forEach(el => {
            el.addEventListener("click", () => this._execute(el));
        });
    }

    _esc(s) { return (s || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;"); }

    // ── Keyboard Navigation ──
    navigateList(e) {
        const items = this.hasListTarget ? this.listTarget.querySelectorAll(".cmd-palette-item") : [];
        if (items.length === 0) return;

        if (e.key === "ArrowDown") {
            e.preventDefault();
            this._activeIndex = Math.min(this._activeIndex + 1, items.length - 1);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            this._activeIndex = Math.max(this._activeIndex - 1, 0);
        } else if (e.key === "Enter") {
            e.preventDefault();
            const active = items[this._activeIndex];
            if (active) this._execute(active);
        }

        this._highlightActive();
    }

    _highlightActive() {
        const items = this.hasListTarget ? this.listTarget.querySelectorAll(".cmd-palette-item") : [];
        items.forEach((el, i) => {
            if (i === this._activeIndex) {
                el.classList.add("active");
                el.scrollIntoView({ block: "nearest" });
            } else {
                el.classList.remove("active");
            }
        });
    }

    // ── Execute ──
    _execute(el) {
        const action = el.dataset.actionType;
        const url = el.dataset.url;
        const eventName = el.dataset.event;
        const label = el.querySelector(".cmd-palette-label")?.textContent || "";

        // Track in recent
        this._addRecent(label);

        if (action === "navigate" && url) {
            window.location.href = url;
        } else if (action === "dispatch" && eventName) {
            window.dispatchEvent(new CustomEvent(eventName));
        }
        this._close();
    }

    // ── Recent Commands ──
    _loadRecent() {
        try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; }
        catch { return []; }
    }

    _addRecent(label) {
        this._recent = [label, ...this._recent.filter(l => l !== label)].slice(0, MAX_RECENT);
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(this._recent)); } catch {}
    }

    // ── Close on backdrop click ──
    closeOnBackdrop(e) {
        if (e.target === this.overlayTarget) this._close();
    }
}
