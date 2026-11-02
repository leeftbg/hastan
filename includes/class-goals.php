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
  kind varchar(20) NOT NULL DEFAULT '',
  subtitle varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'active',
  position int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;");
        dbDelta("CREATE TABLE $steps (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  goal_id bigint(20) unsigned NOT NULL,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  code varchar(10) NOT NULL DEFAULT '',
  title varchar(255) NOT NULL,
  details text NULL,
  position int(11) NOT NULL DEFAULT 0,
  state tinyint(1) NOT NULL DEFAULT 0,
  repeats tinyint(3) unsigned NOT NULL DEFAULT 0,
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
        global $wpdb;
        $wpdb->query("UPDATE $steps SET state = 2 WHERE done_on IS NOT NULL AND state = 0");
        if (!get_option('hastan_exam_seeded')) {
            self::seed_exam();
            update_option('hastan_exam_seeded', 1, false);
        }
        if (get_option('hastan_exam_seeded') && !get_option('hastan_exam_stages')) {
            // The exam got four stages: what was "done" is now the last one.
            $goals = self::goals_table();
            $wpdb->query("UPDATE $steps s JOIN $goals g ON g.id = s.goal_id SET s.state = 3 WHERE g.kind = 'exam' AND s.state = 2");
        }
        update_option('hastan_exam_stages', 1, false);
        if (!get_option('hastan_course_seeded')) {
            self::seed_course();
            update_option('hastan_course_seeded', 1, false);
        }
    }

    /** The psychology lectures: one step per video; details hold the duration and the link. */
    private static function seed_course(): void
    {
        $course = require __DIR__ . '/course-psychology.php';
        $id = self::save([
            'title' => $course['title'],
            'subtitle' => $course['subtitle'],
            'kind' => 'course',
            'due' => '',
            'status' => 'active',
            'position' => 1,
        ]);
        foreach ($course['lectures'] as $i => [$code, $title, $duration]) {
            self::add_step($id, $title, $i, 0, $code, $duration . "\n" . $course['playlist'] . ($i + 1));
        }
    }

    /** "2:25:09" → seconds. */
    public static function seconds(string $duration): int
    {
        $parts = array_map('intval', explode(':', trim($duration)));
        $s = 0;
        foreach ($parts as $p) {
            $s = $s * 60 + $p;
        }
        return $s;
    }

    /** The state exam: the "Экзамены" goal gets its syllabus, topic by topic. */
    private static function seed_exam(): void
    {
        $exam = require __DIR__ . '/exam-ppt.php';
        $id = 0;
        foreach (self::all() as $goal) {
            if (mb_strtolower($goal['title']) === 'экзамены') {
                $id = (int) $goal['id'];
            }
        }
        $id = self::save([
            'title' => $exam['title'],
            'subtitle' => $exam['subtitle'],
            'kind' => 'exam',
            'due' => 'до лятото',
            'status' => 'active',
            'position' => 0,
        ], $id);
        foreach ($exam['topics'] as $t => [$topic, $questions]) {
            $topic_id = self::add_step($id, $topic, $t, 0, (string) ($t + 1));
            foreach ($questions as $q => [$question, $books]) {
                self::add_step($id, $question, $q, $topic_id, ($t + 1) . '.' . ($q + 1), implode("\n", $books));
            }
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
            'kind' => (string) ($data['kind'] ?? ''),
            'subtitle' => mb_substr(trim((string) ($data['subtitle'] ?? '')), 0, 255),
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

    public static function add_step(int $goal_id, string $title, ?int $position = null, int $parent_id = 0, string $code = '', ?string $details = null): int
    {
        global $wpdb;
        $wpdb->insert(self::steps_table(), [
            'goal_id' => $goal_id,
            'parent_id' => $parent_id,
            'code' => $code,
            'title' => mb_substr(trim($title), 0, 255),
            'details' => $details,
            'position' => $position ?? count(self::steps($goal_id)),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Exam questions go through four stages (0 — not started, 1 — notes, 2 — cheat sheet, 3 — ready); other steps through three. */
    public static function max_state(int $goal_id): int
    {
        $goal = self::get($goal_id);
        return $goal && $goal['kind'] === 'exam' ? 3 : 2;
    }

    public static function set_state(int $step_id, int $state, ?string $day = null): void
    {
        global $wpdb;
        $step = self::get_step($step_id);
        if (!$step) {
            return;
        }
        $max = self::max_state((int) $step['goal_id']);
        $state = max(0, min($max, $state));
        $wpdb->update(self::steps_table(), [
            'state' => $state,
            'done_on' => $state === $max ? ($day ?? Hastan_Settings::now()->format('Y-m-d')) : null,
        ], ['id' => $step_id]);
    }

    /** Repeats are separate from the stage: reading a question again before sleep. */
    public static function set_repeats(int $step_id, int $repeats): void
    {
        global $wpdb;
        $wpdb->update(self::steps_table(), ['repeats' => max(0, min(9, $repeats))], ['id' => $step_id]);
    }

    public static function get_step(int $step_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::steps_table() . ' WHERE id = %d', $step_id), ARRAY_A);
        return $row ?: null;
    }

    /** Steps as a tree: top-level steps, each with 'children'. */
    public static function tree(int $goal_id): array
    {
        $all = self::steps($goal_id);
        $top = [];
        foreach ($all as $step) {
            if (!(int) $step['parent_id']) {
                $top[(int) $step['id']] = $step + ['children' => []];
            }
        }
        foreach ($all as $step) {
            if (isset($top[(int) $step['parent_id']])) {
                $top[(int) $step['parent_id']]['children'][] = $step;
            }
        }
        return array_values($top);
    }

    /** Steps that count for progress: leaves only. */
    public static function leaves(int $goal_id): array
    {
        $all = self::steps($goal_id);
        $parents = array_flip(array_map(fn ($s) => (int) $s['parent_id'], $all));
        return array_values(array_filter($all, fn ($s) => !isset($parents[(int) $s['id']])));
    }

    public static function delete_step(int $step_id): void
    {
        global $wpdb;
        $wpdb->delete(self::steps_table(), ['id' => $step_id]);
        $wpdb->delete(self::steps_table(), ['parent_id' => $step_id]);
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
        $out = ['Цели (без напоминаний; шаги: [ ] не начат, [~] в процессе, [x] готов; у экзамена стадии [1] конспект, [2] шпаргалка, [x] готов, ↻N — сколько раз повторён; заметки — дневник пути):'];
        foreach ($goals as $goal) {
            $out[] = "#{$goal['id']} {$goal['title']}" . ($goal['due'] !== '' ? " — {$goal['due']}" : '') . ' (' . self::STATUSES[$goal['status']] . ')';
            $marks = $goal['kind'] === 'exam' ? [' ', '1', '2', 'x'] : [' ', '~', 'x'];
            foreach (self::leaves((int) $goal['id']) as $step) {
                $title = $step['code'] !== '' ? $step['code'] . ' ' . mb_strimwidth($step['title'], 0, 50, '…') : $step['title'];
                $out[] = "   шаг #{$step['id']} [" . ($marks[(int) $step['state']] ?? 'x') . "] {$title}" . ((int) $step['repeats'] ? ' ↻' . (int) $step['repeats'] : '');
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
                'kind' => $goal['kind'],
                'subtitle' => $goal['subtitle'],
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
        $own = array_map('intval', array_column(self::steps($id), 'id'));
        $max = self::max_state($id);
        foreach (['started_step_ids' => 1, 'notes_step_ids' => 1, 'cheatsheet_step_ids' => 2, 'done_step_ids' => $max, 'reset_step_ids' => 0] as $key => $state) {
            foreach ((array) ($input[$key] ?? []) as $step_id) {
                if (in_array((int) $step_id, $own, true)) {
                    self::set_state((int) $step_id, $state, $day);
                }
            }
        }
        foreach ((array) ($input['repeated_step_ids'] ?? []) as $step_id) {
            $step = in_array((int) $step_id, $own, true) ? self::get_step((int) $step_id) : null;
            if ($step) {
                self::set_repeats((int) $step_id, (int) $step['repeats'] + 1);
            }
        }
        if (trim((string) ($input['note'] ?? '')) !== '') {
            self::add_note($id, $day, (string) $input['note']);
        }
        return 'Готово, цель #' . $id . '.';
    }
}
