<?php
/**
 * SelfHealLedger — 恢复分类账: "Fix once, immune forever"
 *
 * 按指纹检索已验证修复 → 下次同指纹自动应用.
 * 存储: kag_entities 表 (type=修复模式, created_by=recovery-ledger)
 */
declare(strict_types=1);

namespace Converge\Foundation\Resilience;

class SelfHealLedger
{
    /** 查账本: fingerprint → {strategy, confidence, verified, samples} 或 null */
    public static function lookup(string $fingerprint): ?array
    {
        try {
            if (!function_exists('db')) return null;
            $db = db()->raw();
            $stmt = $db->prepare(
                "SELECT content, maturity FROM kag_entities
                 WHERE type='修复模式' AND created_by='recovery-ledger'
                 AND content LIKE ? ORDER BY maturity='已验证' DESC, created_at DESC LIMIT 10"
            );
            $like = '%"fingerprint":"' . $fingerprint . '"%';
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            while ($row = $res->fetch_assoc()) { $rows[] = $row; }
            $stmt->close();
            if (!$rows) return null;

            $verified = array_filter($rows, fn($r) => $r['maturity'] === '已验证');
            $best = $verified ? reset($verified) : $rows[0];
            $c = json_decode($best['content'] ?? '{}', true) ?: [];

            return [
                'fingerprint' => $fingerprint,
                'strategy'    => $c['strategy'] ?? 'retry-3x',
                'confidence'  => $verified ? (int)(count($verified) / count($rows) * 100) : 40,
                'verified'    => !empty($verified),
                'samples'     => count($rows),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** 记录恢复结果 → KAG (成功=已验证, 失败=假设) */
    public static function record(string $fingerprint, string $strategy, bool $success, string $category, int $elapsedMs): void
    {
        try {
            if (!function_exists('db')) return;
            $db = db()->raw();
            $id = 'ledger-' . bin2hex(random_bytes(12));
            $title = '[恢复账本] ' . $fingerprint . ' → ' . $strategy;
            $content = json_encode(['fingerprint' => $fingerprint, 'strategy' => $strategy, 'success' => $success, 'elapsedMs' => $elapsedMs, 'category' => $category, 'at' => date('c')], JSON_UNESCAPED_UNICODE); // AlpineHelper: KAG storage, not HTML
            $tags = json_encode(['恢复账本', $category, $success ? '成功' : '失败'], JSON_UNESCAPED_UNICODE); /* AlpineHelper omit: KAG storage */
            $stmt = $db->prepare(
                "INSERT INTO kag_entities (id, project_id, type, title, content, tags, maturity, created_by)
                 VALUES (?, 'converge', '修复模式', ?, ?, ?, ?, 'recovery-ledger')"
            );
            $stmt->bind_param('sssss', $id, $title, $content, $tags, $success ? '已验证' : '假设');
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable) {}
    }

    /** 账本统计: totalRecords, verifiedRecords, uniqueFingerprints, hitRate */
    public static function stats(): array
    {
        try {
            if (!function_exists('db')) return ['totalRecords' => 0];
            $db = db()->raw();
            $res = $db->query("SELECT content, maturity FROM kag_entities WHERE type='修复模式' AND created_by='recovery-ledger'");
            $rows = []; $ok = 0; $fps = [];
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
                if ($row['maturity'] === '已验证') $ok++;
                $c = json_decode($row['content'] ?? '{}', true);
                if ($c && isset($c['fingerprint'])) $fps[$c['fingerprint']] = true;
            }
            return [
                'totalRecords'       => count($rows),
                'verifiedRecords'    => $ok,
                'uniqueFingerprints' => count($fps),
                'hitRate'            => count($rows) ? (int)($ok / count($rows) * 100) : 0,
            ];
        } catch (\Throwable) { return ['totalRecords' => 0]; }
    }
}
