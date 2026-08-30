<?php
defined('ABSPATH') || exit;

// ============================================================
// 5. СЕРВЕРНАЯ ВАЛИДАЦИЯ
// ============================================================

function ysc_verify_token($token) {
    static $already_checked = null;
    static $already_result  = null;

    if ($already_checked !== null) {
        return $already_result;
    }

    if (ysc_is_limit_reached()) {
        error_log('[YSC] Лимит ' . YSC_MONTHLY_LIMIT . ' запросов исчерпан. Проверка пропущена.');
        $already_checked = true;
        $already_result  = true;
        return true;
    }

    if (empty($token)) {
        $already_checked = true;
        $already_result  = false;
        return false;
    }

    $current_count = ysc_increment_counter();
    $remaining     = YSC_MONTHLY_LIMIT - $current_count;

    if ($remaining <= 500 && $remaining > 0) {
        error_log('[YSC] ВНИМАНИЕ: осталось ' . $remaining . ' запросов до лимита!');
    }

    ysc_maybe_send_limit_notice($current_count);

    $response = wp_remote_post(
        'https://smartcaptcha.yandexcloud.net/validate',
        array(
            'timeout' => 15,
            'body'    => array(
                'secret' => YSC_SERVER_KEY,
                'token'  => $token,
                'ip'     => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            ),
        )
    );

    if (is_wp_error($response)) {
        error_log('[YSC] Ошибка запроса: ' . $response->get_error_message());
        $already_checked = true;
        $already_result  = false;
        return false;
    }

    $body   = json_decode(wp_remote_retrieve_body($response), true);
    $result = isset($body['status']) && $body['status'] === 'ok';

    $already_checked = true;
    $already_result  = $result;
    return $result;
}

// --- 5.1 Contact Form 7 ---
if (ysc_form_enabled('cf7')) {
    add_filter('wpcf7_validate', 'ysc_validate_cf7', 10, 2);
}
function ysc_validate_cf7($result, $tags) {
    $token = sanitize_text_field(wp_unslash($_POST['smart-token'] ?? ''));
    if (!ysc_verify_token($token)) {
        $result->invalidate(array(
            'type' => 'captcha',
            'name' => 'smart-token',
        ), 'Проверка капчи не пройдена. Попробуйте снова.');
    }
    return $result;
}

// --- 5.2 WooCommerce Checkout ---
if (ysc_form_enabled('woo')) {
    add_action('woocommerce_checkout_process', 'ysc_validate_checkout');
}
function ysc_validate_checkout() {
    $token = sanitize_text_field(wp_unslash($_POST['smart-token'] ?? ''));
    if (!ysc_verify_token($token)) {
        wc_add_notice(
            __('Проверка капчи не пройдена. Попробуйте снова.', 'woocommerce'),
            'error'
        );
    }
}

// --- 5.3 WooCommerce Login ---
if (ysc_form_enabled('woo')) {
    add_filter('woocommerce_process_login_errors', 'ysc_validate_woo_login', 10, 3);
}
function ysc_validate_woo_login($validation_error, $username, $password) {
    $token = sanitize_text_field(wp_unslash($_POST['smart-token'] ?? ''));
    if (!ysc_verify_token($token)) {
        return new WP_Error(
            'captcha_failed',
            __('Проверка капчи не пройдена. Попробуйте снова.', 'woocommerce')
        );
    }
    return $validation_error;
}

// --- 5.4 wp-login.php ---
if (ysc_form_enabled('wp_login')) {
    add_filter('authenticate', 'ysc_validate_wp_login', 30, 3);
}
function ysc_validate_wp_login($user, $username, $password) {
    if (
        empty($_POST) ||
        !isset($_POST['log']) ||
        (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
    ) {
        return $user;
    }

    $token = sanitize_text_field(wp_unslash($_POST['smart-token'] ?? ''));
    if (!ysc_verify_token($token)) {
        return new WP_Error(
            'captcha_failed',
            '<strong>Ошибка:</strong> Проверка капчи не пройдена. Попробуйте снова.'
        );
    }
    return $user;
}

// --- 5.5 Формы Impreza / UpSolution ---
if (ysc_form_enabled('impreza')) {
    add_filter('us_form_validate', 'ysc_validate_impreza_form', 10, 2);
    add_action('wp_ajax_nopriv_us_ajax_form_submit', 'ysc_validate_us_ajax_form', 1);
    add_action('wp_ajax_us_ajax_form_submit',        'ysc_validate_us_ajax_form', 1);
}
function ysc_validate_impreza_form($errors, $form_data) {
    $token = sanitize_text_field(wp_unslash($_POST['smart-token'] ?? ''));
    if (!ysc_verify_token($token)) {
        $errors[] = 'Проверка капчи не пройдена. Попробуйте снова.';
    }
    return $errors;
}
function ysc_validate_us_ajax_form() {
    $token = sanitize_text_field(wp_unslash($_POST['smart-token'] ?? ''));
    if (!ysc_verify_token($token)) {
        wp_send_json_error(array(
            'message' => 'Проверка капчи не пройдена. Попробуйте снова.',
        ));
        wp_die();
    }
}
