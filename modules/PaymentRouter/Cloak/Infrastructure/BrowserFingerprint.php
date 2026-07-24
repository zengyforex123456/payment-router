<?php
/**
 * BrowserFingerprint — 浏览器指纹检测
 *
 * 通过 PHP 可检测的信号 + JS Challenge 验证来识别自动化/爬虫。
 * 真实用户会执行 JavaScript，爬虫/审核工具通常不会。
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Infrastructure;

use Converge\Modules\PaymentRouter\Cloak\Domain\CloakVisitor;

final class BrowserFingerprint
{
    /** 已知爬虫/自动化工具的 JS 指纹特征 */
    private const HEADLESS_SIGNATURES = [
        'HeadlessChrome', 'PhantomJS', 'NightmareJS', 'Puppeteer',
        'Playwright', 'Selenium', 'WebDriver', 'Cypress',
    ];

    /**
     * 生成 JS Challenge 页面。
     * 真实浏览器会自动跳转，爬虫看到的是空白页。
     */
    public static function renderChallenge(string $redirectUrl, string $safeFallbackUrl): string
    {
        $token = bin2hex(random_bytes(16));
        $safeHtml = htmlspecialchars($safeFallbackUrl);
        $realHtml = htmlspecialchars($redirectUrl);

        return <<<HTML
<!DOCTYPE html><html><head><meta charset="utf-8"><title>Loading...</title>
<script>
(function(){
  var t="{$token}",r="{$realHtml}",f="{$safeHtml}";
  // 1. WebDriver 检测
  if(navigator.webdriver){location.href=f;return;}
  // 2. Chrome headless 检测
  if(navigator.userAgent.indexOf('HeadlessChrome')>-1){location.href=f;return;}
  // 3. 屏幕分辨率检测 (headless 通常为 800x600 或无)
  if(!screen.width||!screen.height||screen.width<100||screen.height<100){location.href=f;return;}
  // 4. 真实浏览器通过 → 记录标记 → 跳转真实页
  var d=new Date();d.setTime(d.getTime()+86400000);
  document.cookie='_pr_js='+t+';path=/;expires='+d.toUTCString();
  location.href=r;
})();
</script>
<noscript><meta http-equiv="refresh" content="0;url={$safeHtml}"></noscript>
</head><body></body></html>
HTML;
    }

    /**
     * 验证 JS Challenge Cookie。
     * 被 PHP setcookie 的 `_pr_js` 存在 → JS 已执行 → 真实浏览器。
     */
    public static function verifyJsCookie(): bool
    {
        return isset($_COOKIE['_pr_js']) && strlen($_COOKIE['_pr_js']) === 32;
    }

    /**
     * 检测 User-Agent 是否有浏览器指纹异常的标记。
     */
    public static function detectHeadless(CloakVisitor $visitor): bool
    {
        foreach (self::HEADLESS_SIGNATURES as $sig) {
            if (stripos($visitor->userAgent, $sig) !== false) return true;
        }
        return false;
    }
}
