import { Controller } from "@hotwired/stimulus";

/**
 * Modal Controller — 模态框 (替换 x-show 模态框模式)
 *
 * HTML:
 *   <div data-controller="modal">
 *     <button data-action="click->modal#open">Open Modal</button>
 *     <dialog data-modal-target="dialog">
 *       <div class="modal-content">...</div>
 *       <button data-action="click->modal#close">Close</button>
 *     </dialog>
 *   </div>
 *
 * 或使用 <div> 模式:
 *   <div data-controller="modal">
 *     <button data-action="click->modal#open">Open</button>
 *     <div data-modal-target="panel" class="modal-overlay" style="display:none">
 *       <div class="modal-content">...</div>
 *       <button data-action="click->modal#close">Close</button>
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ["dialog", "panel"];

    connect() {
        this._boundEsc = this._onEsc.bind(this);
        document.addEventListener("keydown", this._boundEsc);
    }

    disconnect() {
        document.removeEventListener("keydown", this._boundEsc);
    }

    open(e) {
        e && e.preventDefault();
        if (this.hasDialogTarget) {
            this.dialogTarget.showModal();
        }
        if (this.hasPanelTarget) {
            this.panelTargets.forEach(p => { p.style.display = ""; });
            document.body.style.overflow = "hidden";
        }
    }

    close(e) {
        e && e.preventDefault();
        if (this.hasDialogTarget) {
            this.dialogTarget.close();
        }
        if (this.hasPanelTarget) {
            this.panelTargets.forEach(p => { p.style.display = "none"; });
            document.body.style.overflow = "";
        }
    }

    _onEsc(e) {
        if (e.key === "Escape") this.close();
    }
}
