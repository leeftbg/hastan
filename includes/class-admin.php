<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress admin: the task board, habits, ideas and the settings page.
 */
class Hastan_Admin
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_post_hastan_settings', [self::class, 'save_settings']);
        add_action('admin_post_hastan_action', [self::class, 'handle_action']);
        add_action('admin_post_hastan_idea', [self::class, 'handle_idea']);
        add_action('admin_post_hastan_habit_toggle', [self::class, 'toggle_habit']);
        add_action('admin_post_hastan_habits', [self::class, 'save_habits']);
    }

    public static function menu(): void
    {
        add_menu_page('Hastan', 'Hastan', 'manage_options', 'hastan', [self::class, 'board_page'], 'dashicons-yes-alt', 3);
        add_submenu_page('hastan', 'Задачи', 'Задачи', 'manage_options', 'hastan', [self::class, 'board_page']);
        add_submenu_page('hastan', 'Привычки', 'Привычки', 'manage_options', 'hastan-habits', [self::class, 'habits_page']);
        add_submenu_page('hastan', 'Идеи', 'Идеи', 'manage_options', 'hastan-ideas', [self::class, 'ideas_page']);
        add_submenu_page('hastan', 'Настройки Hastan', 'Настройки', 'manage_options', 'hastan-settings', [self::class, 'settings_page']);
    }

    private static function check(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Нет доступа');
        }
        check_admin_referer($action);
    }

    private static function back(string $page, string $notice = ''): void
    {
        wp_safe_redirect(add_query_arg(['page' => $page, 'hastan_notice' => rawurlencode($notice)], admin_url('admin.php')));
        exit;
    }

    private static function notice(): void
    {
        if (!empty($_GET['hastan_notice'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(wp_unslash($_GET['hastan_notice'])) . '</p></div>';
        }
    }

    /* ---------- habits ---------- */

    /** The paper table: days down, habits across, squares coloured red and black. */
    public static function habits_page(): void
    {
        $habits = Hastan_Habits::all();
        $today = Hastan_Settings::now()->setTime(0, 0);
        $days = [];
        for ($i = 27; $i >= 0; $i--) {
            $days[] = $today->modify("-{$i} days");
        }
        $marks = Hastan_Habits::marks($days[0]->format('Y-m-d'), $today->format('Y-m-d'));
        echo '<div class="wrap"><h1>Hastan: привычки</h1>';
        self::notice();
        echo '<style>.hastan-sheet{background:#fff;padding:24px 28px;display:inline-block;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.12);overflow-x:auto;max-width:100%}
.hastan-grid{border-collapse:collapse;font-family:"Segoe Print","Comic Sans MS",cursive}
.hastan-grid th{height:150px;vertical-align:bottom;padding:0 0 6px;font-weight:400;font-size:13px}
.hastan-grid th span{writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap}
.hastan-grid td{width:26px;height:22px;border:1px solid #b9c6d3;padding:0}.hastan-grid td.d{border:0;padding-right:8px;text-align:right;font-size:13px;width:auto;white-space:nowrap}
.hastan-grid td a{display:block;width:100%;height:100%}.hastan-grid td.r{background:#d7262e}.hastan-grid td.b{background:#1b1b1f}
.hastan-grid tr.today td.d{font-weight:700}
.hastan-habits-form td{padding:4px 8px 4px 0}</style>';
        if ($habits) {
            echo '<div class="hastan-sheet"><table class="hastan-grid"><tr><th></th>';
            foreach ($habits as $habit) {
                echo '<th><span>' . esc_html($habit['name']) . '</span></th>';
            }
            echo '</tr>';
            foreach ($days as $r => $day) {
                $key = $day->format('Y-m-d');
                echo '<tr' . ($key === $today->format('Y-m-d') ? ' class="today"' : '') . '><td class="d">' . esc_html($day->format('d.m')) . '</td>';
                foreach ($habits as $c => $habit) {
                    $done = !empty($marks[(int) $habit['id']][$key]);
                    $url = wp_nonce_url(admin_url('admin-post.php?action=hastan_habit_toggle&habit=' . (int) $habit['id'] . '&day=' . $key), 'hastan_habit_toggle');
                    echo '<td' . ($done ? ' class="' . (($r + $c) % 2 ? 'b' : 'r') . '"' : '') . '><a href="' . esc_url($url) . '" title="' . esc_attr($habit['name'] . ', ' . $day->format('d.m')) . '"></a></td>';
                }
                echo '</tr>';
            }
            echo '</table></div>';
            echo '<p class="description">Нажмите на клетку, чтобы закрасить или стереть. Отмечать можно и в Telegram: кнопками в вечернем сообщении, словами («шея, силовые») или фото бумажной таблицы.</p>';
        }

        echo '<h2>Список и ритм</h2><p class="description">Ритм — не реже чем раз в столько дней. Если привычка выпадет из ритма, утром придёт одна строка «давно не было», один раз.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('hastan_habits');
        echo '<input type="hidden" name="action" value="hastan_habits"><table class="hastan-habits-form"><tr><th>Привычка</th><th>Ритм, дней</th><th>Удалить</th></tr>';
        foreach (Hastan_Habits::all() as $habit) {
            $id = (int) $habit['id'];
            echo '<tr><td><input type="text" name="habit[' . $id . '][name]" value="' . esc_attr($habit['name']) . '" class="regular-text"></td>'
                . '<td><input type="number" min="1" max="60" name="habit[' . $id . '][every_days]" value="' . (int) $habit['every_days'] . '" style="width:70px"></td>'
                . '<td><input type="checkbox" name="habit[' . $id . '][delete]" value="1"></td></tr>';
        }
        echo '<tr><td><input type="text" name="new[name]" placeholder="Новая привычка" class="regular-text"></td>'
            . '<td><input type="number" min="1" max="60" name="new[every_days]" value="2" style="width:70px"></td><td></td></tr></table>';
        echo '<p><button class="button button-primary">Сохранить</button></p></form></div>';
    }

    public static function toggle_habit(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Нет доступа');
        }
        check_admin_referer('hastan_habit_toggle');
        $id = (int) ($_GET['habit'] ?? 0);
        $day = (string) ($_GET['day'] ?? '');
        if (Hastan_Habits::get($id) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
            Hastan_Habits::mark($id, $day, !Hastan_Habits::is_done($id, $day));
        }
        self::back('hastan-habits');
    }

    public static function save_habits(): void
    {
        self::check('hastan_habits');
        $position = 0;
        foreach ((array) ($_POST['habit'] ?? []) as $id => $row) {
            $row = wp_unslash($row);
            if (!empty($row['delete'])) {
                Hastan_Habits::delete((int) $id);
                continue;
            }
            $name = sanitize_text_field($row['name'] ?? '');
            if ($name !== '') {
                Hastan_Habits::save(['name' => $name, 'every_days' => (int) ($row['every_days'] ?? 1), 'position' => $position++], (int) $id);
            }
        }
        $new = wp_unslash((array) ($_POST['new'] ?? []));
        $name = sanitize_text_field($new['name'] ?? '');
        if ($name !== '') {
            Hastan_Habits::save(['name' => $name, 'every_days' => (int) ($new['every_days'] ?? 1), 'position' => $position]);
        }
        self::back('hastan-habits', 'Сохранено.');
    }

    /* ---------- ideas ---------- */

    public static function ideas_page(): void
    {
        $archive = ($_GET['view'] ?? '') === 'archive';
        $ideas = Hastan_DB::ideas($archive ? 'archived' : 'new');
        $action_url = admin_url('admin-post.php');
        $base = admin_url('admin.php?page=hastan-ideas');
        echo '<div class="wrap"><h1>Hastan: идеи</h1>';
        self::notice();
        echo '<style>.hastan-ideas{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;max-width:1200px;margin-top:16px}
.hastan-idea{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:8px}
.hastan-idea h3{margin:0;font-size:16px;line-height:1.35}.hastan-idea-meta{display:flex;gap:8px;align-items:center;color:#646970;font-size:12px}
.hastan-topic{background:#f0f6fc;color:#2271b1;border-radius:20px;padding:2px 10px;font-weight:600}
.hastan-idea-body{font-size:14px;line-height:1.55;white-space:pre-line}
.hastan-idea details{color:#646970;font-size:12px}.hastan-idea-actions{margin-top:auto;display:flex;gap:6px}</style>';
        echo '<p><a href="' . esc_url($base) . '"' . ($archive ? '' : ' style="font-weight:600"') . '>Новые</a> | '
            . '<a href="' . esc_url($base . '&view=archive') . '"' . ($archive ? ' style="font-weight:600"' : '') . '>Архив</a></p>';
        if (!$ideas) {
            echo '<p>' . ($archive ? 'В архиве пусто.' : 'Идей пока нет. Напишите боту, например: «идея: сделать рубрику с рецептами коктейлей».') . '</p>';
        }
        echo '<div class="hastan-ideas">';
        foreach ($ideas as $idea) {
            $date = Hastan_DB::utc_to_local($idea['created_at']);
            echo '<div class="hastan-idea"><div class="hastan-idea-meta">'
                . ($idea['topic'] !== '' ? '<span class="hastan-topic">' . esc_html($idea['topic']) . '</span>' : '')
                . '<span>' . esc_html($date->format('d.m.Y')) . '</span></div>'
                . '<h3>' . esc_html($idea['title']) . '</h3>'
                . '<div class="hastan-idea-body">' . esc_html($idea['body']) . '</div>';
            if (!empty($idea['original'])) {
                echo '<details><summary>Как было сказано</summary>' . esc_html($idea['original']) . '</details>';
            }
            echo '<div class="hastan-idea-actions">';
            $ops = $archive ? ['restore' => 'Вернуть', 'delete' => 'Удалить'] : ['archive' => 'В архив', 'delete' => 'Удалить'];
            foreach ($ops as $op => $label) {
                echo '<form method="post" action="' . esc_url($action_url) . '">';
                wp_nonce_field('hastan_idea');
                echo '<input type="hidden" name="action" value="hastan_idea"><input type="hidden" name="op" value="' . $op . '">'
                    . '<input type="hidden" name="id" value="' . (int) $idea['id'] . '">'
                    . '<button class="button button-small"' . ($op === 'delete' ? ' onclick="return confirm(\'Удалить идею?\')"' : '') . '>' . $label . '</button></form>';
            }
            echo '</div></div>';
        }
        echo '</div></div>';
    }

    public static function handle_idea(): void
    {
        self::check('hastan_idea');
        $id = (int) ($_POST['id'] ?? 0);
        switch (sanitize_key($_POST['op'] ?? '')) {
            case 'archive':
                Hastan_DB::set_idea_status($id, 'archived');
                break;
            case 'restore':
                Hastan_DB::set_idea_status($id, 'new');
                break;
            case 'delete':
                Hastan_DB::delete_idea($id);
                break;
        }
        self::back('hastan-ideas');
    }

    /* ---------- task board ---------- */

    public static function board_page(): void
    {
        $tasks = Hastan_DB::open_tasks();
        $action_url = admin_url('admin-post.php');
        echo '<div class="wrap"><h1>Hastan: задачи</h1>';
        self::notice();
        echo '<style>.hastan-task{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 16px;margin:12px 0;max-width:760px}
.hastan-task h3{margin:0 0 4px}.hastan-meta{color:#646970;margin-bottom:8px}.hastan-items{margin:0 0 8px}.hastan-items li{margin:2px 0}
.hastan-done{color:#8c8f94;text-decoration:line-through}.hastan-inline{display:inline}.hastan-overdue{color:#b32d2e;font-weight:600}</style>';

        if (!$tasks) {
            echo '<p>Открытых задач нет. Напишите помощнику в Telegram или добавьте задачу ниже.</p>';
        }
        $now_utc = gmdate('Y-m-d H:i:s');
        foreach ($tasks as $task) {
            $due = Hastan_DB::utc_to_local($task['due_at']);
            echo '<div class="hastan-task"><h3>' . esc_html($task['title']) . '</h3><div class="hastan-meta">';
            if ($due) {
                $class = $task['due_at'] < $now_utc ? ' class="hastan-overdue"' : '';
                echo '<span' . $class . '>Срок: ' . esc_html($due->format('d.m.Y')) . '</span>';
            } else {
                echo 'Без срока';
            }
            if ($task['notes']) {
                echo ' · ' . esc_html($task['notes']);
            }
            echo '</div><ul class="hastan-items">';
            foreach (Hastan_DB::items((int) $task['id']) as $item) {
                if ($item['status'] === 'cancelled') {
                    continue;
                }
                $done = $item['status'] === 'done';
                echo '<li><form class="hastan-inline" method="post" action="' . esc_url($action_url) . '">';
                wp_nonce_field('hastan_action');
                echo '<input type="hidden" name="action" value="hastan_action"><input type="hidden" name="op" value="toggle">'
                    . '<input type="hidden" name="id" value="' . (int) $item['id'] . '">'
                    . '<label><input type="checkbox" onchange="this.form.submit()"' . checked($done, true, false) . '> '
                    . '<span' . ($done ? ' class="hastan-done"' : '') . '>' . esc_html($item['title']) . '</span>'
                    . ((int) $item['optional'] ? ' <em style="color:#888">по желанию</em>' : '') . '</label></form></li>';
            }
            echo '</ul>';
            foreach (['done' => 'Готово целиком', 'cancel' => 'Отменить'] as $op => $label) {
                echo '<form class="hastan-inline" method="post" action="' . esc_url($action_url) . '">';
                wp_nonce_field('hastan_action');
                echo '<input type="hidden" name="action" value="hastan_action"><input type="hidden" name="op" value="' . $op . '">'
                    . '<input type="hidden" name="id" value="' . (int) $task['id'] . '">'
                    . '<button class="button' . ($op === 'done' ? ' button-primary' : '') . '">' . esc_html($label) . '</button></form> ';
            }
            echo '</div>';
        }

        echo '<h2>Добавить задачу</h2><form method="post" action="' . esc_url($action_url) . '">';
        wp_nonce_field('hastan_action');
        echo '<input type="hidden" name="action" value="hastan_action"><input type="hidden" name="op" value="add">'
            . '<p><input type="text" name="title" class="regular-text" placeholder="Например: Пост про открытие сезона" required> '
            . '<input type="datetime-local" name="due"> <select name="template"><option value="">Без шаблона</option>';
        foreach (Hastan_Templates::all() as $key => $tpl) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($tpl['name']) . '</option>';
        }
        echo '</select> <button class="button button-primary">Добавить</button></p></form></div>';
    }

    public static function handle_action(): void
    {
        self::check('hastan_action');
        $op = sanitize_key($_POST['op'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        switch ($op) {
            case 'toggle':
                $item = Hastan_DB::get_task($id);
                if ($item) {
                    Hastan_DB::update_task($id, ['status' => $item['status'] === 'done' ? 'open' : 'done']);
                    Hastan_DB::maybe_close_parent((int) $item['parent_id']);
                }
                break;
            case 'done':
            case 'cancel':
                Hastan_DB::close_task($id, $op === 'done' ? 'done' : 'cancelled');
                break;
            case 'add':
                $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
                if ($title !== '') {
                    $input = [
                        'title' => $title,
                        'due' => str_replace('T', ' ', sanitize_text_field(wp_unslash($_POST['due'] ?? ''))) ?: null,
                        'template' => sanitize_key($_POST['template'] ?? '') ?: null,
                        'notes' => null,
                        'extra_items' => [],
                    ];
                    Hastan_Brain::execute('add_task', $input);
                }
                break;
        }
        self::back('hastan');
    }

    /* ---------- settings ---------- */

    public static function settings_page(): void
    {
        Hastan_Settings::ensure_secrets();
        $s = Hastan_Settings::all();
        $url = admin_url('admin-post.php');
        $tz = wp_timezone_string();
        $cron_url = site_url('wp-cron.php');

        echo '<div class="wrap"><h1>Hastan: настройки</h1>';
        self::notice();

        echo '<h2>Подключение</h2><table class="widefat striped" style="max-width:760px"><tbody>';
        echo '<tr><td>Telegram-бот</td><td>' . ($s['telegram_token'] ? 'токен сохранён' : '<b>нужен токен</b> (шаг 1 ниже)') . '</td></tr>';
        echo '<tr><td>Чат</td><td>';
        if ($s['chat_id']) {
            echo 'подключён';
        } else {
            echo 'не подключён. Сохраните токен, нажмите «Подключить бота», затем откройте бота в Telegram и отправьте ему:<br><code>/start ' . esc_html($s['link_code']) . '</code>';
        }
        echo '</td></tr>';
        echo '<tr><td>Коннектор для Claude</td><td><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr(Hastan_MCP::url()) . '"><br>'
            . 'Добавьте этот адрес в Claude: Настройки → Коннекторы → Добавить свой коннектор. Никому его не показывайте: по нему можно читать и менять ваши задачи.</td></tr>';
        echo '<tr><td>Claude API</td><td>' . ($s['anthropic_key'] ? 'ключ сохранён' : '<b>нужен ключ</b>') . '</td></tr>';
        echo '<tr><td>Часовой пояс сайта</td><td>' . esc_html($tz) . ' (сейчас ' . esc_html(Hastan_Settings::now()->format('H:i')) . '). Поменять: <a href="' . esc_url(admin_url('options-general.php')) . '">Настройки → Общие</a></td></tr>';
        echo '</tbody></table>';

        echo '<p>';
        foreach (['webhook' => 'Подключить бота', 'unlink' => 'Отвязать чат', 'new_mcp_key' => 'Сменить адрес коннектора', 'test_morning' => 'Прислать утренний план сейчас', 'test_evening' => 'Прислать вечернюю проверку сейчас'] as $op => $label) {
            echo '<form style="display:inline" method="post" action="' . esc_url($url) . '">';
            wp_nonce_field('hastan_settings');
            echo '<input type="hidden" name="action" value="hastan_settings"><input type="hidden" name="op" value="' . $op . '">'
                . '<button class="button">' . esc_html($label) . '</button></form> ';
        }
        echo '</p>';

        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('hastan_settings');
        echo '<input type="hidden" name="action" value="hastan_settings"><input type="hidden" name="op" value="save">';
        echo '<table class="form-table"><tbody>';
        self::field('Токен Telegram-бота', '<input type="password" name="telegram_token" class="regular-text" autocomplete="off" placeholder="' . ($s['telegram_token'] ? 'сохранён, оставьте пустым' : 'от @BotFather') . '">');
        self::field('Ключ Claude API', '<input type="password" name="anthropic_key" class="regular-text" autocomplete="off" placeholder="' . ($s['anthropic_key'] ? 'сохранён, оставьте пустым' : 'sk-ant-…') . '">');
        self::field('Модель Claude', '<input type="text" name="model" value="' . esc_attr($s['model']) . '" class="regular-text">');
        $effort = '';
        foreach (['low' => 'низкая, дешевле и быстрее', 'medium' => 'средняя', 'high' => 'высокая'] as $k => $label) {
            $effort .= '<option value="' . $k . '"' . selected($s['effort'], $k, false) . '>' . esc_html($label) . '</option>';
        }
        self::field('Глубина размышлений', '<select name="effort">' . $effort . '</select>');
        self::field('Утренний план', '<input type="time" name="morning_time" value="' . esc_attr($s['morning_time']) . '">');
        self::field('Вечерняя проверка', '<input type="time" name="evening_time" value="' . esc_attr($s['evening_time']) . '">');
        self::field('Проекты сайта', '<input type="text" name="site_projects" value="' . esc_attr($s['site_projects']) . '" class="regular-text"><p class="description">Через запятую. У задач этих проектов нет сроков; если по ним долго нет работы, утром придёт напоминание.</p>');
        self::field('Напомнить про сайт после, часов', '<input type="number" min="24" max="720" name="site_idle_hours" value="' . (int) $s['site_idle_hours'] . '">');
        self::field('О вас и вашей работе', '<textarea name="about" rows="3" class="large-text">' . esc_textarea($s['about']) . '</textarea><p class="description">Помощник учитывает это в ответах и идеях.</p>');
        foreach (Hastan_Templates::all() as $key => $tpl) {
            self::field('Шаблон «' . esc_html($tpl['name']) . '»', '<textarea name="tpl_' . esc_attr($key) . '" rows="6" class="large-text">' . esc_textarea(implode("\n", $tpl['items'])) . '</textarea><p class="description">Один пункт чек-листа на строку.</p>');
        }
        echo '</tbody></table>';
        submit_button('Сохранить');
        echo '</form>';

        echo '<h2>Расписание на сервере</h2><p>Чтобы утреннее и вечернее сообщения приходили, даже когда на сайт никто не заходит, добавьте в FastPanel задание cron с запуском <b>раз в час</b>:</p>'
            . '<p><code>wget -q -O /dev/null ' . esc_html($cron_url) . '</code></p></div>';
    }

    private static function field(string $label, string $html): void
    {
        echo '<tr><th scope="row">' . $label . '</th><td>' . $html . '</td></tr>';
    }

    public static function save_settings(): void
    {
        self::check('hastan_settings');
        $op = sanitize_key($_POST['op'] ?? '');
        $now = Hastan_Settings::now();

        switch ($op) {
            case 'webhook':
                if (!Hastan_Settings::get('telegram_token')) {
                    self::back('hastan-settings', 'Сначала сохраните токен бота.');
                }
                self::back('hastan-settings', Hastan_Telegram::set_webhook()
                    ? 'Бот подключён. Теперь отправьте ему /start с кодом из таблицы.'
                    : 'Telegram не принял подключение. Проверьте токен и что сайт открывается по https.');
                // no break: back() exits
            case 'unlink':
                Hastan_Settings::set(['chat_id' => '', 'link_code' => '']);
                Hastan_Settings::ensure_secrets();
                self::back('hastan-settings', 'Чат отвязан. Для нового подключения используйте новый код.');
                // no break
            case 'new_mcp_key':
                Hastan_Settings::set(['mcp_key' => '']);
                Hastan_Settings::ensure_secrets();
                self::back('hastan-settings', 'Адрес коннектора сменён. Старый больше не работает: обновите его в настройках Claude.');
                // no break
            case 'test_morning':
                Hastan_Scheduler::morning($now, true);
                self::back('hastan-settings', 'Утреннее сообщение отправлено (если было что сказать).');
                // no break
            case 'test_evening':
                Hastan_Scheduler::evening($now, true);
                self::back('hastan-settings', 'Вечернее сообщение отправлено (если у событий на сегодня-завтра что-то не сделано).');
                // no break
            case 'save':
                $values = [
                    'model' => sanitize_text_field(wp_unslash($_POST['model'] ?? '')) ?: 'claude-opus-5-5',
                    'effort' => in_array($_POST['effort'] ?? '', ['low', 'medium', 'high'], true) ? $_POST['effort'] : 'low',
                    'morning_time' => preg_match('/^\d{2}:\d{2}$/', $_POST['morning_time'] ?? '') ? $_POST['morning_time'] : '10:00',
                    'evening_time' => preg_match('/^\d{2}:\d{2}$/', $_POST['evening_time'] ?? '') ? $_POST['evening_time'] : '20:00',
                    'about' => sanitize_textarea_field(wp_unslash($_POST['about'] ?? '')),
                    'site_projects' => sanitize_text_field(wp_unslash($_POST['site_projects'] ?? '')),
                    'site_idle_hours' => max(24, min(720, (int) ($_POST['site_idle_hours'] ?? 72))),
                ];
                foreach (['telegram_token', 'anthropic_key'] as $secret) {
                    $value = trim(sanitize_text_field(wp_unslash($_POST[$secret] ?? '')));
                    if ($value !== '') {
                        $values[$secret] = $value;
                    }
                }
                Hastan_Settings::set($values);
                foreach (array_keys(Hastan_Templates::all()) as $key) {
                    if (isset($_POST['tpl_' . $key])) {
                        Hastan_Templates::save_items($key, explode("\n", sanitize_textarea_field(wp_unslash($_POST['tpl_' . $key]))));
                    }
                }
                self::back('hastan-settings', 'Сохранено.');
        }
        self::back('hastan-settings');
    }
}
