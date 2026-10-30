<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Telegram bot: the webhook that receives messages and the calls that send them.
 */
class Hastan_Telegram
{
    public static function init(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('hastan/v1', '/telegram', [
                'methods' => 'POST',
                'callback' => [self::class, 'webhook'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    public static function webhook_url(): string
    {
        return rest_url('hastan/v1/telegram');
    }

    /** Calls a Bot API method and returns the decoded "result", or null on failure. */
    public static function api(string $method, array $params = [])
    {
        $token = Hastan_Settings::get('telegram_token');
        if (!$token) {
            return null;
        }
        $response = wp_remote_post("https://api.telegram.org/bot{$token}/{$method}", [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($params),
        ]);
        if (is_wp_error($response)) {
            error_log('Hastan Telegram ' . $method . ': ' . $response->get_error_message());
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['ok'])) {
            error_log('Hastan Telegram ' . $method . ': ' . wp_remote_retrieve_body($response));
            return null;
        }
        return $body['result'];
    }

    /**
     * Sends text to the linked chat. $buttons is a list of rows, each a list of
     * [text, callback_data] pairs.
     */
    public static function send(string $text, array $buttons = []): bool
    {
        $chat_id = Hastan_Settings::get('chat_id');
        if (!$chat_id || trim($text) === '') {
            return false;
        }
        $chunks = self::split($text, 4000);
        $ok = true;
        foreach ($chunks as $i => $chunk) {
            $params = ['chat_id' => $chat_id, 'text' => $chunk, 'link_preview_options' => ['is_disabled' => true]];
            if ($buttons && $i === count($chunks) - 1) {
                $params['reply_markup'] = ['inline_keyboard' => array_map(
                    fn ($row) => array_map(fn ($b) => ['text' => $b[0], 'callback_data' => $b[1]], $row),
                    $buttons
                )];
            }
            $ok = self::api('sendMessage', $params) !== null && $ok;
        }
        return $ok;
    }

    private static function split(string $text, int $size): array
    {
        $chunks = [];
        while (mb_strlen($text) > $size) {
            $cut = mb_strrpos(mb_substr($text, 0, $size), "\n") ?: $size;
            $chunks[] = mb_substr($text, 0, $cut);
            $text = ltrim(mb_substr($text, $cut));
        }
        $chunks[] = $text;
        return $chunks;
    }

    public static function set_webhook(): bool
    {
        delete_option('hastan_last_update_id'); // A new bot counts its updates from its own start.
        return self::api('setWebhook', [
            'url' => self::webhook_url(),
            'secret_token' => Hastan_Settings::get('telegram_secret'),
            'allowed_updates' => ['message', 'callback_query'],
            'drop_pending_updates' => true,
        ]) !== null;
    }

    public static function webhook(WP_REST_Request $request): WP_REST_Response
    {
        $secret = Hastan_Settings::get('telegram_secret');
        if (!$secret || !hash_equals($secret, (string) $request->get_header('x_telegram_bot_api_secret_token'))) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $update = $request->get_json_params();
        if (!is_array($update) || !isset($update['update_id'])) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        // Telegram retries an update it did not get an answer to in time; handle each once.
        $last = (int) get_option('hastan_last_update_id', 0);
        if ((int) $update['update_id'] <= $last) {
            return new WP_REST_Response(['ok' => true], 200);
        }
        update_option('hastan_last_update_id', (int) $update['update_id'], false);

        // Answer Telegram at once and think after the response is sent.
        add_action('shutdown', function () use ($update) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(180);
            self::handle_update($update);
        });
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handle_update(array $update): void
    {
        if (isset($update['callback_query'])) {
            self::handle_callback($update['callback_query']);
            return;
        }
        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }
        $chat_id = (string) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        if (str_starts_with($text, '/start')) {
            self::link_chat($chat_id, trim(substr($text, 6)));
            return;
        }
        if ($chat_id !== (string) Hastan_Settings::get('chat_id')) {
            return; // A private bot: everyone else is ignored.
        }
        if (isset($message['voice']) || isset($message['audio'])) {
            self::send('Голосовые я пока не понимаю. Нажмите микрофон на клавиатуре телефона и надиктуйте текстом — так сработает.');
            return;
        }
        if ($text === '') {
            return;
        }

        // A like shows the message arrived; text follows only when there is something to say.
        self::api('setMessageReaction', [
            'chat_id' => $chat_id,
            'message_id' => $message['message_id'],
            'reaction' => [['type' => 'emoji', 'emoji' => '👍']],
        ]);
        $reply = Hastan_Brain::reply($text);
        if (!in_array(mb_strtolower(trim($reply, " \n.!")), ['', 'ок', 'ok'], true)) {
            self::send($reply);
        }
    }

    private static function link_chat(string $chat_id, string $code): void
    {
        $linked = (string) Hastan_Settings::get('chat_id');
        if ($linked === $chat_id) {
            self::send('Я на связи. Пишите задачи как есть, например: «в субботу день открытых дверей, нужен пост».');
            return;
        }
        if ($linked !== '' || $code === '' || !hash_equals((string) Hastan_Settings::get('link_code'), strtolower($code))) {
            self::api('sendMessage', ['chat_id' => $chat_id, 'text' => 'Это личный помощник. Чтобы подключиться, отправьте /start и код со страницы настроек Hastan.']);
            return;
        }
        Hastan_Settings::set(['chat_id' => $chat_id]);
        self::send("Готово, Hastan подключён к этому чату.\n\nПишите сюда задачи обычными словами, например: «к пятнице пост про открытие сезона». Я разложу их по чек-листам, утром пришлю план, а вечером спрошу, что сделано.");
    }

    private static function handle_callback(array $cb): void
    {
        $chat_id = (string) ($cb['message']['chat']['id'] ?? '');
        if ($chat_id !== (string) Hastan_Settings::get('chat_id')) {
            return;
        }
        [$action, $id] = array_pad(explode(':', (string) ($cb['data'] ?? ''), 2), 2, '');
        $task = Hastan_DB::get_task((int) $id);
        if (!$task) {
            self::api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Задача не найдена']);
            return;
        }

        if ($action === 'done') {
            Hastan_DB::close_task((int) $id, 'done');
            $note = "Отмечено как сделанное: {$task['title']}";
        } elseif ($action === 'tomorrow') {
            $tomorrow = Hastan_Settings::now()->modify('+1 day');
            $due = Hastan_DB::utc_to_local($task['due_at']);
            $new = $due && $due->modify('+1 day') > $tomorrow ? $due->modify('+1 day') : $tomorrow;
            Hastan_DB::update_task((int) $id, ['due_at' => Hastan_DB::local_to_utc($new->format('Y-m-d'))]);
            $note = "Перенесено на {$new->format('d.m')}: {$task['title']}";
        } else {
            return;
        }

        self::api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => $note]);
        Hastan_DB::log('user', "[кнопка] {$note}");
        self::send($note);
    }

    /** One row of buttons for a task. */
    public static function task_buttons(array $task): array
    {
        return [['✅ ' . mb_strimwidth($task['title'], 0, 28, '…'), 'done:' . $task['id']], ['➡️ Завтра', 'tomorrow:' . $task['id']]];
    }
}
