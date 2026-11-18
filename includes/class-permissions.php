<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permission requests from Claude Code on the computer: a hook posts what Claude
 * wants to do, the bot shows it with «Разрешить / Запретить» buttons, and the hook
 * polls until the answer comes. Requests live in transients for an hour.
 */
class Hastan_Permissions
{
    const TTL = HOUR_IN_SECONDS;

    public static function init(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('hastan/v1', '/ask/(?P<key>[A-Za-z0-9]+)', [
                'methods' => 'POST',
                'callback' => [self::class, 'create'],
                'permission_callback' => '__return_true',
            ]);
            register_rest_route('hastan/v1', '/ask/(?P<key>[A-Za-z0-9]+)/(?P<id>[a-z0-9]+)', [
                'methods' => ['GET', 'POST'],
                'callback' => [self::class, 'status'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function url(): string
    {
        if ((string) Hastan_Settings::get('ask_key') === '') {
            Hastan_Settings::ensure_secrets(); // Sites installed before this feature have no key yet.
        }
        return rest_url('hastan/v1/ask/' . Hastan_Settings::get('ask_key'));
    }

    private static function allowed(WP_REST_Request $request): bool
    {
        $key = (string) Hastan_Settings::get('ask_key');
        return $key !== '' && hash_equals($key, (string) $request['key']);
    }

    private static function get(string $id): ?array
    {
        $req = get_transient('hastan_perm_' . $id);
        return is_array($req) ? $req : null;
    }

    private static function put(string $id, array $req): void
    {
        set_transient('hastan_perm_' . $id, $req, self::TTL);
    }

    /** POST {project, tool, input}: sends the question to the bot, returns {id}. */
    public static function create(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::allowed($request)) {
            return new WP_REST_Response(['error' => 'not found'], 404);
        }
        $params = $request->get_json_params() ?: $request->get_params();
        $project = mb_substr(trim((string) ($params['project'] ?? '')), 0, 60) ?: 'Claude Code';
        $tool = mb_substr(trim((string) ($params['tool'] ?? '')), 0, 80);
        $input = $params['input'] ?? [];

        // A plain notice (Claude asks on the computer, the bot only lets you know): one line, no buttons.
        if (!empty($params['notify']) || in_array(mb_strtolower($tool), ['', 'уведомление', 'notification'], true)) {
            Hastan_Telegram::send('🔐 ' . $project . ' просит разрешение');
            return new WP_REST_Response(['id' => '', 'status' => 'sent'], 200);
        }
        $text = '🔐 ' . $project . ' просит разрешение' . "\n\n" . self::describe($tool, is_array($input) ? $input : ['' => (string) $input]);

        $id = strtolower(wp_generate_password(12, false));
        $sent = Hastan_Telegram::api('sendMessage', [
            'chat_id' => Hastan_Settings::get('chat_id'),
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => Hastan_Telegram::keyboard([[['✅ Разрешить', 'perm:' . $id . ':a'], ['⛔ Запретить', 'perm:' . $id . ':d']]]),
        ]);
        if (!$sent) {
            return new WP_REST_Response(['error' => 'telegram'], 502);
        }
        self::put($id, ['status' => 'pending', 'text' => $text, 'message_id' => (int) ($sent['message_id'] ?? 0)]);
        return new WP_REST_Response(['id' => $id], 200);
    }

    /** GET: {status}. POST {expire: true}: the hook gave up waiting, the buttons go away. */
    public static function status(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::allowed($request)) {
            return new WP_REST_Response(['error' => 'not found'], 404);
        }
        $id = (string) $request['id'];
        $req = self::get($id);
        if (!$req) {
            return new WP_REST_Response(['status' => 'expired'], 200);
        }
        if ($request->get_method() === 'POST' && $req['status'] === 'pending') {
            $req['status'] = 'expired';
            self::put($id, $req);
            self::close_message($req, '⌛ Ответа не было, решите на компьютере');
        }
        return new WP_REST_Response(['status' => $req['status']], 200);
    }

    /** A tap on «Разрешить» or «Запретить». */
    public static function answer(array $cb, string $data): void
    {
        [$id, $choice] = array_pad(explode(':', $data, 2), 2, '');
        $req = self::get($id);
        if (!$req || $req['status'] !== 'pending') {
            Hastan_Telegram::api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Запрос уже неактуален']);
            Hastan_Telegram::api('editMessageReplyMarkup', [
                'chat_id' => $cb['message']['chat']['id'],
                'message_id' => $cb['message']['message_id'],
                'reply_markup' => ['inline_keyboard' => []],
            ]);
            return;
        }
        $req['status'] = $choice === 'a' ? 'allow' : 'deny';
        self::put($id, $req);
        Hastan_Telegram::api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        self::close_message($req, $req['status'] === 'allow' ? '✅ Разрешено' : '⛔ Запрещено');
    }

    private static function close_message(array $req, string $note): void
    {
        if (empty($req['message_id'])) {
            return;
        }
        Hastan_Telegram::api('editMessageText', [
            'chat_id' => Hastan_Settings::get('chat_id'),
            'message_id' => $req['message_id'],
            'text' => mb_substr($req['text'], 0, 3900) . "\n\n" . $note,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /** What Claude wants to do, in a few readable lines. */
    private static function describe(string $tool, array $input): string
    {
        $cut = fn ($s, $n = 1500) => mb_strimwidth(trim((string) $s), 0, $n, '…');
        switch ($tool) {
            case 'Bash':
            case 'PowerShell':
                $out = 'Команда: ' . $cut($input['command'] ?? '');
                if (!empty($input['description'])) {
                    $out .= "\n(" . $cut($input['description'], 200) . ')';
                }
                return $out;
            case 'Write':
                return 'Создать или перезаписать файл: ' . $cut($input['file_path'] ?? '', 300);
            case 'Edit':
            case 'MultiEdit':
            case 'NotebookEdit':
                return 'Изменить файл: ' . $cut($input['file_path'] ?? $input['notebook_path'] ?? '', 300);
            case 'Read':
                return 'Прочитать файл: ' . $cut($input['file_path'] ?? '', 300);
            case 'WebFetch':
                return 'Открыть страницу: ' . $cut($input['url'] ?? '', 300);
            case 'WebSearch':
                return 'Поиск в интернете: ' . $cut($input['query'] ?? '', 300);
        }
        $json = wp_json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ($tool !== '' ? $tool : 'Действие') . ($json && $json !== '[]' && $json !== '{}' ? ': ' . $cut($json, 800) : '');
    }
}
