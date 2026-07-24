<?php
/**
 * ShadowMode — 新功能影子验证: ≥3 周期输出但不决策
 *
 * 阶段: shadow_1 → shadow_2 → shadow_3 → graduated → active
 * 毕业条件: 3+ 周期无回归 + 输出与正式一致 + 性能无退化
 *
 * 用法:
 *   $sm = new ShadowMode($db);
 *   $sm->register('bot-detector-v2');
 *   // ... run 3 cycles ...
 *   $sm->recordCycle('bot-detector-v2', $shadowResult, $productionResult);
 *   if ($sm->canGraduate('bot-detector-v2')) {
 *       $sm->graduate('bot-detector-v2');
 *   }
 */
declare(strict_types=1);

namespace Converge\Foundation\Resilience;

use mysqli;

class ShadowMode
{
    private mysqli $db;
    private string $table = 'shadow_features';

    /** 影子阶段 */
    public const PHASE_SHADOW_1  = 'shadow_1';
    public const PHASE_SHADOW_2  = 'shadow_2';
    public const PHASE_SHADOW_3  = 'shadow_3';
    public const PHASE_GRADUATED = 'graduated';
    public const PHASE_ACTIVE    = 'active';
    public const PHASE_RETIRED   = 'retired';

    /** 毕业所需最少周期 */
    private const MIN_CYCLES = 3;

    /** 最大偏差容忍度 (0-1, shadow 输出 vs production 输出) */
    private const MAX_DIVERGENCE = 0.05;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    /**
     * 注册新影子功能
     * @param string $name       功能名
     * @param array  $meta       元数据 {description, owner, expected_impact}
     * @param string $startPhase 初始阶段
     */
    public function register(string $name, array $meta = [], string $startPhase = self::PHASE_SHADOW_1): void
    {
        $json = json_encode($meta, JSON_UNESCAPED_UNICODE);
        $now  = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            "INSERT INTO {$this->table} (name, phase, cycles_completed, meta, created_at, updated_at)
             VALUES (?, ?, 0, ?, ?, ?)
             ON DUPLICATE KEY UPDATE phase = VALUES(phase), meta = VALUES(meta), updated_at = VALUES(updated_at)"
        );
        $stmt->bind_param('sssss', $name, $startPhase, $json, $now, $now);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * 记录一个影子周期
     *
     * @param string $name            功能名
     * @param mixed  $shadowOutput    影子模式输出
     * @param mixed  $productionOutput 正式输出
     * @return bool 该周期是否通过
     */
    public function recordCycle(string $name, $shadowOutput, $productionOutput): bool
    {
        $feature = $this->get($name);
        if (!$feature) {
            throw new \RuntimeException("ShadowMode: 功能 '{$name}' 未注册");
        }

        $passed = $this->compareOutputs($shadowOutput, $productionOutput);
        $cycles = (int)$feature['cycles_completed'] + 1;
        $newPhase = $this->advancePhase($feature['phase'], $cycles, $passed);

        $now = date('Y-m-d H:i:s');
        $log = json_encode([
            'cycle'     => $cycles,
            'passed'    => $passed,
            'shadow'    => $shadowOutput,
            'production'=> $productionOutput,
            'timestamp' => $now,
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $this->db->prepare(
            "UPDATE {$this->table}
             SET phase = ?, cycles_completed = ?, last_cycle_log = ?,
                 last_cycle_at = ?, updated_at = ?
             WHERE name = ?"
        );
        $stmt->bind_param('sissss', $newPhase, $cycles, $log, $now, $now, $name);
        $stmt->execute();
        $stmt->close();

        return $passed;
    }

    /** 功能是否可以毕业 */
    public function canGraduate(string $name): bool
    {
        $feature = $this->get($name);
        if (!$feature) return false;

        return $feature['phase'] === self::PHASE_GRADUATED;
    }

    /** 毕业 → 正式激活 */
    public function graduate(string $name): void
    {
        $feature = $this->get($name);
        if (!$feature || !$this->canGraduate($name)) {
            throw new \RuntimeException("ShadowMode: '{$name}' 不满足毕业条件");
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "UPDATE {$this->table} SET phase = ?, updated_at = ? WHERE name = ?"
        );
        $phase = self::PHASE_ACTIVE;
        $stmt->bind_param('sss', $phase, $now, $name);
        $stmt->execute();
        $stmt->close();
    }

    /** 获取功能状态 */
    public function get(string $name): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM {$this->table} WHERE name = ?");
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $row['meta'] = json_decode($row['meta'], true) ?: [];
        }
        return $row ?: null;
    }

    /** 列出所有影子功能 */
    public function listAll(): array
    {
        $result = $this->db->query("SELECT * FROM {$this->table} ORDER BY updated_at DESC");
        $features = [];
        while ($row = $result->fetch_assoc()) {
            $row['meta'] = json_decode($row['meta'], true) ?: [];
            $features[] = $row;
        }
        return $features;
    }

    /** 统计 */
    public function stats(): array
    {
        $all = $this->listAll();
        $byPhase = [];
        foreach ($all as $f) {
            $p = $f['phase'];
            $byPhase[$p] = ($byPhase[$p] ?? 0) + 1;
        }
        return [
            'total'     => count($all),
            'active'    => $byPhase[self::PHASE_ACTIVE] ?? 0,
            'in_shadow' => ($byPhase[self::PHASE_SHADOW_1] ?? 0)
                         + ($byPhase[self::PHASE_SHADOW_2] ?? 0)
                         + ($byPhase[self::PHASE_SHADOW_3] ?? 0),
            'graduated' => $byPhase[self::PHASE_GRADUATED] ?? 0,
        ];
    }

    /** 比较影子输出与正式输出是否一致 */
    private function compareOutputs($shadow, $production): bool
    {
        // 简单类型: 严格比较
        if (is_scalar($shadow) && is_scalar($production)) {
            return $shadow === $production;
        }

        // 数组: 比较 keys 和 values
        if (is_array($shadow) && is_array($production)) {
            $shadowKeys = array_keys($shadow);
            $prodKeys   = array_keys($production);
            if (count(array_diff($shadowKeys, $prodKeys)) > 0) return false;
            if (count(array_diff($prodKeys, $shadowKeys)) > 0) return false;

            foreach ($shadowKeys as $k) {
                if (!$this->compareOutputs($shadow[$k], $production[$k])) return false;
            }
            return true;
        }

        // null vs null
        if ($shadow === null && $production === null) return true;

        return false;
    }

    /** 根据周期和通过状态推进阶段 */
    private function advancePhase(string $currentPhase, int $cycles, bool $passed): string
    {
        if (!$passed) {
            // 回归 → 回退到 shadow_1
            return self::PHASE_SHADOW_1;
        }

        return match ($currentPhase) {
            self::PHASE_SHADOW_1 => ($cycles >= 1) ? self::PHASE_SHADOW_2 : self::PHASE_SHADOW_1,
            self::PHASE_SHADOW_2 => ($cycles >= 2) ? self::PHASE_SHADOW_3 : self::PHASE_SHADOW_2,
            self::PHASE_SHADOW_3 => ($cycles >= self::MIN_CYCLES) ? self::PHASE_GRADUATED : self::PHASE_SHADOW_3,
            self::PHASE_GRADUATED, self::PHASE_ACTIVE => $currentPhase,
            default => self::PHASE_SHADOW_1,
        };
    }

    private function ensureTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS {$this->table} (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(128) NOT NULL UNIQUE,
                phase VARCHAR(32) NOT NULL DEFAULT 'shadow_1',
                cycles_completed INT DEFAULT 0,
                meta JSON,
                last_cycle_log JSON,
                last_cycle_at DATETIME,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_phase (phase)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
