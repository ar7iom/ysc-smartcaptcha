<?php
/**
 * Минимальное окружение WordPress для интеграционных тестов модулей плагина.
 */

define('ABSPATH', __DIR__ . '/');
define('AUTH_KEY', 'test-auth-key-not-for-production');
define('YSC_SERVER_KEY', 'server-secret');
define('YSC_CLIENT_KEY', 'client-key');
define('YSC_MONTHLY_LIMIT', 10000);
define('YSC_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
define('YSC_PLUGIN_URL', 'https://example.test/wp-content/plugins/ysc-smartcaptcha/');
define('YSC_VERSION', 'test');

$GLOBALS['ysc_options'] = array(
    'ysc_forms_enabled' => array(
        'cf7' => 1,
        'impreza' => 1,
        'woo' => 1,
        'wp_login' => 1,
    ),
    'ysc_request_counter' => array('count' => 0, 'month' => date('Y-m')),
    'ysc_monthly_limit' => 10000,
);
$GLOBALS['ysc_http_responses'] = array();
$GLOBALS['ysc_http_requests'] = array();
$GLOBALS['ysc_enqueued_scripts'] = array();
$GLOBALS['ysc_enqueued_styles'] = array();
$GLOBALS['ysc_inline_scripts'] = array();
$GLOBALS['ysc_registered_settings'] = array();
$GLOBALS['ysc_settings_errors'] = array();

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['ysc_options'])
        ? $GLOBALS['ysc_options'][$name]
        : $default;
}

function update_option($name, $value, $autoload = null) {
    $GLOBALS['ysc_options'][$name] = $value;
    return true;
}

function add_option($name, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($name, $GLOBALS['ysc_options'])) return false;
    $GLOBALS['ysc_options'][$name] = $value;
    return true;
}

function add_action() {}
function add_filter() {}

function register_setting($group, $name, $args) {
    $GLOBALS['ysc_registered_settings'][$name] = $args;
}

function add_settings_error($setting, $code, $message) {
    $GLOBALS['ysc_settings_errors'][] = compact('setting', 'code', 'message');
}

function sanitize_text_field($value) {
    return trim(strip_tags($value));
}

function wp_unslash($value) {
    return is_string($value) ? stripslashes($value) : $value;
}

function wp_json_encode($value, $flags = 0) {
    return json_encode($value, $flags);
}

function __($message, $domain = null) {
    return $message;
}

class WP_Error {
    private $errors = array();

    public function __construct($code = '', $message = '') {
        if ($code !== '') $this->add($code, $message);
    }

    public function add($code, $message) {
        $this->errors[$code][] = $message;
    }

    public function get_error_codes() {
        return array_keys($this->errors);
    }

    public function get_error_message($code = '') {
        if ($code === '') {
            $codes = $this->get_error_codes();
            $code = reset($codes);
        }
        return isset($this->errors[$code][0]) ? $this->errors[$code][0] : '';
    }
}

function is_wp_error($value) {
    return $value instanceof WP_Error;
}

function wp_remote_post($url, $args) {
    $GLOBALS['ysc_http_requests'][] = array('url' => $url, 'args' => $args);
    if (!$GLOBALS['ysc_http_responses']) {
        throw new RuntimeException('Не задан имитированный HTTP-ответ.');
    }
    return array_shift($GLOBALS['ysc_http_responses']);
}

function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}

function wp_mail() {
    return true;
}

function get_bloginfo($field) {
    return $field === 'url' ? 'https://example.test' : 'Test site';
}

function wp_enqueue_style($handle, $src, $deps, $version) {
    $GLOBALS['ysc_enqueued_styles'][$handle] = compact('src', 'deps', 'version');
}

function wp_enqueue_script($handle, $src, $deps, $version, $in_footer) {
    $GLOBALS['ysc_enqueued_scripts'][$handle] = compact('src', 'deps', 'version', 'in_footer');
}

function wp_add_inline_script($handle, $code, $position) {
    $GLOBALS['ysc_inline_scripts'][$handle] = compact('code', 'position');
}

function determine_locale() {
    return 'ru_RU';
}

function ysc_form_enabled($type) {
    $enabled = get_option('ysc_forms_enabled', array());
    return !empty($enabled[$type]);
}

require_once YSC_PLUGIN_DIR . 'includes/crypto.php';
require_once YSC_PLUGIN_DIR . 'includes/counter.php';
require_once YSC_PLUGIN_DIR . 'includes/notifications.php';
require_once YSC_PLUGIN_DIR . 'includes/validation.php';
require_once YSC_PLUGIN_DIR . 'includes/form-patch.php';
require_once YSC_PLUGIN_DIR . 'includes/enqueue.php';
require_once YSC_PLUGIN_DIR . 'admin/settings-page.php';
