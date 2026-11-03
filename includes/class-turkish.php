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
 $s = self::plain($s);
        $ch = mb_substr(self::lower(ltrim($s, " \t\"'«(¡¿")), 0, 1);
        return $ch === 'i' ? 'İ' : ($ch === 'ı' ? 'I' : mb_strtoupper($ch));
    }

    /** Highlight marks: *красный*, ^бирюзовый^, ~золотой~. Matching parts get the same colour. */
    const MARKS = ['*' => 'hl', '^' => 'hl hl2', '~' => 'hl hl3'];

    public static function mark(string $s): string
    {
        $s = esc_html($s);
        foreach (self::MARKS as $m => $class) {
            $q = preg_quote($m, '/');
            $s = preg_replace('/' . $q . '([^' . $q . '\n]+)' . $q . '/u', '<mark class="' . $class . '">$1</mark>', $s);
        }
        return $s;
    }

    public static function plain(string $s): string
    {
        return trim(str_replace(array_keys(self::MARKS), '', $s));
    }

    public static function russian(string $s): bool
    {
        return (bool) preg_match('/\p{Cyrillic}/u', $s);
    }

    /** Lines with Russian on both sides of the dash, saved as words before 0.19.1, become notes. */
    public static function fix_notes(): void
    {
        global $wpdb;
        $goal = self::goal();
        if (!$goal) {
            return;
        }
        foreach (self::entries((int) $goal['id']) as $e) {
            if ($e['tr'] !== '' && self::russian($e['tr'])) {
                $wpdb->update(Hastan_Goals::notes_table(), ['tr' => '', 'body' => self::as_line($e), 'kind' => 'note'], ['id' => (int) $e['id']]);
            }
        }
    }

    /** Turkish goes first, whichever side it was typed on: "Книга на столе — Kitap masada" is swapped. */
    private static function sides(string $tr, string $ru): array
    {
        $cyr = fn ($s) => (bool) preg_match('/\p{Cyrillic}/u', $s);
        return $ru !== '' && $cyr($tr) && !$cyr($ru) ? [$ru, $tr] : [$tr, $ru];
    }

    private static function kind_of(string $tr, string $kind): string
    {
        if (isset(self::KINDS[$kind]) && ($kind === 'note') === ($tr === '')) {
            return $kind;
        }
        return $tr === '' ? 'note' : (preg_match('/\s/u', self::plain($tr)) ? 'phrase' : 'word');
    }

    /** Splits a typed line into Turkish and translation; a line without a dash is a note. */
    public static function parse(string $line): ?array
    {
        $line = trim(preg_replace('/^\s*(?:[•·]|-(?=\s))\s*/u', '', $line));
        if ($line === '') {
            return null;
        }
        $parts = preg_split('/\s+[—–-]\s+|\s*[—–=]\s*/u', $line, 2);
        if (count($parts) === 2 && trim($parts[0]) !== '') {
            [$tr, $ru] = self::sides(trim($parts[0]), trim($parts[1]));
            if (!self::russian($tr)) {
                return ['tr' => $tr, 'ru' => $ru, 'kind' => self::kind_of($tr, '')];
            }
            // Russian on both sides of the dash: a thought, not a word to learn.
        }
        return ['tr' => '', 'ru' => $line, 'kind' => 'note'];
    }

    /** An entry as it is typed, for editing. */
    public static function as_line(array $e): string
    {
        return $e['tr'] === '' ? $e['body'] : $e['tr'] . ($e['body'] !== '' ? ' — ' . $e['body'] : '');
    }

    public static function is_bullet(string $line): bool
    {
        return (bool) preg_match('/^\s*(?:[•·]|[-–—](?=\s))/u', $line);
    }

    /** A note with a list: lines starting with "- " become points. */
    public static function note_html(string $body): string
    {
        $html = '';
        $list = false;
        foreach (preg_split('/\R/u', $body) as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (self::is_bullet($line)) {
                $html .= ($list ? '' : '<ul>') . '<li>' . self::mark(trim(preg_replace('/^\s*(?:[•·]|[-–—])\s*/u', '', $line))) . '</li>';
                $list = true;
            } else {
                $html .= ($list ? '</ul>' : '') . '<p>' . self::mark(trim($line)) . '</p>';
                $list = false;
            }
        }
        return $html . ($list ? '</ul>' : '');
    }

    public static function update(int $note_id, string $line): void
    {
        global $wpdb;
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $line)), 'strlen'));
        if (count($lines) > 1) {
            $body = implode("\n", array_map(fn ($l) => self::is_bullet($l) ? '- ' . trim(preg_replace('/^\s*(?:[•·]|[-–—])\s*/u', '', $l)) : $l, $lines));
            $wpdb->update(Hastan_Goals::notes_table(), ['tr' => '', 'body' => $body, 'kind' => 'note'], ['id' => $note_id]);
            return;
        }
        $e = self::parse($line);
        if ($e) {
            $wpdb->update(Hastan_Goals::notes_table(), ['tr' => mb_substr($e['tr'], 0, 255), 'body' => $e['ru'], 'kind' => $e['kind']], ['id' => $note_id]);
        }
    }

    /** Entries written before the swap existed: Russian on the left, Turkish on the right. */
    public static function fix_sides(): void
    {
        global $wpdb;
        $goal = self::goal();
        if (!$goal) {
            return;
        }
        foreach (self::entries((int) $goal['id']) as $e) {
            [$tr, $ru] = self::sides($e['tr'], $e['body']);
            if ($tr !== $e['tr']) {
                $wpdb->update(Hastan_Goals::notes_table(), ['tr' => $tr, 'body' => $ru], ['id' => (int) $e['id']]);
            }
        }
    }

    public static function add(int $goal_id, string $day, string $kind, string $tr, string $ru): void
    {
        global $wpdb;
        [$tr, $ru] = self::sides(mb_substr(trim($tr), 0, 255), trim($ru));
        if ($tr === '' && $ru === '') {
            return;
        }
        if (self::russian($tr)) {
            [$tr, $ru, $kind] = ['', $tr . ($ru !== '' ? ' — ' . $ru : ''), 'note'];
        }
        $kind = self::kind_of($tr, $kind);
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
        $items = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $e = self::parse($line);
            if (!$e) {
                continue;
            }
            // "- …" right after a note is a point of that note's list.
            $last = count($items) - 1;
            if ($e['kind'] === 'note' && $last >= 0 && $items[$last]['kind'] === 'note' && self::is_bullet($line)) {
                $items[$last]['ru'] .= "\n- " . $e['ru'];
                continue;
            }
            $items[] = $e;
        }
        foreach ($items as $e) {
            self::add($goal_id, $day, $e['kind'], $e['tr'], $e['ru']);
        }
        return count($items);
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
            $k = self::lower(self::plain($e['tr']));
            if (!isset($seen[$k])) {
                $seen[$k] = $e;
            }
        }
        uasort($seen, fn ($a, $b) => strcmp(self::sort_key(self::plain($a['tr'])), self::sort_key(self::plain($b['tr']))));
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
            . ($last ? '; недавние: ' . implode(', ', array_map(fn ($e) => self::plain($e['tr']), $last)) : '');
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
