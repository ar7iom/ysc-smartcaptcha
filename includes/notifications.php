<?php
defined('ABSPATH') || exit;

// ============================================================
// 6. EMAIL УВЕДОМЛЕНИЯ О ЛИМИТЕ
// ============================================================

function ysc_maybe_send_limit_notice($current_count) {
    $limit     = YSC_MONTHLY_LIMIT;
    $month_key = date('Y-m');

    if ($current_count === (int)($limit * 0.8)) {
        if (!get_option('ysc_notice_80_sent_' . $month_key, false)) {
            ysc_send_limit_email(80, $current_count);
            update_option('ysc_notice_80_sent_' . $month_key, true, false);
        }
    }

    if ($current_count >= $limit) {
        if (!get_option('ysc_notice_100_sent_' . $month_key, false)) {
            ysc_send_limit_email(100, $current_count);
            update_option('ysc_notice_100_sent_' . $month_key, true, false);
        }
    }
}

function ysc_send_limit_email($percent, $current_count) {
    $admin_email = get_option('admin_email');
    $site_name   = get_bloginfo('name');
    $site_url    = get_bloginfo('url');
    $reset_date  = date('d.m.Y', strtotime('first day of next month'));
    $remaining   = max(0, YSC_MONTHLY_LIMIT - $current_count);

    if ($percent >= 100) {
        $subject = "[{$site_name}] Лимит Яндекс SmartCaptcha исчерпан";
        $message = "Лимит бесплатных запросов SmartCaptcha исчерпан!\n\n"
            . "Сайт: {$site_url}\n"
            . "Использовано: {$current_count} / " . YSC_MONTHLY_LIMIT . "\n"
            . "Проверка капчи ОТКЛЮЧЕНА до: {$reset_date}\n\n"
            . "Все формы работают без проверки до сброса счётчика.";
    } else {
        $subject = "[{$site_name}] Яндекс SmartCaptcha: использовано {$percent}% лимита";
        $message = "Использовано {$percent}% бесплатного лимита SmartCaptcha.\n\n"
            . "Сайт: {$site_url}\n"
            . "Использовано: {$current_count} / " . YSC_MONTHLY_LIMIT . "\n"
            . "Осталось: {$remaining} запросов\n"
            . "Сброс счётчика: {$reset_date}";
    }

    wp_mail($admin_email, $subject, $message);
}
