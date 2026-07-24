<?php
/**
 * IpIntelService — IP 情报服务接口
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Application;

use Converge\Modules\PaymentRouter\Cloak\Domain\CloakVisitor;

interface IpIntelService
{
    public function enrich(CloakVisitor $visitor): CloakVisitor;
}

/**
 * BuiltinIpIntel — 内置 IP 情报（零依赖，基于已知数据中心 ASN / IP 段）
 */
final class BuiltinIpIntel implements IpIntelService
{
    /** 已知爬虫/数据中心 IP 段 */
    private const DATACENTER_CIDRS = [
        '69.171.224.0/19',  // Facebook
        '66.220.144.0/20',  // Facebook
        '31.13.64.0/18',    // Facebook
        '173.252.64.0/18',  // Facebook
        '66.249.64.0/19',   // Google
        '64.233.160.0/19',  // Google
        '74.125.0.0/16',    // Google
        '3.0.0.0/8',        // AWS
        '13.0.0.0/8',       // AWS
        '18.0.0.0/8',       // AWS
        '35.0.0.0/8',       // Google Cloud
        '34.0.0.0/8',       // Google Cloud
        '104.0.0.0/8',      // Google Cloud
        '52.0.0.0/8',       // AWS
        '54.0.0.0/8',       // AWS
    ];

    public function enrich(CloakVisitor $visitor): CloakVisitor
    {
        $isDatacenter = $visitor->isDatacenter;

        // CIDR 检查
        if (!$isDatacenter) {
            foreach (self::DATACENTER_CIDRS as $cidr) {
                if ($this->ipInCidr($visitor->ip, $cidr)) { $isDatacenter = true; break; }
            }
        }

        return new CloakVisitor(
            $visitor->ip, $visitor->userAgent, $visitor->acceptLanguage,
            $visitor->referrer, $visitor->country,
            $visitor->isProxy, $isDatacenter, $visitor->matches
        );
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) return false;
        return ($ipLong & (-1 << (32 - (int)$bits))) === ($subLong & (-1 << (32 - (int)$bits)));
    }
}
