import { Controller } from "@hotwired/stimulus";

/**
 * Dropdown Controller — 通用展开/收起 (替换 x-data="{open:false}" + @click="open=!open")
 *
 * HTML:
 *   <div data-controller="dropdown">
 *     <button data-action="click->dropdown#toggle">Menu</button>
 *     <div data-dropdown-target="panel" style="display:none">...</div>
 *   </div>
 *
 * 选项: data-dropdown-close-outside-value="true" — 点击外部自动关闭
 *       data-dropdown-esc-close-value="true"          — ESC 关闭
 */
export default class extends Controller {
    static targets = ["panel"];
    static values = { open: Boolean, closeOutside: Boolean, escClose: Boolean };

    connect() {
        this.openValue = false;
        this._boundClick = this._onClickOutside.bind(this);
        this._boundEsc = this._onEsc.bind(this);
        if (this.closeOutsideValue) {
            document.addEventListener("click", this._boundClick);
        }
        if (this.escCloseValue) {
            document.addEventListener("keydown", this._boundEsc);
        }
    }

    disconnect() {
        document.removeEventListener("click", this._boundClick);
        document.removeEventListener("keydown", this._boundEsc);
    }

    toggle(e) {
        e.stopPropagation();
        this.openValue = !this.openValue;
        this._render();
    }

    open(e) {
        e && e.stopPropagation();
        this.openValue = true;
        this._render();
    }

    close(e) {
        e && e.stopPropagation();
        this.openValue = false;
        this._render();
    }

    _onClickOutside(e) {
        if (this.openValue && !this.element.contains(e.target)) {
            this.openValue = false;
            this._render();
        }
    }

    _onEsc(e) {
        if (this.openValue && e.key === "Escape") {
            this.openValue = false;
            this._render();
        }
    }

    _render() {
        this.panelTargets.forEach(p => {
            p.style.display = this.openValue ? "" : "none";
        });
    }
}
