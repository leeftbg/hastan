<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The Turkish diary: a goal whose diary holds what was learned, a word, a phrase
 * or a note at a time. Entries live in the goal notes table: kind (word, phrase,
 * note), tr (the Turkish) and body (the translation or the note). No reminders.
 */
class Hastan_Turkish
{
    const KINDS = ['word' => 'слово', 'phrase' => 'фраза', 'note' => 'заметка'];
    const ALPHABET = 'abcçdefgğhıijklmnoöprsştuüvyz';

    /** The old "Английский и турецкий" goal becomes English; Turkish gets its own goal with a diary. */
    public static function seed(): void
    {
        foreach (Hastan_Goals::all() as $goal) {
            if (mb_strtolower(trim($goal['title'])) === 'английский и турецкий') {
                Hastan_Goals::save(['title' => 'Английский'] + $goal, (int) $goal['id']);
            }
        }
        if (!self::goal()) {
            Hastan_Goals::save([
                'title' => 'Турецкий',
                'subtitle' => 'Türkçe günlüğüm · мой дневник',
                'kind' => 'turkish',
                'status' => 'active',
                'position' => 2,
            ]);
        }
    }

    public static function goal(): ?array
    {
        foreach (Hastan_Goals::all() as $goal) {
            if ($goal['kind'] === 'turkish') {
                return $goal;
            }
        }
        return null;
    }

    /** Turkish lowercase: I → ı and İ → i, which mb_strtolower gets wrong. */
    public static function lower(string $s): string
    {
        return mb_strtolower(str_replace(['I', 'İ'], ['ı', 'i'], $s));
    }

    /** A key that sorts by the Turkish alphabet (ç after c, ı before i, and so on). */
    public static function sort_key(string $s): string
    {
        $letters = mb_str_split(self::ALPHABET);
        $order = array_flip($letters);
        $key = '';
        foreach (mb_str_split(self::lower($s)) as $ch) {
            $key .= isset($order[$ch]) ? chr(65 + $order[$ch]) : ($ch === ' ' ? ' ' : '~');
        }
        return $key;
    }

    /** First letter for the dictionary headings, in Turkish capitals. */
    public static function initial(string $s): string
    {
        $ch = mb_substr(self::lower(ltrim($s, " \t\"'«(¡¿")), 0, 1);
        return $ch === 'i' ? 'İ' : ($ch === 'ı' ? 'I' : mb_strtoupper($ch));
    }

    public static function add(int $goal_id, string $day, string $kind, string $tr, string $ru): void
    {
        global $wpdb;
        $tr = mb_substr(trim($tr), 0, 255);
        $ru = trim($ru);
        if ($tr === '' && $ru === '') {
            return;
        }
        if (!isset(self::KINDS[$kind])) {
            $kind = $tr === '' ? 'note' : (preg_match('/\s/u', $tr) ? 'phrase' : 'word');
        }
        $wpdb->insert(Hastan_Goals::notes_table(), [
            'goal_id' => $goal_id,
            'day' => $day,
            'kind' => $kind,
            'tr' => $tr,
            'body' => $ru,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Lines as typed on the page: "merhaba — привет" is a word, "nasılsın? — как дела?"
     * a phrase, a line without a dash a note.
     */
    public static function add_lines(int $goal_id, string $day, string $text): int
    {
        $n = 0;
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim(preg_replace('/^\s*(?:[•·*]|-(?=\s))\s*/u', '', $line));
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+[—–-]\s+|\s*[—–=]\s*/u', $line, 2);
            if (count($parts) === 2 && trim($parts[0]) !== '') {
                self::add($goal_id, $day, '', $parts[0], $parts[1]);
            } else {
                self::add($goal_id, $day, 'note', '', $line);
            }
            $n++;
        }
        return $n;
    }

    /** Entries newest day first, in the order they were written within a day. */
    public static function entries(int $goal_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Hastan_Goals::notes_table() . ' WHERE goal_id = %d ORDER BY day DESC, id ASC', $goal_id), ARRAY_A);
    }

    public static function by_day(array $entries): array
    {
        $days = [];
        foreach ($entries as $e) {
            $days[$e['day']][] = $e;
        }
        return $days;
    }

    /** Words and phrases, each once, in Turkish alphabetical order. */
    public static function dictionary(array $entries): array
    {
        $seen = [];
        foreach ($entries as $e) {
            if ($e['tr'] === '') {
                continue;
            }
            $k = self::lower($e['tr']);
            if (!isset($seen[$k])) {
                $seen[$k] = $e;
            }
        }
        uasort($seen, fn ($a, $b) => strcmp(self::sort_key($a['tr']), self::sort_key($b['tr'])));
        return array_values($seen);
    }

    public static function counts(array $entries): array
    {
        $c = ['word' => 0, 'phrase' => 0, 'note' => 0];
        foreach (self::dictionary($entries) as $e) {
            $c[$e['kind'] === 'phrase' ? 'phrase' : 'word']++;
        }
        foreach ($entries as $e) {
            $c['note'] += $e['tr'] === '' ? 1 : 0;
        }
        return $c + ['days' => count(self::by_day($entries))];
    }

    /** One line for the assistant's picture of the goals. */
    public static function summary(int $goal_id): string
    {
        $entries = self::entries($goal_id);
        if (!$entries) {
            return 'дневник турецкого пока пуст';
        }
        $c = self::counts($entries);
        $last = array_slice(array_filter($entries, fn ($e) => $e['tr'] !== ''), 0, 5);
        return 'дневник: слов ' . $c['word'] . ', фраз ' . $c['phrase'] . ', заметок ' . $c['note'] . ', последняя запись ' . Hastan_Goals::short_date($entries[0]['day'])
            . ($last ? '; недавние: ' . implode(', ', array_map(fn ($e) => $e['tr'], $last)) : '');
    }

    /** Tool: write what was learned into the diary. */
    public static function tool(array $input): string
    {
        $goal = self::goal();
        if (!$goal) {
            self::seed();
            $goal = self::goal();
        }
        $day = !empty($input['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'])
            ? $input['date']
            : Hastan_Settings::now()->format('Y-m-d');
        $n = 0;
        foreach ((array) ($input['entries'] ?? []) as $e) {
            $tr = trim((string) ($e['tr'] ?? ''));
            if ($tr === '') {
                continue;
            }
            self::add((int) $goal['id'], $day, (string) ($e['kind'] ?? ''), $tr, (string) ($e['ru'] ?? ''));
            $n++;
        }
        if (trim((string) ($input['note'] ?? '')) !== '') {
            self::add((int) $goal['id'], $day, 'note', '', (string) $input['note']);
            $n++;
        }
        if (!$n) {
            throw new InvalidArgumentException('нечего записывать');
        }
        return 'Записано в дневник турецкого: ' . $n . '.';
    }
}
