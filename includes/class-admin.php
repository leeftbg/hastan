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
