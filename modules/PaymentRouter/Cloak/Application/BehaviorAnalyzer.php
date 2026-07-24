<?php
/**
 * BehaviorAnalyzer — 访客行为分析器
 *
 * 通过 JS Beacon 收集行为信号，计算风险评分。
 * 信号: 页面停留时间 / 滚动深度 / 交互次数 / 回访频率 / 转化意向
 *
 * 风险分: 0=完全可信(真实客户) → 10=极高风险(审核员)
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Application;

use Converge\Contracts\DatabaseInterface;

final class BehaviorAnalyzer
{
    private DatabaseInterface $db;

    /** 风险阈值（可配置） */
    public int $stayTimeMin = 5;        // 停留时间阈值（秒），<此值 +5分
    public int $scrollDepthMin = 30;    // 滚动深度阈值（%），<此值 +3分
    public int $interactionMin = 1;     // 最小交互数，<此值 +2分
    public int $reviewerMaxScore = 4;   // 超过此分数 = 疑似审核员

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * 处理前端 JS Beacon 提交的行为数据。
     *
     * @param array $beacon {session_id, stay_seconds, scroll_pct, clicks, page_url}
     * @return array{risk_score: int, risk_level: string, disposition: string}
     */
    public function analyze(array $beacon): array
    {
        $stayTime = (int)($beacon['stay_seconds'] ?? 0);
        $scrollPct = (int)($beacon['scroll_pct'] ?? 0);
        $clicks = (int)($beacon['clicks'] ?? 0);
        $hasConversion = (bool)($beacon['has_conversion'] ?? false);
        $sessionId = $beacon['session_id'] ?? '';

        // 计算风险分（0-10）
        $score = 0;

        // 1. 停留时间（最强信号）
        if ($stayTime < 3) {
            $score += 5;  // 秒退 ← 审核员典型行为
        } elseif ($stayTime < 8) {
            $score += 3;  // 快速浏览
        } elseif ($stayTime > 30) {
            $score -= 2;  // 长时间停留 ← 真实客户
        }

        // 2. 滚动深度
        if ($scrollPct < 15) {
            $score += 3;  // 几乎不滚动
        } elseif ($scrollPct > 60) {
            $score -= 1;  // 深度浏览
        }

        // 3. 交互次数
        if ($clicks === 0) {
            $score += 2;  // 零交互
        } elseif ($clicks > 5) {
            $score -= 1;  // 高交互
        }

        // 4. 转化意图（终极裁决 → 直接归零）
        if ($hasConversion) {
            $score = 0;  // 转化 = 100% 真实客户，无论其他行为
        }

        $score = max(0, min(10, $score));

        // 判定
        $disposition = match (true) {
            $hasConversion            => 'real',       // 已转化 → 真实客户
            $score >= 7               => 'safe_content', // 高风险 → 动态合规内容
            $score >= 4               => 'challenge',    // 疑似 → JS Challenge
            default                   => 'real',         // 低风险 → 真实页面
        };

        // 持久化
        if ($sessionId) {
            $stmt = $this->db->prepare(
                'INSERT INTO payment_router_cloak_behavior (session_id, stay_seconds, scroll_pct, clicks, has_conversion, risk_score, disposition)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $conv = (int)$hasConversion;
            $stmt->bind_param('siiiiis', $sessionId, $stayTime, $scrollPct, $clicks, $conv, $score, $disposition);
            $stmt->execute();
        }

        return [
            'risk_score'  => $score,
            'risk_level'  => $score >= 7 ? 'high' : ($score >= 4 ? 'medium' : 'low'),
            'disposition' => $disposition,
        ];
    }

    /**
     * 查询会话历史行为。
     */
    public function getSessionHistory(string $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM payment_router_cloak_behavior WHERE session_id = ? ORDER BY created_at DESC LIMIT 10'
        );
        $stmt->bind_param('s', $sessionId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * 生成前端 JS 行为追踪代码片段。
     * 嵌入到 A 站/B 站页面中。
     */
    public static function trackerJs(string $beaconUrl, string $sessionId): string
    {
        $b = htmlspecialchars($beaconUrl);
        $s = htmlspecialchars($sessionId);
        return <<<JS
<script>
(function(){
  var sid='{$s}',beacon='{$b}',start=Date.now(),clicks=0,maxScroll=0,hasConv=false;
  document.addEventListener('click',function(){clicks++});
  document.addEventListener('scroll',function(){var p=Math.round(window.scrollY/(document.body.scrollHeight-window.innerHeight)*100);if(p>maxScroll)maxScroll=p});
  window._pr_trackConversion=function(){hasConv=true};
  window.addEventListener('beforeunload',function(){
    var stay=Math.round((Date.now()-start)/1000);
    var d={session_id:sid,stay_seconds:stay,scroll_pct:maxScroll,clicks:clicks,has_conversion:hasConv?1:0,page_url:location.href};
    navigator.sendBeacon(beacon,JSON.stringify(d));
  });
})();
</script>
JS;
    }
}
