/**
 * theme-toggle.js — Dark/light mode toggle (replaces Alpine.store('theme'))
 * Reads/writes localStorage, sets .dark class on <html>
 */
(function() {
    var mode = localStorage.getItem('theme') || 
        (window.matchMedia('(prefers-color-scheme:dark)').matches ? 'dark' : 'light');
    
    function apply() {
        document.documentElement.classList.toggle('dark', mode === 'dark');
        localStorage.setItem('theme', mode);
        // Update any toggle buttons
        document.querySelectorAll('[data-theme-toggle]').forEach(function(btn) {
            btn.textContent = mode === 'dark' ? '☀️' : '🌙';
            btn.setAttribute('title', mode === 'dark' ? 'Light mode' : 'Dark mode');
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        apply();
        document.querySelectorAll('[data-theme-toggle]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                mode = mode === 'dark' ? 'light' : 'dark';
                apply();
            });
        });
    });

    // Expose for inline usage
    window.toggleTheme = function() { mode = mode === 'dark' ? 'light' : 'dark'; apply(); };
})();
