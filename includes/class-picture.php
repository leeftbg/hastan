<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The habit picture: a mosaic over a real image. Every habit mark opens one
 * piece of the part of the picture that belongs to that habit; closed pieces
 * are not shown at all. A picture pack is a zip with picture.jpg, tiles.png
 * (the piece id of every pixel, R + G * 256) and picture.json (parts, their
 * habits and the order in which their pieces open).
 */
class Hastan_Picture
{
    const OPTION = 'hastan_picture';
    const PAPER = [243, 239, 230];

    public static function dir(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . 'hastan-picture/';
    }

    public static function url(string $file): string
    {
        return trailingslashit(wp_upload_dir()['baseurl']) . 'hastan-picture/' . $file . '?v=' . (int) (self::state()['version'] ?? 0);
    }

    public static function state(): array
    {
        $state = get_option(self::OPTION);
        return is_array($state) ? $state : [];
    }

    public static function exists(): bool
    {
        return !empty(self::state()['start']) && file_exists(self::dir() . 'picture.json');
    }

    public static function meta(): ?array
    {
        $json = @file_get_contents(self::dir() . 'picture.json');
        $meta = $json ? json_decode($json, true) : null;
        return is_array($meta) && !empty($meta['zones']) ? $meta : null;
    }

    /** Unpacks an uploaded pack; parts are matched to habits by name. Returns an error or null. */
    public static function install_pack(string $zip_path): ?string
    {
        if (!class_exists('ZipArchive')) {
            return 'На сервере нет модуля zip для PHP.';
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            return 'Это не zip-архив.';
        }
        $files = [];
        foreach (['picture.jpg', 'tiles.png', 'picture.json'] as $name) {
            $data = $zip->getFromName($name);
            if ($data === false) {
                $zip->close();
                return "В архиве нет файла {$name}.";
            }
            $files[$name] = $data;
        }
        $zip->close();
        $meta = json_decode($files['picture.json'], true);
        if (!is_array($meta) || empty($meta['zones'])) {
            return 'Файл picture.json повреждён.';
        }
        wp_mkdir_p(self::dir());
        foreach ($files as $name => $data) {
            file_put_contents(self::dir() . $name, $data);
        }

        $by_name = [];
        foreach (Hastan_Habits::all() as $habit) {
            $by_name[mb_strtolower($habit['name'])] = (int) $habit['id'];
        }
        $map = [];
        foreach ($meta['zones'] as $i => $zone) {
            $map[$i] = array_values(array_filter(array_map(
                fn ($name) => $by_name[mb_strtolower($name)] ?? 0,
                (array) ($zone['habits'] ?? [])
            )));
        }
        $state = self::state();
        update_option(self::OPTION, [
            'start' => Hastan_Settings::now()->format('Y-m-d'),
            'map' => $map,
            'version' => (int) ($state['version'] ?? 0) + 1,
        ], false);
        return null;
    }

    public static function set_map(array $map): void
    {
        $state = self::state();
        $state['map'] = $map;
        update_option(self::OPTION, $state, false);
    }

    /** Per part: name, habit ids, pieces total and the ids of open pieces. */
    public static function progress(): array
    {
        $meta = self::meta();
        $state = self::state();
        if (!$meta || empty($state['start'])) {
            return [];
        }
        $today = Hastan_Settings::now()->format('Y-m-d');
        $marks = Hastan_Habits::marks($state['start'], $today);
        $out = [];
        foreach ($meta['zones'] as $i => $zone) {
            $habit_ids = array_map('intval', (array) ($state['map'][$i] ?? []));
            $count = 0;
            foreach ($habit_ids as $id) {
                $count += count($marks[$id] ?? []);
            }
            $tiles = (array) $zone['tiles'];
            $out[] = [
                'name' => $zone['name'],
                'habits' => $habit_ids,
                'total' => count($tiles),
                'open' => array_slice($tiles, 0, min($count, count($tiles))),
            ];
        }
        return $out;
    }

    public static function open_ids(): array
    {
        $ids = [];
        foreach (self::progress() as $zone) {
            array_push($ids, ...$zone['open']);
        }
        return $ids;
    }

    /** A small PNG of the current state for Telegram, or null. */
    public static function render_png(int $step = 2): ?string
    {
        if (!self::exists() || !function_exists('imagecreatefrompng')) {
            return null;
        }
        $picture = @imagecreatefromjpeg(self::dir() . 'picture.jpg');
        $tiles = @imagecreatefrompng(self::dir() . 'tiles.png');
        if (!$picture || !$tiles) {
            return null;
        }
        $open = array_flip(self::open_ids());
        $w = intdiv(imagesx($tiles), $step);
        $h = intdiv(imagesy($tiles), $step);
        $out = imagecreatetruecolor($w, $h);
        $paper = imagecolorallocate($out, ...self::PAPER);
        $line = imagecolorallocate($out, 226, 220, 207);
        imagefill($out, 0, 0, $paper);
        $prev_row = [];
        for ($y = 0; $y < $h; $y++) {
            $left = -1;
            $row = [];
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($tiles, $x * $step, $y * $step);
                $id = (($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) * 256;
                $row[$x] = $id;
                if (isset($open[$id])) {
                    imagesetpixel($out, $x, $y, imagecolorat($picture, $x * $step, $y * $step));
                } elseif ($id !== $left || (isset($prev_row[$x]) && $prev_row[$x] !== $id)) {
                    imagesetpixel($out, $x, $y, $line);
                }
                $left = $id;
            }
            $prev_row = $row;
        }
        ob_start();
        imagepng($out);
        return ob_get_clean();
    }
}
