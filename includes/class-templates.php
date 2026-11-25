<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checklist templates: a typical job unfolds into its steps. None by default.
 */
class Hastan_Templates
{
    const OPTION = 'hastan_templates';

    /** Lotus checklists (event, post, Reels, ads) are no longer kept: they never come back, even from saved settings. */
    const RETIRED = ['event', 'post', 'video', 'ads'];

    public static function defaults(): array
    {
        return [];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION);
        $all = is_array($saved) && $saved ? $saved + self::defaults() : self::defaults();
        return array_diff_key($all, array_flip(self::RETIRED));
    }

    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function save_items(string $key, array $items): void
    {
        $all = self::all();
        if (!isset($all[$key])) {
            return;
        }
        $all[$key]['items'] = array_values(array_filter(array_map('trim', $items), 'strlen'));
        update_option(self::OPTION, $all, false);
    }

    /** Text for the assistant's instructions. */
    public static function describe(): string
    {
        $lines = [];
        foreach (self::all() as $key => $tpl) {
            $lines[] = "- {$key} ({$tpl['name']}): " . implode('; ', $tpl['items']);
        }
        return implode("\n", $lines);
    }
}
