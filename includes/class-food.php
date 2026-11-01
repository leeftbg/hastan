<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calorie counting: a personal food list (kcal per 100 g, an optional usual
 * portion) and a log of what was eaten each day. Unknown foods are added by
 * the assistant with an estimate and can be corrected in the admin.
 */
class Hastan_Food
{
    public static function foods_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_foods';
    }

    public static function log_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_food_log';
    }

    public static function install(string $charset): void
    {
        $foods = self::foods_table();
        $log = self::log_table();
        dbDelta("CREATE TABLE $foods (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  kcal decimal(6,1) NOT NULL DEFAULT 0,
  protein decimal(5,1) NULL,
  fat decimal(5,1) NULL,
  carbs decimal(5,1) NULL,
  portion_g smallint(5) unsigned NULL,
  estimated tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY name (name)
) $charset;");
        dbDelta("CREATE TABLE $log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  food_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(100) NOT NULL,
  grams smallint(5) unsigned NOT NULL,
  kcal decimal(7,1) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY day (day)
) $charset;");
    }

    /** "88.0" → "88", "3.5" → "3.5", null → "". */
    public static function num($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $s = (string) $value;
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    public static function limit(): int
    {
        return max(500, (int) Hastan_Settings::get('kcal_limit'));
    }

    /* ---------- foods ---------- */

    public static function foods(): array
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::foods_table() . ' ORDER BY name', ARRAY_A);
    }

    public static function find(string $name): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::foods_table() . ' WHERE LOWER(name) = %s',
            mb_strtolower(trim($name))
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::foods_table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    /** Adds a food or updates the one with this id; empty optional numbers are stored as NULL. */
    public static function save(array $data, int $id = 0): int
    {
        global $wpdb;
        $num = fn ($v) => ($v === null || $v === '') ? null : (float) str_replace(',', '.', (string) $v);
        $fields = [
            'name' => mb_substr(trim((string) $data['name']), 0, 100),
            'kcal' => max(0, (float) $num($data['kcal'] ?? 0)),
            'protein' => $num($data['protein'] ?? null),
            'fat' => $num($data['fat'] ?? null),
            'carbs' => $num($data['carbs'] ?? null),
            'portion_g' => ($p = $num($data['portion_g'] ?? null)) ? (int) $p : null,
            'estimated' => !empty($data['estimated']) ? 1 : 0,
        ];
        if ($id) {
            $wpdb->update(self::foods_table(), $fields, ['id' => $id]);
            return $id;
        }
        $wpdb->insert(self::foods_table(), $fields + ['created_at' => gmdate('Y-m-d H:i:s')]);
        return (int) $wpdb->insert_id;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::foods_table(), ['id' => $id]);
    }

    /* ---------- log ---------- */

    public static function add_entry(array $food, int $grams, string $day): float
    {
        global $wpdb;
        $kcal = round((float) $food['kcal'] * $grams / 100, 1);
        $wpdb->insert(self::log_table(), [
            'day' => $day,
            'food_id' => (int) $food['id'],
            'name' => $food['name'],
            'grams' => $grams,
            'kcal' => $kcal,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return $kcal;
    }

    public static function delete_entry(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::log_table(), ['id' => $id]);
    }

    public static function entries(string $day): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::log_table() . ' WHERE day = %s ORDER BY id',
            $day
        ), ARRAY_A);
    }

    public static function total(string $day): float
    {
        global $wpdb;
        return (float) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(kcal), 0) FROM ' . self::log_table() . ' WHERE day = %s', $day));
    }

    /** Totals per day: [Y-m-d => kcal] for days that have entries. */
    public static function totals(string $from, string $to): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT day, SUM(kcal) AS kcal FROM ' . self::log_table() . ' WHERE day BETWEEN %s AND %s GROUP BY day ORDER BY day',
            $from,
            $to
        ), ARRAY_A);
        return array_column($rows, 'kcal', 'day');
    }

    /**
     * After a day is over: if food was logged and the day stayed within the
     * limit, the "Питание" habit gets its square for that day.
     */
    public static function close_day(string $day): void
    {
        if (!self::entries($day) || self::total($day) > self::limit()) {
            return;
        }
        foreach (Hastan_Habits::all() as $habit) {
            if (str_contains(mb_strtolower($habit['name']), 'питание')) {
                Hastan_Habits::mark((int) $habit['id'], $day, true);
            }
        }
    }

    /* ---------- for the assistant ---------- */

    public static function describe(DateTimeImmutable $today): string
    {
        $day = $today->format('Y-m-d');
        $foods = self::foods();
        $names = array_map(
            fn ($f) => $f['name'] . ' ' . self::num($f['kcal']) . ($f['portion_g'] ? " (порция {$f['portion_g']} г)" : ''),
            $foods
        );
        $out = ['Продукты в базе (ккал на 100 г): ' . ($names ? implode('; ', $names) : 'пока нет')];
        $entries = self::entries($day);
        $out[] = 'Съедено сегодня (' . round(self::total($day)) . ' из ' . self::limit() . ' ккал): '
            . ($entries ? implode('; ', array_map(fn ($e) => "#{$e['id']} {$e['name']} {$e['grams']} г = " . round($e['kcal']), $entries)) : 'ничего');
        return implode("\n", $out);
    }

    /** Tool: log what was eaten. Unknown foods need an estimate and are saved as estimated. */
    public static function log_tool(array $input): string
    {
        $day = !empty($input['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'])
            ? $input['date']
            : Hastan_Settings::now()->format('Y-m-d');
        $added = 0.0;
        $errors = [];
        foreach ((array) ($input['items'] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $food = self::find($name);
            if (!$food) {
                if (empty($item['kcal_per_100g_if_new'])) {
                    $errors[] = "{$name}: нет в базе, нужна оценка калорийности";
                    continue;
                }
                $id = self::save([
                    'name' => $name,
                    'kcal' => $item['kcal_per_100g_if_new'],
                    'portion_g' => $item['portion_g_if_new'] ?? null,
                    'estimated' => true,
                ]);
                $food = self::get($id);
            }
            $grams = (int) ($item['grams'] ?? 0) ?: (int) $food['portion_g'];
            if ($grams <= 0) {
                $errors[] = "{$name}: не указан вес и нет обычной порции";
                continue;
            }
            $added += self::add_entry($food, $grams, $day);
        }
        $total = self::total($day);
        return '+' . round($added) . ', за ' . substr($day, 8, 2) . '.' . substr($day, 5, 2) . ' ' . round($total) . ' из ' . self::limit()
            . ($errors ? "\nНе записано: " . implode('; ', $errors) : '');
    }
}
