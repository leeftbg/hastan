<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storage: tasks (a checklist item is a task with a parent) and the chat log.
 * All datetimes in the database are UTC.
 */
class Hastan_DB
{
    const VERSION = '6';

    public static function tasks_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_tasks';
    }

    public static function log_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_log';
    }

    public static function ideas_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_ideas';
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $tasks = self::tasks_table();
        $log = self::log_table();

        dbDelta("CREATE TABLE $tasks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL,
  notes text NULL,
  template varchar(20) NOT NULL DEFAULT '',
  project varchar(60) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  position int(11) NOT NULL DEFAULT 0,
  optional tinyint(1) NOT NULL DEFAULT 0,
  due_at datetime NULL,
  reminded_at datetime NULL,
  done_at datetime NULL,
  source varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY parent_id (parent_id),
  KEY status (status)
) $charset;");

        $ideas = self::ideas_table();
        dbDelta("CREATE TABLE $ideas (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  topic varchar(60) NOT NULL DEFAULT '',
  body text NOT NULL,
  original text NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $charset;");

        Hastan_Habits::install($charset);

        dbDelta("CREATE TABLE $log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  role varchar(10) NOT NULL,
  content text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;");

        $old = (int) get_option('hastan_db_version');
        if ($old && $old < 4) {
            // Site work has no deadlines any more.
            foreach (self::open_tasks() as $task) {
                if (Hastan_Settings::is_site_project($task['project'])) {
                    $wpdb->update($tasks, ['due_at' => null], ['id' => (int) $task['id']]);
                }
            }
        }
        add_option('hastan_site_activity', time(), '', false);
        update_option('hastan_db_version', self::VERSION, false);
    }

    /* ---------- tasks ---------- */

    public static function create_task(array $data): int
    {
        global $wpdb;
        $wpdb->insert(self::tasks_table(), [
            'parent_id' => (int) ($data['parent_id'] ?? 0),
            'title' => mb_substr((string) $data['title'], 0, 255),
            'notes' => $data['notes'] ?? null,
            'template' => $data['template'] ?? '',
            'project' => mb_substr((string) ($data['project'] ?? ''), 0, 60),
            'status' => 'open',
            'position' => (int) ($data['position'] ?? 0),
            'optional' => !empty($data['optional']) ? 1 : 0,
            'due_at' => $data['due_at'] ?? null,
            'source' => $data['source'] ?? '',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    public static function get_task(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::tasks_table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function update_task(int $id, array $fields): void
    {
        global $wpdb;
        if (isset($fields['status'])) {
            $fields['done_at'] = $fields['status'] === 'done' ? gmdate('Y-m-d H:i:s') : null;
        }
        if (array_key_exists('due_at', $fields)) {
            $fields['reminded_at'] = null;
        }
        $wpdb->update(self::tasks_table(), $fields, ['id' => $id]);
    }

    public static function items(int $task_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::tasks_table() . ' WHERE parent_id = %d ORDER BY position, id',
            $task_id
        ), ARRAY_A);
    }

    /** Top-level tasks that are still open, soonest deadline first. */
    public static function open_tasks(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . self::tasks_table() . " WHERE parent_id = 0 AND status = 'open' ORDER BY due_at IS NULL, due_at, id",
            ARRAY_A
        );
    }

    /** Top-level tasks finished within the last $hours hours. */
    public static function recently_done(int $hours): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::tasks_table() . " WHERE parent_id = 0 AND status = 'done' AND done_at >= %s ORDER BY done_at",
            gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS)
        ), ARRAY_A);
    }

    /** Closes the task when every required checklist item is done. Optional items don't hold it open. */
    public static function maybe_close_parent(int $task_id): bool
    {
        $items = self::items($task_id);
        if (!$items) {
            return false;
        }
        foreach ($items as $item) {
            if ($item['status'] === 'open' && !(int) $item['optional']) {
                return false;
            }
        }
        $task = self::get_task($task_id);
        if ($task && $task['status'] === 'open') {
            self::close_task($task_id, 'done');
            return true;
        }
        return false;
    }

    /** Finishes or cancels a task: required items are done with it, optional ones go away. */
    public static function close_task(int $task_id, string $status): void
    {
        self::update_task($task_id, ['status' => $status]);
        foreach (self::items($task_id) as $item) {
            if ($item['status'] === 'open') {
                self::update_task((int) $item['id'], ['status' => $status === 'done' && !(int) $item['optional'] ? 'done' : 'cancelled']);
            }
        }
    }

    /* ---------- chat log ---------- */

    public static function log(string $role, string $content): void
    {
        global $wpdb;
        $wpdb->insert(self::log_table(), [
            'role' => $role,
            'content' => $content,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** The last $limit messages of the last two days, oldest first. */
    public static function recent_log(int $limit = 20): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT role, content FROM ' . self::log_table() . ' WHERE created_at >= %s ORDER BY id DESC LIMIT %d',
            gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS),
            $limit
        ), ARRAY_A);
        return array_reverse($rows);
    }

    /* ---------- ideas ---------- */

    public static function add_idea(array $data): int
    {
        global $wpdb;
        $wpdb->insert(self::ideas_table(), [
            'title' => mb_substr((string) $data['title'], 0, 200),
            'topic' => mb_substr((string) ($data['topic'] ?? ''), 0, 60),
            'body' => (string) $data['body'],
            'original' => $data['original'] ?? null,
            'status' => 'new',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Ideas with the given status, newest first. */
    public static function ideas(string $status): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::ideas_table() . ' WHERE status = %s ORDER BY created_at DESC, id DESC',
            $status
        ), ARRAY_A);
    }

    public static function set_idea_status(int $id, string $status): void
    {
        global $wpdb;
        $wpdb->update(self::ideas_table(), ['status' => $status], ['id' => $id]);
    }

    public static function delete_idea(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::ideas_table(), ['id' => $id]);
    }

    /* ---------- dates ---------- */

    /** "2026-10-31 18:00" in site time → UTC string for the database, or null. */
    public static function local_to_utc(?string $local): ?string
    {
        if ($local === null || trim($local) === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable(trim($local), wp_timezone());
        } catch (Exception $e) {
            return null;
        }
        $dt = $dt->setTime(12, 0); // Deadlines are whole days; the time is ignored.
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC string from the database → DateTimeImmutable in site time. */
    public static function utc_to_local(?string $utc): ?DateTimeImmutable
    {
        if (!$utc) {
            return null;
        }
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(wp_timezone());
    }
}
