<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress admin: the task board, habits, goals, food, ideas and the settings page.
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
        add_action('admin_post_hastan_picture', [self::class, 'save_picture']);
        add_action('admin_post_hastan_food', [self::class, 'save_food']);
        add_action('admin_post_hastan_goal', [self::class, 'handle_goal']);
    }

    public static function menu(): void
    {
        add_menu_page('Hastan', 'Hastan', 'manage_options', 'hastan', [self::class, 'board_page'], 'dashicons-yes-alt', 3);
        add_submenu_page('hastan', 'Задачи', 'Задачи', 'manage_options', 'hastan', [self::class, 'board_page']);
        add_submenu_page('hastan', 'Привычки', 'Привычки', 'manage_options', 'hastan-habits', [self::class, 'habits_page']);
        add_submenu_page('hastan', 'Цели', 'Цели', 'manage_options', 'hastan-goals', [self::class, 'goals_page']);
        add_submenu_page('hastan', 'Питание', 'Питание', 'manage_options', 'hastan-food', [self::class, 'food_page']);
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
        // From the day the picture started (or the habits were added), at most 60 days back.
        $state = Hastan_Picture::state();
        $first = !empty($state['start'])
            ? new DateTimeImmutable($state['start'], $today->getTimezone())
            : ($habits ? min(array_map(fn ($h) => Hastan_DB::utc_to_local($h['created_at'])->setTime(0, 0), $habits)) : $today);
        $first = max($first, $today->modify('-59 days'));
        $days = [];
        for ($day = $first; $day <= $today; $day = $day->modify('+1 day')) {
            $days[] = $day;
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
        self::picture_section();
        if ($habits) {
            echo '<h2>Таблица</h2><div class="hastan-sheet"><table class="hastan-grid"><tr><th></th>';
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
            echo '<p class="description">Нажмите на клетку, чтобы закрасить или стереть. Отмечать можно и в Telegram: словами («шея, силовые») или фото бумажной таблицы.</p>';
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

    /** The mosaic: open pieces in colour, closed ones only as faint outlines on paper. */
    private static function picture_section(): void
    {
        echo '<h2>Картинка</h2>';
        $action = esc_url(admin_url('admin-post.php'));
        if (Hastan_Picture::exists()) {
            $progress = Hastan_Picture::progress();
            $meta = Hastan_Picture::meta();
            echo '<div class="hastan-sheet" style="padding:12px"><canvas id="hastan-picture" style="max-width:100%;height:auto;display:block"></canvas></div>';
            echo '<p>';
            foreach ($progress as $zone) {
                echo '<span style="display:inline-block;margin-right:18px">' . esc_html($zone['name']) . ': ' . count($zone['open']) . ' из ' . (int) $zone['total'] . '</span>';
            }
            echo '</p>';
            $data = wp_json_encode([
                'picture' => Hastan_Picture::url('picture.jpg'),
                'tiles' => Hastan_Picture::url('tiles.png'),
                'open' => Hastan_Picture::open_ids(),
                'paper' => Hastan_Picture::PAPER,
            ]);
            echo "<script>(function(d){var c=document.getElementById('hastan-picture'),n=2,img=new Image(),t=new Image();
function go(){if(--n)return;var w=t.width,h=t.height;c.width=w;c.height=h;var x=c.getContext('2d',{willReadFrequently:true});
x.drawImage(t,0,0);var ti=x.getImageData(0,0,w,h).data;x.drawImage(img,0,0,w,h);var out=x.getImageData(0,0,w,h),p=out.data,o={};
d.open.forEach(function(i){o[i]=1});for(var y=0;y<h;y++)for(var k=0;k<w;k++){var j=(y*w+k)*4,id=ti[j]+ti[j+1]*256;if(o[id])continue;
var e=(k>0&&ti[j-4]+ti[j-3]*256!==id)||(y>0&&ti[j-w*4]+ti[j-w*4+1]*256!==id);p[j]=d.paper[0]-(e?17:0);p[j+1]=d.paper[1]-(e?19:0);p[j+2]=d.paper[2]-(e?23:0);}
x.putImageData(out,0,0);}img.onload=go;t.onload=go;img.src=d.picture;t.src=d.tiles;})({$data});</script>";

            echo '<h3>Какая часть за что отвечает</h3><form method="post" action="' . $action . '">';
            wp_nonce_field('hastan_picture');
            echo '<input type="hidden" name="action" value="hastan_picture"><input type="hidden" name="op" value="map"><table class="hastan-habits-form">';
            $habits = Hastan_Habits::all();
            foreach ($meta['zones'] as $i => $zone) {
                echo '<tr><td><b>' . esc_html($zone['name']) . '</b></td><td>';
                foreach ($habits as $habit) {
                    $on = in_array((int) $habit['id'], $progress[$i]['habits'], true);
                    echo '<label style="margin-right:12px"><input type="checkbox" name="map[' . $i . '][]" value="' . (int) $habit['id'] . '"' . checked($on, true, false) . '> ' . esc_html($habit['name']) . '</label>';
                }
                echo '</td></tr>';
            }
            echo '</table><p><button class="button">Сохранить</button></p></form>';
        } else {
            echo '<p>Картинки пока нет. Загрузите архив с картинкой, который подготовил Claude.</p>';
        }
        echo '<form method="post" action="' . $action . '" enctype="multipart/form-data">';
        wp_nonce_field('hastan_picture');
        echo '<input type="hidden" name="action" value="hastan_picture"><input type="hidden" name="op" value="upload">'
            . '<input type="file" name="pack" accept=".zip"> <button class="button">' . (Hastan_Picture::exists() ? 'Загрузить новую картинку (начнётся заново)' : 'Загрузить') . '</button></form>';
    }

    private static function movement_today(): string
    {
        $day = Hastan_Settings::now()->format('Y-m-d');
        $parts = [];
        if (($move = Hastan_Steps::get_move($day)) !== null) {
            $parts[] = $move . ' из ' . Hastan_Steps::move_goal() . ' ккал подвижности';
        }
        if ($steps = Hastan_Steps::get($day)) {
            $parts[] = $steps . ' шагов';
        }
        return $parts ? ' Сегодня пришло: ' . implode(', ', $parts) . '.' : '';
    }

    public static function save_picture(): void
    {
        self::check('hastan_picture');
        if (($_POST['op'] ?? '') === 'map') {
            $map = [];
            foreach ((array) ($_POST['map'] ?? []) as $i => $ids) {
                $map[(int) $i] = array_map('intval', (array) $ids);
            }
            $meta = Hastan_Picture::meta();
            foreach (array_keys($meta['zones'] ?? []) as $i) {
                $map[$i] = $map[$i] ?? [];
            }
            ksort($map);
            Hastan_Picture::set_map($map);
            self::back('hastan-habits', 'Сохранено.');
        }
        $file = $_FILES['pack']['tmp_name'] ?? '';
        if (!$file || !is_uploaded_file($file)) {
            self::back('hastan-habits', 'Файл не загрузился.');
        }
        $error = Hastan_Picture::install_pack($file);
        self::back('hastan-habits', $error ?? 'Картинка загружена. Отметки считаются с сегодняшнего дня.');
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

    /* ---------- food ---------- */

    public static function food_page(): void
    {
        $today = Hastan_Settings::now();
        $day = $today->format('Y-m-d');
        $limit = Hastan_Food::limit();
        $action = esc_url(admin_url('admin-post.php'));
        $fmt = [Hastan_Food::class, 'num'];
        echo '<div class="wrap"><h1>Hastan: питание</h1>';
        self::notice();
        echo '<style>.hastan-food td,.hastan-food th{padding:4px 8px 4px 0;text-align:left}.hastan-food input[type=number]{width:80px}
.hastan-bar{height:10px;background:#e5e5e5;border-radius:5px;max-width:420px;overflow:hidden}.hastan-bar i{display:block;height:100%;background:#2e7d32}.hastan-bar i.over{background:#d7262e}
.hastan-est{color:#996800;font-size:12px}</style>';

        $total = Hastan_Food::total($day);
        echo '<h2>Сегодня: ' . round($total) . ' из ' . $limit . ' ккал</h2>';
        echo '<div class="hastan-bar"><i class="' . ($total > $limit ? 'over' : '') . '" style="width:' . min(100, round($total / $limit * 100)) . '%"></i></div>';
        $entries = Hastan_Food::entries($day);
        if ($entries) {
            echo '<table class="hastan-food" style="margin-top:8px">';
            foreach ($entries as $e) {
                echo '<tr><td>' . esc_html($e['name']) . '</td><td>' . (int) $e['grams'] . ' г</td><td>' . round($e['kcal']) . ' ккал</td><td><form method="post" action="' . $action . '">';
                wp_nonce_field('hastan_food');
                echo '<input type="hidden" name="action" value="hastan_food"><input type="hidden" name="op" value="delete_entry"><input type="hidden" name="id" value="' . (int) $e['id'] . '"><button class="button-link">убрать</button></form></td></tr>';
            }
            echo '</table>';
        } else {
            echo '<p>Сегодня пока ничего не записано. Напишите боту, например: «каша 100 г, банан».</p>';
        }

        $totals = Hastan_Food::totals($today->modify('-13 days')->format('Y-m-d'), $day);
        if ($totals) {
            echo '<h2>Последние 2 недели</h2><table class="hastan-food">';
            foreach (array_reverse($totals, true) as $d => $kcal) {
                $over = $kcal > $limit;
                echo '<tr><td>' . esc_html(substr($d, 8, 2) . '.' . substr($d, 5, 2)) . '</td><td' . ($over ? ' style="color:#d7262e;font-weight:600"' : '') . '>' . round($kcal) . ' ккал</td></tr>';
            }
            echo '</table>';
        }

        echo '<h2>Продукты</h2><p class="description">Калории и БЖУ на 100 г. Порция — для штучного и своих блюд: тогда боту можно писать без веса («банан», «мой салат»). Пометка «оценка» значит, что число подставил бот; поправьте по этикетке, и пометка уйдёт.</p>';
        echo '<form method="post" action="' . $action . '">';
        wp_nonce_field('hastan_food');
        echo '<input type="hidden" name="action" value="hastan_food"><input type="hidden" name="op" value="foods"><table class="hastan-food">'
            . '<tr><th>Продукт или блюдо</th><th>Ккал</th><th>Белки</th><th>Жиры</th><th>Углеводы</th><th>Порция, г</th><th>Удалить</th></tr>';
        $row = function (string $key, array $f) use ($fmt) {
            echo '<tr><td><input type="text" name="' . $key . '[name]" value="' . esc_attr($f['name'] ?? '') . '" class="regular-text">'
                . (!empty($f['estimated']) ? ' <span class="hastan-est">оценка</span>' : '') . '</td>';
            foreach (['kcal', 'protein', 'fat', 'carbs', 'portion_g'] as $field) {
                echo '<td><input type="number" step="0.1" min="0" name="' . $key . '[' . $field . ']" value="' . esc_attr($fmt($f[$field] ?? null)) . '"></td>';
            }
            echo '<td>' . (isset($f['id']) ? '<input type="checkbox" name="' . $key . '[delete]" value="1">' : '') . '</td></tr>';
        };
        foreach (Hastan_Food::foods() as $food) {
            $row('food[' . (int) $food['id'] . ']', $food);
        }
        for ($i = 0; $i < 8; $i++) {
            $row('new[' . $i . ']', []);
        }
        echo '</table><p><button class="button button-primary">Сохранить</button></p></form></div>';
    }

    public static function save_food(): void
    {
        self::check('hastan_food');
        if (($_POST['op'] ?? '') === 'delete_entry') {
            Hastan_Food::delete_entry((int) ($_POST['id'] ?? 0));
            self::back('hastan-food');
        }
        $clean = function (array $row): array {
            $row = wp_unslash($row);
            return array_map(fn ($v) => sanitize_text_field((string) $v), $row);
        };
        foreach ((array) ($_POST['food'] ?? []) as $id => $row) {
            $row = $clean((array) $row);
            $old = Hastan_Food::get((int) $id);
            if (!$old) {
                continue;
            }
            if (!empty($row['delete'])) {
                Hastan_Food::delete((int) $id);
                continue;
            }
            if ($row['name'] === '') {
                continue;
            }
            // A correction by hand makes the number the user's own.
            $changed = (float) $row['kcal'] !== (float) $old['kcal'];
            $row['estimated'] = $old['estimated'] && !$changed;
            Hastan_Food::save($row, (int) $id);
        }
        foreach ((array) ($_POST['new'] ?? []) as $row) {
            $row = $clean((array) $row);
            if (($row['name'] ?? '') !== '' && ($row['kcal'] ?? '') !== '') {
                if ($existing = Hastan_Food::find($row['name'])) {
                    Hastan_Food::save($row, (int) $existing['id']);
                } else {
                    Hastan_Food::save($row);
                }
            }
        }
        self::back('hastan-food', 'Сохранено.');
    }

    /* ---------- goals ---------- */

    private static function goal_form(string $op, array $fields, string $button, string $extra = ''): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . wp_nonce_field('hastan_goal', '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="hastan_goal"><input type="hidden" name="op" value="' . esc_attr($op) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        return $html . $extra . $button . '</form>';
    }

    public static function goals_page(): void
    {
        $today = Hastan_Settings::now()->format('Y-m-d');
        echo '<div class="wrap"><h1>Hastan: цели</h1>';
        self::notice();
        echo '<style>.hastan-goals{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;max-width:1200px;margin-top:12px}
.hastan-goal{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:10px}
.hastan-goal.later{opacity:.75}.hastan-goal.done{background:#f6fbf6}
.hastan-goal h2{margin:0;font-size:17px}.hastan-goal-meta{color:#646970;font-size:12px}
.hastan-steps{margin:0;padding:0;list-style:none}.hastan-steps li{display:flex;align-items:center;gap:8px;padding:3px 0}
.hastan-steps button.tick{width:20px;height:20px;border:1.5px solid #8c8f94;border-radius:4px;background:#fff;cursor:pointer;padding:0;line-height:16px}
.hastan-steps li.done button.tick{background:#2271b1;border-color:#2271b1;color:#fff}.hastan-steps li.done span{color:#646970}
.hastan-steps .when{color:#8c8f94;font-size:12px}.hastan-steps .x{margin-left:auto;border:0;background:none;color:#b32d2e;cursor:pointer}
.hastan-notes{margin:0;padding:0;list-style:none;font-size:13px;max-height:220px;overflow:auto}.hastan-notes li{padding:3px 0;display:flex;gap:8px}
.hastan-notes .d{color:#646970;min-width:42px}.hastan-notes .x{margin-left:auto;border:0;background:none;color:#b32d2e;cursor:pointer}
.hastan-goal details summary{cursor:pointer;color:#2271b1;font-size:13px}.hastan-goal input[type=text]{max-width:100%}</style>';
        echo '<p class="description">Цели — это путь, а не ритм. Напоминаний по ним нет. Бот сам запишет, если вы скажете «сегодня ездила по двору» или «разобрала тему 3».</p>';
        echo '<div class="hastan-goals">';
        foreach (Hastan_Goals::all() as $goal) {
            $id = (int) $goal['id'];
            $steps = Hastan_Goals::steps($id);
            $done = count(array_filter($steps, fn ($s) => $s['done_on']));
            echo '<div class="hastan-goal ' . esc_attr($goal['status']) . '"><div><h2>' . esc_html($goal['title']) . '</h2><div class="hastan-goal-meta">'
                . esc_html(Hastan_Goals::STATUSES[$goal['status']] ?? '') . ($goal['due'] !== '' ? ' · ' . esc_html($goal['due']) : '')
                . ($steps ? " · шагов {$done} из " . count($steps) : '') . '</div></div>';

            echo '<ul class="hastan-steps">';
            foreach ($steps as $step) {
                $sid = (int) $step['id'];
                echo '<li' . ($step['done_on'] ? ' class="done"' : '') . '>'
                    . self::goal_form('step_toggle', ['step' => $sid], '<button class="tick" title="Отметить">' . ($step['done_on'] ? '✓' : '') . '</button>')
                    . '<span>' . esc_html($step['title']) . '</span>'
                    . ($step['done_on'] ? '<span class="when">' . esc_html(Hastan_Goals::short_date($step['done_on'])) . '</span>' : '')
                    . self::goal_form('step_delete', ['step' => $sid], '<button class="x" title="Удалить шаг" onclick="return confirm(\'Удалить шаг?\')">×</button>')
                    . '</li>';
            }
            echo '</ul>';
            echo self::goal_form('step_add', ['goal' => $id], ' <button class="button button-small">Добавить шаг</button>', '<input type="text" name="title" placeholder="Новый шаг" required>');

            $notes = Hastan_Goals::notes($id);
            echo '<div><strong style="font-size:13px">Дневник</strong>' . ($notes ? '' : ' <span class="hastan-goal-meta">пока пусто</span>') . '<ul class="hastan-notes">';
            foreach ($notes as $note) {
                echo '<li><span class="d">' . esc_html(Hastan_Goals::short_date($note['day'])) . '</span><span>' . esc_html($note['body']) . '</span>'
                    . self::goal_form('note_delete', ['note' => (int) $note['id']], '<button class="x" title="Удалить" onclick="return confirm(\'Удалить запись?\')">×</button>') . '</li>';
            }
            echo '</ul></div>';
            echo self::goal_form('note_add', ['goal' => $id], ' <button class="button button-small">Записать</button>',
                '<input type="date" name="day" value="' . esc_attr($today) . '"> <input type="text" name="body" placeholder="Что было" required>');

            $status = '';
            foreach (Hastan_Goals::STATUSES as $key => $label) {
                $status .= '<option value="' . $key . '"' . selected($goal['status'], $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '<details><summary>Изменить цель</summary><p>'
                . self::goal_form('save', ['goal' => $id], '<br><button class="button button-small">Сохранить</button>',
                    '<input type="text" name="title" value="' . esc_attr($goal['title']) . '" class="regular-text"><br>'
                    . '<input type="text" name="due" value="' . esc_attr($goal['due']) . '" placeholder="Срок словами, например «к лету»"> '
                    . '<select name="status">' . $status . '</select>')
                . ' ' . self::goal_form('delete', ['goal' => $id], '<button class="button button-small button-link-delete" onclick="return confirm(\'Удалить цель вместе с шагами и дневником?\')">Удалить</button>')
                . '</p></details></div>';
        }
        echo '</div>';
        echo '<h2>Новая цель</h2>' . self::goal_form('save', ['goal' => 0], ' <button class="button button-primary">Добавить</button>',
            '<input type="text" name="title" placeholder="Название" class="regular-text" required> <input type="text" name="due" placeholder="Срок словами">'
            . '<input type="hidden" name="status" value="active">');
        echo '</div>';
    }

    public static function handle_goal(): void
    {
        self::check('hastan_goal');
        $text = fn ($key) => sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        switch (sanitize_key($_POST['op'] ?? '')) {
            case 'save':
                $id = (int) ($_POST['goal'] ?? 0);
                $goal = $id ? Hastan_Goals::get($id) : null;
                if ($text('title') !== '') {
                    Hastan_Goals::save([
                        'title' => $text('title'),
                        'due' => $text('due'),
                        'status' => sanitize_key($_POST['status'] ?? 'active'),
                        'position' => $goal ? (int) $goal['position'] : count(Hastan_Goals::all()),
                    ], $goal ? $id : 0);
                }
                break;
            case 'delete':
                Hastan_Goals::delete((int) ($_POST['goal'] ?? 0));
                break;
            case 'step_add':
                if ($text('title') !== '' && Hastan_Goals::get((int) ($_POST['goal'] ?? 0))) {
                    Hastan_Goals::add_step((int) $_POST['goal'], $text('title'));
                }
                break;
            case 'step_toggle':
                global $wpdb;
                $step = (int) ($_POST['step'] ?? 0);
                $done = $wpdb->get_var($wpdb->prepare('SELECT done_on FROM ' . Hastan_Goals::steps_table() . ' WHERE id = %d', $step));
                Hastan_Goals::set_step($step, $done ? null : Hastan_Settings::now()->format('Y-m-d'));
                break;
            case 'step_delete':
                Hastan_Goals::delete_step((int) ($_POST['step'] ?? 0));
                break;
            case 'note_add':
                $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text('day')) ? $text('day') : Hastan_Settings::now()->format('Y-m-d');
                if ($text('body') !== '' && Hastan_Goals::get((int) ($_POST['goal'] ?? 0))) {
                    Hastan_Goals::add_note((int) $_POST['goal'], $day, $text('body'));
                }
                break;
            case 'note_delete':
                Hastan_Goals::delete_note((int) ($_POST['note'] ?? 0));
                break;
        }
        self::back('hastan-goals');
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
        echo '<tr><td>Адрес для шагов</td><td><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr(Hastan_Steps::url()) . '"><br>'
            . '<span class="description">Для автоматизации в «Командах» на iPhone. Держите в секрете.' . self::movement_today() . '</span></td></tr>';
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
        self::field('Цель подвижности, ккал', '<input type="number" min="10" max="3000" name="move_goal" value="' . (int) $s['move_goal'] . '"><p class="description">Как в кольце «Подвижность» на iPhone. Используется, если автоматизация отправляет активную энергию.</p>');
        self::field('Норма шагов в день', '<input type="number" min="100" max="50000" name="steps_goal" value="' . (int) $s['steps_goal'] . '">');
        self::field('Норма калорий в день', '<input type="number" min="500" max="6000" name="kcal_limit" value="' . (int) $s['kcal_limit'] . '">');
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
                    'move_goal' => max(10, min(3000, (int) ($_POST['move_goal'] ?? 360))),
                    'steps_goal' => max(100, min(50000, (int) ($_POST['steps_goal'] ?? 8000))),
                    'kcal_limit' => max(500, min(6000, (int) ($_POST['kcal_limit'] ?? 2000))),
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
