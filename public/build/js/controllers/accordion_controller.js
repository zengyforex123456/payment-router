import { Controller } from "@hotwired/stimulus";

/**
 * Accordion Controller — FAQ 展开/收起 (替换 x-data activeFaq 模式)
 *
 * HTML:
 *   <div data-controller="accordion">
 *     <dt data-action="click->accordion#toggle" data-accordion-index-param="0">Q</dt>
 *     <dd data-accordion-target="panel" data-accordion-panel="0" style="display:none">A</dd>
 *   </div>
 *
 * 单选模式 (data-accordion-single-value="true"): 同时只展开一个
 */
export default class extends Controller {
    static targets = ["panel"];
    static values = { active: Number, single: Boolean };

    connect() {
        this.activeValue = -1;
        this._render();
    }

    toggle({ params }) {
        const idx = parseInt(params.index);
        if (this.activeValue === idx) {
            this.activeValue = -1; // collapse
        } else {
            this.activeValue = idx;
        }
        this._render();
    }

    _render() {
        this.panelTargets.forEach(p => {
            const idx = parseInt(p.dataset.accordionPanel);
            p.style.display = idx === this.activeValue ? "" : "none";
        });
    }
}
