<?php
/**
 * EvaluateCloakUseCase — 斗篷核心引擎
 *
 * 实时分析访客，按优先级匹配规则，返回判定结果。
 * 规则优先级: 内置爬虫检测 → 用户自定义规则 → 默认放行到安全页。
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Application;

use Converge\Modules\PaymentRouter\Cloak\Domain\{CloakVisitor, CloakRule, CloakDecision};
use Converge\Modules\PaymentRouter\Cloak\Infrastructure\BrowserFingerprint;

final class EvaluateCloakUseCase
{
    private array $rules;                       // 用户自定义规则
    private IpIntelService $ipIntel;            // IP 情报服务

    public function __construct(array $rules = [], ?IpIntelService $ipIntel = null)
    {
        $this->rules = $rules;
        $this->ipIntel = $ipIntel ?? new BuiltinIpIntel();
    }

    /**
     * 执行斗篷判定。
     *
     * @param string $safeUrl   安全页 URL (B 站)
     * @param string $realUrl   真实页 URL (A 站)
     * @return array{action: string, redirect: string, reason: string, visitor: array}
     */
    public function execute(CloakVisitor $visitor, string $safeUrl, string $realUrl): array
    {
        // 1. IP 情报增强
        $visitor = $this->ipIntel->enrich($visitor);

        // 2. 内置爬虫检测（最高优先级）
        $decision = $this->checkBuiltinCrawlers($visitor);
        if ($decision) {
            return $this->toResult($decision, $safeUrl, $realUrl);
        }

        // 3. 用户自定义规则（按优先级排序）
        usort($this->rules, fn(CloakRule $a, CloakRule $b) => $a->priority <=> $b->priority);
        foreach ($this->rules as $rule) {
            if (!$rule->enabled) continue;
            if ($rule->matches($visitor)) {
                $decision = new CloakDecision($rule->action, "rule #{$rule->id}: {$rule->field} {$rule->operator} {$rule->value}", $rule, $visitor);
                return $this->toResult($decision, $safeUrl, $realUrl);
            }
        }

        // 4. 智能默认：真实用户信号 → 真实页；可疑流量 → 安全页
        if ($this->isRealUser($visitor)) {
            return $this->toResult(CloakDecision::real('real user signals detected', $visitor), $safeUrl, $realUrl);
        }
        return $this->toResult(CloakDecision::defaultSafe($visitor), $safeUrl, $realUrl);
    }

    private function checkBuiltinCrawlers(CloakVisitor $visitor): ?CloakDecision
    {
        // Facebook 爬虫 (含 catalog)
        if (stripos($visitor->userAgent, 'facebookexternalhit') !== false
            || stripos($visitor->userAgent, 'Facebot') !== false
            || stripos($visitor->userAgent, 'facebookcatalog') !== false) {
            return CloakDecision::safe('Facebook crawler detected', null, $visitor);
        }
        // Google 爬虫
        if (stripos($visitor->userAgent, 'Googlebot') !== false
            || stripos($visitor->userAgent, 'AdsBot-Google') !== false) {
            return CloakDecision::safe('Google crawler detected', null, $visitor);
        }
        // TikTok 爬虫
        if (stripos($visitor->userAgent, 'TikTok') !== false
            || stripos($visitor->userAgent, 'Bytespider') !== false) {
            return CloakDecision::safe('TikTok/Bytedance crawler detected', null, $visitor);
        }
        // Pinterest / Bing / Twitter
        foreach (['Pinterest', 'Bingbot', 'Twitterbot', 'LinkedInBot', 'DuckDuckBot'] as $bot) {
            if (stripos($visitor->userAgent, $bot) !== false) {
                return CloakDecision::safe("{$bot} crawler detected", null, $visitor);
            }
        }
        // 数据中心 IP
        if ($visitor->isDatacenter) {
            return CloakDecision::safe('Datacenter/hosting IP detected', null, $visitor);
        }
        // 代理/VPN
        if ($visitor->isProxy) {
            return CloakDecision::safe('Proxy/VPN IP detected', null, $visitor);
        }
        // Headless browser / automation 检测
        if (BrowserFingerprint::detectHeadless($visitor)) {
            return CloakDecision::safe('Headless browser/automation detected', null, $visitor);
        }
        // 空 User-Agent
        if (empty($visitor->userAgent)) {
            return CloakDecision::safe('Empty User-Agent (likely bot)', null, $visitor);
        }
        return null;
    }

    /** 判断是否真实用户（有真实浏览器特征且无数据中心/代理标记） */
    private function isRealUser(CloakVisitor $v): bool
    {
        // 排除：空 UA、已知爬虫、数据中心、代理
        if (empty($v->userAgent)) return false;
        if ($v->isDatacenter || $v->isProxy) return false;
        // 有 Accept-Language 且有正常 UA → 大概率真实用户
        if (!empty($v->acceptLanguage) && strlen($v->userAgent) > 30) return true;
        // 有 Referrer（来自广告点击）→ 真实用户
        if (!empty($v->referrer)) return true;
        return false;
    }

    private function toResult(CloakDecision $d, string $safeUrl, string $realUrl): array
    {
        return [
            'action'   => $d->action,
            'redirect' => $d->action === 'real' ? $realUrl : $safeUrl,
            'reason'   => $d->reason,
            'visitor'  => [
                'ip'       => substr($d->visitor->ip, 0, 3) . '.***',
                'country'  => $d->visitor->country,
                'is_proxy' => $d->visitor->isProxy,
            ],
        ];
    }
}
