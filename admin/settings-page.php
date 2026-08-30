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
    // Ключи сохраняем через кастомный обработчик (шифрование)
    register_setting('ysc_settings_group', 'ysc_client_key', array(
        'sanitize_callback' => 'ysc_sanitize_client_key',
        'default'           => '',
    ));
    register_setting('ysc_settings_group', 'ysc_server_key', array(
        'sanitize_callback' => 'ysc_sanitize_server_key',
        'default'           => '',
    ));
    register_setting('ysc_settings_group', 'ysc_monthly_limit', array(
        'sanitize_callback' => 'absint',
        'default'           => 10000,
    ));
    register_setting('ysc_settings_group', 'ysc_forms_enabled', array(
        'sanitize_callback' => 'ysc_sanitize_forms_enabled',
        'default'           => array(
            'cf7'      => 1,
            'impreza'  => 1,
            'woo'      => 1,
            'wp_login' => 1,
        ),
    ));
}

/**
 * Шифруем клиентский ключ перед сохранением.
 * Если поле пустое — оставляем старое значение.
 */
function ysc_sanitize_client_key($value) {
    $value = sanitize_text_field($value);
    if (empty($value)) {
        return get_option('ysc_client_key', '');
    }
    return ysc_encrypt($value);
}

/**
 * Шифруем серверный ключ перед сохранением.
 */
function ysc_sanitize_server_key($value) {
    $value = sanitize_text_field($value);
    if (empty($value)) {
        return get_option('ysc_server_key', '');
    }
    return ysc_encrypt($value);
}

/**
 * Санитизация чекбоксов форм.
 */
function ysc_sanitize_forms_enabled($value) {
    $allowed = array('cf7', 'impreza', 'woo', 'wp_login');
    $result  = array();
    foreach ($allowed as $key) {
        $result[$key] = !empty($value[$key]) ? 1 : 0;
    }
    return $result;
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
    $forms      = get_option('ysc_forms_enabled', array(
        'cf7'      => 1,
        'impreza'  => 1,
        'woo'      => 1,
        'wp_login' => 1,
    ));

    $bar_color = $percent >= 100 ? '#dc3232'
               : ($percent >= 80  ? '#f56e28'
               : ($percent >= 50  ? '#ffb900' : '#46b450'));

    // Проверяем наличие ключей
    $has_client_key = !empty(ysc_get_client_key());
    $has_server_key = !empty(ysc_get_server_key());
    ?>
    <div class="wrap">
        <h1>🛡️ Яндекс SmartCaptcha</h1>

        <?php if (!$has_client_key || !$has_server_key): ?>
        <div class="notice notice-warning">
            <p>
                <strong>Внимание:</strong>
                <?php if (!$has_client_key && !$has_server_key): ?>
                    Клиентский и серверный ключи не заданы. Капча не работает.
                <?php elseif (!$has_client_key): ?>
                    Клиентский ключ не задан. Капча не будет показываться.
                <?php else: ?>
                    Серверный ключ не задан. Токены не будут проверяться на сервере.
                <?php endif; ?>
                Укажите ключи ниже.
            </p>
        </div>
        <?php endif; ?>

        <!-- Статус и счётчик -->
        <div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">

            <div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:20px;min-width:280px;flex:1;">
                <h3 style="margin-top:0;font-size:14px;color:#444;">Использование за <?php echo esc_html($counter['month']); ?></h3>
                <div style="display:flex;justify-content:space-between;margin-bottom:8px;font-size:13px;">
                    <span style="color:#555;">Запросов использовано:</span>
                    <strong>
                        <?php echo number_format($count, 0, ',', ' '); ?>
                        /
                        <?php echo number_format($limit, 0, ',', ' '); ?>
                    </strong>
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
                        <?php echo $is_limit
                            ? 'Проверка отключена до ' . esc_html($reset_date)
                            : 'Все формы защищены'; ?>
                    </span>
                </div>
            </div>

        </div>

        <?php settings_errors('ysc_settings_group'); ?>

        <form method="post" action="options.php">
            <?php settings_fields('ysc_settings_group'); ?>

            <!-- Ключи API -->
            <h2 style="font-size:15px;border-bottom:1px solid #eee;padding-bottom:8px;margin-bottom:4px;">Ключи API</h2>
            <p style="color:#666;font-size:12px;margin-top:4px;margin-bottom:16px;">
                🔒 Ключи хранятся в базе данных в зашифрованном виде (AES-256-CBC).
                Оставьте поле пустым, чтобы не менять текущий ключ.
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row" style="width:220px;">
                        <label for="ysc_client_key">Клиентский ключ</label>
                    </th>
                    <td>
                        <input type="text"
                               id="ysc_client_key"
                               name="ysc_client_key"
                               value=""
                               class="regular-text"
                               autocomplete="off"
                               placeholder="<?php echo $has_client_key ? '••••••••••••••••••••' : 'ysc1_...'; ?>">
                        <p class="description">
                            Публичный ключ из
                            <a href="https://console.yandex.cloud/" target="_blank" rel="noopener">Яндекс Cloud</a>
                            → SmartCaptcha. Передаётся на фронтенд.
                            <?php if ($has_client_key): ?>
                                <span style="color:#46b450;">✓ Ключ задан.</span>
                            <?php endif; ?>
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
                               value=""
                               class="regular-text"
                               autocomplete="new-password"
                               placeholder="<?php echo $has_server_key ? '••••••••••••••••••••' : 'ysc2_...'; ?>">
                        <p class="description">
                            Секретный ключ для серверной валидации.
                            <strong>Никогда не передаётся на фронтенд.</strong>
                            <?php if ($has_server_key): ?>
                                <span style="color:#46b450;">✓ Ключ задан.</span>
                            <?php endif; ?>
                        </p>
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
                            При достижении лимита проверка автоматически отключается.
                        </p>
                    </td>
                </tr>
            </table>

            <!-- Поддерживаемые типы форм -->
            <h2 style="font-size:15px;border-bottom:1px solid #eee;padding-bottom:8px;margin:24px 0 4px;">Защищаемые типы форм</h2>
            <p style="color:#666;font-size:12px;margin-top:4px;margin-bottom:16px;">
                Отключите типы форм, которые не используются на сайте, чтобы не регистрировать лишние хуки.
            </p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Contact Form 7</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ysc_forms_enabled[cf7]"
                                   value="1"
                                   <?php checked(!empty($forms['cf7'])); ?>>
                            Включить поддержку CF7
                        </label>
                        <p class="description">Добавляет капчу в формы <code>form.wpcf7-form</code>. Отключите, если CF7 не установлен.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Impreza / UpSolution</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ysc_forms_enabled[impreza]"
                                   value="1"
                                   <?php checked(!empty($forms['impreza'])); ?>>
                            Включить поддержку форм Impreza
                        </label>
                        <p class="description">Добавляет капчу в формы <code>w-form</code>, <code>us-form</code> и AJAX-обработку UpSolution. Отключите, если тема не Impreza.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">WooCommerce</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ysc_forms_enabled[woo]"
                                   value="1"
                                   <?php checked(!empty($forms['woo'])); ?>>
                            Включить поддержку WooCommerce
                        </label>
                        <p class="description">Добавляет капчу на оформление заказа, вход и регистрацию WooCommerce. Отключите, если WooCommerce не установлен.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">WordPress Login</th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="ysc_forms_enabled[wp_login]"
                                   value="1"
                                   <?php checked(!empty($forms['wp_login'])); ?>>
                            Включить защиту стандартной формы входа
                        </label>
                        <p class="description">Добавляет капчу на <code>wp-login.php</code>. Рекомендуется оставить включённым.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Сохранить настройки'); ?>
        </form>

        <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
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
        !isset($_GET['page']) || sanitize_text_field(wp_unslash($_GET['page'])) !== 'ysc-smartcaptcha' ||
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
