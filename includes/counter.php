<?php
defined('ABSPATH') || exit;

// ============================================================
// 1. СЧЁТЧИК ЗАПРОСОВ
// ============================================================

/**
 * Возвращает текущий счётчик. Кэшируется внутри запроса через статику.
 * Флаг $reset_cache позволяет сбросить кэш после инкремента.
 */
function ysc_get_counter($reset_cache = false) {
    static $cached = null;

    if ($reset_cache) {
        $cached = null;
        return null;
    }

    if ($cached !== null) {
        return $cached;
    }

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

    $cached = $counter;
    return $cached;
}

function ysc_increment_counter() {
    // Сбрасываем кэш перед инкрементом
    ysc_get_counter(true);

    $counter = ysc_get_counter();
    $counter['count']++;
    update_option('ysc_request_counter', $counter, false);

    // Сбрасываем кэш после записи, чтобы следующий вызов прочёл актуальные данные
    ysc_get_counter(true);

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
