<?php
declare(strict_types=1);

namespace Converge\Core\Migration;

/**
 * PathResolver — 迁移路径解析器
 *
 * 读取 migration-registry.json 中已完成映射，
 * 将旧路径解析为新路径。用于验证迁移完整性。
 *
 * 注意：PSR-4 类加载由 Composer 管理，不需要 PathResolver。
 * 本类仅用于验证和调试。
 */
class PathResolver
{
    /** @var array<string, string> source => target */
    private array $mappings = [];

    /** @var array<string, string> target => source (反向映射) */
    private array $reverse = [];

    public function __construct(string $registryPath)
    {
        if (! file_exists($registryPath)) {
            return;
        }

        $registry = json_decode(file_get_contents($registryPath), true);
        if (! $registry || ! isset($registry['batches'])) {
            return;
        }

        foreach ($registry['batches'] as $batch) {
            if (($batch['status'] ?? 'pending') !== 'completed') {
                continue;
            }
            foreach ($batch['items'] as $item) {
                if (($item['status'] ?? 'pending') === 'completed' && isset($item['target'])) {
                    $src  = rtrim((string) $item['source'], '/');
                    $tgt  = rtrim((string) $item['target'], '/');
                    $this->mappings[$src] = $tgt;
                    $this->reverse[$tgt] = $src;
                }
            }
        }
    }

    /**
     * 解析路径：已迁移返回新路径，未迁移返回原路径。
     */
    public function resolve(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        foreach ($this->mappings as $source => $target) {
            if (str_starts_with($normalized, $source . '/') || $normalized === $source) {
                $rest = substr($normalized, strlen($source));
                return $target . $rest;
            }
        }
        return $path;
    }

    /**
     * 反向解析：新路径 → 旧路径（调试用）。
     */
    public function reverse(string $newPath): string
    {
        $normalized = str_replace('\\', '/', $newPath);
        foreach ($this->reverse as $target => $source) {
            if (str_starts_with($normalized, $target . '/') || $normalized === $target) {
                $rest = substr($normalized, strlen($target));
                return $source . $rest;
            }
        }
        return $newPath;
    }

    /**
     * 返回所有已完成的映射。
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->mappings;
    }

    /**
     * 验证所有已迁移路径的文件实际存在。
     * @return string[] 错误消息列表，空数组 = 全部通过
     */
    public function verify(): array
    {
        $errors = [];
        foreach ($this->mappings as $source => $target) {
            $fullPath = APP_ROOT . '/' . $target;
            if (! file_exists($fullPath)) {
                $errors[] = "MISSING: $target (was: $source) — expected at $fullPath";
            }
        }
        return $errors;
    }

    /**
     * 检查一个路径是否已被迁移。
     */
    public function isMigrated(string $path): bool
    {
        return $this->resolve($path) !== $path;
    }
}
