<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Study material for the state exam: per question a full explanation in
 * Russian, why it is useful in life, a short Bulgarian answer to learn, a
 * Bulgarian–Russian term list and self-check questions. Claude writes it
 * through the exam_material tool; the first questions ship as files in
 * includes/exam-material and are loaded once.
 */
class Hastan_Exam
{
    const FIELDS = ['ru', 'why', 'bg', 'terms', 'quiz', 'sources', 'notes'];
    const LEVELS = ['deep' => 'глубоко', 'normal' => 'нормально', 'min' => 'на сдачу'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'hastan_exam_material';
    }

    public static function install(string $charset): void
    {
        $table = self::table();
        dbDelta("CREATE TABLE $table (
  step_id bigint(20) unsigned NOT NULL,
  level varchar(10) NOT NULL DEFAULT '',
  ru longtext NULL,
  why text NULL,
  bg longtext NULL,
  terms text NULL,
  quiz text NULL,
  sources text NULL,
  notes longtext NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (step_id)
) $charset;");
        self::load_files();
    }

    /** The exam goal's id, or 0. */
    public static function goal_id(): int
    {
        foreach (Hastan_Goals::all() as $goal) {
            if ($goal['kind'] === 'exam') {
                return (int) $goal['id'];
            }
        }
        return 0;
    }

    /** A question of the exam by its code, like «1.1». */
    public static function find(string $code): ?array
    {
        $goal = self::goal_id();
        foreach ($goal ? Hastan_Goals::leaves($goal) : [] as $q) {
            if ($q['code'] === trim($code)) {
                return $q;
            }
        }
        return null;
    }

    public static function get(int $step_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE step_id = %d', $step_id), ARRAY_A);
        return $row ?: null;
    }

    /** Step ids that have material, with their level. */
    public static function index(): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results('SELECT step_id, level FROM ' . self::table(), ARRAY_A) as $row) {
            $out[(int) $row['step_id']] = $row['level'];
        }
        return $out;
    }

    /** Sets the given fields; an empty value leaves a field alone, «-» clears it. */
    public static function save(int $step_id, array $fields): void
    {
        global $wpdb;
        $row = self::get($step_id) ?? ['step_id' => $step_id, 'level' => ''];
        unset($row['updated_at']);
        foreach (array_merge(self::FIELDS, ['level']) as $f) {
            $value = trim((string) ($fields[$f] ?? ''));
            if ($value === '-') {
                $row[$f] = $f === 'level' ? '' : null;
            } elseif ($value !== '') {
                $row[$f] = $f === 'level' ? (isset(self::LEVELS[$value]) ? $value : '') : $value;
            }
        }
        $row['updated_at'] = current_time('mysql', true);
        $wpdb->replace(self::table(), $row);
    }

    /** Material files: includes/exam-material/<code>.md, sections opened by «=== field ===». */
    public static function load_files(): void
    {
        $loaded = (array) get_option('hastan_exam_files', []);
        foreach (glob(__DIR__ . '/exam-material/*.md') ?: [] as $file) {
            $code = basename($file, '.md');
            $stamp = md5_file($file);
            if (($loaded[$code] ?? '') === $stamp) {
                continue;
            }
            $q = self::find($code);
            if (!$q) {
                continue;
            }
            self::save((int) $q['id'], self::parse((string) file_get_contents($file)));
            $loaded[$code] = $stamp;
        }
        update_option('hastan_exam_files', $loaded, false);
    }

    public static function parse(string $text): array
    {
        $out = [];
        $parts = preg_split('/^=== *([a-z]+) *===\s*$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $out[$parts[$i]] = trim($parts[$i + 1]);
        }
        return $out;
    }

    /* ---------- tool ---------- */

    public static function tool_schema(): array
    {
        return [
            'name' => 'exam_material',
            'description' => 'Учебный материал к вопросу госэкзамена (страница вопроса в Hastan → Цели → Държавен изпит). '
                . 'Порядок: сначала разбор по-русски до полного понимания, потом короткий болгарский конспект для заучивания. '
                . 'Текст в Markdown: ## и ### заголовки, **жирный**, списки «- » и «1. », «> » выноска, таблицы «| a | b |». '
                . 'terms — строки «болгарский термин — русский перевод». quiz — блоки «В: вопрос» и «О: ответ», через пустую строку. '
                . 'Сноски: в тексте {{слово}} или {{слово|ключ}} делает слово нажимаемым; в notes — разделы «### ключ» с пояснением (читать для понимания, не учить). '
                . 'Пустое поле не меняется, «-» очищает. Без code — вернёт список вопросов и у каких есть материал.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'Номер вопроса, например «1.1»'],
                    'level' => ['type' => 'string', 'description' => 'deep — глубоко, normal — нормально, min — на сдачу'],
                    'ru' => ['type' => 'string', 'description' => 'Разбор по-русски, Markdown'],
                    'why' => ['type' => 'string', 'description' => 'Зачем это в жизни, Markdown'],
                    'bg' => ['type' => 'string', 'description' => 'Конспект-ответ на болгарском для экзамена, Markdown'],
                    'terms' => ['type' => 'string', 'description' => 'Словарик: «термин — перевод» по строке'],
                    'quiz' => ['type' => 'string', 'description' => 'Самопроверка: «В: …» и «О: …»'],
                    'sources' => ['type' => 'string', 'description' => 'Источники, Markdown-список'],
                    'notes' => ['type' => 'string', 'description' => 'Сноски: разделы «### ключ» и абзацы пояснения, Markdown'],
                    'read' => ['type' => 'boolean', 'description' => 'true — вернуть текущий материал вопроса'],
                ],
            ],
        ];
    }

    public static function tool(array $input): string
    {
        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $have = self::index();
            $lines = [];
            foreach (array_merge([], ...array_map(fn ($t) => $t['children'], Hastan_Goals::tree(self::goal_id()))) as $q) {
                $lines[] = $q['code'] . ' ' . $q['title'] . (isset($have[(int) $q['id']]) ? ' — есть материал' . ($have[(int) $q['id']] ? ' (' . self::LEVELS[$have[(int) $q['id']]] . ')' : '') : '');
            }
            return $lines ? implode("\n", $lines) : 'Экзамен не найден.';
        }
        $q = self::find($code);
        if (!$q) {
            throw new InvalidArgumentException("Нет вопроса {$code}");
        }
        if (!empty($input['read'])) {
            $row = self::get((int) $q['id']);
            if (!$row) {
                return "{$code} {$q['title']}: материала нет.";
            }
            $out = "{$code} {$q['title']}" . ($row['level'] ? ' · ' . self::LEVELS[$row['level']] : '');
            foreach (self::FIELDS as $f) {
                $out .= "\n\n=== {$f} ===\n" . (string) $row[$f];
            }
            return $out;
        }
        self::save((int) $q['id'], $input);
        return "Сохранено: {$code}. Страница: " . admin_url('admin.php?page=hastan-goals&goal=' . self::goal_id() . '&q=' . (int) $q['id']);
    }

    /* ---------- rendering ---------- */

    private static function inline(string $text): string
    {
        $html = esc_html($text);
        $html = preg_replace_callback('/\{\{([^{}|]+?)(?:\|([^{}]+?))?\}\}/u', fn ($m) => '<button type="button" class="hq-fn" data-fn="' . esc_attr(self::note_key(html_entity_decode($m[2] ?? '' ?: $m[1]))) . '">' . $m[1] . '</button>', $html);
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html);
        $html = preg_replace('/(?<![\w*])_(.+?)_(?!\w)/u', '<em>$1</em>', $html);
        $html = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u', fn ($m) => '<a href="' . esc_url(html_entity_decode($m[2])) . '" target="_blank" rel="noopener">' . $m[1] . '</a>', $html);
        return $html;
    }

    /** A small Markdown: headings, paragraphs, lists, callouts, tables, rules. */
    public static function markdown(string $text): string
    {
        $lines = preg_split('/\R/u', trim($text));
        $out = '';
        $para = [];
        $list = null;
        $quote = [];
        $table = [];
        $flush = function () use (&$out, &$para, &$list, &$quote, &$table) {
            if ($para) {
                $out .= '<p>' . implode('<br>', array_map([self::class, 'inline'], $para)) . '</p>';
                $para = [];
            }
            if ($list) {
                $out .= '<' . $list['tag'] . '>';
                foreach ($list['items'] as $item) {
                    $out .= '<li>' . self::inline($item['text']) . ($item['sub'] ? '<ul><li>' . implode('</li><li>', array_map([self::class, 'inline'], $item['sub'])) . '</li></ul>' : '') . '</li>';
                }
                $out .= '</' . $list['tag'] . '>';
                $list = null;
            }
            if ($quote) {
                $out .= '<aside class="hq-callout">' . self::markdown(implode("\n", $quote)) . '</aside>';
                $quote = [];
            }
            if ($table) {
                $rows = array_values(array_filter($table, fn ($r) => !preg_match('/^\|?[\s:|-]+\|?$/', $r)));
                $out .= '<div class="hq-table"><table>';
                foreach ($rows as $i => $r) {
                    $cells = array_map('trim', explode('|', trim(trim($r), '|')));
                    $tag = $i === 0 && count($rows) > 1 ? 'th' : 'td';
                    $out .= '<tr><' . $tag . '>' . implode('</' . $tag . '><' . $tag . '>', array_map([self::class, 'inline'], $cells)) . '</' . $tag . '></tr>';
                }
                $out .= '</table></div>';
                $table = [];
            }
        };
        foreach ($lines as $line) {
            $t = rtrim($line);
            if ($quote && !str_starts_with(ltrim($t), '>')) {
                $flush();
            }
            if ($table && !str_starts_with(ltrim($t), '|')) {
                $flush();
            }
            if (trim($t) === '') {
                $flush();
            } elseif (preg_match('/^(#{2,4})\s+(.*)$/u', $t, $m)) {
                $flush();
                $level = strlen($m[1]);
                $out .= '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
            } elseif (preg_match('/^(-{3,}|\*{3,})$/', trim($t))) {
                $flush();
                $out .= '<hr>';
            } elseif (str_starts_with(ltrim($t), '>')) {
                if (!$quote) {
                    $flush();
                }
                $quote[] = preg_replace('/^\s*>\s?/', '', $t);
            } elseif (str_starts_with(ltrim($t), '|')) {
                if (!$table) {
                    $flush();
                }
                $table[] = $t;
            } elseif (preg_match('/^(\s*)(?:[-*•]|(\d+)[.)])\s+(.*)$/u', $t, $m)) {
                $indent = strlen($m[1]);
                $tag = $m[2] !== '' ? 'ol' : 'ul';
                if ($indent >= 2 && $list && $list['items']) {
                    $list['items'][count($list['items']) - 1]['sub'][] = $m[3];
                    continue;
                }
                if ($para || ($list && $list['tag'] !== $tag)) {
                    $flush();
                }
                $list = $list ?? ['tag' => $tag, 'items' => []];
                $list['items'][] = ['text' => $m[3], 'sub' => []];
            } elseif ($list && preg_match('/^\s{2,}\S/', $t)) {
                $last = count($list['items']) - 1;
                $list['items'][$last]['text'] .= ' ' . trim($t);
            } else {
                if ($list) {
                    $flush();
                }
                $para[] = trim($t);
            }
        }
        $flush();
        return $out;
    }

    public static function note_key(string $key): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $key)));
    }

    /** Footnotes: «### key» sections, each rendered. */
    public static function notes(string $text): array
    {
        $out = [];
        $parts = preg_split('/^###\s+(.+)$/mu', trim($text), -1, PREG_SPLIT_DELIM_CAPTURE);
        for ($i = 1; $i + 1 < count($parts); $i += 2) {
            $title = trim($parts[$i]);
            $out[self::note_key($title)] = [mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1), self::markdown($parts[$i + 1])];
        }
        return $out;
    }

    /** «термин — перевод» lines as pairs. */
    public static function terms(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/u', trim($text)) as $line) {
            $line = trim(preg_replace('/^[-*•]\s*/u', '', trim($line)));
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+[—–-]\s+/u', $line, 2);
            $out[] = [$parts[0], $parts[1] ?? ''];
        }
        return $out;
    }

    /** «В: …» / «О: …» blocks as [question, answer]. */
    public static function quiz(string $text): array
    {
        $out = [];
        $q = null;
        $a = [];
        foreach (preg_split('/\R/u', trim($text)) as $line) {
            if (preg_match('/^\s*(?:В|Q|Въпрос)\s*[:.]\s*(.*)$/u', $line, $m)) {
                if ($q !== null) {
                    $out[] = [$q, trim(implode("\n", $a))];
                }
                $q = $m[1];
                $a = [];
            } elseif (preg_match('/^\s*(?:О|A|Отговор)\s*[:.]\s*(.*)$/u', $line, $m)) {
                $a[] = $m[1];
            } elseif ($q !== null) {
                $a[] = $line;
            }
        }
        if ($q !== null) {
            $out[] = [$q, trim(implode("\n", $a))];
        }
        return $out;
    }
}
