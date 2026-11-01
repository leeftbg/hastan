<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Goals: things with a path, not a rhythm (exams by summer, overcoming the fear
 * of driving). A goal has steps that get ticked once and a diary of short
 * notes. There are no reminders about goals.
 */
class Hastan_Goals
{
    const STATUSES = ['active' => 'в работе', 'later' => 'на будущее', 'done' => 'достигнута'];

    public static function goals_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_goals';
    }

    public static function steps_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_goal_steps';
    }

    public static function notes_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_goal_notes';
    }

    public static function install(string $charset): void
    {
        $goals = self::goals_table();
        $steps = self::steps_table();
        $notes = self::notes_table();
        dbDelta("CREATE TABLE $goals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(150) NOT NULL,
  due varchar(60) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  position int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;");
        dbDelta("CREATE TABLE $steps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  goal_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL,
  position int(11) NOT NULL DEFAULT 0,
  done_on date NULL,
  PRIMARY KEY  (id),
  KEY goal_id (goal_id)
) $charset;");
        dbDelta("CREATE TABLE $notes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  goal_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  body text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY goal_id (goal_id)
) $charset;");

        if (!get_option('hastan_goals_seeded')) {
            self::seed();
            update_option('hastan_goals_seeded', 1, false);
        }
    }

    /** The first goals, and the car moved over from habits with its days as trips. */
    private static function seed(): void
    {
        self::save(['title' => 'Экзамены', 'due' => 'к лету', 'status' => 'active', 'position' => 0]);

        $car = self::save(['title' => 'Машина: водить без страха', 'due' => '', 'status' => 'active', 'position' => 1]);
        foreach (['Двор', 'Знакомый маршрут', 'Центр', 'Трасса'] as $pos => $step) {
            self::add_step($car, $step, $pos);
        }
        foreach (Hastan_Habits::all(false) as $habit) {
            if (mb_strtolower(trim($habit['name'])) !== 'машина') {
                continue;
            }
            $id = (int) $habit['id'];
            $days = array_keys(Hastan_Habits::marks('2000-01-01', '2100-01-01')[$id] ?? []);
            sort($days);
            foreach ($days as $day) {
                self::add_note($car, $day, 'Поездка');
            }
            Hastan_Habits::save(['name' => $habit['name'], 'every_days' => $habit['every_days'], 'position' => $habit['position'], 'active' => 0], $id);
            // The pier part of the picture waits for a habit that is not here yet.
            $state = Hastan_Picture::state();
            if (!empty($state['map'])) {
                foreach ($state['map'] as $i => $ids) {
                    $state['map'][$i] = array_values(array_filter((array) $ids, fn ($h) => (int) $h !== $id));
                }
                Hastan_Picture::set_map($state['map']);
            }
        }

        self::save(['title' => 'Английский и турецкий', 'due' => '', 'status' => 'later', 'position' => 2]);
    }

    /* ---------- data ---------- */

    public static function all(): array
    {
        global $wpdb;
        return $wpdb->get_results('SELECT * FROM ' . self::goals_table() . " ORDER BY FIELD(status, 'active', 'later', 'done'), position, id", ARRAY_A);
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::goals_table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function save(array $data, int $id = 0): int
    {
        global $wpdb;
        $fields = [
            'title' => mb_substr(trim((string) $data['title']), 0, 150),
            'due' => mb_substr(trim((string) ($data['due'] ?? '')), 0, 60),
            'status' => isset(self::STATUSES[$data['status'] ?? '']) ? $data['status'] : 'active',
            'position' => (int) ($data['position'] ?? 0),
        ];
        if ($id) {
            $wpdb->update(self::goals_table(), $fields, ['id' => $id]);
            return $id;
        }
        $wpdb->insert(self::goals_table(), $fields + ['created_at' => gmdate('Y-m-d H:i:s')]);
        return (int) $wpdb->insert_id;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::goals_table(), ['id' => $id]);
        $wpdb->delete(self::steps_table(), ['goal_id' => $id]);
        $wpdb->delete(self::notes_table(), ['goal_id' => $id]);
    }

    public static function steps(int $goal_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::steps_table() . ' WHERE goal_id = %d ORDER BY position, id', $goal_id), ARRAY_A);
    }

    public static function add_step(int $goal_id, string $title, ?int $position = null): int
    {
        global $wpdb;
        $wpdb->insert(self::steps_table(), [
            'goal_id' => $goal_id,
            'title' => mb_substr(trim($title), 0, 200),
            'position' => $position ?? count(self::steps($goal_id)),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function set_step(int $step_id, ?string $done_on): void
    {
        global $wpdb;
        $wpdb->update(self::steps_table(), ['done_on' => $done_on], ['id' => $step_id]);
    }

    public static function delete_step(int $step_id): void
    {
        global $wpdb;
        $wpdb->delete(self::steps_table(), ['id' => $step_id]);
    }

    public static function notes(int $goal_id, int $limit = 0): array
    {
        global $wpdb;
        $sql = $wpdb->prepare('SELECT * FROM ' . self::notes_table() . ' WHERE goal_id = %d ORDER BY day DESC, id DESC', $goal_id);
        return $wpdb->get_results($sql . ($limit ? ' LIMIT ' . (int) $limit : ''), ARRAY_A);
    }

    public static function add_note(int $goal_id, string $day, string $body): void
    {
        global $wpdb;
        $wpdb->insert(self::notes_table(), [
            'goal_id' => $goal_id,
            'day' => $day,
            'body' => trim($body),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public static function delete_note(int $note_id): void
    {
        global $wpdb;
        $wpdb->delete(self::notes_table(), ['id' => $note_id]);
    }

    /* ---------- for the assistant ---------- */

    public static function describe(): string
    {
        $goals = self::all();
        if (!$goals) {
            return 'Целей нет.';
        }
        $out = ['Цели (без напоминаний; шаги отмечаются один раз, заметки — дневник пути):'];
        foreach ($goals as $goal) {
            $out[] = "#{$goal['id']} {$goal['title']}" . ($goal['due'] !== '' ? " — {$goal['due']}" : '') . ' (' . self::STATUSES[$goal['status']] . ')';
            foreach (self::steps((int) $goal['id']) as $step) {
                $out[] = "   шаг #{$step['id']} [" . ($step['done_on'] ? 'x' : ' ') . "] {$step['title']}";
            }
            $notes = self::notes((int) $goal['id'], 3);
            if ($notes) {
                $out[] = '   последние заметки: ' . implode('; ', array_map(fn ($n) => self::short_date($n['day']) . ' ' . mb_strimwidth($n['body'], 0, 60, '…'), $notes));
            }
        }
        return implode("\n", $out);
    }

    public static function short_date(string $day): string
    {
        return substr($day, 8, 2) . '.' . substr($day, 5, 2);
    }

    /** Tool: create or change a goal, add steps, tick steps, write a note. */
    public static function tool(array $input): string
    {
        $id = (int) ($input['goal_id'] ?? 0);
        $goal = $id ? self::get($id) : null;
        if ($id && !$goal) {
            throw new InvalidArgumentException('цель не найдена');
        }
        $title = trim((string) ($input['title'] ?? ''));
        $due = $input['due'] ?? '';
        $status = (string) ($input['status'] ?? '');
        if (!$goal) {
            if ($title === '') {
                throw new InvalidArgumentException('для новой цели нужно название');
            }
            $id = self::save(['title' => $title, 'due' => $due, 'status' => $status ?: 'active', 'position' => count(self::all())]);
        } elseif ($title !== '' || $due !== '' || $status !== '') {
            self::save([
                'title' => $title !== '' ? $title : $goal['title'],
                'due' => $due !== '' ? ($due === '-' ? '' : $due) : $goal['due'],
                'status' => $status !== '' ? $status : $goal['status'],
                'position' => $goal['position'],
            ], $id);
        }
        foreach ((array) ($input['add_steps'] ?? []) as $step) {
            if (trim((string) $step) !== '') {
                self::add_step($id, (string) $step);
            }
        }
        $day = !empty($input['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'])
            ? $input['date']
            : Hastan_Settings::now()->format('Y-m-d');
        $own = array_column(self::steps($id), 'id');
        foreach ((array) ($input['done_step_ids'] ?? []) as $step_id) {
            if (in_array((string) $step_id, array_map('strval', $own), true)) {
                self::set_step((int) $step_id, $day);
            }
        }
        if (trim((string) ($input['note'] ?? '')) !== '') {
            self::add_note($id, $day, (string) $input['note']);
        }
        return 'Готово, цель #' . $id . '.';
    }
}
