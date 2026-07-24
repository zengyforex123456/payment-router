<?php

declare(strict_types=1);

namespace Converge\Security;

/**
 * BotVerdict — 单次 Bot 检测分析结果
 *
 * 由 BotDetector::analyze() 返回，包含评分、判定标志和行动建议。
 */
class BotVerdict
{
    public function __construct(
        public readonly int $score,
        public readonly bool $isBot,
        public readonly bool $isSuspicious,
        public readonly array $reasons,
        public readonly string $action,
    ) {}

    public function shouldBlock(): bool
    {
        return $this->action === 'block' || $this->action === 'block_and_ban';
    }

    /** @return array{score:int, is_bot:bool, is_suspicious:bool, reasons:array, action:string} */
    public function toArray(): array
    {
        return [
            'score'         => $this->score,
            'is_bot'        => $this->isBot,
            'is_suspicious' => $this->isSuspicious,
            'reasons'       => $this->reasons,
            'action'        => $this->action,
        ];
    }
}
