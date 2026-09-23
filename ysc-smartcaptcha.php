<?php
/**
 * Plugin Name: Яндекс SmartCaptcha
 * Plugin URI:  https://github.com/ar7iom/ysc-smartcaptcha
 * Description: Невидимая Яндекс SmartCaptcha для всех форм WordPress. Поддерживает CF7, Impreza, WooCommerce, wp-login.php.
 * Version:     1.1.4
 * Author:      Bienen Vibecoding (Claude)
 * License:     GPL-2.0-or-later
 * Text Domain: ysc-smartcaptcha
 */

defined('ABSPATH') || exit;

define('YSC_VERSION',    '1.1.4');
define('YSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YSC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Подключаем модули — только код, без вызовов БД
require_once YSC_PLUGIN_DIR . 'includes/crypto.php';
require_once YSC_PLUGIN_DIR . 'includes/counter.php';
require_once YSC_PLUGIN_DIR . 'includes/notifications.php';

// Инициализация после загрузки WordPress
add_action('plugins_loaded', 'ysc_init_plugin');
function ysc_init_plugin() {
    // Константы с ключами инициализируем здесь — БД уже доступна
    if (!defined('YSC_CLIENT_KEY')) {
        define('YSC_CLIENT_KEY',    ysc_get_client_key());
    }
    if (!defined('YSC_SERVER_KEY')) {
        define('YSC_SERVER_KEY',    ysc_get_server_key());
    }
    if (!defined('YSC_MONTHLY_LIMIT')) {
        define('YSC_MONTHLY_LIMIT', (int) get_option('ysc_monthly_limit', 10000));
    }

    // Подключаем остальные модули после инициализации констант
    require_once YSC_PLUGIN_DIR . 'includes/enqueue.php';
    require_once YSC_PLUGIN_DIR . 'includes/form-patch.php';
    require_once YSC_PLUGIN_DIR . 'includes/validation.php';
    require_once YSC_PLUGIN_DIR . 'admin/settings-page.php';
    require_once YSC_PLUGIN_DIR . 'admin/dashboard-widget.php';
}

// Активация
register_activation_hook(__FILE__, 'ysc_on_activate');
function ysc_on_activate() {
    if (!get_option('ysc_request_counter')) {
        add_option('ysc_request_counter', array(
            'count' => 0,
            'month' => date('Y-m'),
        ), '', false);
    }
    if (!get_option('ysc_forms_enabled')) {
        add_option('ysc_forms_enabled', array(
            'cf7'      => 1,
            'impreza'  => 1,
            'woo'      => 1,
            'wp_login' => 1,
        ), '', false);
    }
}

// Ссылка "Настройки" в списке плагинов
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ysc_plugin_action_links');
function ysc_plugin_action_links($links) {
    $url = admin_url('options-general.php?page=ysc-smartcaptcha');
    array_unshift($links, '<a href="' . esc_url($url) . '">Настройки</a>');
    return $links;
}

/**
 * Проверить включена ли поддержка конкретного типа форм.
 */
function ysc_form_enabled($type) {
    $enabled = get_option('ysc_forms_enabled', array());
    return !empty($enabled[$type]);
}
