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
     * 10:00: unfinished checklist items of upcoming events, a nudge when the
     * site has been idle too long, and gross inconsistencies in one line each.
     */
    public static function morning(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('morning_time'), 'hastan_last_morning')) {
            return;
        }
        $out = self::event_lines(self::events($now));
        $idle = time() - (int) get_option('hastan_site_activity', time());
        if ($idle >= (int) Hastan_Settings::get('site_idle_hours') * HOUR_IN_SECONDS) {
            $out[] = '— Сайтом не занимались ' . floor($idle / DAY_IN_SECONDS) . ' дн.';
        }
        $out = array_merge($out, self::inconsistencies());
        if ($out) {
            Hastan_Telegram::send("Доброе утро!\n\n" . implode("\n", $out));
        }
    }

    /** 20:00: events today or tomorrow that still have something undone. */
    public static function evening(DateTimeImmutable $now, bool $force = false): void
    {
        if (!$force && !self::due_now($now, (string) Hastan_Settings::get('evening_time'), 'hastan_last_evening')) {
            return;
        }
        $until = $now->modify('+1 day')->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $lines = self::event_lines(array_filter(self::events($now), fn ($t) => $t['due_at'] <= $until));
        if ($lines) {
            Hastan_Telegram::send("Не забыть:\n" . implode("\n", $lines));
        }
    }

    /** Open events that have not passed yet. */
    private static function events(DateTimeImmutable $now): array
    {
        $from = $now->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return array_values(array_filter(
            Hastan_DB::open_tasks(),
            fn ($t) => $t['template'] === 'event' && $t['due_at'] !== null && $t['due_at'] >= $from
        ));
    }

    /** "— Greek party 6.11: сторис, событие на сайте" for each event with open items. */
    private static function event_lines(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $open = array_filter(
                Hastan_DB::items((int) $event['id']),
                fn ($i) => $i['status'] === 'open' && !(int) $i['optional']
            );
            if ($open) {
                $out[] = '— ' . $event['title'] . ': ' . implode(', ', array_column($open, 'title'));
            }
        }
        return $out;
    }

    /** Only lines starting with ❗, so the AI cannot add advice or comments. */
    private static function inconsistencies(): array
    {
        $text = Hastan_Brain::compose(
            'Проверь заметки и названия открытых задач на грубые несоответствия фактов: разные даты, время или цены одного и того же события. '
            . 'Каждое найденное — одна строка, начинается с ❗, только факт, например «❗ Маскарад: в постах 20:00 и 21:00». '
            . 'Ничего не советуй, не проверяй, что сделано, не пиши ничего, кроме таких строк. Если несоответствий нет, ответь пустой строкой.'
        );
        return array_values(array_filter(
            array_map('trim', explode("\n", (string) $text)),
            fn ($line) => str_starts_with($line, '❗')
        ));
    }
}
