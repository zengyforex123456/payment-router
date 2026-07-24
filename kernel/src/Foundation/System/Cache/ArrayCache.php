<?php
/**
 * ArrayCache — CacheInterface 的默认内存实现
 *
 * 适合 CLI 模式、单请求缓存。生产环境可通过 DI 替换为 Redis/Memcached。
 *
 * 用法:
 *   $cache = new ArrayCache();
 *   $cache->set('key', $value, 300);
 *   $value = $cache->get('key');
 */
declare(strict_types=1);

namespace Converge\Foundation\System\Cache;

use Converge\Contracts\CacheInterface;

class ArrayCache implements CacheInterface
{
    /** @var array<string, array{value: mixed, expires: int}> */
    private array $store = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (!isset($this->store[$key])) {
            return $default;
        }

        $entry = $this->store[$key];
        if ($entry['expires'] > 0 && $entry['expires'] < time()) {
            unset($this->store[$key]);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->store[$key] = [
            'value'   => $value,
            'expires' => $ttl > 0 ? time() + $ttl : 0,
        ];
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }
}
