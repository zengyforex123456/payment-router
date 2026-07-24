import { Controller } from "@hotwired/stimulus";

/**
 * Search Controller — global page search with Ctrl+K
 * data-controller="search" on the wrapper
 */
export default class extends Controller {
    static targets = ["input", "results", "empty", "loading"];
    static values = { state: String };
    
    connect() {
        this.query = '';
        if (this.hasLoadingTarget) this.loadingTarget.style.display = 'none';
    }

    search() {
        this.stateValue = 'loading';
        if (this.hasLoadingTarget) this.loadingTarget.style.display = '';
        this.query = (this.inputTarget.value || '').trim().toLowerCase();
        if (this.query.length < 2) {
            this.resultsTarget.innerHTML = '';
            this.resultsTarget.style.display = 'none';
            if (this.hasEmptyTarget) this.emptyTarget.style.display = 'none';
            if (this.hasLoadingTarget) this.loadingTarget.style.display = 'none';
            this.stateValue = 'idle';
            return;
        }
        // searchIndex is a global injected by PHP
        const idx = window.__SEARCH_INDEX || [];
        const hits = idx.filter(item => item.l.toLowerCase().includes(this.query)).slice(0, 6);
        this.stateValue = hits.length ? 'data' : 'empty';
        this.resultsTarget.innerHTML = hits.map(r =>
            `<a href="${r.u}" class="search-result-item">${r.l}</a>`
        ).join('');
        this.resultsTarget.style.display = hits.length ? '' : 'none';
        if (this.hasEmptyTarget) this.emptyTarget.style.display = hits.length ? 'none' : '';
        if (this.hasLoadingTarget) this.loadingTarget.style.display = 'none';
    }

    focus() {
        if (this.query.length >= 2) {
            this.resultsTarget.style.display = '';
        }
    }

    blur() {
        setTimeout(() => { this.resultsTarget.style.display = 'none'; }, 150);
    }

    clear() {
        this.query = '';
        this.inputTarget.value = '';
        this.resultsTarget.innerHTML = '';
        this.resultsTarget.style.display = 'none';
    }
}
