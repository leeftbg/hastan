<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Exercises for a page of the Turkish diary: Claude writes 35 translations into
 * Turkish, 10 into Russian and 5 endings to fill in, on the grammar of that page
 * only, with other words. Answers are checked here, without Claude. A phrase is
 * learned after LEARNED right answers on different days; a mistake starts it over.
 */
class Hastan_Drills
{
    const HOOK = 'hastan_tr_drill';
    const LEARNED = 4;
    const PLAN = ['ru_tr' => 35, 'tr_ru' => 10, 'fill' => 5];
    const KINDS = ['ru_tr' => 'на турецкий', 'tr_ru' => 'на русский', 'fill' => 'допишите'];

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'generate']);
    }

    public static function drills_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_tr_drills';
    }

    public static function items_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_tr_items';
    }

    public static function install(string $charset): void
    {
        $drills = self::drills_table();
        $items = self::items_table();
        dbDelta("CREATE TABLE $drills (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  goal_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  words text NULL,
  error varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY goal_id (goal_id)
) $charset;");
        dbDelta("CREATE TABLE $items (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  drill_id bigint(20) unsigned NOT NULL,
  kind varchar(10) NOT NULL DEFAULT 'ru_tr',
  prompt varchar(255) NOT NULL,
  hint varchar(255) NOT NULL DEFAULT '',
  answer varchar(255) NOT NULL,
  accept text NULL,
  position int(11) NOT NULL DEFAULT 0,
  streak tinyint(3) unsigned NOT NULL DEFAULT 0,
  last_ok date NULL,
  last_answer varchar(255) NOT NULL DEFAULT '',
  last_result tinyint(1) NOT NULL DEFAULT 0,
  learned tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY drill_id (drill_id)
) $charset;");
    }

    /* ---------- data ---------- */

    public static function all(int $goal_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::drills_table() . ' WHERE goal_id = %d ORDER BY id DESC', $goal_id), ARRAY_A);
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::drills_table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    public static function items(int $drill_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::items_table() . ' WHERE drill_id = %d ORDER BY position, id', $drill_id), ARRAY_A);
    }

    public static function item(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::items_table() . ' WHERE id = %d', $id), ARRAY_A);
        return $row ?: null;
    }

    /** [learned, total] per drill. */
    public static function progress(int $drill_id): array
    {
        $items = self::items($drill_id);
        return [count(array_filter($items, fn ($i) => (int) $i['learned'])), count($items)];
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::drills_table(), ['id' => $id]);
        $wpdb->delete(self::items_table(), ['drill_id' => $id]);
    }

    public static function delete_item(int $id): void
    {
        global $wpdb;
        $wpdb->delete(self::items_table(), ['id' => $id]);
    }

    public static function set_learned(int $id, bool $learned): void
    {
        global $wpdb;
        $wpdb->update(self::items_table(), ['learned' => $learned ? 1 : 0, 'streak' => $learned ? self::LEARNED : 0, 'last_result' => 0, 'last_answer' => ''], ['id' => $id]);
    }

    /* ---------- making a drill ---------- */

    /** Queues a drill for a diary page; Claude writes it in the background. */
    public static function request(int $goal_id, string $day): int
    {
        global $wpdb;
        [$date] = self::date_words($day);
        $wpdb->insert(self::drills_table(), [
            'goal_id' => $goal_id,
            'day' => $day,
            'title' => $date,
            'status' => 'pending',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $id = (int) $wpdb->insert_id;
        wp_schedule_single_event(time(), self::HOOK, [$id]);
        spawn_cron();
        return $id;
    }

    private static function date_words(string $day): array
    {
        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $t = strtotime($day . ' 12:00:00');
        return [(int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1]];
    }

    private static function fail(int $id, string $why): void
    {
        global $wpdb;
        $wpdb->update(self::drills_table(), ['status' => 'failed', 'error' => mb_substr($why, 0, 255)], ['id' => $id]);
    }

    public static function generate(int $id): void
    {
        global $wpdb;
        $drill = self::get($id);
        if (!$drill || $drill['status'] !== 'pending') {
            return;
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }
        $entries = Hastan_Turkish::entries((int) $drill['goal_id']);
        $page = array_values(array_filter($entries, fn ($e) => $e['day'] === $drill['day']));
        if (!$page) {
            self::fail($id, 'На этой странице дневника нет записей.');
            return;
        }
        $lines = array_map(fn ($e) => '- ' . Hastan_Turkish::plain(Hastan_Turkish::as_line($e)), $page);
        $known = array_map(fn ($e) => Hastan_Turkish::plain($e['tr']), array_filter(Hastan_Turkish::dictionary($entries), fn ($e) => $e['kind'] === 'word'));

        $system = 'You write Turkish practice exercises for a Russian-speaking beginner. You are strict about grammar scope: the learner has studied only what is on the given diary page.';
        $prompt = "The learner's diary page (Turkish — Russian, and notes in Russian):\n" . implode("\n", $lines)
            . "\n\nWords the learner already knows: " . ($known ? implode(', ', $known) : 'none yet') . "."
            . "\n\nWrite exactly " . self::PLAN['ru_tr'] . " items of kind ru_tr, " . self::PLAN['tr_ru'] . " of kind tr_ru and " . self::PLAN['fill'] . " of kind fill, in that order."
            . "\n\nRules:"
            . "\n- Use only the grammar shown on this page: the same sentence patterns, suffixes and word order as the examples. No other cases, tenses, persons, question forms, plurals, possessives, negation or articles unless the page shows them. If the page is only about gender, use only sentences of the kind shown."
            . "\n- Change the words, not the grammar: use many different everyday nouns (and adjectives only if the page uses them), so the learner gains vocabulary. Mix known words with new common words. Do not repeat the page's own examples."
            . "\n- Turkish must be correct, with Turkish letters (ç ğ ı İ ö ş ü) and correct vowel harmony and consonant changes. Sentences short, like the examples."
            . "\n- ru_tr: prompt is the Russian sentence, answer is the Turkish. tr_ru: prompt is Turkish, answer is a natural Russian translation. fill: prompt is the Turkish sentence with the practised ending replaced by ___ (for example «Kalem sandalye___»), answer is only the missing letters (for example «de»), hint is the Russian translation of the whole sentence. For ru_tr and tr_ru the hint is empty."
            . "\n- accept: other answers that are equally correct (word order or synonyms the page allows); empty when there are none. Capital letters and the final full stop do not matter, do not list such variants."
            . "\n- new_words: every Turkish word used in the items that is not in the known list, with its Russian translation, each once."
            . "\n- title: the topic of the page in Russian, two to five words.";
        $item = [
            'type' => 'object',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => array_keys(self::PLAN)],
                'prompt' => ['type' => 'string'],
                'hint' => ['type' => 'string'],
                'answer' => ['type' => 'string'],
                'accept' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['kind', 'prompt', 'hint', 'answer', 'accept'],
            'additionalProperties' => false,
        ];
        $schema = [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'items' => ['type' => 'array', 'items' => $item],
                'new_words' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['tr' => ['type' => 'string'], 'ru' => ['type' => 'string']],
                    'required' => ['tr', 'ru'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['title', 'items', 'new_words'],
            'additionalProperties' => false,
        ];
        try {
            $data = Hastan_Brain::json($system, $prompt, $schema);
        } catch (Throwable $e) {
            self::fail($id, $e->getMessage());
            return;
        }
        $pos = 0;
        foreach ((array) ($data['items'] ?? []) as $it) {
            $kind = isset(self::PLAN[$it['kind'] ?? '']) ? $it['kind'] : 'ru_tr';
            if (trim((string) ($it['prompt'] ?? '')) === '' || trim((string) ($it['answer'] ?? '')) === '') {
                continue;
            }
            $wpdb->insert(self::items_table(), [
                'drill_id' => $id,
                'kind' => $kind,
                'prompt' => mb_substr(trim($it['prompt']), 0, 255),
                'hint' => mb_substr(trim((string) ($it['hint'] ?? '')), 0, 255),
                'answer' => mb_substr(trim($it['answer']), 0, 255),
                'accept' => wp_json_encode(array_values(array_filter(array_map('trim', (array) ($it['accept'] ?? []))))),
                'position' => $pos++,
            ]);
        }
        if (!$pos) {
            self::fail($id, 'Claude не прислал ни одного задания.');
            return;
        }
        $words = array_values(array_filter((array) ($data['new_words'] ?? []), fn ($w) => trim((string) ($w['tr'] ?? '')) !== ''));
        $wpdb->update(self::drills_table(), [
            'status' => 'ready',
            'title' => mb_substr(trim((string) ($data['title'] ?? '')) ?: $drill['title'], 0, 255),
            'words' => wp_json_encode($words),
        ], ['id' => $id]);
    }

    /* ---------- checking ---------- */

    /** Lowercase, Turkish-aware, without punctuation and extra spaces; ё counts as е. */
    public static function norm(string $s): string
    {
        $s = Hastan_Turkish::lower(Hastan_Turkish::plain($s));
        $s = str_replace(['ё', '’', '`'], ['е', "'", "'"], $s);
        $s = preg_replace('/[.,!?;:«»"“”()\-–—]+/u', ' ', $s);
        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    public static function is_right(array $item, string $answer): bool
    {
        $given = self::norm($answer);
        if ($given === '') {
            return false;
        }
        $ok = array_merge([$item['answer']], (array) json_decode((string) $item['accept'], true));
        foreach ($ok as $variant) {
            if (self::norm((string) $variant) === $given) {
                return true;
            }
        }
        return false;
    }

    /** Checks the filled answers; blank ones are left alone. Returns [right, wrong]. */
    public static function check(int $drill_id, array $answers, string $today): array
    {
        global $wpdb;
        $right = $wrong = 0;
        $wpdb->update(self::items_table(), ['last_result' => 0], ['drill_id' => $drill_id]);
        foreach (self::items($drill_id) as $item) {
            $answer = trim((string) ($answers[(int) $item['id']] ?? ''));
            if ($answer === '' || (int) $item['learned']) {
                continue;
            }
            if (self::is_right($item, $answer)) {
                $right++;
                self::count_right($item, $answer, $today);
            } else {
                $wrong++;
                $wpdb->update(self::items_table(), ['streak' => 0, 'last_ok' => null, 'last_answer' => mb_substr($answer, 0, 255), 'last_result' => 2], ['id' => (int) $item['id']]);
            }
        }
        return [$right, $wrong];
    }

    /** A right answer counts once a day towards learning. */
    private static function count_right(array $item, string $answer, string $today): void
    {
        global $wpdb;
        $streak = (int) $item['streak'] + ($item['last_ok'] === $today ? 0 : 1);
        $wpdb->update(self::items_table(), [
            'streak' => $streak,
            'last_ok' => $today,
            'last_answer' => mb_substr($answer, 0, 255),
            'last_result' => 1,
            'learned' => $streak >= self::LEARNED ? 1 : 0,
        ], ['id' => (int) $item['id']]);
    }

    /** "My answer is right too": counts it and remembers the variant. */
    public static function accept(int $item_id, string $today): void
    {
        global $wpdb;
        $item = self::item($item_id);
        if (!$item || $item['last_answer'] === '') {
            return;
        }
        $accept = (array) json_decode((string) $item['accept'], true);
        $accept[] = $item['last_answer'];
        $wpdb->update(self::items_table(), ['accept' => wp_json_encode(array_values(array_unique($accept)))], ['id' => $item_id]);
        self::count_right(self::item($item_id), $item['last_answer'], $today);
    }

    /**
     * Where the answer differs from the right one: both strings as [char, same?] lists,
     * aligned by the longest common subsequence (case and ё ignored).
     */
    public static function diff(string $given, string $right, bool $words = false): array
    {
        $split = fn ($s) => $words ? preg_split('/(\s+)/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) : mb_str_split($s);
        $a = $split($given);
        $b = $split($right);
        $key = fn ($c) => trim(preg_replace('/[.,!?;:«»"]+/u', '', str_replace('ё', 'е', Hastan_Turkish::lower($c))));
        $n = count($a);
        $m = count($b);
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $key($a[$i]) === $key($b[$j]) ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $ga = array_map(fn ($c) => [$c, false], $a);
        $gb = array_map(fn ($c) => [$c, false], $b);
        for ($i = 0, $j = 0; $i < $n && $j < $m;) {
            if ($key($a[$i]) === $key($b[$j])) {
                $ga[$i++][1] = true;
                $gb[$j++][1] = true;
            } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return [$ga, $gb];
    }

    /** New words of a drill into the dictionary, skipping those already there. */
    public static function add_words(array $drill, string $today): int
    {
        $goal_id = (int) $drill['goal_id'];
        $have = array_flip(array_map(fn ($e) => Hastan_Turkish::lower(Hastan_Turkish::plain($e['tr'])), Hastan_Turkish::dictionary(Hastan_Turkish::entries($goal_id))));
        $n = 0;
        foreach ((array) json_decode((string) $drill['words'], true) as $w) {
            $tr = trim((string) ($w['tr'] ?? ''));
            if ($tr === '' || isset($have[Hastan_Turkish::lower($tr)])) {
                continue;
            }
            Hastan_Turkish::add($goal_id, $today, 'word', $tr, (string) ($w['ru'] ?? ''));
            $have[Hastan_Turkish::lower($tr)] = true;
            $n++;
        }
        return $n;
    }
}
