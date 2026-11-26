<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs every minute: the 10:00 morning message. Nothing else is sent on schedule.
 */
class Hastan_Scheduler
{
    const HOOK = 'hastan_tick';

    public static function init(): void
    {
        add_filter('cron_schedules', function ($schedules) {
            $schedules['hastan_minute'] = ['interval' => 60, 'display' => 'Hastan: каждую минуту'];
            return $schedules;
        });
        add_action(self::HOOK, [self::class, 'tick']);
        add_action('init', function () {
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_event(time() + 60, 'hastan_minute', self::HOOK);
            }
        });
    }

    public static function activate(): void
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'hastan_minute', self::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function tick(): void
    {
        if (!Hastan_Settings::get('chat_id') || get_transient('hastan_tick_lock')) {
            return;
        }
        set_transient('hastan_tick_lock', 1, 5 * MINUTE_IN_SECONDS);
        try {
            $now = Hastan_Settings::now();
            self::morning($now);
        } finally {
            delete_transient('hastan_tick_lock');
        }
    }

    /** True once per day, in the three hours after $time. */
    private static function due_now(DateTimeImmutable $now, string $time, string $option): bool
    {
        [$h, $m] = array_map('intval', explode(':', $time . ':0'));
        $start = $now->setTime($h, $m);
        if ($now < $start || $now > $start->modify('+3 hours')) {
            return false;
        }
        if (get_option($option) === $now->format('Y-m-d')) {
            return false;
        }
        update_option($option, $now->format('Y-m-d'), false);
        return true;
    }

    /**
     * 10:00: tasks the user asked to be reminded of today, tasks due today
     * (not lotus site), and missed habits. Overdue tasks are not repeated.
     * Nothing is sent when there is nothing to say.
     */
    public static function morning(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('morning_time'), 'hastan_last_morning')) {
            return;
        }
        $out = [];
        Hastan_Food::close_day($now->modify('-1 day')->format('Y-m-d'));
        foreach (Hastan_DB::morning_tasks($now) as $task) {
            $project = $task['project'] !== '' ? $task['project'] . ': ' : '';
            $out[] = '— ' . $project . $task['title'];
        }
        Hastan_DB::drop_past_reminders($now);
        $lapses = Hastan_Habits::lapses($now);
        if ($lapses) {
            $out[] = '— Пропущено: ' . implode(', ', $lapses);
        }
        if ($out) {
            Hastan_Telegram::send("Доброе утро!\n\n" . implode("\n", $out));
        }
    }
}
