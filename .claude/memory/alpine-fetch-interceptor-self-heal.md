---
name: alpine-fetch-interceptor-self-heal
description: Alpine.js fetch interceptor prevents white-screen when PHP returns HTML errors instead of JSON
metadata: 
  node_type: memory
  type: feedback
  originSessionId: 42ec1c5a-90e4-4a0c-abd2-8a5c4c99c9d4
---

# Alpine.js Fetch 拦截器 — 防 PHP 崩溃白屏

**检测模式**: Builder 页面显示但区块操作无响应。Network 面板显示 POST 请求返回 200 但内容是 PHP 错误 HTML（Fatal error/Parse error），Alpine 尝试解析 JSON 失败，组件静默崩溃。

**根因**: PHP 异常/错误输出 HTML 而非 JSON。`fetch().then(r => r.json())` 在 HTML 响应上抛异常，未捕获的 Promise rejection 导致 Alpine 组件停止响应。

**修复** — 全局 fetch 拦截器:
```js
// builder.js 最顶部（Alpine 初始化之前）
(function() {
  var _fetch = window.fetch;
  window.fetch = function(url, opts) {
    return _fetch.apply(this, arguments).then(function(res) {
      if (typeof url === 'string' && url.includes('/builder.php')) {
        var clone = res.clone();
        var ct = clone.headers.get('content-type') || '';
        // 期望 JSON 但返回了 HTML → PHP 崩溃
        if (ct.includes('text/html') && opts && String(opts.body).includes('action=')) {
          return clone.text().then(function(html) {
            if (html.includes('Fatal error') || html.includes('Parse error')) {
              console.error('[Self-Heal] PHP error intercepted');
              return new Response(JSON.stringify({ok:false, error:'Server error'}), {
                status: 200, headers: {'Content-Type': 'application/json'}
              });
            }
            return res;
          });
        }
      }
      return res;
    }).catch(function(err) {
      // 网络故障 → 降级响应
      if (typeof url === 'string' && url.includes('/builder.php')) {
        return new Response(JSON.stringify({ok:false, error:'Network offline'}), {
          status: 200, headers: {'Content-Type': 'application/json'}
        });
      }
      throw err;
    });
  };
})();
```

**验证**: 故意引入 PHP 语法错误 → Builder 显示 toast "Server error" 而非白屏
