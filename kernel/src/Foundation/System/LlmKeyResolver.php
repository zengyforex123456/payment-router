<?php

declare(strict_types=1);

namespace Converge\Foundation\System;

/**
 * LlmKeyResolver — 多模型独立 API Key 解析器
 *
 * 每个 DeepSeek 模型可配置独立 Key，三级降级链:
 *   L1: LLM_KEY_DEEPSEEK_V4PRO    (模型专用)
 *   L2: LLM_KEY_DEEPSEEK           (Provider 通用)
 *   L3: DEEPSEEK_API_KEY           (旧版兼容)
 *
 * 用法:
 *   $key = LlmKeyResolver::resolve('deepseek', 'deepseek-v4-pro');
 *   if (!$key) throw new \RuntimeException('未配置 API Key');
 *
 * 安全: Key 只通过环境变量注入，不存文件、不写日志、不返回到前端。
 */
class LlmKeyResolver
{
    /** Provider 别名标准化 */
    private const PROVIDER_ALIASES = [
        'ds' => 'deepseek', 'deepseek' => 'deepseek',
        'oa' => 'openai', 'openai' => 'openai',
        'ant' => 'anthropic', 'anthropic' => 'anthropic', 'claude' => 'anthropic',
    ];

    /**
     * 三级降级解析: 模型专用 → Provider通用 → 旧版兼容
     *
     * @return array{key: string, source: string}|null
     */
    public static function resolve(string $provider, string $model): ?array
    {
        $prov = self::normalizeProvider($provider);
        if (!$prov) return null;

        // L1: LLM_KEY_DEEPSEEK_V4PRO (模型专用)
        $modelEnv = self::modelKeyEnv($prov, $model);
        if ($modelEnv) {
            $val = getenv($modelEnv) ?: ($_ENV[$modelEnv] ?? ($_SERVER[$modelEnv] ?? null));
            if ($val) return ['key' => $val, 'source' => $modelEnv];
        }

        // L2: LLM_KEY_DEEPSEEK (Provider 通用)
        $provEnv = self::providerKeyEnv($prov);
        if ($provEnv) {
            $val = getenv($provEnv) ?: ($_ENV[$provEnv] ?? ($_SERVER[$provEnv] ?? null));
            if ($val) return ['key' => $val, 'source' => $provEnv];
        }

        // L3: DEEPSEEK_API_KEY (旧版兼容)
        $legacyEnv = strtoupper($prov) . '_API_KEY';
        $val = getenv($legacyEnv) ?: ($_ENV[$legacyEnv] ?? ($_SERVER[$legacyEnv] ?? null));
        if ($val) return ['key' => $val, 'source' => $legacyEnv . ' (legacy)'];

        return null;
    }

    /** 只取 key 值，不关心来源 */
    public static function getKey(string $provider, string $model): ?string
    {
        $resolved = self::resolve($provider, $model);
        return $resolved['key'] ?? null;
    }

    /** 生成模型专用环境变量名: deepseek + v4-pro → LLM_KEY_DEEPSEEK_V4PRO */
    public static function modelKeyEnv(string $provider, string $model): string
    {
        $prov = self::normalizeProvider($provider);
        $suffix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $model));
        $suffix = preg_replace('/_+/', '_', $suffix);
        return "LLM_KEY_{$prov}_{$suffix}";
    }

    /** 生成 Provider 通用环境变量名: deepseek → LLM_KEY_DEEPSEEK */
    public static function providerKeyEnv(string $provider): string
    {
        $prov = self::normalizeProvider($provider);
        return "LLM_KEY_{$prov}";
    }

    /** 列出所有已配置的 Key (masked，不暴露真实值) */
    public static function listConfigured(): array
    {
        $configured = [];
        $allEnv = array_merge($_ENV, $_SERVER, getenv() ?: []);

        foreach ($allEnv as $name => $value) {
            if (!is_string($value) || $value === '') continue;

            $isMatch = str_starts_with($name, 'LLM_KEY_')
                || in_array($name, ['DEEPSEEK_API_KEY', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY'], true);

            if (!$isMatch) continue;
            if (isset($configured[$name])) continue;

            $configured[$name] = substr($value, 0, 8) . '...' . substr($value, -4);
        }

        ksort($configured);
        return $configured;
    }

    /** 检查某个模型是否已配置 */
    public static function hasKey(string $provider, string $model): bool
    {
        return self::resolve($provider, $model) !== null;
    }

    private static function normalizeProvider(string $provider): ?string
    {
        $lower = strtolower($provider);
        return self::PROVIDER_ALIASES[$lower] ?? $lower;
    }
}
