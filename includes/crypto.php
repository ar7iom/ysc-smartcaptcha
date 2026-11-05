<?php
defined('ABSPATH') || exit;

// ============================================================
// ШИФРОВАНИЕ КЛЮЧЕЙ API
// ============================================================

/**
 * Получить 32-байтный ключ шифрования из AUTH_KEY (wp-config.php).
 */
function ysc_get_encryption_key() {
    $salt = defined('AUTH_KEY') ? AUTH_KEY : 'ysc-fallback-salt-change-me';
    return hash('sha256', $salt . 'ysc_v1', true);
}

/**
 * Зашифровать строку (AES-256-GCM).
 * Возвращает версионированную base64-строку: IV + тег + данные.
 */
function ysc_encrypt($plaintext) {
    if (!is_string($plaintext) || $plaintext === '') return '';
    if (!function_exists('openssl_encrypt')) return '';

    $key = ysc_get_encryption_key();
    $iv  = random_bytes(12);
    $tag = '';

    $encrypted = openssl_encrypt(
        $plaintext,
        'AES-256-GCM',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'ysc-smartcaptcha:v2',
        16
    );
    if ($encrypted === false || strlen($tag) !== 16) return '';

    return 'ysc2:' . base64_encode($iv . $tag . $encrypted);
}

/**
 * Расшифровать строку. Поддерживаются новый GCM и старые форматы CBC/Base64.
 */
function ysc_decrypt($ciphertext) {
    if (!is_string($ciphertext) || $ciphertext === '') return '';

    if (strpos($ciphertext, 'ysc2:') === 0) {
        return ysc_decrypt_gcm(substr($ciphertext, 5));
    }

    return ysc_decrypt_legacy($ciphertext);
}

function ysc_decrypt_gcm($ciphertext) {
    if (!function_exists('openssl_decrypt')) return '';

    $data = base64_decode($ciphertext, true);
    if ($data === false || strlen($data) < 29) return '';

    $iv        = substr($data, 0, 12);
    $tag       = substr($data, 12, 16);
    $encrypted = substr($data, 28);

    $decrypted = openssl_decrypt(
        $encrypted,
        'AES-256-GCM',
        ysc_get_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'ysc-smartcaptcha:v2'
    );

    return $decrypted !== false ? $decrypted : '';
}

function ysc_decrypt_legacy($ciphertext) {
    if (!function_exists('openssl_decrypt')) {
        $decoded = base64_decode($ciphertext, true);
        return $decoded !== false ? $decoded : '';
    }

    $data = base64_decode($ciphertext, true);
    if ($data === false || $data === '') return '';

    $iv_len = openssl_cipher_iv_length('AES-256-CBC');
    if (strlen($data) > $iv_len) {
        $iv        = substr($data, 0, $iv_len);
        $encrypted = substr($data, $iv_len);
        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            ysc_get_encryption_key(),
            OPENSSL_RAW_DATA,
            $iv
        );
        if ($decrypted !== false) return $decrypted;
    }

    // Совместимость с резервным Base64-форматом ранних версий.
    // Ключи Yandex Cloud состоят из печатных ASCII-символов.
    if (preg_match('/^[\x21-\x7E]+$/', $data)) return $data;

    return '';
}

/**
 * Получить расшифрованный клиентский ключ.
 */
function ysc_get_client_key() {
    return ysc_decrypt(get_option('ysc_client_key', ''));
}

/**
 * Получить расшифрованный серверный ключ.
 */
function ysc_get_server_key() {
    return ysc_decrypt(get_option('ysc_server_key', ''));
}
