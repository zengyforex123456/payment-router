/**
 * toast.js — Toast notifications (replaces Alpine.store('toast'))
 * Usage: window.showToast('message', 'success|error|warning|info')
 */
(function() {
    var container = null;
    
    function ensureContainer() {
        if (container) return container;
        container = document.createElement('div');
        container.className = 'toast-container';
        container.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(container);
        return container;
    }

    function icon(type) {
        var icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
        return icons[type] || 'ℹ️';
    }

    window.showToast = function(message, type) {
        type = type || 'info';
        var el = document.createElement('div');
        el.style.cssText = 'background:var(--surface-raised,#fff);color:var(--content-primary,#111);padding:10px 16px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);font-size:14px;display:flex;align-items:center;gap:8px;animation:toastIn .25s ease;min-width:240px;';
        el.textContent = icon(type) + ' ' + message;
        ensureContainer().appendChild(el);
        setTimeout(function() {
            el.style.opacity = '0';
            el.style.transition = 'opacity .2s';
            setTimeout(function() { el.remove(); }, 200);
        }, 4000);
    };
})();
