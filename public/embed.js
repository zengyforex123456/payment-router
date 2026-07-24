/**
 * PaymentRouter Cloak — Embed Script v0.1
 *
 * 一行接入，零安装:
 *   <script src="https://your-domain.com/embed.js?key=ck_xxx&safe=https://safe.com&real=https://real.com"></script>
 *
 * 自动完成: Cloak判定 → 重定向分流 → 行为追踪 → 动态内容切换
 */
(function() {
  var script = document.currentScript;
  var params = new URLSearchParams(script.src.split('?')[1]);
  var API    = script.src.split('/embed.js')[0];
  var KEY    = params.get('key') || '';
  var SAFE   = params.get('safe') || location.href;
  var REAL   = params.get('real') || location.href;

  // 1. 发送 Cloak 请求 → 获取分流判定
  fetch(API + '/cloak?safe_url=' + encodeURIComponent(SAFE) + '&real_url=' + encodeURIComponent(REAL) + '&key=' + KEY, {
    headers: { 'X-Cloak-Key': KEY }
  })
  .then(function(r) { return r.json(); })
  .then(function(d) {
    // 2. 如果是 SAFE → 重定向到安全页
    if (d.action === 'safe' && d.redirect !== location.href) {
      location.replace(d.redirect);
      return;
    }
    // 3. REAL → 留在当前页 + 注入行为追踪
    if (d.action === 'real') {
      injectTracker(API, d.visitor);
    }
    // 4. safe_content → 动态替换敏感词
    if (d.action === 'safe_content') {
      sanitizePage();
      injectTracker(API, d.visitor);
    }
  })
  .catch(function() {
    // API 不可达 → 默认显示安全页
    if (SAFE !== location.href) location.replace(SAFE);
  });

  // 注入行为追踪 (页面停留/滚动/点击 → Beacon)
  function injectTracker(api, visitor) {
    var sid = 'sess_' + Math.random().toString(36).substr(2, 16);
    var start = Date.now(), clicks = 0, maxScroll = 0, hasConv = false;

    document.addEventListener('click', function() { clicks++; });
    document.addEventListener('scroll', function() {
      var p = Math.round(window.scrollY / (document.body.scrollHeight - window.innerHeight) * 100);
      if (p > maxScroll) maxScroll = p;
    });
    window._pr_trackConversion = function() { hasConv = true; };

    window.addEventListener('beforeunload', function() {
      var stay = Math.round((Date.now() - start) / 1000);
      navigator.sendBeacon(api + '/cloak/beacon', JSON.stringify({
        session_id: sid, stay_seconds: stay, scroll_pct: maxScroll,
        clicks: clicks, has_conversion: hasConv ? 1 : 0, page_url: location.href
      }));
    });

    // 触发转化追踪: 在加购/表单提交按钮上调用 window._pr_trackConversion()
    document.querySelectorAll('form[action*="checkout"], button[name*="cart"], .add-to-cart, .single_add_to_cart_button')
      .forEach(function(el) { el.addEventListener('click', function() { hasConv = true; }); });
  }

  // 动态内容替换 (审核员看到合规版)
  function sanitizePage() {
    var map = { '仿牌':'正品','F牌':'精品','复刻':'定制','1:1':'高品质','减肥药':'保健品','电子烟':'香薰机','成人用品':'个人护理' };
    function walk(node) {
      if (node.nodeType === 3) { for (var k in map) { node.nodeValue = node.nodeValue.replace(new RegExp(k,'gi'), map[k]); } }
      else if (node.nodeType === 1 && !/^(SCRIPT|STYLE|IFRAME)$/.test(node.tagName)) {
        for (var i = 0; i < node.childNodes.length; i++) walk(node.childNodes[i]);
      }
    }
    walk(document.body);
  }
})();
