<?php
/** LicenseBootstrapInternals — HTTP/Crypto/State/JWT/CircuitBreaker trait */
declare(strict_types=1);
namespace Converge\Foundation\License;

trait LicenseBootstrapInternals
{
    // ── HTTP ──
    private function activate(): array {
        $p = ['client_id'=>$this->clientId,'license_key'=>$this->licenseKey,'hostname'=>gethostname(),'mac_addr'=>$this->getMacAddress(),'container_id'=>$this->containerId,'timestamp'=>time()];
        $p['signature'] = $this->sign($p); return $this->post('/api/license/activate', $p);
    }
    private function renewJwt(): array {
        $p = ['client_id'=>$this->clientId,'container_id'=>$this->containerId,'jwt'=>$this->jwtToken,'timestamp'=>time()];
        $p['signature'] = $this->sign($p); return $this->post('/api/license/renew', $p);
    }
    private function post(string $ep, array $data): array {
        $ch = curl_init($this->authServer . $ep);
        curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Container-Id: '.$this->containerId],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($err) throw new NetworkException("Unreachable: {$err}");
        if ($code === 403) { $b = json_decode((string)$r, true)?:[]; throw new LicenseRevokedException($b['reason']??'Revoked'); }
        if ($code === 429) throw new NetworkException('Rate limited');
        if ($code !== 200) throw new NetworkException("HTTP {$code}");
        return json_decode((string)$r, true) ?: [];
    }

    // ── Crypto ──
    private function sign(array $data): string { unset($data['signature']); ksort($data); return hash_hmac('sha256',(string)json_encode($data),$this->licenseKey); }

    // ── Hardware ──
    private function generateContainerId(): string { return hash('sha256', (gethostname()?:'unknown').':'.$this->getMacAddress().':'.$this->clientId); }
    private function getMacAddress(): string {
        if (file_exists($f='/sys/class/net/eth0/address') && ($m=trim(file_get_contents($f)?:''))) return $m;
        if (PHP_OS_FAMILY==='Linux') { exec('ip link show 2>/dev/null|grep "link/ether"|head -1|awk \'{print $2}\'',$o); if (!empty($o[0])) return trim($o[0]); }
        return hash('sha256', gethostname()?:'docker');
    }

    // ── State ──
    private function saveState(): void {
        try { $s=['jwt'=>$this->jwtToken,'decrypt_key'=>$this->decryptKey,'last_successful_renewal'=>$this->lastSuccessfulRenewal,'emergency_activated_at'=>$this->emergencyActivatedAt,'consecutive_failures'=>$this->consecutiveFailures]; $k=substr(hash('sha256',$this->containerId,true),0,32); $iv=random_bytes(16); file_put_contents($this->instancePath,base64_encode($iv.openssl_encrypt(json_encode($s),'aes-256-cbc',$k,0,$iv)),LOCK_EX); } catch (\Throwable) {}
    }
    private function loadState(): void {
        try { if(!file_exists($this->instancePath)) return; $c=file_get_contents($this->instancePath); if(!$c||strlen($c)<17) return; $d=base64_decode($c); $k=substr(hash('sha256',$this->containerId,true),0,32); $j=openssl_decrypt(substr($d,16),'aes-256-cbc',$k,0,substr($d,0,16)); if(!$j) return; $s=json_decode($j,true)?:[]; $this->jwtToken=$s['jwt']??null; $this->decryptKey=$s['decrypt_key']??null; $this->lastSuccessfulRenewal=$s['last_successful_renewal']??0; $this->emergencyActivatedAt=$s['emergency_activated_at']??0; $this->consecutiveFailures=$s['consecutive_failures']??0; } catch (\Throwable) {}
    }

    // ── JWT ──
    private function handleActivationResponse(array $r): void {
        $this->jwtToken=$r['jwt']??''; $this->decryptKey=$r['decrypt_key']??'';
        if(!$this->jwtToken||!$this->decryptKey) throw new LicenseException('Missing JWT or key');
        $p=explode('.',$this->jwtToken); if(count($p)!==3) throw new LicenseException('Invalid JWT');
        $pl=json_decode(base64_decode(strtr($p[1],'-_','+/')),true)?:[]; $exp=$pl['exp']??0;
        if($exp&&$exp<time()) throw new LicenseException('JWT expired');
        if(isset($pl['limit'])&&($pl['count']??0)>$pl['limit']) throw new LicenseException("Concurrent limit: {$pl['count']}/{$pl['limit']}");
        $this->lastSuccessfulRenewal=time(); $this->consecutiveFailures=0; $this->saveState();
    }
    private function isJwtExpired(): bool {
        if(!$this->jwtToken) return true; $p=explode('.',$this->jwtToken); if(count($p)!==3) return true;
        return (json_decode(base64_decode(strtr($p[1],'-_','+/')),true)?:[])['exp']??0 < time();
    }

    // ── Circuit Breaker ──
    private function handleNetworkFailure(NetworkException $e): string {
        if($this->decryptKey){$h=intdiv(time()-$this->lastSuccessfulRenewal,3600); if($h<self::EMERGENCY_MAX_HOURS){$this->activateEmergency(); return $this->decryptKey;}}
        throw new LicenseException('Cannot bootstrap: offline and no cached key');
    }
    private function activateEmergency(): void { if($this->emergencyActivatedAt===0){$this->emergencyActivatedAt=time();} $this->saveState(); }
    private function isEmergencyMode(): bool {
        if($this->emergencyActivatedAt===0) return false; $h=intdiv(time()-$this->emergencyActivatedAt,3600);
        if($h>=self::EMERGENCY_MAX_HOURS) return false;
        $this->logHourly('Emergency',"Emergency {$h}h/".self::EMERGENCY_MAX_HOURS.'h');
        return true;
    }

    // ── Logging ──
    private function log(string $stage, string $msg): void { error_log("[License][{$stage}] {$msg}"); }
    private function logHourly(string $stage, string $msg): void {
        $ck='lic_log_'.date('YmdH'); if(function_exists('apcu_fetch')&&apcu_fetch($ck)) return;
        if(function_exists('apcu_store')) apcu_store($ck,true,3600); $this->log($stage,$msg);
    }
}
