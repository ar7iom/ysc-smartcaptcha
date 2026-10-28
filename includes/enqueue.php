<?php
defined('ABSPATH') || exit;

// ============================================================
// 2. ПОДКЛЮЧЕНИЕ СКРИПТА
// ============================================================

add_action('wp_enqueue_scripts',    'ysc_enqueue_captcha_script');
add_action('login_enqueue_scripts', 'ysc_enqueue_captcha_script');

function ysc_enqueue_captcha_script() {
    if (ysc_is_limit_reached()) return;
    if (!YSC_CLIENT_KEY)        return;
    if (ysc_get_form_selectors() === array()) return;

    wp_enqueue_style(
        'ysc-smartcaptcha',
        YSC_PLUGIN_URL . 'assets/frontend.css',
        array(),
        YSC_VERSION
    );

    // Explicit rendering: callback is registered before the provider script.
    $captcha_src = 'https://smartcaptcha.yandexcloud.net/captcha.js?render=onload&onload=yscSmartCaptchaOnload';

    wp_enqueue_script(
        'yandex-smartcaptcha',
        $captcha_src,
        array(),
        null,
        true
    );

    // Callback onload должен существовать до загрузки captcha.js.
    wp_add_inline_script('yandex-smartcaptcha', ysc_get_inline_js(), 'before');
}

/**
 * Язык виджета по локали WordPress.
 */
function ysc_get_widget_language() {
    $locale  = function_exists('determine_locale') ? determine_locale() : get_locale();
    $lang    = strtolower(substr((string) $locale, 0, 2));
    $allowed = array('ru', 'en', 'be', 'kk', 'tt', 'uk', 'uz', 'tr');
    return in_array($lang, $allowed, true) ? $lang : 'ru';
}

/**
 * Селекторы только для включённых типов форм.
 * Без широких подстрочных селекторов вроде [class*="w-form"] —
 * они цепляют форму поиска Impreza (class="w-form-row").
 */
function ysc_get_form_selectors() {
    $forms     = get_option('ysc_forms_enabled', array());
    $selectors = array();

    if (!empty($forms['cf7'])) {
        $selectors[] = 'form.wpcf7-form';
        $selectors[] = '.wpcf7 form';
    }

    if (!empty($forms['impreza'])) {
        $selectors[] = 'form.w-form';
        $selectors[] = 'form.us-form';
        $selectors[] = 'form.for_cform';
    }

    if (!empty($forms['woo'])) {
        $selectors[] = 'form.woocommerce-checkout';
        $selectors[] = 'form.woocommerce-form-login';
        $selectors[] = 'form.woocommerce-form-register';
    }

    if (!empty($forms['wp_login'])) {
        $selectors[] = '#loginform';
    }

    return $selectors;
}

/** Configuration is encoded as JSON, not interpolated into JavaScript strings. */
function ysc_get_inline_js() {
    $config = array(
        'sitekey' => YSC_CLIENT_KEY,
        'language' => ysc_get_widget_language(),
        'selectors' => ysc_get_form_selectors(),
        'message' => 'Не удалось выполнить проверку капчи. Проверьте соединение и блокировщики, затем повторите отправку.',
        'busyMessage' => 'Дождитесь завершения проверки другой формы.',
    );
    return 'window.yscCaptchaConfig = ' . wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';' . "\n" . file_get_contents(YSC_PLUGIN_DIR . 'assets/frontend.js');
}
