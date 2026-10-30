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

    public static function morning(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('morning_time'), 'hastan_last_morning')) {
            return;
        }
        $tasks = Hastan_DB::open_tasks();
        if (!$tasks) {
            return;
        }
        $text = Hastan_Brain::compose(
            'Пора утреннего сообщения. Напиши план на сегодня: что горит (срок сегодня или уже прошёл), '
            . 'что нужно начать заранее, чтобы успеть к ближайшим срокам, и какие пункты чек-листов ещё не сделаны. '
            . 'Если задачи из разных проектов (в квадратных скобках), сгруппируй по проектам. '
            . 'Коротко, 5–10 строк, начни с «Доброе утро». Важные несоответствия — одной строкой с ❗. Задачи без срока упомяни одной строкой в конце, если они есть.'
        ) ?? self::plain_list('Доброе утро! Открытые задачи:', $tasks);
        Hastan_Telegram::send($text);
    }

    public static function evening(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('evening_time'), 'hastan_last_evening')) {
            return;
        }
        $end_of_day = $now->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $tasks = array_values(array_filter(
            Hastan_DB::open_tasks(),
            fn ($t) => $t['due_at'] !== null && $t['due_at'] <= $end_of_day
        ));
        if (!$tasks) {
            return;
        }
        $text = Hastan_Brain::compose(
            'Пора вечерней проверки. Задачи со сроком сегодня или раньше ещё открыты. Для каждой назови, что именно не сделано '
            . '(например, «пост вышел, а сторис к нему нет»), и спроси: сделать сейчас или перенести на завтра. '
            . 'Если задачи из разных проектов, сгруппируй по проектам. Коротко. Под сообщением будут кнопки «готово» и «завтра» для каждой задачи, упомяни это одной фразой.'
        ) ?? self::plain_list('Вечерняя проверка. Не закрыто:', $tasks);
        Hastan_Telegram::send($text, array_map([Hastan_Telegram::class, 'task_buttons'], array_slice($tasks, 0, 6)));
    }

    /** A list without the AI, for when Claude is unreachable. */
    private static function plain_list(string $heading, array $tasks): string
    {
        $out = [$heading];
        foreach ($tasks as $task) {
            $due = Hastan_DB::utc_to_local($task['due_at']);
            $out[] = '— ' . $task['title'] . ($due ? ' (до ' . $due->format('d.m') . ')' : '');
            foreach (Hastan_DB::items((int) $task['id']) as $item) {
                if ($item['status'] === 'open') {
                    $out[] = '   · ' . $item['title'];
                }
            }
        }
        return implode("\n", $out);
    }
}
