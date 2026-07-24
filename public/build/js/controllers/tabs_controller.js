import { Controller } from "@hotwired/stimulus";

/**
 * Tabs Controller — 标签页切换 (替换 x-data + @click Tab)
 *
 * HTML:
 *   <div data-controller="tabs" data-tabs-active-value="tab1">
 *     <button data-action="click->tabs#switch" data-tabs-tab-param="tab1">Tab 1</button>
 *     <button data-action="click->tabs#switch" data-tabs-tab-param="tab2">Tab 2</button>
 *     <div data-tabs-target="panel" data-tabs-panel="tab1">Content 1</div>
 *     <div data-tabs-target="panel" data-tabs-panel="tab2" style="display:none">Content 2</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ["panel"];
    static values = { active: String };

    connect() {
        this._render();
    }

    switch({ params }) {
        this.activeValue = params.tab;
        this._render();
    }

    _render() {
        const active = this.activeValue;
        this.panelTargets.forEach(p => {
            p.style.display = p.dataset.tabsPanel === active ? "" : "none";
        });
        this.element.querySelectorAll("[data-tabs-tab-param]").forEach(btn => {
            btn.classList.toggle("active", btn.dataset.tabsTabParam === active);
        });
    }
}
