<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Steps from the phone: an iOS Shortcuts automation posts the day's step count
 * from Apple Health to a secret URL. Reaching the goal colours the steps habit.
 */
class Hastan_Steps
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_steps';
    }

    public static function install(string $charset): void
    {
        $table = self::table();
        dbDelta("CREATE TABLE $table (
  day date NOT NULL,
  steps int(10) unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (day)
) $charset;");
    }

    public static function init(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('hastan/v1', '/steps/(?P<key>[A-Za-z0-9]+)', [
                'methods' => ['POST', 'GET'],
                'callback' => [self::class, 'receive'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function url(): string
    {
        return rest_url('hastan/v1/steps/' . Hastan_Settings::get('steps_key'));
    }

    public static function goal(): int
    {
        return max(100, (int) Hastan_Settings::get('steps_goal'));
    }

    public static function get(string $day): ?int
    {
        global $wpdb;
        $steps = $wpdb->get_var($wpdb->prepare('SELECT steps FROM ' . self::table() . ' WHERE day = %s', $day));
        return $steps === null ? null : (int) $steps;
    }

    /** Shortcuts may send "8532", 8532.0 or "8 532,0"; any of them is fine. */
    private static function parse_number($value): int
    {
        $s = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], (string) $value);
        return max(0, (int) round((float) $s));
    }

    public static function receive(WP_REST_Request $request): WP_REST_Response
    {
        $key = (string) Hastan_Settings::get('steps_key');
        if ($key === '' || !hash_equals($key, (string) $request['key'])) {
            return new WP_REST_Response(['error' => 'not found'], 404);
        }
        $steps = $request->get_param('steps');
        if ($steps === null || $steps === '') {
            return new WP_REST_Response(['error' => 'no steps'], 400);
        }
        $day = (string) $request->get_param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = Hastan_Settings::now()->format('Y-m-d');
        }
        $count = self::parse_number($steps);
        self::save($day, $count);
        return new WP_REST_Response(['ok' => true, 'date' => $day, 'steps' => self::get($day), 'goal' => self::goal()], 200);
    }

    /** Keeps the day's highest count; reaching the goal marks the steps habit. */
    public static function save(string $day, int $steps): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (day, steps, updated_at) VALUES (%s, %d, %s) ON DUPLICATE KEY UPDATE steps = GREATEST(steps, VALUES(steps)), updated_at = VALUES(updated_at)',
            $day,
            $steps,
            gmdate('Y-m-d H:i:s')
        ));
        if ((int) self::get($day) < self::goal()) {
            return;
        }
        foreach (Hastan_Habits::all() as $habit) {
            if (str_contains(mb_strtolower($habit['name']), 'шаг')) {
                Hastan_Habits::mark((int) $habit['id'], $day, true);
            }
        }
    }
}
