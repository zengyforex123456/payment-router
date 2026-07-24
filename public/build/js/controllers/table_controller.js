import { Controller } from "@hotwired/stimulus";

/**
 * Table Controller — 数据表格排序/筛选/分页 (替换 dataTable Alpine 组件)
 *
 * HTML:
 *   <div data-controller="table" data-table-sort-value="name" data-table-dir-value="asc">
 *     <input data-action="input->table#filter" data-table-target="filter" placeholder="搜索...">
 *     <th data-action="click->table#sort" data-table-column-param="name">Name ▾</th>
 *     <tbody data-table-target="body">
 *       <tr data-table-row>...</tr>
 *     </tbody>
 *     <span data-table-target="count"></span>
 *   </div>
 */
export default class extends Controller {
    static targets = ["body", "filter", "count", "empty", "loading"];
    static values = { sort: String, dir: String, state: { type: String, default: "data" } };

    connect() {
        this.sortValue = this.sortValue || "";
        this.dirValue = this.dirValue || "asc";
        this._allRows = Array.from(this.bodyTarget.querySelectorAll("[data-table-row]"));
        if (this.hasCountTarget) {
            this.countTarget.textContent = this._allRows.length;
        }
        if (this.hasLoadingTarget) this.loadingTarget.style.display = "none";
    }

    filter() {
        this.stateValue = "loading";
        if (this.hasLoadingTarget) this.loadingTarget.style.display = "";
        const q = (this.filterTarget.value || "").toLowerCase();
        let visible = 0;
        this._allRows.forEach(row => {
            const match = !q || row.textContent.toLowerCase().includes(q);
            row.style.display = match ? "" : "none";
            if (match) visible++;
        });
        if (this.hasCountTarget) this.countTarget.textContent = visible;
        this.stateValue = visible === 0 ? "empty" : "data";
        if (this.hasLoadingTarget) this.loadingTarget.style.display = "none";
        if (this.hasEmptyTarget) {
            this.emptyTarget.style.display = this.stateValue === "empty" ? "" : "none";
        }
    }

    sort({ params }) {
        const col = params.column;
        if (this.sortValue === col) {
            this.dirValue = this.dirValue === "asc" ? "desc" : "asc";
        } else {
            this.sortValue = col;
            this.dirValue = "asc";
        }
        this._sortRows();
    }

    _sortRows() {
        const col = this.sortValue;
        const dir = this.dirValue;
        const rows = this._allRows.slice();
        rows.sort((a, b) => {
            const aVal = (a.dataset[`table${col.charAt(0).toUpperCase() + col.slice(1)}`] || a.querySelector(`[data-table-col="${col}"]`)?.textContent || "").trim();
            const bVal = (b.dataset[`table${col.charAt(0).toUpperCase() + col.slice(1)}`] || b.querySelector(`[data-table-col="${col}"]`)?.textContent || "").trim();
            const aNum = parseFloat(aVal);
            const bNum = parseFloat(bVal);
            if (!isNaN(aNum) && !isNaN(bNum)) {
                return dir === "asc" ? aNum - bNum : bNum - aNum;
            }
            return dir === "asc" ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        });
        rows.forEach(r => this.bodyTarget.appendChild(r));
    }
}
