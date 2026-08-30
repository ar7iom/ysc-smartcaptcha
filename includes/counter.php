<?php
defined('ABSPATH') || exit;

// ============================================================
// 1. СЧЁТЧИК ЗАПРОСОВ
// ============================================================

function ysc_get_counter() {
    $default = array(
        'count' => 0,
        'month' => date('Y-m'),
    );
    $counter = get_option('ysc_request_counter', $default);

    if ( ! is_array($counter) || empty($counter['month']) ) {
        $counter = $default;
    }

    if ($counter['month'] !== date('Y-m')) {
        $counter = $default;
        update_option('ysc_request_counter', $counter, false);
        error_log('[YSC] Счётчик сброшен: новый месяц ' . date('Y-m'));
    }

    return $counter;
}

function ysc_increment_counter() {
    $counter = ysc_get_counter();
    $counter['count']++;
    update_option('ysc_request_counter', $counter, false);
    return $counter['count'];
}

function ysc_is_limit_reached() {
    $counter = ysc_get_counter();
    return $counter['count'] >= YSC_MONTHLY_LIMIT;
}

function ysc_get_remaining() {
    $counter = ysc_get_counter();
    return max(0, YSC_MONTHLY_LIMIT - $counter['count']);
}
