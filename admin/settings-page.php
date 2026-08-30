<?php
defined('ABSPATH') || exit;

// ============================================================
// СТРАНИЦА НАСТРОЕК
// ============================================================

add_action('admin_menu', 'ysc_add_settings_page');
function ysc_add_settings_page() {
    add_options_page(
        'Яндекс SmartCaptcha',
        'SmartCaptcha',
        'manage_options',
        'ysc-smartcaptcha',
        'ysc_render_settings_page'
    );
}

add_action('admin_init', 'ysc_register_settings');
function ysc_register_settings() {
    register_setting('ysc_settings_group', 'ysc_client_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ));
    register_setting('ysc_settings_group', 'ysc_server_key', array(
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ));
    register_setting('ysc_settings_group', 'ysc_monthly_limit', array(
        'sanitize_callback' => 'absint',
        'default'           => 10000,
    ));
}

function ysc_render_settings_page() {
    if (!current_user_can('manage_options')) return;

    $counter    = ysc_get_counter();
    $count      = $counter['count'];
    $limit      = (int) get_option('ysc_monthly_limit', 10000);
    $remaining  = max(0, $limit - $count);
    $percent    = $limit > 0 ? min(100, round(($count / $limit) * 100)) : 0;
    $reset_date = date('d.m.Y', strtotime('first day of next month'));
    $is_limit   = ysc_is_limit_reached();

    $bar_color = $percent >= 100 ? '#dc3232'
               : ($percent >= 80  ? '#f56e28'
               : ($percent >= 50  ? '#ffb900' : '#46b450'));
    ?>
    <div class="wrap">
        <h1>🛡️ Яндекс SmartCaptcha</h1>

        <?php if (!get_option('ysc_client_key')): ?>
        <div class="notice notice-warning">
            <p><strong>Внимание:</strong> Клиентский ключ не задан. Капча не работает. Укажите ключи ниже.</p>
        </div>
        <?php endif; ?>

        <!-- Статус и счётчик -->
        <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;min-width:280px;flex:1;">
                <h3 style="margin-top:0;font-size:14px;color:#444;">Использование за <?php echo esc_html($counter['month']); ?></h3>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;">
                    <span style="color:#555;">Запросов использовано:</span>
                    <strong><?php echo number_format($count, 0, ',', ' '); ?> / <?php echo number_format($limit, 0, ',', ' '); ?></strong>
                </div>
                <div style="background:#e8e8e8;border-radius:4px;height:10px;overflow:hidden;margin-bottom:4px;">
                    <div style="width:<?php echo esc_attr($percent); ?>%;background:<?php echo esc_attr($bar_color); ?>;height:10px;border-radius:4px;transition:width .3s;"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#888;margin-bottom:8px;">
                    <span><?php echo esc_html($percent); ?>% использовано</span>
                    <span>Осталось: <?php echo number_format($remaining, 0, ',', ' '); ?></span>
                </div>
                <div style="font-size:11px;color:#aaa;">Сброс счётчика: <?php echo esc_html($reset_date); ?></div>
            </div>

            <div style="background:<?php echo $is_limit ? '#ffeaea' : '#edfaee'; ?>;border:1px solid <?php echo $is_limit ? '#f5c6c6' : '#b2dfb5'; ?>;border-radius:6px;padding:20px;min-width:200px;display:flex;align-items:center;gap:14px;">
                <span style="font-size:34px;line-height:1;"><?php echo $is_limit ? '⛔' : '✅'; ?></span>
                <div>
                    <strong style="font-size:14px;display:block;margin-bottom:2px;">
                        <?php echo $is_limit ? 'Лимит исчерпан' : 'Капча активна'; ?>
                    </strong>
                    <span style="font-size:12px;color:#555;">
                        <?php echo $is_limit ? 'Проверка отключена до ' . esc_html($reset_date) : 'Все формы защищены'; ?>
                    </span>
                </div>
            </div>

        </div>

        <?php settings_errors('ysc_settings_group'); ?>

        <!-- Форма настроек -->
        <form method="post" action="options.php">
            <?php settings_fields('ysc_settings_group'); ?>

            <h2 style="font-size:15px;border-bottom:1px solid #eee;padding-bottom:8px;margin-bottom:16px;">Настройки</h2>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row" style="width:220px;">
                        <label for="ysc_client_key">Клиентский ключ</label>
                    </th>
                    <td>
                        <input type="text"
                               id="ysc_client_key"
                               name="ysc_client_key"
                               value="<?php echo esc_attr(get_option('ysc_client_key', '')); ?>"
                               class="regular-text"
                               autocomplete="off"
                               placeholder="ysc1_...">
                        <p class="description">
                            Публичный ключ из <a href="https://console.yandex.cloud/" target="_blank" rel="noopener">Яндекс Cloud</a> → SmartCaptcha.
                            Передаётся на фронтенд.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ysc_server_key">Серверный ключ</label>
                    </th>
                    <td>
                        <input type="password"
                               id="ysc_server_key"
                               name="ysc_server_key"
                               value="<?php echo esc_attr(get_option('ysc_server_key', '')); ?>"
                               class="regular-text"
                               autocomplete="new-password"
                               placeholder="ysc2_...">
                        <p class="description">Секретный ключ для серверной валидации. <strong>Никогда не передаётся на фронтенд.</strong></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="ysc_monthly_limit">Лимит запросов / месяц</label>
                    </th>
                    <td>
                        <input type="number"
                               id="ysc_monthly_limit"
                               name="ysc_monthly_limit"
                               value="<?php echo esc_attr(get_option('ysc_monthly_limit', 10000)); ?>"
                               class="small-text"
                               min="1"
                               step="1">
                        <p class="description">
                            Бесплатный тариф Яндекс — <strong>10 000</strong> запросов в месяц.
                            При достижении лимита проверка автоматически отключается до сброса счётчика.
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Сохранить настройки'); ?>
        </form>

        <!-- Поддерживаемые формы -->
        <h2 style="font-size:15px;border-bottom:1px solid #eee;padding-bottom:8px;margin:24px 0 16px;">Поддерживаемые формы</h2>
        <ul style="margin:0;padding-left:20px;font-size:13px;line-height:2;color:#444;">
            <li>✅ Contact Form 7</li>
            <li>✅ Impreza / UpSolution (w-form, us-form)</li>
            <li>✅ WooCommerce (оформление заказа, вход, регистрация)</li>
            <li>✅ Стандартная форма входа WordPress (wp-login.php)</li>
        </ul>

        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
        <!-- Отладка -->
        <h2 style="font-size:15px;border-bottom:1px solid #eee;padding-bottom:8px;margin:24px 0 16px;">🔧 Отладка</h2>
        <p style="font-size:12px;color:#999;margin-bottom:10px;">WP_DEBUG активен. Кнопка сброса доступна только в режиме отладки.</p>
        <?php
            $nonce = wp_create_nonce('ysc_reset_counter');
            echo '<a href="' . esc_url(admin_url('options-general.php?page=ysc-smartcaptcha&ysc_reset=1&_wpnonce=' . $nonce)) . '"
                    class="button button-secondary"
                    onclick="return confirm(\'Сбросить счётчик капчи на 0?\')">
                   ↺ Сбросить счётчик
                 </a>';
        ?>
        <?php endif; ?>
    </div>
    <?php
}

// Обработчик ручного сброса
add_action('admin_init', 'ysc_handle_manual_reset');
function ysc_handle_manual_reset() {
    if (
        !defined('WP_DEBUG') || !WP_DEBUG ||
        !current_user_can('manage_options') ||
        !isset($_GET['ysc_reset']) ||
        !isset($_GET['page']) || $_GET['page'] !== 'ysc-smartcaptcha' ||
        !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'] ?? '')), 'ysc_reset_counter')
    ) {
        return;
    }

    update_option('ysc_request_counter', array(
        'count' => 0,
        'month' => date('Y-m'),
    ), false);

    wp_redirect(admin_url('options-general.php?page=ysc-smartcaptcha&ysc_reset_done=1'));
    exit;
}

add_action('admin_notices', 'ysc_settings_notices');
function ysc_settings_notices() {
    $page = sanitize_text_field(wp_unslash($_GET['page'] ?? ''));
    if ($page !== 'ysc-smartcaptcha') return;

    if (isset($_GET['ysc_reset_done'])) {
        echo '<div class="notice notice-success is-dismissible">'
           . '<p>✅ <strong>Счётчик SmartCaptcha сброшен.</strong></p>'
           . '</div>';
    }
}
