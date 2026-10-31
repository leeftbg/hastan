<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Checklist templates: a typical job (post, video, ads) unfolds into its steps.
 */
class Hastan_Templates
{
    const OPTION = 'hastan_templates';

    public static function defaults(): array
    {
        return [
            'event' => [
                'name' => 'Событие',
                'items' => [
                    'Пост',
                    'Сторис',
                    'Reels',
                    'Реклама',
                    'Событие на сайте',
                ],
            ],
            'post' => [
                'name' => 'Пост',
                'items' => [
                    'Текст поста и хэштеги',
                    'Картинка для ленты Instagram 1080×1350',
                    'Сторис 1080×1920',
                    'Обложка для сайта 1080×1080',
                    'Публикация в Instagram',
                    'Публикация в Facebook',
                    'Новость на сайте',
                    'Сторис-анонс поста',
                ],
            ],
            'video' => [
                'name' => 'Видео / Reels',
                'items' => [
                    'Отобрать исходники',
                    'Монтаж 9:16 (1080×1920)',
                    'Субтитры',
                    'Обложка',
                    'Музыка из разрешённой библиотеки',
                    'Публикация',
                    'Сторис-анонс',
                ],
            ],
            'ads' => [
                'name' => 'Реклама Meta',
                'items' => [
                    'Цель и бюджет',
                    'Аудитория',
                    'Креативы 1:1, 4:5 и 9:16',
                    'Тексты объявлений',
                    'Запуск кампании',
                    'Проверка результатов через 3 дня',
                    'Отчёт после окончания',
                ],
            ],
        ];
    }

    public static function all(): array
    {
        $saved = get_option(self::OPTION);
        return is_array($saved) && $saved ? $saved + self::defaults() : self::defaults();
    }

    public static function get(string $key): ?array
    {
        $all = self::all();
        return $all[$key] ?? null;
    }

    public static function save_items(string $key, array $items): void
    {
        $all = self::all();
        if (!isset($all[$key])) {
            return;
        }
        $all[$key]['items'] = array_values(array_filter(array_map('trim', $items), 'strlen'));
        update_option(self::OPTION, $all, false);
    }

    /** Text for the assistant's instructions. */
    public static function describe(): string
    {
        $lines = [];
        foreach (self::all() as $key => $tpl) {
            $lines[] = "- {$key} ({$tpl['name']}): " . implode('; ', $tpl['items']);
        }
        return implode("\n", $lines);
    }
}
