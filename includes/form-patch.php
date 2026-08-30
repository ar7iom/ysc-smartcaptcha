<?php
defined('ABSPATH') || exit;

// ============================================================
// 4. PHP: ДОБАВЛЕНИЕ КЛАССА К ФОРМАМ
// ============================================================

add_filter('us_form_args', 'ysc_add_class_to_impreza_form', 10, 1);
function ysc_add_class_to_impreza_form($args) {
    if (ysc_is_limit_reached()) return $args;
    $args['classes'] = isset($args['classes'])
        ? $args['classes'] . ' ysc-protected'
        : 'ysc-protected';
    return $args;
}

add_filter('the_content', 'ysc_add_class_to_forms_in_content', 99);
add_filter('widget_text',  'ysc_add_class_to_forms_in_content', 99);

function ysc_add_class_to_forms_in_content($content) {
    if (empty($content) || ysc_is_limit_reached()) return $content;
    return ysc_patch_html_forms($content);
}

add_filter('us_ajax_response', 'ysc_patch_ajax_response_forms', 10, 1);
function ysc_patch_ajax_response_forms($response) {
    if (ysc_is_limit_reached()) return $response;
    if (is_array($response) && isset($response['html'])) {
        $response['html'] = ysc_patch_html_forms($response['html']);
    }
    return $response;
}

add_action('wp_ajax_us_ajax_grid',        'ysc_buffer_us_ajax_output', 0);
add_action('wp_ajax_nopriv_us_ajax_grid', 'ysc_buffer_us_ajax_output', 0);

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

function ysc_patch_html_forms($html) {
    if (empty($html)) return $html;

    return preg_replace_callback(
        '/(<form\b[^>]*>)/i',
        function($matches) {
            $tag = $matches[1];
            if (strpos($tag, 'ysc-protected') !== false) return $tag;

            if (strpos($tag, 'class=') !== false) {
                $tag = preg_replace(
                    '/class=(["\'])([^"\']*)\1/',
                    'class=$1$2 ysc-protected$1',
                    $tag
                );
            } else {
                $tag = str_replace('<form', '<form class="ysc-protected"', $tag);
            }
            return $tag;
        },
        $html
    );
}
