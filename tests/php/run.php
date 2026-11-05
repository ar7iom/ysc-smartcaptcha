<?php
require __DIR__ . '/bootstrap.php';

$tests = array();
$failures = 0;

function ysc_test($name, $callback) {
    $GLOBALS['tests'][] = array($name, $callback);
}

function ysc_assert_true($condition, $message = 'Условие должно быть истинным.') {
    if (!$condition) throw new RuntimeException($message);
}

function ysc_assert_same($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        $details = "\nОжидалось: " . var_export($expected, true)
            . "\nПолучено: " . var_export($actual, true);
        throw new RuntimeException($message . $details);
    }
}

function ysc_assert_contains($needle, $haystack, $message = '') {
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException($message ?: "Не найдена строка: {$needle}");
    }
}

function ysc_response($code, $body) {
    return array(
        'response' => array('code' => $code),
        'body' => is_string($body) ? $body : json_encode($body),
    );
}

function ysc_reset_http() {
    $GLOBALS['ysc_http_responses'] = array();
    $GLOBALS['ysc_http_requests'] = array();
}

function ysc_set_counter($count) {
    $GLOBALS['ysc_options']['ysc_request_counter'] = array(
        'count' => $count,
        'month' => date('Y-m'),
    );
    ysc_get_counter(true);
}

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

ysc_test('GCM: шифрование и расшифровка', function() {
    $encrypted = ysc_encrypt('ysc2_test_secret');
    ysc_assert_contains('ysc2:', $encrypted);
    ysc_assert_true(strpos($encrypted, 'test_secret') === false, 'Открытый текст попал в шифротекст.');
    ysc_assert_same('ysc2_test_secret', ysc_decrypt($encrypted));
    ysc_assert_true($encrypted !== ysc_encrypt('ysc2_test_secret'), 'IV не должен повторяться.');
    ysc_assert_same('', ysc_encrypt(array('invalid')));
});

ysc_test('GCM: изменение шифротекста обнаруживается', function() {
    $encrypted = ysc_encrypt('authenticated-value');
    $data = base64_decode(substr($encrypted, 5), true);
    $data[strlen($data) - 1] = $data[strlen($data) - 1] ^ "\x01";
    $tampered = 'ysc2:' . base64_encode($data);
    ysc_assert_same('', ysc_decrypt($tampered));
    ysc_assert_same('', ysc_decrypt('ysc2:not-base64!'));
});

ysc_test('Совместимость: старые CBC и Base64 расшифровываются', function() {
    $key = ysc_get_encryption_key();
    $iv = random_bytes(openssl_cipher_iv_length('AES-256-CBC'));
    $legacy = base64_encode($iv . openssl_encrypt(
        'legacy-cbc-secret',
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    ));
    ysc_assert_same('legacy-cbc-secret', ysc_decrypt($legacy));
    ysc_assert_same('ysc1_legacy_base64', ysc_decrypt(base64_encode('ysc1_legacy_base64')));
    ysc_assert_same('', ysc_decrypt(array('invalid')));
});

ysc_test('Настройки: ключи не шифруются повторно и не теряются', function() {
    $GLOBALS['ysc_options']['ysc_client_key'] = 'existing-ciphertext';
    $GLOBALS['ysc_options']['ysc_server_key'] = 'existing-server-ciphertext';
    ysc_assert_same('existing-ciphertext', ysc_sanitize_client_key(''));
    ysc_assert_same('existing-ciphertext', ysc_sanitize_client_key(array('invalid')));

    $encrypted = ysc_sanitize_server_key('new-server-secret');
    ysc_assert_same('new-server-secret', ysc_decrypt($encrypted));
    ysc_assert_same($encrypted, ysc_sanitize_server_key($encrypted));
    ysc_assert_same('existing-server-ciphertext', ysc_sanitize_server_key(''));
});

ysc_test('Настройки: чекбоксы и формы принимают только ожидаемые значения', function() {
    ysc_assert_same(1, ysc_sanitize_checkbox('1'));
    ysc_assert_same(0, ysc_sanitize_checkbox(null));
    ysc_assert_same(0, ysc_sanitize_checkbox('0'));
    ysc_assert_same(
        array('cf7' => 0, 'impreza' => 0, 'woo' => 0, 'wp_login' => 0),
        ysc_sanitize_forms_enabled('invalid')
    );
    ysc_register_settings();
    ysc_assert_true(isset($GLOBALS['ysc_registered_settings']['ysc_hide_shield']));
});

ysc_test('Входные данные: токен и IP проверяются по типу и формату', function() {
    unset($_POST['smart-token'], $_SERVER['REMOTE_ADDR']);
    ysc_assert_same('', ysc_get_submitted_token());
    $_POST['smart-token'] = array('token');
    ysc_assert_same('', ysc_get_submitted_token());
    $_POST['smart-token'] = "  valid-token\n";
    ysc_assert_same('valid-token', ysc_get_submitted_token());

    $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
    ysc_assert_same('203.0.113.10', ysc_get_remote_ip());
    $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
    ysc_assert_same('2001:db8::1', ysc_get_remote_ip());
    $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
    ysc_assert_same('', ysc_get_remote_ip());
    $_SERVER['REMOTE_ADDR'] = array('203.0.113.10');
    ysc_assert_same('', ysc_get_remote_ip());
});

ysc_test('API: актуальный endpoint, параметры и кеш одноразового токена', function() {
    ysc_reset_http();
    ysc_set_counter(0);
    $_SERVER['REMOTE_ADDR'] = '203.0.113.20';
    $GLOBALS['ysc_http_responses'][] = ysc_response(200, array('status' => 'ok', 'host' => 'example.test'));

    ysc_assert_true(ysc_verify_token('unique-valid-token'));
    ysc_assert_true(ysc_verify_token('unique-valid-token'));
    ysc_assert_same(1, count($GLOBALS['ysc_http_requests']), 'Повторная проверка не должна расходовать токен повторно.');
    ysc_assert_same(1, ysc_get_counter()['count']);

    $request = $GLOBALS['ysc_http_requests'][0];
    ysc_assert_same('https://smartcaptcha.cloud.yandex.ru/validate', $request['url']);
    ysc_assert_same('server-secret', $request['args']['body']['secret']);
    ysc_assert_same('unique-valid-token', $request['args']['body']['token']);
    ysc_assert_same('203.0.113.20', $request['args']['body']['ip']);
});

ysc_test('API: некорректные токены и ответы отклоняются до исчерпания лимита', function() {
    ysc_reset_http();
    ysc_set_counter(0);
    ysc_assert_true(!ysc_verify_token(''));
    ysc_assert_true(!ysc_verify_token(str_repeat('x', 16385)));
    ysc_assert_same(0, count($GLOBALS['ysc_http_requests']));

    $cases = array(
        'failed-status-token' => ysc_response(200, array('status' => 'failed', 'message' => '')),
        'http-error-token' => ysc_response(500, array('status' => 'ok')),
        'invalid-json-token' => ysc_response(200, '{invalid'),
        'network-error-token' => new WP_Error('http_request_failed', 'Network unavailable'),
    );
    foreach ($cases as $token => $response) {
        $GLOBALS['ysc_http_responses'][] = $response;
        ysc_assert_true(!ysc_verify_token($token), "Токен {$token} должен быть отклонён.");
    }
});

ysc_test('Лимит: прежнее отключение проверки и новых API-запросов сохранено', function() {
    ysc_reset_http();
    ysc_set_counter(YSC_MONTHLY_LIMIT);
    ysc_assert_true(ysc_verify_token(''));
    ysc_assert_true(ysc_verify_token('token-after-limit'));
    ysc_assert_same(0, count($GLOBALS['ysc_http_requests']));
    ysc_assert_same(YSC_MONTHLY_LIMIT, ysc_get_counter()['count']);
    ysc_set_counter(0);
});

ysc_test('WooCommerce: ошибка капчи не удаляет другие ошибки входа', function() {
    ysc_reset_http();
    ysc_set_counter(0);
    $errors = new WP_Error('invalid_username', 'Исходная ошибка');
    $_POST['smart-token'] = 'woo-login-failed-token';
    $GLOBALS['ysc_http_responses'][] = ysc_response(200, array('status' => 'failed'));

    $result = ysc_validate_woo_login($errors, 'user', 'password');
    ysc_assert_true($result === $errors, 'Должен возвращаться исходный объект WP_Error.');
    ysc_assert_true(in_array('invalid_username', $result->get_error_codes(), true));
    ysc_assert_true(in_array('captcha_failed', $result->get_error_codes(), true));
});

ysc_test('HTML: добавляются классы только поддерживаемым POST-формам', function() {
    $cf7 = ysc_patch_html_forms('<form CLASS="wpcf7-form" method="post">');
    ysc_assert_contains('class="wpcf7-form ysc-protected"', $cf7);

    $login = ysc_patch_html_forms('<form id="loginform" method="post">');
    ysc_assert_contains('class="ysc-protected"', $login);

    $get = '<form class="wpcf7-form" method="get">';
    ysc_assert_same($get, ysc_patch_html_forms($get));
    $search = '<form class="wpcf7-form" role="search" method="post">';
    ysc_assert_same($search, ysc_patch_html_forms($search));
    $unrelated = '<form class="contact-form" method="post">';
    ysc_assert_same($unrelated, ysc_patch_html_forms($unrelated));

    $protected = '<form class="wpcf7-form ysc-protected" method="post">';
    ysc_assert_same($protected, ysc_patch_html_forms($protected));
    $array = array('invalid');
    ysc_assert_same($array, ysc_patch_html_forms($array));
});

ysc_test('HTML: JSON-ответы Impreza обрабатываются без повреждения данных', function() {
    $json = wp_json_encode(array('html' => '<form class="w-form" method="post">', 'success' => true));
    $patched = json_decode(ysc_patch_output_buffer($json), true);
    ysc_assert_contains('ysc-protected', $patched['html']);
    ysc_assert_same(true, $patched['success']);

    $non_string = array('html' => array('not-html'), 'success' => false);
    $result = json_decode(ysc_patch_output_buffer(wp_json_encode($non_string)), true);
    ysc_assert_same($non_string, $result);
});

ysc_test('Подключение: актуальный скрипт и настраиваемое уведомление', function() {
    ysc_set_counter(0);
    $GLOBALS['ysc_options']['ysc_hide_shield'] = 0;
    ysc_enqueue_captcha_script();
    ysc_assert_same(
        'https://smartcaptcha.cloud.yandex.ru/captcha.js?render=onload&onload=yscSmartCaptchaOnload',
        $GLOBALS['ysc_enqueued_scripts']['yandex-smartcaptcha']['src']
    );
    $inline = $GLOBALS['ysc_inline_scripts']['yandex-smartcaptcha']['code'];
    ysc_assert_contains('"hideShield":false', $inline);

    $GLOBALS['ysc_options']['ysc_hide_shield'] = 1;
    ysc_assert_contains('"hideShield":true', ysc_get_inline_js());
});

foreach ($tests as $test) {
    list($name, $callback) = $test;
    try {
        $callback();
        echo "[OK] {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        echo "[FAIL] {$name}\n  {$error->getMessage()}\n";
    }
}

restore_error_handler();
echo "\nТестов: " . count($tests) . ", ошибок: {$failures}\n";
exit($failures > 0 ? 1 : 0);
