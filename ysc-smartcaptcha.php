<?php
/**
 * Plugin Name: Яндекс SmartCaptcha
 * Plugin URI:  https://github.com/
 * Description: Невидимая Яндекс SmartCaptcha для всех форм WordPress. Поддерживает CF7, Impreza, WooCommerce, wp-login.php.
 * Version:     1.0.0
 * Author:      Bienen Vibecoding (Claude)
 * License:     GPL-2.0-or-later
 * Text Domain: ysc-smartcaptcha
 */

defined('ABSPATH') || exit;

define('YSC_VERSION',    '1.0.0');
define('YSC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YSC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Настройки из БД
define('YSC_CLIENT_KEY',    get_option('ysc_client_key', ''));
define('YSC_SERVER_KEY',    get_option('ysc_server_key', ''));
define('YSC_MONTHLY_LIMIT', (int) get_option('ysc_monthly_limit', 10000));

// Модули
require_once YSC_PLUGIN_DIR . 'includes/counter.php';
require_once YSC_PLUGIN_DIR . 'includes/enqueue.php';
require_once YSC_PLUGIN_DIR . 'includes/form-patch.php';
require_once YSC_PLUGIN_DIR . 'includes/validation.php';
require_once YSC_PLUGIN_DIR . 'includes/notifications.php';
require_once YSC_PLUGIN_DIR . 'admin/settings-page.php';
require_once YSC_PLUGIN_DIR . 'admin/dashboard-widget.php';

// Активация
register_activation_hook(__FILE__, 'ysc_on_activate');
function ysc_on_activate() {
    if (!get_option('ysc_request_counter')) {
        add_option('ysc_request_counter', array(
            'count' => 0,
            'month' => date('Y-m'),
        ), '', false);
    }
}

// Ссылка "Настройки" в списке плагинов
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'ysc_plugin_action_links');
function ysc_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=ysc-smartcaptcha') . '">Настройки</a>';
    array_unshift($links, $settings_link);
    return $links;
}
