<?php
/**
 * Plugin Name: Hastan — личный ИИ-помощник
 * Description: Задачи, чек-листы и напоминания через Telegram. Разбирает сообщения с помощью Claude, утром присылает план, вечером проверяет, что сделано.
 * Version: 0.8.0
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Author: xf
 * Text Domain: hastan
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HASTAN_VERSION', '0.8.0');
define('HASTAN_DIR', plugin_dir_path(__FILE__));
define('HASTAN_FILE', __FILE__);

require_once HASTAN_DIR . 'includes/class-settings.php';
require_once HASTAN_DIR . 'includes/class-db.php';
require_once HASTAN_DIR . 'includes/class-templates.php';
require_once HASTAN_DIR . 'includes/class-habits.php';
require_once HASTAN_DIR . 'includes/class-telegram.php';
require_once HASTAN_DIR . 'includes/class-brain.php';
require_once HASTAN_DIR . 'includes/class-scheduler.php';
require_once HASTAN_DIR . 'includes/class-mcp.php';
require_once HASTAN_DIR . 'includes/class-admin.php';

register_activation_hook(__FILE__, function () {
    Hastan_DB::install();
    Hastan_Settings::ensure_secrets();
    Hastan_Scheduler::activate();
});

register_deactivation_hook(__FILE__, function () {
    Hastan_Scheduler::deactivate();
});

add_action('plugins_loaded', function () {
    if (get_option('hastan_db_version') !== Hastan_DB::VERSION) {
        Hastan_DB::install();
        Hastan_Settings::ensure_secrets();
    }
});

// Updates come from GitHub releases: WordPress offers a new version like any other plugin.
if (file_exists(HASTAN_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php')) {
    require_once HASTAN_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
    $hastan_updates = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker('https://github.com/leeftbg/hastan/', __FILE__, 'hastan');
    $hastan_updates->getVcsApi()->enableReleaseAssets();
}

Hastan_Telegram::init();
Hastan_Scheduler::init();
Hastan_MCP::init();
Hastan_Admin::init();
