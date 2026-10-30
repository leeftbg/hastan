<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings, stored in one option.
 */
class Hastan_Settings
{
    const OPTION = 'hastan_settings';

    public static function defaults(): array
    {
        return [
            'telegram_token' => '',
            'telegram_secret' => '',
            'link_code' => '',
            'mcp_key' => '',
            'chat_id' => '',
            'anthropic_key' => '',
            'model' => 'claude-opus-5-5',
            'effort' => 'low',
            'morning_time' => '10:00',
            'evening_time' => '20:00',
            'about' => 'Я веду SMM клуба: посты в Instagram и Facebook, новости на сайте, монтаж видео, реклама в Meta.',
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function get(string $key)
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function set(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    public static function ensure_secrets(): void
    {
        $s = self::all();
        $changes = [];
        if ($s['telegram_secret'] === '') {
            $changes['telegram_secret'] = wp_generate_password(32, false);
        }
        if ($s['mcp_key'] === '') {
            $changes['mcp_key'] = wp_generate_password(32, false);
        }
        if ($s['link_code'] === '') {
            $changes['link_code'] = strtolower(wp_generate_password(8, false));
        }
        if ($changes) {
            self::set($changes);
        }
    }

    /** Current local time in the site's timezone. */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', wp_timezone());
    }
}
