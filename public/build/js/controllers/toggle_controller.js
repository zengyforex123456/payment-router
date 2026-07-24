import { Controller } from "@hotwired/stimulus";

/**
 * Toggle Controller — generic show/hide for dropdowns (quick create, timezone, role menus)
 * HTML: data-controller="toggle" data-action="click->toggle#toggle"
 *        + data-toggle-target="panel" on the dropdown panel
 */
export default class extends Controller {
    static targets = ["panel"];

    connect() {
        this.open = false;
        this._boundClose = this._close.bind(this);
        document.addEventListener('click', this._boundClose);
    }

    disconnect() {
        document.removeEventListener('click', this._boundClose);
    }

    toggle(e) {
        e.stopPropagation();
        this.open = !this.open;
        this.panelTargets.forEach(p => {
            p.style.display = this.open ? '' : 'none';
        });
    }

    _close(e) {
        if (this.open && !this.element.contains(e.target)) {
            this.open = false;
            this.panelTargets.forEach(p => { p.style.display = 'none'; });
        }
    }
}
