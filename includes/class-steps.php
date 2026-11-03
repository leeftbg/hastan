<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Movement from the phone: an iOS Shortcuts automation posts the day's step
 * count or active energy (the Move ring) from Apple Health to a secret URL.
 * Reaching the goal colours the steps habit. When active energy is sent, its
 * goal decides, so the square matches the closed ring on the phone.
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
  move_kcal int(10) unsigned NULL,
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

    public static function move_goal(): int
    {
        return max(10, (int) Hastan_Settings::get('move_goal'));
    }

    public static function get_move(string $day): ?int
    {
        global $wpdb;
        $kcal = $wpdb->get_var($wpdb->prepare('SELECT move_kcal FROM ' . self::table() . ' WHERE day = %s', $day));
        return $kcal === null ? null : (int) $kcal;
    }

    public static function get(string $day): ?int
    {
        global $wpdb;
        $steps = $wpdb->get_var($wpdb->prepare('SELECT steps FROM ' . self::table() . ' WHERE day = %s', $day));
        return $steps === null ? null : (int) $steps;
    }

    /** The last days that came from the phone, newest first. */
    public static function recent(int $days = 7): array
    {
        global $wpdb;
        $from = Hastan_Settings::now()->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE day >= %s ORDER BY day DESC', $from), ARRAY_A);
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
        $move = $request->get_param('move');
        $has = fn ($v) => $v !== null && $v !== '';
        if (!$has($steps) && !$has($move)) {
            return new WP_REST_Response(['error' => 'no steps'], 400);
        }
        $day = (string) $request->get_param('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            $day = Hastan_Settings::now()->format('Y-m-d');
        }
        if ($has($move)) {
            self::save_move($day, self::parse_number($move));
            return new WP_REST_Response(['ok' => true, 'date' => $day, 'move' => self::get_move($day), 'goal' => self::move_goal()], 200);
        }
        self::save($day, self::parse_number($steps));
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
        if (self::get_move($day) === null && (int) self::get($day) >= self::goal()) {
            self::mark($day);
        }
    }

    /** Active energy, kcal: the Move ring. */
    public static function save_move(string $day, int $kcal): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (day, steps, move_kcal, updated_at) VALUES (%s, 0, %d, %s) ON DUPLICATE KEY UPDATE move_kcal = GREATEST(COALESCE(move_kcal, 0), VALUES(move_kcal)), updated_at = VALUES(updated_at)',
            $day,
            $kcal,
            gmdate('Y-m-d H:i:s')
        ));
        if ((int) self::get_move($day) >= self::move_goal()) {
            self::mark($day);
        }
    }

    private static function mark(string $day): void
    {
        foreach (Hastan_Habits::all() as $habit) {
            if (str_contains(mb_strtolower($habit['name']), 'шаг')) {
                Hastan_Habits::mark((int) $habit['id'], $day, true);
            }
        }
    }
}
