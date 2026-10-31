<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Runs every minute: the morning plan and the evening check. Nothing else is sent on schedule.
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
            self::evening($now);
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
     * 10:00: tasks due today and tomorrow. Overdue tasks are not repeated:
     * they were already in yesterday's messages.
     */
    public static function morning(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('morning_time'), 'hastan_last_morning')) {
            return;
        }
        $today = self::due_on($now);
        $tomorrow = self::due_on($now->modify('+1 day'));
        if (!$today && !$tomorrow) {
            return;
        }
        $out = ['Доброе утро!'];
        if ($today) {
            $out[] = "\nСегодня:\n" . self::titles($today);
        }
        if ($tomorrow) {
            $out[] = "\nЗавтра:\n" . self::titles($tomorrow);
        }
        Hastan_Telegram::send(implode("\n", $out));
    }

    /** 20:00: what is due today and still open, with buttons. */
    public static function evening(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('evening_time'), 'hastan_last_evening')) {
            return;
        }
        $tasks = self::due_on($now);
        if (!$tasks) {
            return;
        }
        Hastan_Telegram::send(
            "Сегодня не закрыто:\n" . self::titles($tasks),
            array_map([Hastan_Telegram::class, 'task_buttons'], array_slice($tasks, 0, 8))
        );
    }

    /** Open tasks whose deadline is on the given local day. */
    private static function due_on(DateTimeImmutable $day): array
    {
        $utc = new DateTimeZone('UTC');
        $from = $day->setTime(0, 0)->setTimezone($utc)->format('Y-m-d H:i:s');
        $to = $day->setTime(23, 59, 59)->setTimezone($utc)->format('Y-m-d H:i:s');
        return array_values(array_filter(
            Hastan_DB::open_tasks(),
            fn ($t) => $t['due_at'] !== null && $t['due_at'] >= $from && $t['due_at'] <= $to
        ));
    }

    /** Task titles only, grouped by project. */
    private static function titles(array $tasks): string
    {
        $groups = [];
        foreach ($tasks as $task) {
            $groups[$task['project'] !== '' ? $task['project'] : ''][] = '— ' . $task['title'];
        }
        $out = [];
        foreach ($groups as $project => $lines) {
            if (count($groups) > 1 && $project !== '') {
                $out[] = mb_strtoupper(mb_substr($project, 0, 1)) . mb_substr($project, 1) . ':';
            }
            array_push($out, ...$lines);
        }
        return implode("\n", $out);
    }
}
