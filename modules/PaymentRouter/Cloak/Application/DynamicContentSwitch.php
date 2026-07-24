<?php
/**
 * DynamicContentSwitch — 动态内容分发 (DCD)
 *
 * 根据风险评分动态切换页面内容模板。
 * 不拦截、不弹窗——审核员看到合规版，真实客户看到真实版。
 *
 * 用法 (Latte 模板中):
 *   {if $visitor_risk_score > 7}
 *     {include 'safe_version.latte'}
 *   {else}
 *     {include 'real_version.latte'}
 *   {/if}
 */
declare(strict_types=1);
namespace Converge\Modules\PaymentRouter\Cloak\Application;

final class DynamicContentSwitch
{
    /** 内容替换映射表（JSON 可配置） */
    private array $contentMap;

    private const DEFAULT_MAP = [
        // 品类映射: 真实词 → 安全词
        '仿牌'    => '正品',
        'F牌'     => '精品',
        '复刻'    => '定制',
        '1:1'    => '高品质',
        '原单'    => '品牌',
        '减肥药'  => '保健品',
        '壮阳'    => '男性健康',
        '电子烟'  => '香薰机',
        '烟油'    => '精油',
        '成人用品'=> '个人护理',
        '高仿'    => '同款',
    ];

    public function __construct(?array $customMap = null)
    {
        $this->contentMap = $customMap ?? self::DEFAULT_MAP;
    }

    /**
     * 对内容执行安全替换。
     *
     * @param string $content 原始 HTML/文本
     * @param int    $riskScore 风险分 (0-10)
     * @return string 替换后的安全内容
     */
    public function sanitize(string $content, int $riskScore): string
    {
        if ($riskScore < 7) return $content; // 低风险不替换

        foreach ($this->contentMap as $risky => $safe) {
            $content = str_ireplace($risky, $safe, $content);
        }
        return $content;
    }

    /**
     * 根据风险分决定使用哪个模板。
     *
     * @return string 'real' | 'safe'
     */
    public function selectTemplate(int $riskScore): string
    {
        return $riskScore >= 7 ? 'safe' : 'real';
    }

    /** 获取当前内容映射表 */
    public function getContentMap(): array
    {
        return $this->contentMap;
    }

    /** 添加自定义映射 */
    public function addMapping(string $risky, string $safe): void
    {
        $this->contentMap[$risky] = $safe;
    }
}
