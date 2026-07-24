<?php
/** LicenseBootstrap — Docker JWT key injection. Internal methods → LicenseBootstrapInternals trait. */
declare(strict_types=1);
namespace Converge\Foundation\License;

class LicenseBootstrap
{
    use LicenseBootstrapInternals;

    private string $authServer, $clientId, $licenseKey, $containerId, $instancePath;
    private ?string $jwtToken = null, $decryptKey = null;
    private int $lastSuccessfulRenewal = 0, $emergencyActivatedAt = 0, $consecutiveFailures = 0;
    private const JWT_TTL = 86400, RENEW_INTERVAL = 21600, EMERGENCY_THRESHOLD = 1800, EMERGENCY_MAX_HOURS = 72;

    public function __construct(?string $authServer = null, ?string $clientId = null, ?string $licenseKey = null)
    {
        $this->authServer  = $authServer ?? 'https://converge.io';
        $this->clientId    = $clientId ?? getenv('CONVERGE_CLIENT_ID') ?: '';
        $this->licenseKey  = $licenseKey ?? getenv('CONVERGE_LICENSE_KEY') ?: '';
        $this->containerId = $this->generateContainerId();
        $this->instancePath = (defined('STORAGE_PATH') ? STORAGE_PATH : sys_get_temp_dir()) . '/.license_bootstrap';
        $this->loadState();
    }

    /** Bootstrap — container startup. Returns DECRYPT_KEY. */
    public function bootstrap(): string
    {
        if ($this->jwtToken && $this->decryptKey && !$this->isJwtExpired()) {
            return $this->decryptKey;
        }
        if ($this->isEmergencyMode()) {
            $rem = self::EMERGENCY_MAX_HOURS - intdiv(time() - $this->emergencyActivatedAt, 3600);
            if ($rem > 0) return $this->decryptKey ?? '';
            throw new LicenseException('Emergency mode expired.');
        }
        try {
            $this->handleActivationResponse($this->activate());
            return $this->decryptKey;
        } catch (NetworkException $e) {
            return $this->handleNetworkFailure($e);
        }
    }

    /** Renew JWT every 6h. Blacklisted → container stops within 72h. */
    public function renewIfNeeded(): void
    {
        if (!$this->jwtToken || time() - $this->lastSuccessfulRenewal < self::RENEW_INTERVAL) return;
        try {
            $this->renewJwt();
            $this->consecutiveFailures = 0;
            $this->lastSuccessfulRenewal = time();
            $this->saveState();
        } catch (\Throwable $e) {
            $this->consecutiveFailures++;
            if ($this->consecutiveFailures * 300 > self::EMERGENCY_THRESHOLD) $this->activateEmergency();
            if ($e instanceof LicenseRevokedException) $this->emergencyActivatedAt = time();
        }
    }

    public function getDecryptKey(): ?string { return $this->decryptKey; }

    public function isLicensed(): bool
    {
        return ($this->jwtToken && !$this->isJwtExpired()) || $this->isEmergencyMode();
    }
}
