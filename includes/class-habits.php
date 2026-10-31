<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Personal habits kept in a rhythm: each one should happen at least every N
 * days. A day is marked only when the thing was really done; there are no
 * "minimum versions". Reminders come once, when a habit falls out of rhythm.
 */
class Hastan_Habits
{
    const NOTIFIED_OPTION = 'hastan_habit_notified';

    public static function habits_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_habits';
    }

    public static function marks_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_habit_marks';
    }

    public static function install(string $charset): void
    {
        global $wpdb;
        $habits = self::habits_table();
        $marks = self::marks_table();
        dbDelta("CREATE TABLE $habits (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(100) NOT NULL,
  every_days smallint(5) unsigned NOT NULL DEFAULT 1,
  position int(11) NOT NULL DEFAULT 0,
  active tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;");
        dbDelta("CREATE TABLE $marks (
  habit_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  PRIMARY KEY  (habit_id,day)
) $charset;");

        if (!(int) $wpdb->get_var("SELECT COUNT(*) FROM $habits")) {
            $defaults = [
                ['Питание 2000 ккал', 1],
                ['Силовые', 2],
                ['Шея', 2],
                ['Вестибулярка', 1],
                ['Дом', 3],
                ['Машина', 3],
                ['Письма / сны / воспоминания', 3],
            ];
            foreach ($defaults as $pos => [$name, $every]) {
                self::save(['name' => $name, 'every_days' => $every, 'position' => $pos]);
            }
        }
    }

    /* ---------- data ---------- */

    public static function all(bool $active_only = true): array
    {
        global $wpdb;
        $where = $active_only ? 'WHERE active = 1' : '';
        return $wpdb->get_results('SELECT * FROM ' . self::habits_table() . " $where ORDER BY position, id", ARRAY_A);
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::habits_table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function save(array $data, int $id = 0): int
    {
        global $wpdb;
        $fields = [
            'name' => mb_substr(trim((string) $data['name']), 0, 100),
            'every_days' => max(1, min(60, (int) ($data['every_days'] ?? 1))),
            'position' => (int) ($data['position'] ?? 0),
            'active' => isset($data['active']) ? (int) (bool) $data['active'] : 1,
        ];
        if ($id) {
            $wpdb->update(self::habits_table(), $fields, ['id' => $id]);
            return $id;
        }
        $wpdb->insert(self::habits_table(), $fields + ['created_at' => gmdate('Y-m-d H:i:s')]);
        return (int) $wpdb->insert_id;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::habits_table(), ['id' => $id]);
        $wpdb->delete(self::marks_table(), ['habit_id' => $id]);
    }

    public static function is_done(int $habit_id, string $day): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT 1 FROM ' . self::marks_table() . ' WHERE habit_id = %d AND day = %s',
            $habit_id,
            $day
        ));
    }

    public static function mark(int $habit_id, string $day, bool $done): void
    {
        global $wpdb;
        if ($done) {
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::marks_table() . ' (habit_id, day) VALUES (%d, %s)', $habit_id, $day));
        } else {
            $wpdb->delete(self::marks_table(), ['habit_id' => $habit_id, 'day' => $day]);
        }
    }

    /** Marks between two days: [habit_id => [Y-m-d => true]]. */
    public static function marks(string $from, string $to): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT habit_id, day FROM ' . self::marks_table() . ' WHERE day BETWEEN %s AND %s',
            $from,
            $to
        ), ARRAY_A);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['habit_id']][$row['day']] = true;
        }
        return $out;
    }

    public static function last_done(int $habit_id): ?string
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SELECT MAX(day) FROM ' . self::marks_table() . ' WHERE habit_id = %d', $habit_id)) ?: null;
    }

    /** Whole days since the habit was last done, or since it was added. */
    public static function days_since(array $habit, DateTimeImmutable $today): int
    {
        $last = self::last_done((int) $habit['id']);
        $since = $last
            ? new DateTimeImmutable($last, $today->getTimezone())
            : Hastan_DB::utc_to_local($habit['created_at']);
        return (int) $since->setTime(0, 0)->diff($today->setTime(0, 0))->days;
    }

    /* ---------- messages ---------- */

    /**
     * Habits that just fell out of rhythm, as "Шея (3 дн.)". Each lapse is
     * reported once; the habit is quiet again until it is done and lapses anew.
     */
    public static function lapses(DateTimeImmutable $today): array
    {
        $notified = (array) get_option(self::NOTIFIED_OPTION, []);
        $out = [];
        foreach (self::all() as $habit) {
            $id = (int) $habit['id'];
            $days = self::days_since($habit, $today);
            $last = self::last_done($id) ?? 'never';
            if ($days > (int) $habit['every_days'] && ($notified[$id] ?? null) !== $last) {
                $notified[$id] = $last;
                $out[] = "{$habit['name']} ({$days} дн.)";
            }
        }
        update_option(self::NOTIFIED_OPTION, $notified, false);
        return $out;
    }

    /** Buttons for one day: a tap colours the square or clears it. */
    public static function buttons(string $day): array
    {
        $rows = [];
        $row = [];
        foreach (self::all() as $habit) {
            $mark = self::is_done((int) $habit['id'], $day) ? '🟥' : '⬜';
            $row[] = [$mark . ' ' . $habit['name'], 'habit:' . $habit['id'] . ':' . $day];
            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }
        if ($row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** For the assistant: habits with ids, rhythm and the last 7 days. */
    public static function describe(DateTimeImmutable $today): string
    {
        $habits = self::all();
        if (!$habits) {
            return 'Привычек нет.';
        }
        $from = $today->modify('-6 days')->format('Y-m-d');
        $marks = self::marks($from, $today->format('Y-m-d'));
        $out = ['Привычки (id, ритм, отметки за 7 дней):'];
        foreach ($habits as $habit) {
            $days = array_keys($marks[(int) $habit['id']] ?? []);
            sort($days);
            $out[] = "#{$habit['id']} {$habit['name']} — не реже раза в {$habit['every_days']} дн.; отмечено: "
                . ($days ? implode(', ', array_map(fn ($d) => substr($d, 8, 2) . '.' . substr($d, 5, 2), $days)) : 'нет');
        }
        return implode("\n", $out);
    }
}
