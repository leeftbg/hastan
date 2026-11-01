<?php
if (!defined('ABSPATH')) {
    exit;
}

use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * The assistant itself: talks to Claude, which reads the task list and changes
 * it through tools.
 */
class Hastan_Brain
{
    const MAX_TOOL_ROUNDS = 8;

    private static ?Client $client = null;

    private static function client(): Client
    {
        if (!self::$client) {
            require_once HASTAN_DIR . 'vendor/autoload.php';
            self::$client = new Client(
                apiKey: (string) Hastan_Settings::get('anthropic_key'),
                baseUrl: defined('HASTAN_ANTHROPIC_BASE_URL') ? HASTAN_ANTHROPIC_BASE_URL : null,
            );
        }
        return self::$client;
    }

    /** Answers a message from the user and keeps it in the chat log. */
    public static function reply(string $text, ?array $image = null): string
    {
        $history = Hastan_DB::recent_log();
        Hastan_DB::log('user', $image ? "[фото] {$text}" : $text);
        $answer = self::run($history, $text, true, $image);
        Hastan_DB::log('assistant', $answer);
        return $answer;
    }

    /**
     * Writes a scheduled message (morning plan, evening check) from an
     * instruction; the result goes to the chat log as the assistant's words.
     */
    public static function compose(string $instruction): ?string
    {
        $answer = self::run(Hastan_DB::recent_log(), "[Служебное задание, пользователь его не видит]\n" . $instruction, false);
        if ($answer === null) {
            return null;
        }
        Hastan_DB::log('assistant', $answer);
        return $answer;
    }

    /**
     * One conversation turn with the tool loop. Returns the text answer; on
     * failure returns an error line for the user, or null when $fail_soft is false.
     */
    private static function run(array $history, string $text, bool $fail_soft = true, ?array $image = null): ?string
    {
        if (!Hastan_Settings::get('anthropic_key')) {
            return $fail_soft ? 'Не задан ключ Claude API. Откройте в WordPress: Hastan → Настройки.' : null;
        }

        $messages = [];
        foreach ($history as $row) {
            self::append($messages, $row['role'], $row['content']);
        }
        self::append($messages, 'user', self::context() . "\n\n" . $text);
        if ($image) {
            $last = count($messages) - 1;
            $messages[$last]['content'] = [
                ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $image[0], 'data' => $image[1]]],
                ['type' => 'text', 'text' => $messages[$last]['content']],
            ];
        }

        try {
            for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
                $response = self::client()->beta->messages->create(
                    maxTokens: 16000,
                    messages: $messages,
                    model: (string) Hastan_Settings::get('model'),
                    cacheControl: ['type' => 'ephemeral'],
                    fallbacks: 'default',
                    outputConfig: ['effort' => (string) Hastan_Settings::get('effort')],
                    system: self::system_prompt(),
                    tools: self::tools(),
                    betas: ['server-side-fallback-2026-07-01'],
                );

                if ($response->stopReason === 'refusal') {
                    return $fail_soft ? 'С этим я помочь не могу.' : null;
                }
                if ($response->stopReason !== 'tool_use') {
                    $out = '';
                    foreach ($response->content as $block) {
                        if ($block->type === 'text') {
                            $out .= $block->text;
                        }
                    }
                    return trim($out) !== '' ? trim($out) : 'Готово.';
                }

                $results = [];
                foreach ($response->content as $block) {
                    if ($block instanceof BetaToolUseBlock) {
                        $results[] = self::run_tool($block->id, $block->name, (array) $block->input);
                    }
                }
                $messages[] = ['role' => 'assistant', 'content' => $response->content];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
            return $fail_soft ? 'Запутался в действиях, попробуйте сформулировать проще.' : null;
        } catch (AuthenticationException $e) {
            error_log('Hastan Claude: ' . $e->getMessage());
            return $fail_soft ? 'Claude API не принял ключ. Проверьте его в настройках Hastan.' : null;
        } catch (RateLimitException $e) {
            error_log('Hastan Claude: ' . $e->getMessage());
            return $fail_soft ? 'Claude API просит подождать. Повторите через минуту.' : null;
        } catch (APIStatusException $e) {
            error_log('Hastan Claude: ' . $e->getMessage());
            $detail = is_array($e->body ?? null) ? (string) ($e->body['error']['message'] ?? '') : '';
            return $fail_soft ? "Claude API вернул ошибку {$e->status}: {$detail}\nПерешлите это сообщение в чат с Claude, разберёмся." : null;
        } catch (APIConnectionException $e) {
            error_log('Hastan Claude: ' . $e->getMessage());
            return $fail_soft ? 'Не получилось связаться с Claude API. Повторите чуть позже.' : null;
        }
    }

    /** Adds a text message, merging consecutive messages of the same role. */
    private static function append(array &$messages, string $role, string $text): void
    {
        $last = count($messages) - 1;
        if ($last >= 0 && $messages[$last]['role'] === $role && is_string($messages[$last]['content'])) {
            $messages[$last]['content'] .= "\n\n" . $text;
            return;
        }
        if (!$messages && $role !== 'user') {
            return; // The conversation must start with the user.
        }
        $messages[] = ['role' => $role, 'content' => $text];
    }

    private static function system_prompt(): string
    {
        $about = (string) Hastan_Settings::get('about');
        $templates = Hastan_Templates::describe();
        $site = implode(', ', Hastan_Settings::site_projects());
        return <<<PROMPT
Ты Hastan, личный помощник по работе. Ты общаешься с пользователем в Telegram и ведёшь его задачи, чтобы он ничего не забывал.

О пользователе: {$about}

Главное правило: пользователь не ведёт органайзер. Он пишет как живому коллеге, а ты сам превращаешь это в задачи, сроки и чек-листы и сам следишь за ними.

Как работать:
- Напоминания приходят сами только про две вещи: незакрытые пункты чек-листа событий и долгий перерыв в работе над сайтом. Ничего другого не обещай напоминать.
- Новое событие (вечеринка, форум, концерт и т.п.) — это задача с шаблоном event: проект lotus, срок = дата события, название «Название события д.м». Её пункты: пост, сторис, Reels, реклама, событие на сайте. Отмечай пункт, как только пользователь упомянул, что он готов, или перешёл к следующему; про публикацию не переспрашивай.
- Дополнительную работу по уже заведённому событию (ещё один Reels, ещё реклама) записывай, только если пользователь прямо просит, и без напоминаний.
- Проекты сайта ({$site}): задачи без сроков.
- Привычки — личное, отдельно от работы. Если пользователь говорит, что сделал что-то из привычек (например «шея, силовые»), отметь через mark_habits на сегодня или на названный день. Если прислано фото бумажной таблицы привычек, перенеси все закрашенные клетки через mark_habits (даты в таблице — дд.мм, год текущий). Не хвали, не комментируй и не уговаривай: ответ «ок». Сам про привычки не спрашивай.
- Еда: когда пользователь пишет, что съел или готовит («каша 100 г», «съела 200 г картошки», «банан»), запиши через log_food и ответь одной строкой из результата, например «+260, сегодня 840 из 2000». Если продукта нет в базе, подставь обычную калорийность и скажи коротко «(оценка)». Не советуй, что есть.
- Если пользователь делится идеей на любую тему, а не задачей («идея:», «пришло в голову», «а что если»), сохрани её инструментом save_idea и не создавай задач. Оформи аккуратно, но ничего не добавляй от себя.
- Когда пользователь говорит о другой новой работе, создай задачу инструментом add_task. Срок — только день, без часов. «К пятнице» значит ближайшая пятница.
- Если работа похожа на шаблон, укажи его, и чек-лист добавится сам. Шаблоны:
{$templates}
- Если пользователь говорит, что что-то сделал, найди это в списке задач и отметь инструментом update_items или update_task. Если не ясно, о каком пункте речь, уточни одним коротким вопросом.
- Переносы, новые сроки и отмены делай через update_task.
- Отвечай коротко и по делу, обычным текстом без Markdown, звёздочек и решёток. Списки оформляй строками с тире. Обращайся на «вы».
- Когда пользователь просто сообщает задачу или что сделал, а ты всё записал, ответь ровно одним словом: ок. Не пересказывай задачи, не перечисляй, что ещё горит, не напоминай о других делах: он только что сам об этом написал. Пиши больше, только если он сам спросил или без уточнения никак.
- Пункты с пометкой (по желанию) не упоминай, пока пользователь сам не спросит про них («что по желанию?»). Сроков у них нет. Улучшения, без которых работа и так готова, добавляй как пункты по желанию.
- Не советуй, что и как делать, и не перепроверяй то, что отмечено сделанным, если пользователь сам не спросил. Твоя работа — помнить задачи, а не руководить.
- Важное (несоответствие, ошибка, риск) пиши одной строкой с ❗ в начале, без объяснений и рассуждений. Не превращай такие неясности в пункты «уточнить» и не проси пользователя их выяснять. Например: «❗ Время начала маскарада разное в постах: 20:00 и 21:00». Все сообщения максимально коротко: только суть.
- Когда просят идеи, предлагай конкретные: тема, формат (пост, сторис, Reels), зацепка. С учётом задач и событий из списка.
- Не придумывай, что задача выполнена, если пользователь этого не сказал.

Номера задач (#12) нужны только тебе для инструментов, пользователю их не показывай.
PROMPT;
    }

    /** The current time and the task list, sent with every turn. */
    private static function context(): string
    {
        $now = Hastan_Settings::now();
        $days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];
        $lines = ['[Состояние на сейчас: ' . $days[(int) $now->format('w')] . ', ' . $now->format('d.m.Y H:i') . ']'];
        $lines[] = self::render_tasks(Hastan_DB::open_tasks(), 'Открытые задачи');
        $lines[] = Hastan_Habits::describe($now);
        $lines[] = Hastan_Food::describe($now);
        $done = Hastan_DB::recently_done(24);
        if ($done) {
            $lines[] = self::render_tasks($done, 'Сделано за последние сутки');
        }
        return implode("\n", $lines);
    }

    public static function render_tasks(array $tasks, string $heading): string
    {
        if (!$tasks) {
            return "{$heading}: нет.";
        }
        $out = ["{$heading}:"];
        foreach ($tasks as $task) {
            $due = Hastan_DB::utc_to_local($task['due_at']);
            $project = !empty($task['project']) ? "[{$task['project']}] " : '';
            $out[] = "#{$task['id']} {$project}{$task['title']}" . ($due ? ' — срок ' . $due->format('d.m') : ' — без срока')
                . ($task['notes'] ? " (заметка: {$task['notes']})" : '');
            foreach (Hastan_DB::items((int) $task['id']) as $item) {
                if ((int) $item['optional'] && $item['status'] !== 'open') {
                    continue;
                }
                $mark = $item['status'] === 'done' ? 'x' : ' ';
                $out[] = "   #{$item['id']} [{$mark}] " . ((int) $item['optional'] ? '(по желанию) ' : '') . $item['title'];
            }
        }
        return implode("\n", $out);
    }

    public static function tools(): array
    {
        $templates = array_keys(Hastan_Templates::all());
        return [
            [
                'name' => 'add_task',
                'description' => 'Создаёт задачу. Если указан шаблон, к ней добавляется его чек-лист.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Короткое название, например «Пост: открытие сезона»'],
                        'project' => ['type' => ['string', 'null'], 'description' => 'Проект, к которому относится задача (например «lotus» или «lotus site»), или null'],
                        'due' => ['type' => ['string', 'null'], 'description' => 'Срок, только дата YYYY-MM-DD, или null'],
                        'template' => ['type' => ['string', 'null'], 'description' => 'Ключ шаблона (' . implode(', ', $templates) . ') или null'],
                        'notes' => ['type' => ['string', 'null'], 'description' => 'Подробности, если есть'],
                        'extra_items' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Дополнительные обязательные пункты чек-листа сверх шаблона'],
                        'optional_items' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Пункты «по желанию»: улучшения, без которых работа всё равно готова'],
                    ],
                    'required' => ['title', 'project', 'due', 'template', 'notes', 'extra_items', 'optional_items'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'update_task',
                'description' => 'Меняет задачу или пункт чек-листа: статус (open, done, cancelled), срок, название, заметку, пометку «по желанию» для пункта. Поля, которые не меняются, передай как null.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'status' => ['type' => ['string', 'null'], 'description' => 'open, done, cancelled или null'],
                        'due' => ['type' => ['string', 'null'], 'description' => 'Новый срок, только дата YYYY-MM-DD'],
                        'title' => ['type' => ['string', 'null']],
                        'notes' => ['type' => ['string', 'null']],
                        'optional' => ['type' => ['boolean', 'null'], 'description' => 'Только для пункта: true — «по желанию», false — обязательный, null — не менять'],
                    ],
                    'required' => ['id', 'status', 'due', 'title', 'notes', 'optional'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'update_items',
                'description' => 'Отмечает пункты чек-листа сделанными (done=true) или снова открытыми (done=false). Когда сделаны все пункты, задача закрывается сама.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'item_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'done' => ['type' => 'boolean'],
                    ],
                    'required' => ['item_ids', 'done'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'mark_habits',
                'description' => 'Отмечает привычки сделанными (done=true) или снимает отметку (done=false) в указанные дни.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'marks' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'habit_id' => ['type' => 'integer'],
                                    'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                                ],
                                'required' => ['habit_id', 'date'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'done' => ['type' => 'boolean'],
                    ],
                    'required' => ['marks', 'done'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'log_food',
                'description' => 'Записывает съеденное. Название бери точно как в базе продуктов, если продукт там есть. Для нового продукта укажи обычную калорийность на 100 г (и обычную порцию для штучных), он сохранится в базу как оценка.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => ['type' => 'string'],
                                    'grams' => ['type' => ['integer', 'null'], 'description' => 'Вес в граммах; null — обычная порция'],
                                    'kcal_per_100g_if_new' => ['type' => ['number', 'null'], 'description' => 'Только для продукта, которого нет в базе'],
                                    'portion_g_if_new' => ['type' => ['integer', 'null'], 'description' => 'Обычная порция в граммах для нового штучного продукта (банан, яйцо)'],
                                ],
                                'required' => ['name', 'grams', 'kcal_per_100g_if_new', 'portion_g_if_new'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'date' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD, если не сегодня'],
                    ],
                    'required' => ['items', 'date'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'save_food',
                'description' => 'Добавляет продукт или блюдо в базу или исправляет его калорийность (например, по этикетке). Для своего блюда можно указать ккал на 100 г и порцию.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'kcal_per_100g' => ['type' => 'number'],
                        'portion_g' => ['type' => ['integer', 'null']],
                    ],
                    'required' => ['name', 'kcal_per_100g', 'portion_g'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'delete_food_entries',
                'description' => 'Удаляет ошибочные записи о еде по их номерам (#id из списка «Съедено сегодня»).',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['ids' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                    'required' => ['ids'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'save_idea',
                'description' => 'Сохраняет идею пользователя (не задачу) в отдельный раздел «Идеи» на сайте. Напоминаний по идеям нет.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'description' => 'Короткий выразительный заголовок'],
                        'topic' => ['type' => 'string', 'description' => 'Тема одним-двумя словами: работа, сайт, контент, личное, бизнес и т.п.'],
                        'body' => ['type' => 'string', 'description' => 'Идея, оформленная аккуратно: первая строка — суть одним предложением, дальше детали строками с тире. Только мысли пользователя, без своих добавлений.'],
                        'original' => ['type' => 'string', 'description' => 'Сообщение пользователя дословно'],
                    ],
                    'required' => ['title', 'topic', 'body', 'original'],
                    'additionalProperties' => false,
                ],
            ],
            [
                'name' => 'add_items',
                'description' => 'Добавляет пункты в чек-лист существующей задачи.',
                'strict' => true,
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => ['type' => 'integer'],
                        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'optional' => ['type' => 'boolean', 'description' => 'true — все эти пункты «по желанию»: не обязательны, без сроков, исчезают вместе с задачей'],
                    ],
                    'required' => ['task_id', 'items', 'optional'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    private static function run_tool(string $id, string $name, array $input): array
    {
        try {
            $content = self::execute($name, $input);
            $error = false;
        } catch (InvalidArgumentException $e) {
            $content = 'Ошибка: ' . $e->getMessage();
            $error = true;
        }
        return ['type' => 'tool_result', 'toolUseID' => $id, 'content' => $content, 'isError' => $error];
    }

    /** The project a tool call is about: named directly or through its task. */
    private static function project_of(array $input): ?string
    {
        if (!empty($input['project'])) {
            return (string) $input['project'];
        }
        $id = (int) ($input['id'] ?? $input['task_id'] ?? ($input['item_ids'][0] ?? 0));
        $task = $id ? Hastan_DB::get_task($id) : null;
        if ($task && (int) $task['parent_id']) {
            $task = Hastan_DB::get_task((int) $task['parent_id']);
        }
        return $task['project'] ?? null;
    }

    /** Runs a tool and returns its result as text for Claude. */
    public static function execute(string $name, array $input, string $source = 'telegram'): string
    {
        if (Hastan_Settings::is_site_project(self::project_of($input))) {
            update_option('hastan_site_activity', time(), false); // Someone is working on the site.
        }
        switch ($name) {
            case 'list_tasks':
                $project = trim((string) ($input['project'] ?? ''));
                $filter = fn ($t) => $project === '' || mb_strtolower($t['project']) === mb_strtolower($project);
                $now = Hastan_Settings::now();
                return '[Сейчас ' . $now->format('d.m.Y H:i') . "]\n"
                    . self::render_tasks(array_values(array_filter(Hastan_DB::open_tasks(), $filter)), 'Открытые задачи') . "\n"
                    . self::render_tasks(array_values(array_filter(Hastan_DB::recently_done(72), $filter)), 'Сделано за 3 дня');

            case 'add_task':
                $title = trim((string) ($input['title'] ?? ''));
                if ($title === '') {
                    throw new InvalidArgumentException('пустое название');
                }
                $template = $input['template'] ?? null;
                if ($template !== null && !Hastan_Templates::get($template)) {
                    throw new InvalidArgumentException('нет шаблона ' . $template);
                }
                $task_id = Hastan_DB::create_task([
                    'title' => $title,
                    'notes' => $input['notes'] ?? null,
                    'template' => $template ?? '',
                    'due_at' => Hastan_Settings::is_site_project($input['project'] ?? null) ? null : Hastan_DB::local_to_utc($input['due'] ?? null),
                    'project' => trim((string) ($input['project'] ?? '')),
                    'source' => $source,
                ]);
                $items = $template ? (Hastan_Templates::get($template)['items'] ?? []) : [];
                $items = array_merge($items, (array) ($input['extra_items'] ?? []));
                foreach (array_values($items) as $pos => $item) {
                    Hastan_DB::create_task(['parent_id' => $task_id, 'title' => (string) $item, 'position' => $pos, 'source' => $source]);
                }
                $pos = count($items);
                foreach ((array) ($input['optional_items'] ?? []) as $item) {
                    Hastan_DB::create_task(['parent_id' => $task_id, 'title' => (string) $item, 'position' => $pos++, 'optional' => true, 'source' => $source]);
                }
                return "Создано.\n" . self::render_tasks([Hastan_DB::get_task($task_id)], 'Задача');

            case 'update_task':
                $task = Hastan_DB::get_task((int) ($input['id'] ?? 0));
                if (!$task) {
                    throw new InvalidArgumentException('задача не найдена');
                }
                $fields = [];
                if (!empty($input['status'])) {
                    if (!in_array($input['status'], ['open', 'done', 'cancelled'], true)) {
                        throw new InvalidArgumentException('неизвестный статус ' . $input['status']);
                    }
                    $fields['status'] = $input['status'];
                }
                if (!empty($input['due']) && !Hastan_Settings::is_site_project(self::project_of(['id' => $task['id']]))) {
                    $fields['due_at'] = Hastan_DB::local_to_utc($input['due']);
                }
                if (!empty($input['title'])) {
                    $fields['title'] = $input['title'];
                }
                if (isset($input['notes'])) {
                    $fields['notes'] = $input['notes'];
                }
                if (isset($input['optional']) && (int) $task['parent_id']) {
                    $fields['optional'] = $input['optional'] ? 1 : 0;
                }
                if (in_array($fields['status'] ?? '', ['done', 'cancelled'], true) && !(int) $task['parent_id']) {
                    Hastan_DB::close_task((int) $task['id'], $fields['status']);
                    unset($fields['status']);
                }
                if ($fields) {
                    Hastan_DB::update_task((int) $task['id'], $fields);
                }
                if ((int) $task['parent_id']) {
                    Hastan_DB::maybe_close_parent((int) $task['parent_id']);
                }
                return 'Готово.';

            case 'update_items':
                $parents = [];
                foreach ((array) ($input['item_ids'] ?? []) as $item_id) {
                    $item = Hastan_DB::get_task((int) $item_id);
                    if (!$item) {
                        continue;
                    }
                    Hastan_DB::update_task((int) $item_id, ['status' => !empty($input['done']) ? 'done' : 'open']);
                    if ((int) $item['parent_id']) {
                        $parents[(int) $item['parent_id']] = true;
                    }
                }
                $closed = [];
                foreach (array_keys($parents) as $parent_id) {
                    if (Hastan_DB::maybe_close_parent($parent_id)) {
                        $closed[] = Hastan_DB::get_task($parent_id)['title'];
                    }
                }
                return $closed ? 'Готово. Задачи закрыты целиком: ' . implode(', ', $closed) : 'Готово.';

            case 'mark_habits':
                $count = 0;
                foreach ((array) ($input['marks'] ?? []) as $mark) {
                    $date = (string) ($mark['date'] ?? '');
                    if (Hastan_Habits::get((int) ($mark['habit_id'] ?? 0)) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        Hastan_Habits::mark((int) $mark['habit_id'], $date, !empty($input['done']));
                        $count++;
                    }
                }
                return "Готово, отметок: {$count}.";

            case 'log_food':
                return Hastan_Food::log_tool($input);

            case 'save_food':
                $food = Hastan_Food::find((string) ($input['name'] ?? ''));
                $data = ['name' => $input['name'], 'kcal' => $input['kcal_per_100g'] ?? 0, 'portion_g' => $input['portion_g'] ?? null];
                if ($food) {
                    $data += ['protein' => $food['protein'], 'fat' => $food['fat'], 'carbs' => $food['carbs']];
                }
                Hastan_Food::save($data, $food ? (int) $food['id'] : 0);
                return 'Сохранено в базе.';

            case 'delete_food_entries':
                foreach ((array) ($input['ids'] ?? []) as $id) {
                    Hastan_Food::delete_entry((int) $id);
                }
                return 'Удалено. Сегодня ' . round(Hastan_Food::total(Hastan_Settings::now()->format('Y-m-d'))) . ' из ' . Hastan_Food::limit();

            case 'save_idea':
                if (trim((string) ($input['title'] ?? '')) === '' || trim((string) ($input['body'] ?? '')) === '') {
                    throw new InvalidArgumentException('нужны заголовок и текст');
                }
                Hastan_DB::add_idea($input);
                return 'Идея сохранена.';

            case 'add_items':
                $task = Hastan_DB::get_task((int) ($input['task_id'] ?? 0));
                if (!$task) {
                    throw new InvalidArgumentException('задача не найдена');
                }
                $pos = count(Hastan_DB::items((int) $task['id']));
                $optional = !empty($input['optional']);
                foreach ((array) ($input['items'] ?? []) as $item) {
                    Hastan_DB::create_task(['parent_id' => (int) $task['id'], 'title' => (string) $item, 'position' => $pos++, 'optional' => $optional, 'source' => $source]);
                }
                if ($task['status'] === 'done' && !$optional) {
                    Hastan_DB::update_task((int) $task['id'], ['status' => 'open']);
                }
                return 'Готово.';
        }
        throw new InvalidArgumentException("неизвестный инструмент {$name}");
    }
}
