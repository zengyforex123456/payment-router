import { Controller } from "@hotwired/stimulus";

/**
 * SidebarNav Controller — 侧边栏二级菜单展开/收起
 *
 * 单选模式: 点击一个父级展开其子菜单, 自动收起其他已展开的组
 */
export default class extends Controller {
    static targets = ["group"];
    static values = { active: String };

    connect() {
        this.activeValue = "";
        try {
            const saved = localStorage.getItem("sidebarNavActive");
            if (saved) this.activeValue = saved;
        } catch(e) {}
        this._render();
    }

    toggle({ params }) {
        const group = params.group;
        if (this.activeValue === group) {
            this.activeValue = "";
        } else {
            this.activeValue = group;
        }
        try { localStorage.setItem("sidebarNavActive", this.activeValue); } catch(e) {}
        this._render();
    }

    _render() {
        const active = this.activeValue;
        this.groupTargets.forEach(el => {
            el.style.display = el.dataset.sidebarNavGroup === active ? "" : "none";
        });
        this.element.querySelectorAll("[data-sidebar-nav-group-param]").forEach(btn => {
            const g = btn.dataset.sidebarNavGroupParam;
            btn.classList.toggle("is-expanded", g === active);
        });
    }
}
