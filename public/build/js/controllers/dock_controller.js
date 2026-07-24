import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["panel"];
    static values = { current: String, open: Boolean };

    connect() {
        this.currentValue = this.currentValue || "tracking";
        this.openValue = this.openValue || false;
        try {
            const s = JSON.parse(localStorage.getItem("dockState"));
            if (s) { this.currentValue = s.dock || "tracking"; this.openValue = !!s.open; }
        } catch(e) {}
        this._apply();
    }

    switch({ params: { panel } }) {
        if (panel === this.currentValue) {
            this.openValue = !this.openValue;
        } else {
            this.currentValue = panel;
            this.openValue = true;
        }
        this._apply();
    }

    _apply() {
        const c = this.currentValue;
        const o = this.openValue;
        this.panelTargets.forEach(p => {
            p.classList.toggle("open", p.dataset.dockPanel === c && o);
        });
        this.element.querySelectorAll(".dock-btn").forEach(btn => {
            btn.classList.toggle("active", btn.dataset.dockPanelParam === c);
        });
        try { localStorage.setItem("dockState", JSON.stringify({dock:c, open:o})); } catch(e) {}
    }
}
