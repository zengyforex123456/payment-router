<?php
/**
 * EmbedJsUseCase — 动态生成客户专属 Cloak JS
 *
 * GET /embed.js?key=ck_xxx&safe=URL&real=URL
 * 验证 License → 生成个性化 JS → CDN 缓存 → 日志
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Application;

use Converge\Contracts\DatabaseInterface;
use Converge\Modules\PaymentRouter\Application\LicenseManagerUseCase;

final class EmbedJsUseCase
{
    private DatabaseInterface $db;
    private LicenseManagerUseCase $license;
    private string $apiHost;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
        $this->license = new LicenseManagerUseCase($db, $_ENV['APP_SECRET'] ?? 'change-me');
        $this->apiHost = $_ENV['APP_URL'] ?? 'http://localhost:8080';
    }

    /**
     * 生成客户专属 JS。
     */
    public function render(array $params): string
    {
        $key  = $params['key'] ?? '';
        $safe = $params['safe'] ?? '';
        $real = $params['real'] ?? '';
        $debug= ($params['debug'] ?? '') === '1';

        // 1. 验证 License
        $valid = false;
        $tier  = 'community';
        $domain= '*';
        if ($key) {
            $result = $this->license->validate($key, $params['domain'] ?? '*');
            $valid = $result['valid'] ?? false;
            $tier  = $result['tier'] ?? 'community';
            $domain= $result['domain'] ?? '*';
        }

        // 2. 记录首次加载（防滥用统计）
        $this->logEmbed($key, $tier, $valid);

        // 3. 生成 JS
        $apiHost = $this->apiHost;
        $licenseJson = json_encode(['key'=>$key, 'valid'=>$valid, 'tier'=>$tier]);
        $safeJs = json_encode($safe);
        $realJs = json_encode($real);
        $debugJs = $debug ? 'true' : 'false';

        // 许可证无效 → 返回轻量版（仅基础 Cloak）
        if (!$valid && $key) {
            return $this->renderLimited($safeJs);
        }

        return <<<JS
/** PaymentRouter Cloak v0.1 — License: {$key} — Tier: {$tier} */
(function(){
var C={api:"{$apiHost}",key:"{$key}",safe:{$safeJs},real:{$realJs},tier:"{$tier}",debug:{$debugJs}};
var S=document.currentScript||document.querySelector('script[src*="embed.js"]');
if(!S)return;
var p=new URLSearchParams(S.src.split('?')[1]);
C.safe=p.get('safe')||C.safe;C.real=p.get('real')||C.real;
C.sid='s_'+Math.random().toString(36).substr(2,12);
var start=Date.now(),clicks=0,maxScroll=0,hasConv=false;

// Cloak: 向 API 请求分流判定
var furl=C.api+'/cloak?safe_url='+encodeURIComponent(C.safe)+'&real_url='+encodeURIComponent(C.real);
fetch(furl).then(function(r){return r.json()}).then(function(d){
  if(C.debug)console.log('[Cloak]',d);
  if(d.action==='safe'&&d.redirect!==location.href){location.replace(d.redirect);return;}
  if(d.action==='safe_content'){(function(){var M={仿牌:'正品',F牌:'精品',复刻:'定制','1:1':'高品质',减肥药:'保健品',电子烟:'香薰机',成人用品:'个人护理'};function w(n){if(n.nodeType===3){for(var k in M)n.nodeValue=n.nodeValue.replace(new RegExp(k,'gi'),M[k])}else if(n.nodeType===1&&!/^(SCRIPT|STYLE|IFRAME)$/.test(n.tagName)){for(var i=0;i<n.childNodes.length;i++)w(n.childNodes[i])}}w(document.body)})();}
  track();
}).catch(function(){if(C.safe!==location.href)location.replace(C.safe)});

function track(){
  document.addEventListener('click',function(){clicks++});
  document.addEventListener('scroll',function(){var p=Math.round(window.scrollY/(document.body.scrollHeight-window.innerHeight)*100);if(p>maxScroll)maxScroll=p});
  window._pr_trackConversion=function(){hasConv=true};
  document.querySelectorAll('form[action*="checkout"],.add-to-cart,.single_add_to_cart_button,[name*="cart"]').forEach(function(e){e.addEventListener('click',function(){hasConv=true})});
  window.addEventListener('beforeunload',function(){
    navigator.sendBeacon(C.api+'/cloak/beacon',JSON.stringify({session_id:C.sid,stay_seconds:Math.round((Date.now()-start)/1000),scroll_pct:maxScroll,clicks:clicks,has_conversion:hasConv?1:0,page_url:location.href}))
  });
}
})();
JS;
    }

    /** 许可证无效 → 社区轻量版 */
    private function renderLimited(string $safeJs): string
    {
        return <<<JS
/** PaymentRouter Cloak — Community (unlicensed) */
(function(){
var safe={$safeJs};
if(safe&&safe!==location.href)location.replace(safe);
console.warn('[Cloak] License invalid or expired. Upgrade at paymentrouter.dev');
})();
JS;
    }

    private function logEmbed(string $key, string $tier, bool $valid): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
        $stmt = $this->db->prepare(
            'INSERT INTO payment_router_cloak_logs (tenant_id, action, reason, ip_hash, user_agent_short)
             VALUES (0, ?, ?, ?, ?)'
        );
        $action = $valid ? 'embed_valid' : 'embed_invalid';
        $reason = "tier={$tier}";
        $ipHash = hash('sha256', $ip);
        $stmt->bind_param('ssss', $action, $reason, $ipHash, $ua);
        $stmt->execute();
    }
}
