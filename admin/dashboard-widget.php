<?php
defined('ABSPATH') || exit;

// ============================================================
// 7. ВИДЖЕТ В АДМИНКЕ
// ============================================================

add_action('wp_dashboard_setup', 'ysc_add_dashboard_widget');
function ysc_add_dashboard_widget() {
    wp_add_dashboard_widget(
        'ysc_counter_widget',
        '🛡️ Яндекс SmartCaptcha — использование',
        'ysc_dashboard_widget_content'
    );
}

function ysc_dashboard_widget_content() {
    $counter    = ysc_get_counter();
    $count      = $counter['count'];
    $limit      = YSC_MONTHLY_LIMIT;
    $remaining  = max(0, $limit - $count);
    $percent    = $limit > 0 ? min(100, round(($count / $limit) * 100)) : 0;
    $reset_date = date('d.m.Y', strtotime('first day of next month'));
    $is_limit   = ysc_is_limit_reached();

    $bar_color = $percent >= 100 ? '#dc3232'
               : ($percent >= 80  ? '#f56e28'
               : ($percent >= 50  ? '#ffb900' : '#46b450'));

    echo '<div style="font-family:sans-serif;font-size:13px;line-height:1.8;">';

    if ($is_limit) {
        echo '<div style="background:#ffeaea;border-left:4px solid #dc3232;'
           . 'padding:10px 14px;margin-bottom:14px;border-radius:3px;">';
        echo '<strong>⛔ Лимит исчерпан!</strong> Капча отключена.<br>';
        echo 'Сброс: <strong>' . esc_html($reset_date) . '</strong>';
        echo '</div>';
    } else {
        echo '<div style="background:#edfaee;border-left:4px solid #46b450;'
           . 'padding:10px 14px;margin-bottom:14px;border-radius:3px;">';
        echo '<strong>✅ Капча активна.</strong> Осталось: <strong>'
           . number_format($remaining, 0, ',', ' ') . '</strong> запросов';
        echo '</div>';
    }

    echo '<div style="display:flex;justify-content:space-between;margin-bottom:4px;">';
    echo '<span>Использовано за ' . esc_html($counter['month']) . '</span>';
    echo '<span><strong>' . number_format($count, 0, ',', ' ') . '</strong>'
       . ' / ' . number_format($limit, 0, ',', ' ') . '</span>';
    echo '</div>';

    echo '<div style="background:#e0e0e0;border-radius:4px;height:14px;overflow:hidden;">';
    echo '<div style="width:' . esc_attr($percent) . '%;background:' . esc_attr($bar_color) . ';'
       . 'height:14px;border-radius:4px;"></div>';
    echo '</div>';

    echo '<div style="text-align:right;font-size:11px;color:#666;margin-top:2px;">'
       . esc_html($percent) . '%</div>';

    echo '<hr style="margin:12px 0;border:none;border-top:1px solid #eee;">';
    echo '<table style="width:100%;border-collapse:collapse;">';
    echo '<tr><td style="color:#666;padding:3px 0;">Период:</td>'
       . '<td style="text-align:right;padding:3px 0;"><strong>'
       . esc_html($counter['month']) . '</strong></td></tr>';
    echo '<tr><td style="color:#666;padding:3px 0;">Сброс счётчика:</td>'
       . '<td style="text-align:right;padding:3px 0;"><strong>'
       . esc_html($reset_date) . '</strong></td></tr>';
    echo '<tr><td style="color:#666;padding:3px 0;">Лимит (бесплатный тариф):</td>'
       . '<td style="text-align:right;padding:3px 0;"><strong>'
       . number_format($limit, 0, ',', ' ') . ' запросов</strong></td></tr>';
    echo '</table>';

    echo '<hr style="margin:12px 0;border:none;border-top:1px solid #eee;">';
    echo '<a href="' . esc_url(admin_url('options-general.php?page=ysc-smartcaptcha')) . '" '
       . 'style="font-size:12px;text-decoration:none;color:#0073aa;">⚙️ Настройки плагина</a>';

    echo '</div>';
}
