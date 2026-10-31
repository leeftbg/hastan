<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * MCP connector: lets Claude chats and projects read and update the task list.
 * Speaks MCP over Streamable HTTP (JSON responses only). The secret key in the
 * URL is the only credential, so the URL must stay private.
 */
class Hastan_MCP
{
    const PROTOCOL = '2025-06-18';

    public static function init(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('hastan/v1', '/mcp/(?P<key>[A-Za-z0-9]+)', [
                'methods' => ['POST', 'GET', 'DELETE'],
                'callback' => [self::class, 'handle'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function url(): string
    {
        return rest_url('hastan/v1/mcp/' . Hastan_Settings::get('mcp_key'));
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $key = (string) Hastan_Settings::get('mcp_key');
        if ($key === '' || !hash_equals($key, (string) $request['key'])) {
            return new WP_REST_Response(['error' => 'not found'], 404);
        }
        if ($request->get_method() !== 'POST') {
            return new WP_REST_Response(null, 405); // No server-initiated stream.
        }

        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_REST_Response(self::error(null, -32700, 'Parse error'), 400);
        }
        $batch = array_is_list($payload);
        $answers = [];
        foreach ($batch ? $payload : [$payload] as $message) {
            $answer = self::dispatch(is_array($message) ? $message : []);
            if ($answer !== null) {
                $answers[] = $answer;
            }
        }
        if (!$answers) {
            return new WP_REST_Response(null, 202); // Only notifications.
        }
        return new WP_REST_Response($batch ? $answers : $answers[0], 200);
    }

    private static function dispatch(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = (array) ($message['params'] ?? []);
        if (!array_key_exists('id', $message)) {
            return null; // A notification needs no answer.
        }

        switch ($method) {
            case 'initialize':
                $requested = (string) ($params['protocolVersion'] ?? '');
                return self::result($id, [
                    'protocolVersion' => $requested !== '' ? $requested : self::PROTOCOL,
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'hastan', 'title' => 'Hastan', 'version' => HASTAN_VERSION],
                    'instructions' => self::instructions(),
                ]);
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => self::tools()]);
            case 'tools/call':
                $name = (string) ($params['name'] ?? '');
                $known = array_column(self::tools(), 'name');
                if (!in_array($name, $known, true)) {
                    return self::error($id, -32602, "Unknown tool: {$name}");
                }
                try {
                    $text = Hastan_Brain::execute($name, (array) ($params['arguments'] ?? []), 'claude');
                    $is_error = false;
                } catch (InvalidArgumentException $e) {
                    $text = 'Ошибка: ' . $e->getMessage();
                    $is_error = true;
                }
                return self::result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $is_error]);
        }
        return self::error($id, -32601, "Method not found: {$method}");
    }

    private static function result($id, $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private static function instructions(): string
    {
        $site = implode(', ', Hastan_Settings::site_projects());
        return 'Hastan напоминает пользователю только о двух вещах: незакрытые пункты чек-листа событий и долгий перерыв в работе над сайтом. '
            . 'Новое событие (вечеринка, форум, концерт и т.п.): посмотри list_tasks и, если такого события ещё нет, создай add_task с template=event, '
            . 'project=lotus, due=дата события, title «Название события д.м». Пункты события: пост, сторис, Reels, реклама, событие на сайте. '
            . 'Отмечай пункт через update_items, как только он сделан в чате (готова сторис, готов Reels, готовы креативы) и разговор перешёл к другому. '
            . 'Публикацию не жди и не спрашивай: сделано в чате — значит сделано. '
            . 'Дополнительную работу по событию (ещё Reels, ещё реклама, правки) в задачи не добавляй. '
            . "Проекты сайта ({$site}): в начале работы вызови list_tasks с названием проекта, этим ты отмечаешь, что над сайтом работают. "
            . 'Сроков у задач сайта нет. Без расспросов, упоминай одной короткой строкой в конце ответа.';
    }

    /** The assistant's tools in MCP form, plus a read-only list. */
    private static function tools(): array
    {
        $tools = [[
            'name' => 'list_tasks',
            'title' => 'Задачи',
            'description' => 'Открытые задачи с чек-листами и сделанное за 3 дня. Можно отфильтровать по проекту.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['project' => ['type' => 'string', 'description' => 'Название проекта, например «lotus»; пусто — все']],
            ],
            'annotations' => ['readOnlyHint' => true],
        ]];
        foreach (Hastan_Brain::tools() as $tool) {
            $tools[] = ['name' => $tool['name'], 'description' => $tool['description'], 'inputSchema' => $tool['inputSchema']];
        }
        return $tools;
    }
}
