<?php
/**
 * HealthCheckUseCase — B 站健康检查 + 自动冷却/恢复
 *
 * 对应 PRD R7: 定期探测 B 站可达性 + 支付网关状态
 * 对应 PRD R8: 冷却时间到 → 自动恢复
 *
 * 建议通过 Cron 每分钟调用 execute()。
 */
declare(strict_types=1);

namespace Converge\Modules\PaymentRouter\Application;

use Converge\Modules\PaymentRouter\Domain\BSite;
use Converge\Modules\PaymentRouter\Domain\BSiteRepositoryInterface;

final class HealthCheckUseCase
{
    private BSiteRepositoryInterface $bSiteRepo;

    public function __construct(BSiteRepositoryInterface $bSiteRepo)
    {
        $this->bSiteRepo = $bSiteRepo;
    }

    /**
     * 对指定租户的所有 B 站执行健康检查
     *
     * @return array{checked: int, cooled: int, recovered: int}
     */
    public function execute(int $tenantId): array
    {
        $sites = $this->bSiteRepo->findByTenant($tenantId);
        $checked = 0;
        $cooled = 0;
        $recovered = 0;

        foreach ($sites as $bSite) {
            $checked++;

            // 检查是否应从冷却中恢复
            if ($bSite->status === 'cooling' && !$bSite->isInCooldown()) {
                $updated = $bSite->recover();
                $this->bSiteRepo->save($updated);
                $recovered++;
                continue;
            }

            // 跳过已禁用的 B 站
            if ($bSite->status === 'disabled') {
                continue;
            }

            // 对 active B 站执行 HTTP 健康探测
            if ($bSite->status === 'active') {
                $isHealthy = $this->probeHealth($bSite->domain);
                if (!$isHealthy) {
                    $failing = new BSite(
                        $bSite->id, $bSite->tenantId, $bSite->domain,
                        $bSite->paymentGateway, $bSite->weight, $bSite->maxDailyOrders,
                        $bSite->status, $bSite->cooledUntil,
                        $bSite->consecutiveFailures + 1,
                        $bSite->dailyOrderCount
                    );
                    if ($failing->consecutiveFailures >= 3) {
                        $failing = $failing->cool();
                        $cooled++;
                    }
                    $this->bSiteRepo->save($failing);
                }
            }
        }

        return ['checked' => $checked, 'cooled' => $cooled, 'recovered' => $recovered];
    }

    /** HTTP 探测 B 站是否可达 */
    private function probeHealth(string $domain): bool
    {
        $url = "https://{$domain}/index.php?route=common/home";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_NOBODY => true,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 500;
    }
}
