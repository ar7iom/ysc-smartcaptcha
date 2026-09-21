<?php
defined('ABSPATH') || exit;

// ============================================================
// 4. PHP: ДОБАВЛЕНИЕ КЛАССА К ФОРМАМ
// ============================================================

// Impreza: добавляем класс через фильтр аргументов формы
add_filter('us_form_args', 'ysc_add_class_to_impreza_form', 10, 1);
function ysc_add_class_to_impreza_form($args) {
    if (!ysc_form_enabled('impreza')) return $args;
    if (ysc_is_limit_reached()) return $args;
    $args['classes'] = isset($args['classes'])
        ? $args['classes'] . ' ysc-protected'
        : 'ysc-protected';
    return $args;
}

// Патчим формы в контенте только для включённых типов
add_filter('the_content', 'ysc_add_class_to_forms_in_content', 99);
add_filter('widget_text',  'ysc_add_class_to_forms_in_content', 99);

function ysc_add_class_to_forms_in_content($content) {
    if (empty($content) || ysc_is_limit_reached()) return $content;

    $forms = get_option('ysc_forms_enabled', array());
    $any_enabled = !empty($forms['cf7'])
        || !empty($forms['impreza'])
        || !empty($forms['woo'])
        || !empty($forms['wp_login']);

    if (!$any_enabled) return $content;

    return ysc_patch_html_forms($content);
}

// AJAX-ответы Impreza
add_filter('us_ajax_response', 'ysc_patch_ajax_response_forms', 10, 1);
function ysc_patch_ajax_response_forms($response) {
    if (!ysc_form_enabled('impreza')) return $response;
    if (ysc_is_limit_reached()) return $response;
    if (is_array($response) && isset($response['html'])) {
        $response['html'] = ysc_patch_html_forms($response['html']);
    }
    return $response;
}

if (ysc_form_enabled('impreza')) {
    add_action('wp_ajax_us_ajax_grid',        'ysc_buffer_us_ajax_output', 0);
    add_action('wp_ajax_nopriv_us_ajax_grid', 'ysc_buffer_us_ajax_output', 0);
}

function ysc_buffer_us_ajax_output() {
    if (!ysc_is_limit_reached()) {
        ob_start('ysc_patch_output_buffer');
    }
}

function ysc_patch_output_buffer($buffer) {
    if (empty($buffer)) return $buffer;
    $data = json_decode($buffer, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($data['html'])) {
        $data['html'] = ysc_patch_html_forms($data['html']);
        return wp_json_encode($data);
    }
    return ysc_patch_html_forms($buffer);
}

/**
 * Пропускать поисковые/GET-формы и внутренние формы виджета.
 */
function ysc_form_tag_is_skippable($tag) {
    if (preg_match('/\brole\s*=\s*([\'"])search\1/i', $tag)) {
        return true;
    }
    if (preg_match('/\bmethod\s*=\s*([\'"])get\1/i', $tag)) {
        return true;
    }
    if (preg_match('/\bw-form-row\b/i', $tag) || preg_match('/\bw-search\b/i', $tag)) {
        return true;
    }
    return false;
}

/**
 * Тег <form> относится к включённому типу защиты.
 */
function ysc_form_tag_matches_enabled($tag) {
    $forms = get_option('ysc_forms_enabled', array());

    if (!empty($forms['cf7']) && preg_match('/wpcf7/i', $tag)) {
        return true;
    }

    if (!empty($forms['impreza'])) {
        $is_impreza = preg_match('/(?<![-\w])w-form(?![-\w])/', $tag)
            || preg_match('/(?<![-\w])us-form(?![-\w])/', $tag)
            || preg_match('/(?<![-\w])for_cform(?![-\w])/', $tag);
        if ($is_impreza) {
            return true;
        }
    }

    if (
        !empty($forms['woo'])
        && preg_match('/woocommerce-(checkout|form-login|form-register)/i', $tag)
    ) {
        return true;
    }

    if (!empty($forms['wp_login']) && preg_match('/\bid\s*=\s*([\'"])loginform\1/i', $tag)) {
        return true;
    }

    return false;
}

/**
 * Добавляет класс ysc-protected только к формам нужных типов.
 */
function ysc_patch_html_forms($html) {
    if (empty($html)) return $html;

    return preg_replace_callback(
        '/(<form\b[^>]*>)/i',
        function($matches) {
            $tag = $matches[1];
            if (strpos($tag, 'ysc-protected') !== false) return $tag;
            if (ysc_form_tag_is_skippable($tag)) return $tag;
            if (!ysc_form_tag_matches_enabled($tag)) return $tag;

            if (strpos($tag, 'class=') !== false) {
                $tag = preg_replace(
                    '/class=(["\'])([^"\']*)\1/',
                    'class=$1$2 ysc-protected$1',
                    $tag,
                    1
                );
            } else {
                $tag = preg_replace('/<form\b/i', '<form class="ysc-protected"', $tag, 1);
            }
            return $tag;
        },
        $html
    );
}
