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

    /** What came from the phone in the last week, to see at a glance whether the automation ran. */
    private static function movement_today(): string
    {
        $rows = Hastan_Steps::recent(7);
        if (!$rows) {
            return ' За неделю с телефона ничего не приходило.';
        }
        $lines = [];
        foreach ($rows as $row) {
            $parts = [];
            if ($row['move_kcal'] !== null) {
                $parts[] = $row['move_kcal'] . ' из ' . Hastan_Steps::move_goal() . ' ккал';
            }
            if ((int) $row['steps']) {
                $parts[] = $row['steps'] . ' шагов';
            }
            $lines[] = Hastan_Goals::short_date($row['day']) . ': ' . implode(', ', $parts) . ' (пришло в ' . Hastan_DB::utc_to_local($row['updated_at'])->format('H:i') . ')';
        }
        return '<br>Пришло с телефона за неделю (дней без строки не было вовсе):<br>' . implode('<br>', array_map('esc_html', $lines));
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

    private static function goal_form(string $op, array $fields, string $button, string $extra = '', string $attrs = 'style="display:inline"'): string
    {
        $html = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" ' . $attrs . '>'
            . wp_nonce_field('hastan_goal', '_wpnonce', true, false)
            . '<input type="hidden" name="action" value="hastan_goal"><input type="hidden" name="op" value="' . esc_attr($op) . '">';
        foreach ($fields as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
        }
        return $html . $extra . $button . '</form>';
    }

    private static function goal_url(int $id = 0, string $anchor = ''): string
    {
        return admin_url('admin.php?page=hastan-goals' . ($id ? '&goal=' . $id : '')) . ($anchor !== '' ? '#' . $anchor : '');
    }

    /** Done share of a goal's leaf steps: [done, in progress, total]. */
    private static function goal_progress(int $id): array
    {
        $leaves = Hastan_Goals::leaves($id);
        $max = Hastan_Goals::max_state($id);
        $done = count(array_filter($leaves, fn ($s) => (int) $s['state'] >= $max));
        $doing = count(array_filter($leaves, fn ($s) => (int) $s['state'] > 0 && (int) $s['state'] < $max));
        return [$done, $doing, count($leaves)];
    }

    public static function goals_page(): void
    {
        $goal = !empty($_GET['goal']) ? Hastan_Goals::get((int) $_GET['goal']) : null;
        if ($goal && $goal['kind'] === 'course') {
            self::course_page($goal);
            return;
        }
        if ($goal && $goal['kind'] === 'exam') {
            self::exam_page($goal);
            return;
        }
        if ($goal && $goal['kind'] === 'turkish') {
            $drill = !empty($_GET['drill']) ? Hastan_Drills::get((int) $_GET['drill']) : null;
            if ($drill && (int) $drill['goal_id'] === (int) $goal['id']) {
                self::drill_page($goal, $drill);
            } else {
                self::turkish_page($goal);
            }
            return;
        }
        if ($goal) {
            self::goal_page($goal);
            return;
        }
        echo '<div class="wrap"><h1>Hastan: цели</h1>';
        self::notice();
        echo '<style>.hg-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px;max-width:1100px;margin-top:14px}
.hg-card{display:flex;flex-direction:column;gap:10px;background:#fff;border:1px solid #e2ddd2;border-radius:14px;padding:20px 22px;text-decoration:none;color:#1d2327;transition:box-shadow .15s,transform .15s}
.hg-card:hover{box-shadow:0 6px 20px rgba(60,50,30,.12);transform:translateY(-2px);color:#1d2327}
.hg-card h2{margin:0;font-size:19px;line-height:1.3}.hg-card .sub{color:#646970;font-size:13px}
.hg-card .chip{align-self:flex-start;font-size:11px;letter-spacing:.04em;text-transform:uppercase;border-radius:20px;padding:3px 10px;background:#eef4ee;color:#2f7d5b;font-weight:600}
.hg-card.later .chip{background:#f1efe9;color:#7a7466}.hg-card.later{opacity:.8}.hg-card.done .chip{background:#2f7d5b;color:#fff}
.hg-bar{height:8px;border-radius:8px;background:#efe9dc;overflow:hidden;display:flex}.hg-bar i{display:block;height:100%}
.hg-bar .d{background:#2f7d5b}.hg-bar .p{background:#e3a33b}.hg-card .meta{font-size:12px;color:#646970}</style>';
        echo '<p class="description">Цели — это путь, а не ритм. Напоминаний по ним нет. У каждой цели своя страница.</p><div class="hg-list">';
        foreach (Hastan_Goals::all() as $g) {
            [$done, $doing, $total] = self::goal_progress((int) $g['id']);
            $last = Hastan_Goals::notes((int) $g['id'], 1);
            echo '<a class="hg-card ' . esc_attr($g['status']) . '" href="' . esc_url(self::goal_url((int) $g['id'])) . '">'
                . '<span class="chip">' . esc_html(Hastan_Goals::STATUSES[$g['status']] ?? '') . ($g['due'] !== '' ? ' · ' . esc_html($g['due']) : '') . '</span>'
                . '<h2>' . esc_html($g['title']) . '</h2>' . ($g['subtitle'] !== '' ? '<div class="sub">' . esc_html($g['subtitle']) . '</div>' : '');
            if ($total) {
                echo '<div class="hg-bar"><i class="d" style="width:' . round($done / $total * 100, 1) . '%"></i><i class="p" style="width:' . round($doing / $total * 100, 1) . '%"></i></div>'
                    . '<div class="meta">готово ' . $done . ' из ' . $total . ($doing ? ', в процессе ' . $doing : '') . '</div>';
            }
            if ($g['kind'] === 'turkish') {
                $c = Hastan_Turkish::counts(Hastan_Turkish::entries((int) $g['id']));
                echo '<div class="meta">слов ' . $c['word'] . ' · фраз ' . $c['phrase'] . ' · заметок ' . $c['note'] . '</div>';
                $last = $last && $last[0]['tr'] !== '' ? [['day' => $last[0]['day'], 'body' => $last[0]['tr'] . ' — ' . $last[0]['body']]] : $last;
            }
            if ($last) {
                echo '<div class="meta">последняя запись ' . esc_html(Hastan_Goals::short_date($last[0]['day'])) . ': ' . esc_html(mb_strimwidth($last[0]['body'], 0, 60, '…')) . '</div>';
            }
            echo '</a>';
        }
        echo '</div><h2>Новая цель</h2>' . self::goal_form('save', ['goal' => 0], ' <button class="button button-primary">Добавить</button>',
            '<input type="text" name="title" placeholder="Название" class="regular-text" required> <input type="text" name="due" placeholder="Срок словами">'
            . '<input type="hidden" name="status" value="active">');
        echo '</div>';
    }

    /** Diary and goal settings, shared by both goal views. */
    private static function goal_footer(array $goal, array $labels): string
    {
        $id = (int) $goal['id'];
        $today = Hastan_Settings::now()->format('Y-m-d');
        $notes = Hastan_Goals::notes($id);
        $html = '<div class="hg-diary"><h2>' . esc_html($labels['diary']) . '</h2>';
        $html .= self::goal_form('note_add', ['goal' => $id], ' <button class="button">' . esc_html($labels['write']) . '</button>',
            '<input type="date" name="day" value="' . esc_attr($today) . '"> <input type="text" name="body" class="regular-text" placeholder="' . esc_attr($labels['what']) . '" required>', 'class="hg-note-form"');
        $html .= $notes ? '<ul>' : '<p class="description">' . esc_html($labels['empty']) . '</p>';
        foreach ($notes as $note) {
            $html .= '<li><span class="d">' . esc_html(Hastan_Goals::short_date($note['day'])) . '</span><span>' . esc_html($note['body']) . '</span>'
                . self::goal_form('note_delete', ['note' => (int) $note['id'], 'goal' => $id], '<button class="x" title="×" onclick="return confirm(\'?\')">×</button>') . '</li>';
        }
        $html .= ($notes ? '</ul>' : '') . '</div>';
        return $html . self::goal_edit($goal);
    }

    /** The goal's title, subtitle, due and status, folded away. */
    private static function goal_edit(array $goal): string
    {
        $id = (int) $goal['id'];
        $html = '';
        $status = '';
        foreach (Hastan_Goals::STATUSES as $key => $label) {
            $status .= '<option value="' . $key . '"' . selected($goal['status'], $key, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '<details class="hg-edit"><summary>Изменить цель</summary><p>'
            . self::goal_form('save', ['goal' => $id], '<br><button class="button button-small">Сохранить</button>',
                '<input type="text" name="title" value="' . esc_attr($goal['title']) . '" class="regular-text"><br>'
                . '<input type="text" name="subtitle" value="' . esc_attr($goal['subtitle']) . '" class="regular-text" placeholder="Подзаголовок"><br>'
                . '<input type="text" name="due" value="' . esc_attr($goal['due']) . '" placeholder="Срок словами"> '
                . '<select name="status">' . $status . '</select>')
            . ' ' . self::goal_form('delete', ['goal' => $id], '<button class="button button-small button-link-delete" onclick="return confirm(\'Удалить цель вместе с шагами и дневником?\')">Удалить цель</button>')
            . '</p></details>';
        return $html;
    }

    private static function goal_styles(): string
    {
        return '<style>.hg-back{display:inline-block;margin:10px 0 4px;text-decoration:none}
.hg-diary{max-width:900px;margin-top:28px}.hg-diary ul{margin:12px 0 0;padding:0;list-style:none}.hg-diary li{display:flex;gap:12px;padding:6px 0;border-bottom:1px solid #efe9dc}
.hg-diary .d{color:#8a8476;min-width:46px;font-variant-numeric:tabular-nums}.hg-diary .x,.hg-steps .x{margin-left:auto;border:0;background:none;color:#b32d2e;cursor:pointer;font-size:16px}
.hg-edit{margin:24px 0;max-width:900px}.hg-edit summary{cursor:pointer;color:#2271b1}
.hg-steps{list-style:none;margin:16px 0;padding:0;max-width:700px}.hg-steps li{display:flex;align-items:center;gap:12px;padding:10px 14px;background:#fff;border:1px solid #e2ddd2;border-radius:10px;margin-bottom:8px}
.hg-steps li.s2 span.t{color:#8a8476;text-decoration:line-through}.hg-steps .when{color:#8a8476;font-size:12px}
.hg-dot{width:22px;height:22px;border-radius:50%;border:2px solid #b8b0a0;background:#fff;cursor:pointer;padding:0;display:inline-flex;align-items:center;justify-content:center;font-size:12px;color:#fff;flex:none}
.s1 .hg-dot{border-color:#e3a33b;background:linear-gradient(90deg,#e3a33b 50%,#fff 50%)}.s2 .hg-dot{border-color:#2f7d5b;background:#2f7d5b}</style>';
    }

    /** An ordinary goal: a ladder of steps and a diary. */
    private static function goal_page(array $goal): void
    {
        $id = (int) $goal['id'];
        echo '<div class="wrap"><a class="hg-back" href="' . esc_url(self::goal_url()) . '">← Все цели</a><h1>' . esc_html($goal['title']) . '</h1>';
        self::notice();
        echo self::goal_styles();
        echo '<p class="description">' . esc_html(Hastan_Goals::STATUSES[$goal['status']] ?? '') . ($goal['due'] !== '' ? ' · ' . esc_html($goal['due']) : '')
            . ($goal['subtitle'] !== '' ? ' · ' . esc_html($goal['subtitle']) : '') . '</p>';
        echo '<ol class="hg-steps">';
        foreach (Hastan_Goals::steps($id) as $step) {
            $sid = (int) $step['id'];
            $state = (int) $step['state'];
            echo '<li class="s' . $state . '">'
                . self::goal_form('step_cycle', ['step' => $sid], '<button class="hg-dot" title="Отметить">' . ($state === 2 ? '✓' : '') . '</button>')
                . '<span class="t">' . esc_html($step['title']) . '</span>'
                . ($step['done_on'] ? '<span class="when">' . esc_html(Hastan_Goals::short_date($step['done_on'])) . '</span>' : '')
                . self::goal_form('step_delete', ['step' => $sid], '<button class="x" title="Удалить шаг" onclick="return confirm(\'Удалить шаг?\')">×</button>', '', 'style="margin-left:auto"')
                . '</li>';
        }
        echo '</ol>' . self::goal_form('step_add', ['goal' => $id], ' <button class="button">Добавить шаг</button>', '<input type="text" name="title" class="regular-text" placeholder="Новый шаг" required>');
        echo '<p class="description">Кружок: пусто → в процессе → готово → пусто.</p>';
        echo self::goal_footer($goal, ['diary' => 'Дневник', 'write' => 'Записать', 'what' => 'Что было', 'empty' => 'Пока пусто.']);
        echo '</div>';
    }

    private static function hours(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        return $h ? $h . ' ч' . ($m ? ' ' . $m . ' мин' : '') : $m . ' мин';
    }

    /** A video course: chalkboard hero with hours watched, the next lecture, then every lecture as a tile. */
    private static function course_page(array $goal): void
    {
        $id = (int) $goal['id'];
        $lectures = Hastan_Goals::steps($id);
        $states = ['не начата', 'смотрю', 'просмотрена', 'конспект готов'];
        $total = $watched = 0;
        $done = $doing = $notes = 0;
        $next = null;
        foreach ($lectures as &$l) {
            [$duration, $url] = array_pad(explode("\n", (string) $l['details'], 2), 2, '');
            $l['duration'] = $duration;
            $l['url'] = $url;
            $sec = Hastan_Goals::seconds($duration);
            $total += $sec;
            if ((int) $l['state'] >= 2) {
                $watched += $sec;
                $done++;
                $notes += (int) $l['state'] === 3 ? 1 : 0;
            } elseif ((int) $l['state'] === 1) {
                $doing++;
                $next = $next ?? $l;
            }
        }
        unset($l);
        if (!$next) {
            foreach ($lectures as $l) {
                if ((int) $l['state'] === 0) {
                    $next = $l;
                    break;
                }
            }
        }
        $count = count($lectures);
        $pct = $total ? round($watched / $total * 100) : 0;
        echo '<div class="wrap hc"><a class="hg-back" href="' . esc_url(self::goal_url()) . '">← Все цели</a>';
        self::notice();
        echo self::goal_styles();
        echo '<style>
.hc{--board:#2f4a3e;--board2:#3c5c4d;--chalk:#f4f1e8;--muted:#7a7466;--line:#e6dfd0;--done:#2f7d5b;--doing:#e3a33b;--empty:#e9e3d6;max-width:1180px}
.hc-hero{position:relative;overflow:hidden;display:flex;gap:28px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:radial-gradient(ellipse at 30% 20%,var(--board2),var(--board) 70%);color:var(--chalk);border-radius:18px;padding:30px 34px;margin:8px 0 18px;border:10px solid #6b4f36;box-shadow:inset 0 0 40px rgba(0,0,0,.25)}
.hc-hero h1{color:var(--chalk);font-size:32px;margin:0 0 6px;padding:0;font-family:"Segoe Print","Comic Sans MS",cursive;font-weight:400;letter-spacing:.01em}
.hc-hero .sub{opacity:.85;font-size:14px}.hc-hero .stats{margin-top:14px;display:flex;gap:18px;flex-wrap:wrap;font-size:13px;opacity:.9}
.hc-hero .stats b{font-size:18px;font-weight:600;margin-right:4px}
.hc-hero .psi{position:absolute;right:170px;bottom:-30px;font-size:160px;opacity:.06;font-family:Georgia,serif;pointer-events:none}
.hc-ring{position:relative;width:128px;height:128px;flex:none}.hc-ring svg{transform:rotate(-90deg)}
.hc-ring .n{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:26px;font-weight:600}
.hc-ring .n small{font-size:11px;font-weight:400;opacity:.8;margin-top:2px;text-align:center}
.hc-next{display:flex;align-items:center;gap:16px;background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 18px;margin-bottom:16px;flex-wrap:wrap}
.hc-next .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted)}.hc-next .tt{font-size:17px;font-weight:600;color:#1f2a37}
.hc-play{display:inline-flex;align-items:center;gap:8px;background:#c4302b;color:#fff!important;text-decoration:none;border-radius:24px;padding:9px 18px;font-weight:600;margin-left:auto}
.hc-play:hover{background:#a82722}
.hc-strip{display:grid;grid-template-columns:repeat(27,1fr);gap:4px;max-width:760px;margin:0 0 6px}.hc-strip a{aspect-ratio:1;border-radius:3px;background:var(--empty);display:block}
.hc-strip a.s1{background:var(--doing)}.hc-strip a.s2{background:#9fd0b6}.hc-strip a.s3{background:var(--done)}
.hc .s1 .hg-dot{border-color:var(--doing);background:conic-gradient(var(--doing) 0 120deg,#fff 120deg)}
.hc .s2 .hg-dot{border-color:var(--done);background:conic-gradient(var(--done) 0 240deg,#fff 240deg)}.hc .s3 .hg-dot{border-color:var(--done);background:var(--done)}
.hc-stage{font-size:11px;color:var(--muted);font-weight:400}
.hc-legend{display:flex;gap:16px;color:var(--muted);font-size:12px;margin-bottom:18px;flex-wrap:wrap}.hc-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.hc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px}
.hc-l{display:flex;gap:12px;align-items:flex-start;background:#fbf8f2;border:1px solid var(--line);border-radius:12px;padding:12px 14px;scroll-margin-top:50px}
.hc-l.s2{background:#f6fbf8;border-color:#cfe5d8}.hc-l.s3{background:#eef8f2;border-color:#9fc9b4}.hc-l.s1{border-color:#efc77f;background:#fffaf0}
.hc-l form{flex:none;margin:2px 0 0;line-height:0}
.hc-num{font-family:"Segoe Print","Comic Sans MS",cursive;font-size:12px;color:var(--muted)}
.hc-t{font-size:14px;line-height:1.35;color:#1f2a37;font-weight:500;margin:1px 0 4px}.hc-l.s3 .hc-t{color:var(--muted)}
.hc-meta{display:flex;gap:10px;align-items:center;font-size:12px;color:var(--muted)}
.hc-meta a{text-decoration:none;color:#c4302b;font-weight:600}
@media (max-width:600px){.hc-hero{padding:22px;border-width:7px}.hc-strip{grid-template-columns:repeat(18,1fr)}.hc-play{margin-left:0}}
</style>';
        $r = 54;
        $c = 2 * M_PI * $r;
        echo '<div class="hc-hero"><span class="psi">ψ</span><div><h1>' . esc_html($goal['title']) . '</h1>'
            . ($goal['subtitle'] !== '' ? '<div class="sub">' . esc_html($goal['subtitle']) . '</div>' : '')
            . '<div class="stats"><span><b>' . $done . '</b>из ' . $count . ' лекций</span><span><b>' . $notes . '</b>конспектов</span><span><b>' . esc_html(self::hours($watched)) . '</b>просмотрено</span>'
            . '<span><b>' . esc_html(self::hours(max(0, $total - $watched))) . '</b>осталось</span></div></div>'
            . '<div class="hc-ring"><svg width="128" height="128"><circle cx="64" cy="64" r="' . $r . '" fill="none" stroke="rgba(244,241,232,.15)" stroke-width="9"/>'
            . '<circle cx="64" cy="64" r="' . $r . '" fill="none" stroke="#f4f1e8" stroke-width="9" stroke-dasharray="' . round($watched / max(1, $total) * $c, 1) . ' ' . round($c, 1) . '" stroke-linecap="round"/></svg>'
            . '<div class="n">' . $pct . '%<small>по времени</small></div></div></div>';

        if ($next) {
            echo '<div class="hc-next"><div><div class="lbl">' . ((int) $next['state'] === 1 ? 'Смотрю сейчас' : 'Следующая лекция') . '</div>'
                . '<div class="tt">' . esc_html($next['code'] . ' · ' . $next['title']) . '</div></div>'
                . ($next['duration'] !== '' ? '<span class="hc-meta">' . esc_html($next['duration']) . '</span>' : '')
                . ($next['url'] !== '' ? '<a class="hc-play" href="' . esc_url($next['url']) . '" target="_blank" rel="noopener">▶ Смотреть</a>' : '') . '</div>';
        }

        echo '<div class="hc-strip">';
        foreach ($lectures as $l) {
            echo '<a class="s' . (int) $l['state'] . '" href="#l' . (int) $l['id'] . '" title="' . esc_attr($l['code'] . ' ' . $l['title']) . '"></a>';
        }
        echo '</div><div class="hc-legend"><span><i style="background:var(--empty)"></i>' . $states[0] . '</span><span><i style="background:var(--doing)"></i>' . $states[1]
            . ($doing ? ' (' . $doing . ')' : '') . '</span><span><i style="background:#9fd0b6"></i>' . $states[2] . ' (' . ($done - $notes) . ')</span>'
            . '<span><i style="background:var(--done)"></i>' . $states[3] . ' (' . $notes . ')</span></div>';

        echo '<div class="hc-grid">';
        foreach ($lectures as $l) {
            $state = (int) $l['state'];
            echo '<div class="hc-l s' . $state . '" id="l' . (int) $l['id'] . '">'
                . self::goal_form('step_cycle', ['step' => (int) $l['id'], 'anchor' => 'l' . (int) $l['id']], '<button class="hg-dot" title="' . esc_attr($states[min(3, $state)]) . ' — нажмите для следующего шага">' . ($state === 3 ? '✓' : '') . '</button>', '', '')
                . '<div><div class="hc-num">Лекция ' . esc_html($l['code']) . '</div><div class="hc-t">' . esc_html($l['title']) . ($state >= 2 ? ' <span class="hc-stage">· ' . esc_html($states[min(3, $state)]) . '</span>' : '') . '</div><div class="hc-meta">'
                . ($l['duration'] !== '' ? '<span>' . esc_html($l['duration']) . '</span>' : '')
                . ($l['url'] !== '' ? '<a href="' . esc_url($l['url']) . '" target="_blank" rel="noopener">▶ YouTube</a>' : '')
                . '</div></div></div>';
        }
        echo '</div><p class="hx-hint" style="color:var(--muted);font-size:12px;margin-top:14px">Кружок: не начата → смотрю → просмотрена → конспект готов. Можно и написать боту: «посмотрела лекцию 12», «конспект 12».</p>';
        echo self::goal_footer($goal, ['diary' => 'Конспект и мысли', 'write' => 'Записать', 'what' => 'Что запомнилось', 'empty' => 'Пока пусто.']);
        echo '</div>';
    }

    /** The state exam, in Bulgarian: overall progress, then topic cards with questions and literature. */
    private static function exam_page(array $goal): void
    {
        $id = (int) $goal['id'];
        $tree = Hastan_Goals::tree($id);
        [$done, $doing, $total] = self::goal_progress($id);
        $leaves = Hastan_Goals::leaves($id);
        $points = array_sum(array_map(fn ($q) => min(3, (int) $q['state']), $leaves));
        $pct = $total ? round($points / (3 * $total) * 100) : 0;
        $repeated = count(array_filter($leaves, fn ($q) => (int) $q['repeats'] > 0));
        $by_state = array_count_values(array_map(fn ($q) => (int) $q['state'], $leaves));
        $states = ['не е започнат', 'конспект', 'пищов', 'готов'];
        echo '<div class="wrap hx" lang="bg"><a class="hg-back" href="' . esc_url(self::goal_url()) . '">← Все цели</a>';
        self::notice();
        echo self::goal_styles();
        echo '<style>
.hx{--ink:#1f2a37;--muted:#7a7466;--paper:#fbf8f2;--line:#e6dfd0;--done:#2f7d5b;--doing:#e3a33b;--st1:#f1d9a6;--st2:#e3a33b;--empty:#e9e3d6;max-width:1180px}
.hx-hero{display:flex;gap:28px;align-items:center;justify-content:space-between;flex-wrap:wrap;background:linear-gradient(135deg,#1f2a37,#2d3e52);color:#fff;border-radius:18px;padding:28px 32px;margin:8px 0 18px}
.hx-hero h1{color:#fff;font-size:30px;margin:0 0 6px;padding:0;font-family:Georgia,"Times New Roman",serif;font-weight:600}
.hx-hero .sub{opacity:.8;font-size:14px}.hx-hero .due{display:inline-block;margin-top:12px;background:rgba(255,255,255,.12);border-radius:20px;padding:4px 12px;font-size:12px;letter-spacing:.04em}
.hx-ring{position:relative;width:120px;height:120px;flex:none}.hx-ring svg{transform:rotate(-90deg)}
.hx-ring .n{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-size:28px;font-weight:600}
.hx-ring .n small{font-size:11px;font-weight:400;opacity:.75;margin-top:2px}
.hx-strip{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 8px}.hx-strip a{display:flex;gap:3px;text-decoration:none;padding:4px;border-radius:6px}
.hx-strip a:hover{background:#efe9dc}.hx-strip i{width:14px;height:14px;border-radius:3px;background:var(--empty)}.hx-strip i.s1{background:var(--st1)}.hx-strip i.s2{background:var(--st2)}.hx-strip i.s3{background:var(--done)}
.hx .hg-dot{position:relative}.hx .s1 .hg-dot{border-color:var(--st2);background:conic-gradient(var(--st2) 0 120deg,#fff 120deg)}
.hx .s2 .hg-dot{border-color:var(--st2);background:conic-gradient(var(--st2) 0 240deg,#fff 240deg)}.hx .s3 .hg-dot{border-color:var(--done);background:var(--done)}
.hx-stage{flex:none;font-size:11px;color:var(--muted);margin-top:3px;min-width:58px}.hx-q.s3 .t{color:var(--muted)}
.hx-rep{margin-left:auto;flex:none}.hx-rep button{border:1px solid var(--line);background:#fff;border-radius:12px;padding:1px 8px;cursor:pointer;font-size:12px;color:var(--muted);display:inline-flex;gap:3px;align-items:center;line-height:18px}
.hx-rep button:hover{border-color:#8fb3d9}.hx-rep b{width:6px;height:6px;border-radius:50%;background:#e0d9c9;display:inline-block}.hx-rep b.on{background:#3b7bbf}
.hx-rep.r3 button{border-color:#8fb3d9;color:#3b7bbf}
.hx-hero .rep{display:inline-block;margin:12px 0 0 6px;background:rgba(143,179,217,.18);border-radius:20px;padding:4px 12px;font-size:12px}
.hx-legend{display:flex;gap:16px;color:var(--muted);font-size:12px;margin-bottom:18px;flex-wrap:wrap}.hx-legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin-right:5px;vertical-align:-1px}
.hx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(360px,1fr));gap:16px}
.hx-topic{background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:18px 20px;scroll-margin-top:50px}
.hx-topic.all{border-color:#9fc9b4;background:#f3faf6}
.hx-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:12px}
.hx-num{font-family:Georgia,serif;font-size:30px;line-height:1;color:#c9bfa9;font-weight:600;min-width:38px}.hx-topic.all .hx-num{color:var(--done)}
.hx-head h3{margin:2px 0 4px;font-size:15px;line-height:1.35;color:var(--ink)}.hx-head .c{font-size:12px;color:var(--muted)}
.hx-q{border-top:1px solid var(--line);padding:10px 0}.hx-q:last-child{padding-bottom:0}
.hx-row{display:flex;gap:10px;align-items:flex-start}.hx-row form{flex:none;margin:0;line-height:0}
.hx-code{flex:none;font-size:11px;font-weight:600;color:var(--muted);background:#efe9dc;border-radius:6px;padding:2px 6px;margin-top:2px}
.hx-q .t{font-size:13.5px;line-height:1.45;color:var(--ink)}
.hx-q details{margin:6px 0 0 66px}.hx-q summary{cursor:pointer;color:#2271b1;font-size:12px}
.hx-q ol{margin:6px 0 0 18px;font-size:12.5px;line-height:1.5;color:#3c434a}.hx-q ol li{margin-bottom:3px}
.hx-hint{color:var(--muted);font-size:12px;margin:14px 0 0}
@media (max-width:600px){.hx-grid{grid-template-columns:1fr}.hx-hero{padding:22px}.hx-q details{margin-left:0}}
</style>';
        $r = 52;
        $c = 2 * M_PI * $r;
        echo '<div class="hx-hero"><div><h1>' . esc_html($goal['title']) . '</h1>'
            . ($goal['subtitle'] !== '' ? '<div class="sub">' . esc_html($goal['subtitle']) . '</div>' : '')
            . ($goal['due'] !== '' ? '<span class="due">' . esc_html(mb_strtoupper($goal['due'])) . '</span>' : '')
            . '<span class="rep">↻ повторени ' . $repeated . ' от ' . $total . '</span></div>'
            . '<div class="hx-ring"><svg width="120" height="120"><circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="rgba(255,255,255,.14)" stroke-width="10"/>'
            . '<circle cx="60" cy="60" r="' . $r . '" fill="none" stroke="#5fc293" stroke-width="10" stroke-dasharray="' . round($pct / 100 * $c, 1) . ' ' . round($c, 1) . '" stroke-linecap="round"/></svg>'
            . '<div class="n">' . $pct . '%<small>готови ' . $done . ' от ' . $total . '</small></div></div></div>';

        echo '<div class="hx-strip">';
        foreach ($tree as $topic) {
            echo '<a href="#t' . esc_attr($topic['code']) . '" title="Тема ' . esc_attr($topic['code'] . '. ' . $topic['title']) . '">';
            foreach ($topic['children'] as $q) {
                echo '<i class="s' . (int) $q['state'] . '"></i>';
            }
            echo '</a>';
        }
        $colors = ['var(--empty)', 'var(--st1)', 'var(--st2)', 'var(--done)'];
        echo '</div><div class="hx-legend">';
        foreach ($states as $k => $label) {
            echo '<span><i style="background:' . $colors[$k] . '"></i>' . $label . ($k && !empty($by_state[$k]) ? ' (' . $by_state[$k] . ')' : '') . '</span>';
        }
        echo '</div>';

        echo '<div class="hx-grid">';
        foreach ($tree as $topic) {
            $qs = $topic['children'];
            $ready = count(array_filter($qs, fn ($q) => (int) $q['state'] === 3));
            echo '<section class="hx-topic' . ($qs && $ready === count($qs) ? ' all' : '') . '" id="t' . esc_attr($topic['code']) . '">'
                . '<div class="hx-head"><div class="hx-num">' . esc_html(str_pad($topic['code'], 2, '0', STR_PAD_LEFT)) . '</div><div><h3>' . esc_html($topic['title']) . '</h3>'
                . '<div class="c">Тема ' . esc_html($topic['code']) . ' · готови ' . $ready . ' от ' . count($qs) . '</div></div></div>';
            foreach ($qs as $q) {
                $state = (int) $q['state'];
                $books = array_filter(explode("\n", (string) $q['details']));
                echo '<div class="hx-q s' . $state . '"><div class="hx-row">';
                $reps = (int) $q['repeats'];
                $dots = '';
                for ($i = 1; $i <= 3; $i++) {
                    $dots .= '<b' . ($reps >= $i ? ' class="on"' : '') . '></b>';
                }
                echo self::goal_form('step_cycle', ['step' => (int) $q['id'], 'anchor' => 't' . $topic['code']], '<button class="hg-dot" title="' . esc_attr($states[min(3, $state)]) . ' — натиснете за следващ етап">' . ($state === 3 ? '✓' : '') . '</button>', '', '')
                    . '<span class="hx-code">' . esc_html($q['code']) . '</span><span class="t">' . esc_html($q['title'])
                    . ($state ? ' <span class="hx-stage">· ' . esc_html($states[min(3, $state)]) . '</span>' : '') . '</span>'
                    . self::goal_form('step_repeat', ['step' => (int) $q['id'], 'anchor' => 't' . $topic['code']], '<button title="Повторен ' . $reps . ' пъти. Натиснете, когато повторите">↻ ' . $dots . '</button>', '', 'class="hx-rep r' . min(3, $reps) . '"')
                    . '</div>';
                if ($books) {
                    echo '<details><summary>Литература (' . count($books) . ')</summary><ol>';
                    foreach ($books as $book) {
                        echo '<li>' . esc_html($book) . '</li>';
                    }
                    echo '</ol></details>';
                }
                echo '</div>';
            }
            echo '</section>';
        }
        echo '</div><p class="hx-hint">Кръгчето: не е започнат → конспект → пищов → готов. ↻ — колко пъти сте повторили въпроса (до 3), не влияе на процента. На бота: «конспект 7.2», «шпаргалка 7.1», «повторила 1.1».</p>';
        echo self::goal_footer($goal, ['diary' => 'Дневник', 'write' => 'Запиши', 'what' => 'Какво направих', 'empty' => 'Още няма записи.']);
        echo '</div>';
    }

    /** İznik tiles and the rest of the Turkish look, shared by the diary and its exercises. */
    private static function turkish_styles(): string
    {
        $star = "<svg xmlns='http://www.w3.org/2000/svg' width='72' height='72' viewBox='0 0 72 72'><g fill='none' stroke='%2364c7d6' stroke-opacity='.28' stroke-width='1.4'>"
            . "<path d='M36 14l6.4 15.6L58 36l-15.6 6.4L36 58l-6.4-15.6L14 36l15.6-6.4z'/><rect x='24' y='24' width='24' height='24' transform='rotate(45 36 36)'/><rect x='24' y='24' width='24' height='24'/>"
            . "<circle cx='36' cy='36' r='4'/><path d='M0 0l10 10M72 0L62 10M0 72l10-10M72 72L62 62'/><circle cx='0' cy='0' r='8'/><circle cx='72' cy='0' r='8'/><circle cx='0' cy='72' r='8'/><circle cx='72' cy='72' r='8'/></g></svg>";
        $band = "<svg xmlns='http://www.w3.org/2000/svg' width='28' height='14' viewBox='0 0 28 14'><path d='M0 7L7 0l7 7-7 7z' fill='%23c8402c'/><path d='M14 7l7-7 7 7-7 7z' fill='%232a9d8f'/><circle cx='14' cy='7' r='1.6' fill='%23fbf6ec'/></svg>";
        $tile = "<svg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'><path d='M12 3l2.6 6.4L21 12l-6.4 2.6L12 21l-2.6-6.4L3 12l6.4-2.6z' fill='%23fbf6ec' fill-opacity='.85'/><circle cx='12' cy='12' r='2.2' fill='%23c8402c'/></svg>";
        return '<style>
.ht{--cobalt:#1e4c9a;--navy:#122a5c;--turq:#2a9d8f;--sky:#64c7d6;--coral:#c8402c;--gold:#c9a14a;--cream:#fbf6ec;--paper:#fffdf8;--ink:#1f2a37;--muted:#7a7466;--line:#eadfca;max-width:1180px}
.ht-hero{position:relative;overflow:hidden;border-radius:18px;margin:8px 0 18px;color:#fff;background:url("data:image/svg+xml,' . $star . '"),radial-gradient(ellipse at 20% 0%,#2a5fb8,var(--cobalt) 45%,var(--navy));box-shadow:0 10px 30px rgba(18,42,92,.25)}
.ht-hero:after{content:"";position:absolute;left:0;right:0;bottom:0;height:14px;background:url("data:image/svg+xml,' . $band . '") repeat-x;opacity:.95}
.ht-in{position:relative;display:flex;gap:28px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:30px 34px 40px}
.ht-eyebrow{font-size:11px;letter-spacing:.22em;text-transform:uppercase;color:var(--sky);font-weight:600}
.ht-hero h1{color:#fff;font-family:Georgia,"Times New Roman",serif;font-size:36px;font-weight:600;margin:6px 0 6px;padding:0;letter-spacing:.01em}
.ht-hero h1 i{font-weight:400;color:#f3d9a4;font-size:22px;margin-left:10px}
.ht-hero .sub{opacity:.85;font-size:14px}
.ht-stats{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.ht-stats span{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:22px;padding:5px 13px;font-size:12.5px;backdrop-filter:blur(2px)}
.ht-stats b{font-size:16px;margin-right:4px}.ht-stats em{font-style:normal;opacity:.65;margin-left:4px;font-size:11px}
.ht-tulip{position:absolute;right:190px;bottom:4px;width:84px;opacity:.9;pointer-events:none}
.ht-nazar{position:relative;flex:none;width:136px;height:136px;border-radius:50%;background:radial-gradient(circle,#0b1a3a 0 27%,#8fd3ef 27.5% 45%,#fff 45.5% 62%,#1b5bc4 62.5%);box-shadow:0 0 0 6px rgba(255,255,255,.08),0 8px 22px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center}
.ht-nazar:before{content:"";position:absolute;top:22%;left:26%;width:16%;height:10%;border-radius:50%;background:rgba(255,255,255,.55);transform:rotate(-30deg)}
.ht-nazar .n{position:relative;text-align:center;line-height:1;font-weight:700;font-size:22px}.ht-nazar small{display:block;font-size:9px;font-weight:500;letter-spacing:.08em;margin-top:2px;opacity:.85}
.ht-row{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;margin-bottom:16px}
.ht-card{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:20px 22px;position:relative}
.ht-card h2{font-family:Georgia,serif;font-size:21px;font-weight:600;color:var(--navy);margin:0 0 2px}.ht-card>.ru{color:var(--muted);font-size:13px;margin-bottom:14px}
.ht-form .top{display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap}
.ht-form input[type=date]{border-radius:10px;border-color:var(--line)}
.ht-form textarea{box-sizing:border-box;width:100%;min-height:118px;border:1px solid var(--line);border-radius:12px;padding:12px 14px;font-size:15px;line-height:1.6;background:#fff url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'10\' height=\'24\'%3E%3Cpath d=\'M0 23.5h10\' stroke=\'%23f1e8d6\'/%3E%3C/svg%3E") 0 11px;font-family:Georgia,serif;resize:vertical}
.ht-form textarea:focus{border-color:var(--turq);box-shadow:0 0 0 2px rgba(42,157,143,.18);outline:0}
.ht-keys{display:flex;gap:5px;flex-wrap:wrap}.ht-keys button{width:32px;height:32px;border-radius:8px;border:1px solid var(--line);background:#fff;font-size:15px;font-family:Georgia,serif;color:var(--cobalt);cursor:pointer}
.ht-keys button:hover{border-color:var(--turq);background:#eef8f6}
.ht-send{display:flex;align-items:center;gap:14px;margin-top:12px;flex-wrap:wrap}
.ht-btn{background:var(--coral);color:#fff;border:0;border-radius:24px;padding:10px 22px;font-size:14px;font-weight:600;cursor:pointer;box-shadow:0 3px 0 #9c2f20}
.ht-btn:hover{background:#b23722}.ht-btn:active{transform:translateY(2px);box-shadow:0 1px 0 #9c2f20}
.ht-hint{color:var(--muted);font-size:12px;line-height:1.5}.ht-hint code{background:#f4ecdc;border-radius:4px;padding:1px 5px;font-size:12px}
.ht-flip{perspective:900px;cursor:pointer;height:190px;margin:4px 0 10px}
.ht-flip .in{position:relative;width:100%;height:100%;transition:transform .55s;transform-style:preserve-3d}.ht-flip.on .in{transform:rotateY(180deg)}
.ht-flip .f{position:absolute;inset:0;backface-visibility:hidden;border-radius:14px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:18px}
.ht-flip .front{background:var(--cobalt) url("data:image/svg+xml,' . $star . '");color:#fff;box-shadow:inset 0 0 0 6px var(--cobalt),inset 0 0 0 8px rgba(243,217,164,.6)}
.ht-flip .back{background:var(--cream);color:var(--ink);transform:rotateY(180deg);box-shadow:inset 0 0 0 6px var(--cream),inset 0 0 0 8px var(--turq)}
.ht-flip .w{font-family:Georgia,serif;font-size:28px;line-height:1.2}.ht-flip .back .w{font-family:inherit;font-size:22px}
.ht-flip .k{font-size:10px;letter-spacing:.18em;text-transform:uppercase;opacity:.7;margin-top:10px}
.ht-card .more{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted)}
.ht-card .more button{border:1px solid var(--line);background:#fff;border-radius:16px;padding:4px 12px;cursor:pointer;color:var(--cobalt)}
.ht-weeks{display:flex;align-items:center;gap:16px;margin-bottom:18px;flex-wrap:wrap}
.ht-weeks .lbl{font-family:Georgia,serif;color:var(--navy);font-size:15px}.ht-weeks .lbl small{display:block;font-family:-apple-system,sans-serif;color:var(--muted);font-size:12px}
.ht-tiles{display:flex;gap:4px;flex-wrap:wrap}.ht-tiles i{width:22px;height:22px;border-radius:4px;background:#f1eadb;display:block}
.ht-tiles i.on{background:var(--turq) url("data:image/svg+xml,' . $tile . '") center/18px no-repeat}.ht-tiles i.on.many{background-color:var(--cobalt)}
.ht-tiles i.now{box-shadow:0 0 0 2px var(--gold)}.ht-tiles i.fut{background:transparent;border:1px dashed #e2d7c1}
.ht-main{display:grid;grid-template-columns:1.55fr 1fr;gap:16px;align-items:start}
.ht-sec{font-family:Georgia,serif;font-size:20px;color:var(--navy);margin:4px 0 12px;display:flex;align-items:baseline;gap:10px}.ht-sec small{font-family:-apple-system,sans-serif;font-size:12px;color:var(--muted)}
.ht-day{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:18px 22px 12px;margin-bottom:14px;scroll-margin-top:50px;position:relative}
.ht-day:before{content:"";position:absolute;left:22px;right:22px;top:0;height:3px;border-radius:0 0 3px 3px;background:linear-gradient(90deg,var(--coral),var(--gold),var(--turq),var(--cobalt))}
.ht-dh{display:flex;align-items:baseline;gap:10px;margin-bottom:8px}.ht-dh b{font-family:Georgia,serif;font-size:19px;color:var(--ink);font-weight:600}
.ht-dh span{color:var(--turq);font-style:italic;font-family:Georgia,serif}.ht-dh em{margin-left:auto;font-style:normal;color:var(--muted);font-size:12px}
.ht-e{display:flex;align-items:baseline;gap:12px;padding:8px 0;border-top:1px dashed var(--line)}
.ht-e .tr{font-family:Georgia,serif;font-size:17px;color:var(--cobalt);font-weight:600}.ht-e .ru{color:#4b5563;font-size:14px}.ht-e .ru:before{content:"— ";color:#c9bfa9}
.ht-e.phrase .tr{font-weight:500}
.ht mark.hl{background:linear-gradient(transparent 58%,rgba(200,64,44,.16) 0);color:var(--coral);font-weight:700;padding:0 1px;border-radius:2px}.ht-flip .front mark.hl{color:#ffb4a3;background:none}
.ht-keys .hlb{width:auto;padding:0 12px;color:var(--coral);font-family:inherit;font-size:13px;font-weight:600}
.ht-e .acts{margin-left:auto;display:flex;gap:2px;opacity:0;transition:opacity .15s}.ht-e:hover .acts,.ht-e.editing .acts{opacity:1}
.ht-e .ed{border:0;background:none;color:var(--cobalt);cursor:pointer;font-size:14px}
.ht-e{flex-wrap:wrap}.ht-e .ef{display:none;flex-basis:100%;gap:8px;margin:6px 0 2px}.ht-e.editing .ef{display:flex}
.ht-e .ef input[type=text]{flex:1;border:1px solid var(--turq);border-radius:10px;padding:6px 10px;font-size:14px;font-family:Georgia,serif}
.ht-e .ef .ht-btn{padding:6px 14px;font-size:13px}
.ht-e.note{color:var(--ink);font-size:14px;line-height:1.5}.ht-e.note .star{flex:none;color:var(--coral);font-size:12px}
.ht-e.note .txt{flex:1 1 0;min-width:0}.ht-e .acts{flex:none}
.ht-e .x{border:0;background:none;color:#b32d2e;cursor:pointer;font-size:16px}
.ht-empty{text-align:center;padding:34px 20px;color:var(--muted)}.ht-empty .w{font-family:Georgia,serif;font-size:26px;color:var(--cobalt);margin-bottom:6px}
.ht-dict{position:sticky;top:44px}
.ht-dict input[type=search]{box-sizing:border-box;width:100%;border-radius:20px;border:1px solid var(--line);padding:6px 14px;margin-bottom:10px}
.ht-abc{display:flex;flex-wrap:wrap;gap:2px;margin-bottom:10px}.ht-abc a,.ht-abc span{width:22px;text-align:center;font-size:12px;font-family:Georgia,serif;padding:2px 0;border-radius:4px;text-decoration:none}
.ht-abc a{color:var(--cobalt);background:#eef3fb;font-weight:600}.ht-abc span{color:#d4cab6}
.ht-list{max-height:560px;overflow:auto;margin:0 -6px;padding:0 6px}
.ht-l{font-family:Georgia,serif;color:var(--coral);font-size:15px;margin:10px 0 2px;border-bottom:1px solid var(--line);scroll-margin-top:10px}
.ht-w{display:flex;gap:8px;font-size:13.5px;padding:4px 0}.ht-w .tr{color:var(--cobalt);font-weight:600;font-family:Georgia,serif}.ht-w .ru{color:var(--muted)}
.ht-dh form{margin:0}.ht-mk{border:1px solid var(--line);background:#fff;border-radius:14px;padding:2px 10px;font-size:12px;color:var(--coral);cursor:pointer;font-weight:600}.ht-mk:hover{border-color:var(--coral)}
.ht-gorev{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:18px;scroll-margin-top:50px}
.ht-g{display:block;background:var(--paper);border:1px solid var(--line);border-radius:14px;padding:14px 16px;text-decoration:none;color:var(--ink);transition:box-shadow .15s,transform .15s}.ht-g:hover{box-shadow:0 6px 18px rgba(18,42,92,.12);transform:translateY(-1px);color:var(--ink)}
.ht-g b{font-family:Georgia,serif;font-size:16px;color:var(--navy);display:block;margin-bottom:2px}.ht-g small{color:var(--muted);font-size:12px}
.ht-g .bar{height:7px;border-radius:7px;background:#efe6d3;margin:10px 0 4px;overflow:hidden}.ht-g .bar i{display:block;height:100%;background:linear-gradient(90deg,var(--turq),var(--cobalt))}
.ht-g.pending small{color:var(--turq)}.ht-g.failed small{color:#b32d2e}
.ht-wait{text-align:center;padding:34px}.ht-wait .ht-tiles{justify-content:center;margin-bottom:14px}.ht-wait .ht-tiles i{animation:htp 1.4s infinite ease-in-out}
.ht-wait .ht-tiles i:nth-child(2){animation-delay:.15s}.ht-wait .ht-tiles i:nth-child(3){animation-delay:.3s}.ht-wait .ht-tiles i:nth-child(4){animation-delay:.45s}.ht-wait .ht-tiles i:nth-child(5){animation-delay:.6s}.ht-wait .ht-tiles i:nth-child(6){animation-delay:.75s}.ht-wait .ht-tiles i:nth-child(7){animation-delay:.9s}
@keyframes htp{0%,100%{opacity:.25;transform:scale(.85)}50%{opacity:1;transform:scale(1)}}
.ht-new,.ht-done{margin-bottom:16px}.ht-new summary,.ht-done summary{cursor:pointer;list-style:none;display:flex;align-items:baseline;gap:12px}.ht-new summary::-webkit-details-marker,.ht-done summary::-webkit-details-marker{display:none}
.ht-new summary h2,.ht-done summary h2{margin:0}.ht-new summary .ru,.ht-done summary .ru{color:var(--muted);font-size:13px}
.ht-chips{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0}.ht-chips span{background:#fff;border:1px solid var(--line);border-radius:16px;padding:4px 11px;font-size:13px;color:#4b5563}.ht-chips span b{color:var(--cobalt);font-family:Georgia,serif}.ht-chips span.in{opacity:.55}
.ht-done .ht-w{align-items:center;border-top:1px dashed var(--line);padding:6px 0}.ht-done .ht-w:first-of-type{margin-top:10px}
.ht-score{background:#fff4f1;border:1px solid #f0c7bd;color:#8c2a1b;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:14px}.ht-score.all{background:#eef8f6;border-color:#a9dcd4;color:#1d6b61}.ht-score b{font-size:17px}
.ht-tasks{padding:6px 18px;margin-bottom:18px}
.ht-t{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-top:1px dashed var(--line);scroll-margin-top:60px}.ht-t:first-child{border-top:0}
.ht-t .no{flex:none;width:24px;text-align:right;color:#c9bfa9;font-family:Georgia,serif;font-size:14px;padding-top:8px}
.ht-t .body{flex:1;min-width:0;display:flex;flex-wrap:wrap;align-items:center;gap:6px 14px}
.ht-t .q{font-size:15px;color:var(--ink);flex:1 1 240px}.ht-t .q[lang=tr]{font-family:Georgia,serif;font-size:16px;color:var(--cobalt)}
.ht-t .h{flex-basis:100%;color:var(--muted);font-size:12.5px}
.ht-t input[type=text]{box-sizing:border-box;flex:1 1 260px;border:1px solid var(--line);border-radius:10px;padding:7px 11px;font-size:15px;font-family:Georgia,serif;background:#fff}
.ht-t input.fill{flex:none;width:92px;margin:0 2px;padding:3px 8px;text-align:center;border-style:dashed;border-color:var(--turq)}
.ht-t input:focus{border-color:var(--turq);box-shadow:0 0 0 2px rgba(42,157,143,.18);outline:0}
.ht-t.r1 input{border-color:#8fd0c5;background:#f3fbf9}.ht-t.r2 input{border-color:#eab2a5;background:#fff8f6}
.ht-t .fix,.ht-t .good{flex-basis:100%;font-size:14px;display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px}
.ht-t .fix .you{color:#6b7280;font-family:Georgia,serif}.ht-t .fix del{color:#b32d2e;text-decoration:line-through;text-decoration-thickness:2px;background:#fde8e3;border-radius:2px}
.ht-t .fix .ok{font-family:Georgia,serif;font-size:16px;color:var(--ink);font-weight:600}.ht-t .fix ins{text-decoration:none;color:var(--coral);background:linear-gradient(transparent 55%,rgba(200,64,44,.2) 0);font-weight:700}
.ht-t .fix .arr{color:#c9bfa9}.ht-t .acc{border:1px solid var(--line);background:#fff;border-radius:12px;padding:1px 9px;font-size:11.5px;color:var(--muted);cursor:pointer}.ht-t .acc:hover{color:var(--turq);border-color:var(--turq)}
.ht-t .good{color:#1d6b61;font-family:Georgia,serif}
.ht-t .dots{flex:none;display:flex;gap:3px;padding-top:12px}.ht-t .dots b{width:7px;height:7px;border-radius:50%;background:#e8dfcc}.ht-t .dots b.on{background:var(--turq)}
.ht-t .tools{flex:none;display:flex;gap:2px;padding-top:4px;opacity:.35;transition:opacity .15s}.ht-t:hover .tools{opacity:1}
.ht-t .tools button{border:0;background:none;cursor:pointer;font-size:15px;color:var(--turq);padding:4px}.ht-t .tools .x{color:#b32d2e;font-size:17px}
.ht-bar{position:sticky;bottom:0;z-index:5;display:flex;align-items:center;gap:14px;flex-wrap:wrap;background:rgba(255,253,248,.96);backdrop-filter:blur(4px);border:1px solid var(--line);border-radius:16px;padding:10px 14px;margin:6px 0 10px;box-shadow:0 -4px 18px rgba(18,42,92,.08)}
.ht-bar #ht-filled{font-size:13px;color:var(--navy);font-weight:600}.ht-bar .ht-btn{margin-left:auto}
@media (max-width:900px){.ht-row,.ht-main{grid-template-columns:1fr}.ht-dict{position:static}.ht-tulip{display:none}}
@media (max-width:600px){.ht-nazar{width:96px;height:96px}.ht-bar .ht-hint{display:none}.ht-bar{gap:8px;padding:8px 10px}.ht-bar .ht-keys button{width:28px;height:28px}.ht-in{padding:22px 22px 34px}.ht-hero h1{font-size:28px}.ht-hero h1 i{display:block;margin:4px 0 0}}
</style>';
    }

    private static function drill_url(int $goal_id, int $drill_id, string $extra = ''): string
    {
        return admin_url('admin.php?page=hastan-goals&goal=' . $goal_id . '&drill=' . $drill_id . $extra);
    }

    /** Characters of a diff, the ones that differ wrapped in a tag. */
    private static function diff_html(array $chars, string $tag): string
    {
        $out = '';
        foreach ($chars as [$c, $same]) {
            $out .= $same ? esc_html($c) : '<' . $tag . '>' . esc_html($c) . '</' . $tag . '>';
        }
        return preg_replace('#</' . $tag . '><' . $tag . '>#', '', $out);
    }

    /** Exercises for one diary page: write, check, see the mistakes letter by letter. */
    private static function drill_page(array $goal, array $drill): void
    {
        $id = (int) $goal['id'];
        $did = (int) $drill['id'];
        $items = Hastan_Drills::items($did);
        $active = array_values(array_filter($items, fn ($i) => !(int) $i['learned']));
        $learned = array_values(array_filter($items, fn ($i) => (int) $i['learned']));
        $checked = !empty($_GET['checked']);
        [$date] = self::tr_date($drill['day']);
        echo '<div class="wrap ht"><a class="hg-back" href="' . esc_url(self::goal_url($id, 'gorev')) . '">← ' . esc_html($goal['title']) . '</a>';
        self::notice();
        echo self::goal_styles() . self::turkish_styles();

        $total = count($items);
        $pct = $total ? count($learned) / $total : 0;
        echo '<div class="ht-hero"><div class="ht-in"><div><div class="ht-eyebrow">Görev · задание</div><h1>' . esc_html($drill['title']) . '</h1>'
            . '<div class="sub">По странице дневника за ' . esc_html($date) . '. Фраза выучена после ' . Hastan_Drills::LEARNED . ' верных ответов в разные дни, ошибка начинает её заново.</div>';
        if ($total) {
            echo '<div class="ht-stats"><span><b>' . count($learned) . '</b>выучено<em>öğrenildi</em></span><span><b>' . count($active) . '</b>осталось<em>kalan</em></span><span><b>' . $total . '</b>всего</span></div>';
        }
        echo '</div><div class="ht-nazar" title="Выучено"><div class="n">' . count($learned) . '<small>/ ' . $total . '</small></div></div></div></div>';

        if ($drill['status'] === 'pending') {
            echo '<div class="ht-card ht-wait"><div class="ht-tiles">' . str_repeat('<i class="on"></i>', 7) . '</div><h2>Hazırlanıyor…</h2><div class="ru">Claude составляет 50 фраз по этой странице. Обычно это минута-две, страница обновится сама.</div></div>'
                . '<script>setTimeout(function(){location.reload();},8000);</script></div>';
            return;
        }
        if ($drill['status'] === 'failed') {
            echo '<div class="ht-card"><h2>Не получилось</h2><div class="ru">' . esc_html($drill['error']) . '</div>'
                . self::goal_form('drill_retry', ['goal' => $id, 'drill' => $did], '<button class="ht-btn">Попробовать ещё раз</button>') . '</div></div>';
            return;
        }

        $words = (array) json_decode((string) $drill['words'], true);
        if ($words) {
            $have = array_flip(array_map(fn ($e) => Hastan_Turkish::lower(Hastan_Turkish::plain($e['tr'])), Hastan_Turkish::dictionary(Hastan_Turkish::entries($id))));
            $fresh = array_filter($words, fn ($w) => !isset($have[Hastan_Turkish::lower(trim((string) $w['tr']))]));
            echo '<details class="ht-card ht-new"' . ($fresh ? ' open' : '') . '><summary><h2>Yeni kelimeler</h2><span class="ru">Новые слова в этом задании · ' . count($words) . '</span></summary><div class="ht-chips">';
            foreach ($words as $w) {
                $in = isset($have[Hastan_Turkish::lower(trim((string) $w['tr']))]);
                echo '<span class="' . ($in ? 'in' : '') . '"><b lang="tr">' . esc_html($w['tr']) . '</b> ' . esc_html($w['ru']) . '</span>';
            }
            echo '</div>' . ($fresh ? self::goal_form('drill_words', ['goal' => $id, 'drill' => $did], '<button class="button">Добавить в словарик (' . count($fresh) . ')</button>') : '<p class="ht-hint">Все слова уже в словарике.</p>') . '</details>';
        }

        if ($checked) {
            $r = count(array_filter($items, fn ($i) => (int) $i['last_result'] === 1));
            $w = count(array_filter($items, fn ($i) => (int) $i['last_result'] === 2));
            echo '<div class="ht-score' . ($w ? '' : ' all') . '"><b>' . $r . '</b> верно · <b>' . $w . '</b> ' . self::plural($w, ['ошибка', 'ошибки', 'ошибок']) . ($w ? '. Ошибки ниже, нужные буквы выделены.' : '. Harika!') . '</div>';
        }

        $keys = '<div class="ht-keys ht-dkeys">' . implode('', array_map(fn ($k) => '<button type="button" data-k="' . $k . '">' . $k . '</button>', ['ç', 'ğ', 'ı', 'İ', 'ö', 'ş', 'ü'])) . '</div>';
        $form = '';
        $heads = ['ru_tr' => ['Rusçadan Türkçeye', 'переведите на турецкий'], 'tr_ru' => ['Türkçeden Rusçaya', 'переведите на русский'], 'fill' => ['Boşluğu doldur', 'допишите окончание']];
        $n = 0;
        foreach ($heads as $kind => [$tr, $ru]) {
            $list = array_filter($active, fn ($i) => $i['kind'] === $kind);
            if (!$list) {
                continue;
            }
            $form .= '<div class="ht-sec">' . $tr . ' <small>' . $ru . ' · ' . count($list) . '</small></div><div class="ht-card ht-tasks">';
            foreach ($list as $it) {
                $n++;
                $iid = (int) $it['id'];
                $res = $checked ? (int) $it['last_result'] : 0;
                $dots = '';
                for ($k = 1; $k <= Hastan_Drills::LEARNED; $k++) {
                    $dots .= '<b' . ((int) $it['streak'] >= $k ? ' class="on"' : '') . '></b>';
                }
                $input = '<input type="text" name="a[' . $iid . ']" autocomplete="off" spellcheck="false" lang="' . ($kind === 'tr_ru' ? 'ru' : 'tr') . '"' . ($kind === 'fill' ? ' class="fill"' : '') . '>';
                if ($kind === 'fill') {
                    $parts = explode('___', $it['prompt'], 2);
                    $q = '<span class="q" lang="tr">' . esc_html(rtrim($parts[0])) . $input . esc_html($parts[1] ?? '') . '</span>' . ($it['hint'] !== '' ? '<span class="h">' . esc_html($it['hint']) . '</span>' : '');
                } else {
                    $q = '<span class="q"' . ($kind === 'tr_ru' ? ' lang="tr"' : '') . '>' . esc_html($it['prompt']) . '</span>' . $input;
                }
                $form .= '<div class="ht-t r' . $res . '" id="t' . $iid . '"><span class="no">' . $n . '</span><div class="body">' . $q;
                if ($res === 2) {
                    [$ga, $gb] = Hastan_Drills::diff($it['last_answer'], $it['answer'], $kind === 'tr_ru');
                    $form .= '<div class="fix"><span class="you">' . self::diff_html($ga, 'del') . '</span><span class="arr">→</span><span class="ok"' . ($kind === 'tr_ru' ? '' : ' lang="tr"') . '>' . self::diff_html($gb, 'ins') . '</span>'
                        . '<button type="submit" form="acc' . $iid . '" class="acc" title="Мой вариант тоже правильный">засчитать мой</button></div>';
                } elseif ($res === 1) {
                    $form .= '<div class="good">✓ ' . esc_html($it['last_answer']) . '</div>';
                }
                $form .= '</div><span class="dots" title="Верных ответов подряд в разные дни: ' . (int) $it['streak'] . ' из ' . Hastan_Drills::LEARNED . '">' . $dots . '</span>'
                    . '<span class="tools"><button type="submit" form="lrn' . $iid . '" title="Уже знаю — убрать в выученные">✓</button><button type="submit" form="del' . $iid . '" class="x" title="Удалить фразу" onclick="return confirm(\'Удалить фразу?\')">×</button></span></div>';
            }
            $form .= '</div>';
        }
        if ($active) {
            echo self::goal_form('drill_check', ['goal' => $id, 'drill' => $did], '<div class="ht-bar">' . $keys . '<span id="ht-filled">заполнено 0 из ' . count($active) . '</span><span class="ht-hint">Пустые поля не проверяются.</span><button class="ht-btn">Kontrol et · Проверить</button></div>', $form, 'class="ht-drill"');
        } else {
            echo '<div class="ht-card ht-empty"><div class="w">Tebrikler!</div>Все фразы этого задания выучены.</div>';
        }
        // The small per-row forms live outside the big one: forms cannot nest.
        foreach ($active as $it) {
            $iid = (int) $it['id'];
            echo self::goal_form('drill_accept', ['goal' => $id, 'drill' => $did, 'checked' => (int) $checked, 'item' => $iid], '', '', 'id="acc' . $iid . '" hidden')
                . self::goal_form('drill_learned', ['goal' => $id, 'drill' => $did, 'checked' => (int) $checked, 'item' => $iid, 'on' => 1], '', '', 'id="lrn' . $iid . '" hidden')
                . self::goal_form('drill_item_delete', ['goal' => $id, 'drill' => $did, 'checked' => (int) $checked, 'item' => $iid], '', '', 'id="del' . $iid . '" hidden');
        }

        if ($learned) {
            echo '<details class="ht-card ht-done"><summary><h2>Öğrenildi</h2><span class="ru">Выученные · ' . count($learned) . '</span></summary>';
            foreach ($learned as $it) {
                echo '<div class="ht-w"><span class="tr">' . esc_html($it['kind'] === 'tr_ru' ? $it['prompt'] : ($it['kind'] === 'fill' ? str_replace('___', $it['answer'], $it['prompt']) : $it['answer'])) . '</span><span class="ru">' . esc_html($it['kind'] === 'tr_ru' ? $it['answer'] : ($it['kind'] === 'fill' ? $it['hint'] : $it['prompt'])) . '</span>'
                    . self::goal_form('drill_learned', ['goal' => $id, 'drill' => $did, 'item' => (int) $it['id'], 'on' => 0], '<button class="button button-small" title="Снова тренировать">вернуть</button>', '', 'style="margin-left:auto"') . '</div>';
            }
            echo '</details>';
        }
        echo '<p style="margin-top:24px">' . self::goal_form('drill_delete', ['goal' => $id, 'drill' => $did], '<button class="button button-link-delete" onclick="return confirm(\'Удалить всё задание?\')">Удалить задание целиком</button>') . '</p>';
        echo '<script>(function(){var t=null;document.addEventListener("focusin",function(e){if(e.target.matches(".ht-drill input[type=text]"))t=e.target;});'
            . 'document.querySelectorAll(".ht-dkeys button").forEach(function(b){b.addEventListener("mousedown",function(e){e.preventDefault();});b.addEventListener("click",function(){if(!t)return;var s=t.selectionStart,e=t.selectionEnd,k=b.dataset.k;t.value=t.value.slice(0,s)+k+t.value.slice(e);t.selectionStart=t.selectionEnd=s+k.length;t.dispatchEvent(new Event("input",{bubbles:true}));});});'
            . 'var f=document.getElementById("ht-filled"),all=document.querySelectorAll(".ht-drill input[type=text]");function cnt(){var n=0;all.forEach(function(i){if(i.value.trim())n++;});if(f)f.textContent="заполнено "+n+" из "+all.length;}document.addEventListener("input",cnt);'
            . 'all.forEach(function(i,k){i.addEventListener("keydown",function(e){if(e.key==="Enter"){e.preventDefault();if(all[k+1])all[k+1].focus();}});});})();</script>';
        echo '</div>';
    }

    /** "3 ноября" and the Turkish weekday, for a diary page. */
    private static function tr_date(string $day): array
    {
        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $gun = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
        $t = strtotime($day . ' 12:00:00');
        return [(int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1], $gun[(int) date('w', $t)], date('Y', $t)];
    }

    /** The Turkish diary: İznik tiles, a word card to flip, a page per day and a small dictionary. */
    private static function turkish_page(array $goal): void
    {
        $id = (int) $goal['id'];
        $entries = Hastan_Turkish::entries($id);
        $days = Hastan_Turkish::by_day($entries);
        $dict = Hastan_Turkish::dictionary($entries);
        $c = Hastan_Turkish::counts($entries);
        $now = Hastan_Settings::now();
        $today = $now->format('Y-m-d');

        // Weeks from the first entry (or this week): a tile for every week, lit when something was written.
        $monday = fn (string $d) => date('Y-m-d', strtotime($d . ' 12:00:00 -' . ((int) date('N', strtotime($d . ' 12:00:00')) - 1) . ' days'));
        $start = $monday($entries ? (string) min(array_keys($days)) : $today);
        $lit = [];
        foreach (array_keys($days) as $d) {
            $lit[$monday($d)] = ($lit[$monday($d)] ?? 0) + count($days[$d]);
        }
        $this_week = $monday($today);
        $weeks = [];
        for ($w = $start, $i = 0; $i < 26 || $w <= $this_week; $i++, $w = date('Y-m-d', strtotime($w . ' 12:00:00 +7 days'))) {
            $weeks[] = $w;
        }

        echo '<div class="wrap ht"><a class="hg-back" href="' . esc_url(self::goal_url()) . '">← Все цели</a>';
        self::notice();
        echo self::goal_styles();
        echo self::turkish_styles();

        echo '<div class="ht-hero"><svg class="ht-tulip" viewBox="0 0 60 100" aria-hidden="true"><path d="M30 98C30 76 31 58 30 40" stroke="#2a9d8f" stroke-width="3" fill="none"/>'
            . '<path d="M30 84C16 76 9 60 11 46c10 9 17 21 19 38z" fill="#2a9d8f"/><path d="M30 74c13-8 20-22 18-34-9 8-16 19-18 34z" fill="#3fb6a8"/>'
            . '<path d="M30 43C17 41 13 25 17 7c6 8 9 12 13 4 4 8 7 4 13-4 4 18 0 34-13 36z" fill="#c8402c"/><path d="M30 43c-5-6-6-18-3-30 1 5 2 8 3 8s2-3 3-8c3 12 2 24-3 30z" fill="#e2664f"/></svg>'
            . '<div class="ht-in"><div><div class="ht-eyebrow">Türkçe günlüğüm</div><h1>' . esc_html($goal['title']) . '<i>Türkçe</i></h1>'
            . '<div class="sub">' . esc_html($goal['subtitle'] !== '' && $goal['subtitle'] !== 'Türkçe günlüğüm · мой дневник' ? $goal['subtitle'] : 'Мой дневник: новые слова, фразы и всё, что узнала') . '</div>'
            . '<div class="ht-stats"><span><b>' . $c['word'] . '</b>слов<em>kelime</em></span><span><b>' . $c['phrase'] . '</b>фраз<em>ifade</em></span>'
            . '<span><b>' . $c['note'] . '</b>заметок<em>not</em></span><span><b>' . $c['days'] . '</b>дней с записями<em>gün</em></span></div></div>'
            . '<div class="ht-nazar" title="Слова и фразы в словарике"><div class="n">' . count($dict) . '<small>' . 'KELİME' . '</small></div></div></div></div>';

        // Write, and the word card.
        $cards = array_map(fn ($e) => ['tr' => Hastan_Turkish::mark($e['tr']), 'ru' => $e['body'] !== '' ? Hastan_Turkish::mark($e['body']) : '—', 'k' => $e['kind'] === 'phrase' ? 'ifade · фраза' : 'kelime · слово'], $dict);
        echo '<div class="ht-row"><div class="ht-card ht-form"><h2>Bugün ne öğrendim?</h2><div class="ru">Что я сегодня узнала</div>'
            . self::goal_form('tr_add', ['goal' => $id], '<div class="ht-send"><button class="ht-btn">Kaydet · Записать</button>'
                . '<span class="ht-hint">Каждая строка — отдельная запись: <code>merhaba — привет</code>. Строка без тире — заметка.<br>Выделите буквы и нажмите «Выделить», чтобы сделать их цветными: <code>kitap masa*da*</code>.<br>Боту можно так же: <code>турецкий: teşekkürler — спасибо</code></span></div>',
                '<div class="top"><input type="date" name="day" value="' . esc_attr($today) . '"><div class="ht-keys">'
                . implode('', array_map(fn ($k) => '<button type="button" data-k="' . $k . '">' . $k . '</button>', ['ç', 'ğ', 'ı', 'İ', 'ö', 'ş', 'ü', 'â']))
                . '<button type="button" class="hlb" title="Выделите часть текста и нажмите — она станет цветной">Выделить</button>'
                . '</div></div><textarea name="lines" id="ht-lines" required placeholder="merhaba — привет&#10;günaydın — доброе утро&#10;nasılsın? — как дела?&#10;ı без точки читается как глухое «ы»"></textarea>', '')
            . '</div><div class="ht-card"><h2>Kelime kartı</h2><div class="ru">Нажмите на карточку, чтобы перевернуть</div>'
            . '<div class="ht-flip" id="ht-flip"><div class="in"><div class="f front"><div class="w" id="ht-ftr">Merhaba!</div><div class="k" id="ht-fk">kelime · слово</div></div>'
            . '<div class="f back"><div class="w" id="ht-fru">Привет!</div><div class="k">türkçe → русский</div></div></div></div>'
            . '<div class="more"><span id="ht-fn">' . ($cards ? 'из ' . count($cards) . ' в словарике' : 'Здесь появятся ваши слова') . '</span>'
            . ($cards ? '<button type="button" id="ht-next">Başka · ещё</button>' : '') . '</div></div></div>';

        // Weeks.
        $lit_weeks = count($lit);
        echo '<div class="ht-weeks"><div class="lbl">Hafta hafta<small>неделя за неделей · ' . $lit_weeks . ' ' . self::plural($lit_weeks, ['неделя', 'недели', 'недель']) . ' с записями</small></div><div class="ht-tiles">';
        foreach ($weeks as $w) {
            $n = $lit[$w] ?? 0;
            $cls = $w > $this_week ? 'fut' : ($n ? 'on' . ($n >= 6 ? ' many' : '') : '');
            $till = date('d.m', strtotime($w . ' 12:00:00 +6 days'));
            echo '<i class="' . $cls . ($w === $this_week ? ' now' : '') . '" title="' . esc_attr(Hastan_Goals::short_date($w) . '–' . $till . ($n ? ': записей ' . $n : '')) . '"></i>';
        }
        echo '</div></div>';

        // Exercises made from diary pages.
        $drills = Hastan_Drills::all($id);
        if ($drills) {
            echo '<div class="ht-sec" id="gorev">Görevler <small>задания</small></div><div class="ht-gorev">';
            foreach ($drills as $d) {
                [$l, $t] = Hastan_Drills::progress((int) $d['id']);
                [$dd] = self::tr_date($d['day']);
                $state = ['pending' => 'готовится…', 'failed' => 'не получилось — откройте, чтобы повторить'][$d['status']] ?? 'выучено ' . $l . ' из ' . $t;
                echo '<a class="ht-g ' . esc_attr($d['status']) . '" href="' . esc_url(self::drill_url($id, (int) $d['id'])) . '"><b>' . esc_html($d['title']) . '</b><small>страница ' . esc_html($dd) . '</small>'
                    . '<div class="bar"><i style="width:' . ($t ? round($l / $t * 100, 1) : 0) . '%"></i></div><small>' . esc_html($state) . '</small></a>';
            }
            echo '</div>';
        }

        // Diary pages and the dictionary.
        echo '<div class="ht-main"><div><div class="ht-sec">Günlük <small>дневник</small></div>';
        if (!$days) {
            echo '<div class="ht-day ht-empty"><div class="w">Hoş geldin!</div>Добро пожаловать. Первая страница пока пустая — можно начать с <code>merhaba — привет</code>.</div>';
        }
        foreach ($days as $day => $list) {
            [$date, $gun, $year] = self::tr_date($day);
            $words = count(array_filter($list, fn ($e) => $e['tr'] !== ''));
            echo '<section class="ht-day" id="d' . esc_attr(str_replace('-', '', $day)) . '"><div class="ht-dh"><b>' . esc_html($date) . ($year !== $now->format('Y') ? ' ' . esc_html($year) : '') . '</b><span>' . esc_html($gun) . '</span>'
                . '<em>' . ($words ? $words . ' ' . self::plural($words, ['запись', 'записи', 'записей']) . ' по-турецки' : 'заметки') . '</em>'
                . self::goal_form('tr_drill', ['goal' => $id, 'day' => $day], '<button class="ht-mk" title="50 фраз на эту тему: 35 на турецкий, 10 на русский, 5 окончаний">＋ Задание</button>', '', '') . '</div>';
            foreach ($list as $e) {
                $anchor = 'd' . str_replace('-', '', $day);
                $del = '<span class="acts"><button type="button" class="ed" title="Изменить">✎</button>'
                    . self::goal_form('note_delete', ['note' => (int) $e['id'], 'goal' => $id, 'anchor' => $anchor], '<button class="x" title="Удалить" onclick="return confirm(\'Удалить запись?\')">×</button>', '', '') . '</span>'
                    . self::goal_form('tr_edit', ['note' => (int) $e['id'], 'goal' => $id, 'anchor' => $anchor], '<button class="ht-btn">Сохранить</button>',
                        '<input type="text" name="line" value="' . esc_attr(Hastan_Turkish::as_line($e)) . '"><span class="ht-keys"><button type="button" class="hlb">Выделить</button></span>', 'class="ef"');
                if ($e['tr'] === '') {
                    echo '<div class="ht-e note"><span class="star">✦</span><span class="txt">' . Hastan_Turkish::mark($e['body']) . '</span>' . $del . '</div>';
                } else {
                    echo '<div class="ht-e ' . esc_attr($e['kind']) . '" lang="tr"><span class="tr">' . Hastan_Turkish::mark($e['tr']) . '</span>' . ($e['body'] !== '' ? '<span class="ru" lang="ru">' . Hastan_Turkish::mark($e['body']) . '</span>' : '') . $del . '</div>';
                }
            }
            echo '</section>';
        }
        echo '</div><aside class="ht-card ht-dict"><h2>Sözlük</h2><div class="ru">Словарик: всё по алфавиту</div>';
        if ($dict) {
            $groups = [];
            foreach ($dict as $e) {
                $groups[Hastan_Turkish::initial($e['tr'])][] = $e;
            }
            echo '<input type="search" id="ht-q" placeholder="Ara · поиск"><div class="ht-abc">';
            foreach (mb_str_split('ABCÇDEFGĞHIİJKLMNOÖPRSŞTUÜVYZ') as $L) {
                echo isset($groups[$L]) ? '<a href="#L' . esc_attr(bin2hex($L)) . '">' . esc_html($L) . '</a>' : '<span>' . esc_html($L) . '</span>';
            }
            echo '</div><div class="ht-list" id="ht-list">';
            foreach ($groups as $L => $list) {
                echo '<div class="ht-l" id="L' . esc_attr(bin2hex($L)) . '">' . esc_html($L) . '</div>';
                foreach ($list as $e) {
                    echo '<div class="ht-w" data-s="' . esc_attr(Hastan_Turkish::lower(Hastan_Turkish::plain($e['tr'] . ' ' . $e['body']))) . '"><span class="tr" lang="tr">' . Hastan_Turkish::mark($e['tr']) . '</span><span class="ru">' . Hastan_Turkish::mark($e['body']) . '</span></div>';
                }
            }
            echo '</div>';
        } else {
            echo '<p class="ht-hint">Слова и фразы из дневника сами соберутся здесь по турецкому алфавиту.</p>';
        }
        echo '</aside></div>';

        echo '<script>(function(){var t=document.getElementById("ht-lines");document.addEventListener("focusin",function(ev){if(ev.target.matches("#ht-lines,.ht-e .ef input"))t=ev.target;});'
            . 'document.querySelectorAll(".ht-keys button").forEach(function(b){b.addEventListener("mousedown",function(ev){ev.preventDefault();});b.addEventListener("click",function(){var s=t.selectionStart,e=t.selectionEnd,v=t.value,k;if(b.dataset.k){k=b.dataset.k;t.value=v.slice(0,s)+k+v.slice(e);t.focus();t.selectionStart=t.selectionEnd=s+k.length;return;}'
            . 'if(s===e){t.focus();return;}k="*"+v.slice(s,e).replace(/\\*/g,"")+"*";t.value=v.slice(0,s)+k+v.slice(e);t.focus();t.selectionStart=s;t.selectionEnd=s+k.length;});});'
            . 'document.querySelectorAll(".ht-e .ed").forEach(function(b){b.addEventListener("click",function(){var r=b.closest(".ht-e");r.classList.toggle("editing");var i=r.querySelector(".ef input");if(r.classList.contains("editing")){i.focus();t=i;}});});'
            . 'var cards=' . wp_json_encode($cards) . ',f=document.getElementById("ht-flip"),i=-1;function show(){if(!cards.length)return;var j=cards.length>1?Math.floor(Math.random()*cards.length):0;if(j===i&&cards.length>1)j=(j+1)%cards.length;i=j;f.classList.remove("on");setTimeout(function(){document.getElementById("ht-ftr").innerHTML=cards[i].tr;document.getElementById("ht-fru").innerHTML=cards[i].ru;document.getElementById("ht-fk").textContent=cards[i].k;},f.classList.contains("on")?280:0);}'
            . 'f.addEventListener("click",function(){f.classList.toggle("on");});var n=document.getElementById("ht-next");if(n)n.addEventListener("click",show);show();'
            . 'var q=document.getElementById("ht-q");if(q)q.addEventListener("input",function(){var v=q.value.replace(/I/g,"ı").replace(/İ/g,"i").toLowerCase().trim();document.querySelectorAll("#ht-list .ht-w").forEach(function(w){w.style.display=!v||w.dataset.s.indexOf(v)>-1?"":"none";});document.querySelectorAll("#ht-list .ht-l").forEach(function(h){var x=h.nextElementSibling,any=false;while(x&&x.classList.contains("ht-w")){if(x.style.display!=="none")any=true;x=x.nextElementSibling;}h.style.display=any?"":"none";});});})();</script>';

        echo self::goal_edit($goal) . '</div>';
    }

    private static function plural(int $n, array $forms): string
    {
        $n = abs($n) % 100;
        $d = $n % 10;
        return $forms[$n > 10 && $n < 20 ? 2 : ($d === 1 ? 0 : ($d >= 2 && $d <= 4 ? 1 : 2))];
    }

    public static function handle_goal(): void
    {
        self::check('hastan_goal');
        $text = fn ($key) => sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        $back = (int) ($_POST['goal'] ?? 0);
        $anchor = preg_replace('/[^a-z0-9]/', '', (string) ($_POST['anchor'] ?? ''));
        switch (sanitize_key($_POST['op'] ?? '')) {
            case 'save':
                $id = (int) ($_POST['goal'] ?? 0);
                $goal = $id ? Hastan_Goals::get($id) : null;
                if ($text('title') !== '') {
                    $back = Hastan_Goals::save([
                        'title' => $text('title'),
                        'subtitle' => isset($_POST['subtitle']) ? $text('subtitle') : ($goal['subtitle'] ?? ''),
                        'kind' => $goal['kind'] ?? '',
                        'due' => $text('due'),
                        'status' => sanitize_key($_POST['status'] ?? 'active'),
                        'position' => $goal ? (int) $goal['position'] : count(Hastan_Goals::all()),
                    ], $goal ? $id : 0);
                }
                break;
            case 'delete':
                Hastan_Goals::delete((int) ($_POST['goal'] ?? 0));
                $back = 0;
                break;
            case 'step_add':
                if ($text('title') !== '' && Hastan_Goals::get($back)) {
                    Hastan_Goals::add_step($back, $text('title'));
                }
                break;
            case 'step_cycle':
                $step = Hastan_Goals::get_step((int) ($_POST['step'] ?? 0));
                if ($step) {
                    Hastan_Goals::set_state((int) $step['id'], ((int) $step['state'] + 1) % (Hastan_Goals::max_state((int) $step['goal_id']) + 1));
                    $back = (int) $step['goal_id'];
                }
                break;
            case 'step_repeat':
                $step = Hastan_Goals::get_step((int) ($_POST['step'] ?? 0));
                if ($step) {
                    Hastan_Goals::set_repeats((int) $step['id'], ((int) $step['repeats'] + 1) % 4);
                    $back = (int) $step['goal_id'];
                }
                break;
            case 'step_delete':
                $step = Hastan_Goals::get_step((int) ($_POST['step'] ?? 0));
                if ($step) {
                    Hastan_Goals::delete_step((int) $step['id']);
                    $back = (int) $step['goal_id'];
                }
                break;
            case 'note_add':
                $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text('day')) ? $text('day') : Hastan_Settings::now()->format('Y-m-d');
                if ($text('body') !== '' && Hastan_Goals::get($back)) {
                    Hastan_Goals::add_note($back, $day, $text('body'));
                }
                break;
            case 'note_delete':
                Hastan_Goals::delete_note((int) ($_POST['note'] ?? 0));
                break;
            case 'tr_edit':
                $goal = Hastan_Goals::get($back);
                if ($goal && $goal['kind'] === 'turkish' && $text('line') !== '') {
                    Hastan_Turkish::update((int) ($_POST['note'] ?? 0), $text('line'));
                }
                break;
            case 'tr_drill':
                $goal = Hastan_Goals::get($back);
                $day = $text('day');
                if ($goal && $goal['kind'] === 'turkish' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                    wp_safe_redirect(self::drill_url($back, Hastan_Drills::request($back, $day)));
                    exit;
                }
                break;
            case 'drill_retry':
            case 'drill_check':
            case 'drill_accept':
            case 'drill_learned':
            case 'drill_item_delete':
            case 'drill_words':
            case 'drill_delete':
                $drill = Hastan_Drills::get((int) ($_POST['drill'] ?? 0));
                if (!$drill || (int) $drill['goal_id'] !== $back) {
                    break;
                }
                $did = (int) $drill['id'];
                $item = Hastan_Drills::item((int) ($_POST['item'] ?? 0));
                $item = $item && (int) $item['drill_id'] === $did ? $item : null;
                $today = Hastan_Settings::now()->format('Y-m-d');
                $extra = !empty($_POST['checked']) ? '&checked=1' : '';
                switch (sanitize_key($_POST['op'])) {
                    case 'drill_retry':
                        Hastan_Drills::delete($did);
                        $did = Hastan_Drills::request($back, $drill['day']);
                        break;
                    case 'drill_check':
                        $answers = [];
                        foreach ((array) ($_POST['a'] ?? []) as $k => $v) {
                            $answers[(int) $k] = sanitize_text_field(wp_unslash((string) $v));
                        }
                        Hastan_Drills::check($did, $answers, $today);
                        $extra = '&checked=1';
                        break;
                    case 'drill_accept':
                        if ($item) {
                            Hastan_Drills::accept((int) $item['id'], $today);
                            $extra = '&checked=1#t' . (int) $item['id'];
                        }
                        break;
                    case 'drill_learned':
                        if ($item) {
                            Hastan_Drills::set_learned((int) $item['id'], !empty($_POST['on']));
                        }
                        break;
                    case 'drill_item_delete':
                        if ($item) {
                            Hastan_Drills::delete_item((int) $item['id']);
                        }
                        break;
                    case 'drill_words':
                        Hastan_Drills::add_words($drill, $today);
                        break;
                    case 'drill_delete':
                        Hastan_Drills::delete($did);
                        wp_safe_redirect(self::goal_url($back));
                        exit;
                }
                wp_safe_redirect(self::drill_url($back, $did, $extra));
                exit;
            case 'tr_add':
                $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $text('day')) ? $text('day') : Hastan_Settings::now()->format('Y-m-d');
                $goal = Hastan_Goals::get($back);
                if ($goal && $goal['kind'] === 'turkish') {
                    Hastan_Turkish::add_lines($back, $day, sanitize_textarea_field(wp_unslash($_POST['lines'] ?? '')));
                    $anchor = 'd' . str_replace('-', '', $day);
                }
                break;
        }
        wp_safe_redirect(self::goal_url($back, $anchor));
        exit;
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
