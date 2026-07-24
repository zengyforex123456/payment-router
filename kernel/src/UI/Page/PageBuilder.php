<?php
declare(strict_types=1);

namespace Converge\UI\Page;

/**
 * PageBuilder — one-line page builder integration
 *
 *   PageBuilder::applyTo('landing', ['hero' => &$hero, 'pricing' => &$pricing]);
 *
 * Builder saves to data/pages/{name}.json.
 * applyTo() reads it and merges props by reference.
 * No JSON → returns false, page uses default data (zero impact).
 * Delete JSON → auto-restore defaults (zero-trace rollback).
 */
class PageBuilder
{
    private static ?string $pagesDir = null;

    private static function dir(): string
    {
        return self::$pagesDir ?? (dirname(__DIR__, 3) . '/data/pages');
    }

    /** Override default pages dir (testing) */
    public static function setPagesDir(string $dir): void
    {
        self::$pagesDir = $dir;
    }

    // === Core API ===

    /**
     * Apply Builder config to page section variables (by reference).
     *
     * @param string $pageName    Page identifier (e.g. 'landing', 'about')
     * @param array  $sectionRefs ['sectionType' => &$dataArray, ...]
     *                            Use & references so modifications affect originals
     * @return bool true = Builder config applied, false = no JSON (use defaults)
     */
    public static function applyTo(string $pageName, array $sectionRefs): bool
    {
        $file = self::dir() . '/' . $pageName . '.json';
        if (!file_exists($file)) return false;

        $config = json_decode(file_get_contents($file), true);
        if (!$config) return false;

        $blocks = $config['blocks'] ?? [];
        if (empty($blocks)) return false;

        // Extract per-section props: ['hero'=>['title'=>'...'], 'pricing'=>['title'=>'...']]
        $blockProps = [];
        foreach ($blocks as $b) {
            $type = $b['type'] ?? '';
            if ($type) $blockProps[$type] = $b['props'] ?? [];
        }

        // Merge props into section data (by reference)
        foreach ($sectionRefs as $sectionName => &$sectionData) {
            if (isset($blockProps[$sectionName])) {
                foreach ($blockProps[$sectionName] as $key => $value) {
                    if (!empty($value) || $value === '0') {
                        $sectionData[$key] = $value;
                    }
                }
            }
        }

        return true;
    }

    /** Check if page is managed by Builder */
    public static function isManaged(string $pageName): bool
    {
        return file_exists(self::dir() . '/' . $pageName . '.json');
    }

    /** Delete Builder config → restore default template */
    public static function reset(string $pageName): bool
    {
        $file = self::dir() . '/' . $pageName . '.json';
        if (file_exists($file)) return unlink($file);
        return false;
    }

    /** List all Builder-managed pages */
    public static function listManaged(): array
    {
        $pages = [];
        foreach (glob(self::dir() . '/*.json') as $f) {
            $name = basename($f, '.json');
            if (str_starts_with($name, 'preview')) continue;
            $data = json_decode(file_get_contents($f), true);
            $pages[] = [
                'name'  => $name,
                'title' => $data['title'] ?? $name,
                'blocks'=> count($data['blocks'] ?? []),
            ];
        }
        return $pages;
    }
}
