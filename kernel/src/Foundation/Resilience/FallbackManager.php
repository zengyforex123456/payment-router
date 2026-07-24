<?php

declare(strict_types=1);

namespace Converge\Foundation\Resilience;

/**
 * FallbackManager — 🛡️ 无故障
 *
 * Defines fallback chains for critical components.
 * When a primary service fails, FallbackManager selects the next available
 * alternative (degraded mode), ensuring the tracker never goes completely down.
 *
 * Fallback chains:
 *   GeoIP: DBIP → IP2Location → IPinfo → Unknown
 *   Click tracking: MySQL → file-based queue → memory buffer
 *   Postback dispatch: direct HTTP → async queue
 */
class FallbackManager
{
    private array $chains = [];
    private array $activeComponents = [];

    /**
     * Register a fallback chain for a component.
     *
     * @param string $component  e.g., "geoip", "click_tracking", "postback"
     * @param array<int, string> $providers  Ordered list of provider names
     */
    public function registerChain(string $component, array $providers): void
    {
        $this->chains[$component] = $providers;
        $this->activeComponents[$component] = $providers[0] ?? 'unknown';
    }

    /**
     * Mark a specific provider as failed, activating the next fallback.
     *
     * @return string|null  The newly active provider, or null if all exhausted
     */
    public function markFailed(string $component, string $provider): ?string
    {
        $chain = $this->chains[$component] ?? [];
        $currentIdx = array_search($provider, $chain, true);

        if ($currentIdx === false) {
            return null;
        }

        $nextIdx = $currentIdx + 1;
        if ($nextIdx >= count($chain)) {
            // All providers exhausted for this component
            $this->activeComponents[$component] = 'none';
            return null;
        }

        $this->activeComponents[$component] = $chain[$nextIdx];
        return $chain[$nextIdx];
    }

    /**
     * Get the currently active provider for a component.
     */
    public function getActive(string $component): string
    {
        return $this->activeComponents[$component] ?? 'unknown';
    }

    /**
     * Check if a component has any working provider.
     */
    public function isAvailable(string $component): bool
    {
        return ($this->activeComponents[$component] ?? 'none') !== 'none';
    }

    /**
     * Check if a component is running on a fallback (not primary).
     */
    public function isDegraded(string $component): bool
    {
        $chain = $this->chains[$component] ?? [];
        $active = $this->activeComponents[$component] ?? 'unknown';
        return $active !== ($chain[0] ?? 'unknown') && $active !== 'none';
    }

    /**
     * Reset a component back to its primary provider.
     */
    public function reset(string $component): void
    {
        $chain = $this->chains[$component] ?? [];
        $this->activeComponents[$component] = $chain[0] ?? 'unknown';
    }

    /**
     * Get the full status of all registered components.
     *
     * @return array<string, array{active: string, chain: list<string>, degraded: bool, available: bool}>
     */
    public function getStatus(): array
    {
        $status = [];
        foreach ($this->chains as $component => $chain) {
            $status[$component] = [
                'active' => $this->activeComponents[$component] ?? 'unknown',
                'chain' => $chain,
                'degraded' => $this->isDegraded($component),
                'available' => $this->isAvailable($component),
            ];
        }
        return $status;
    }
}
