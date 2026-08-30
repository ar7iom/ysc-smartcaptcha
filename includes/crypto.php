<?php
defined('ABSPATH') || exit;

// ============================================================
// ШИФРОВАНИЕ КЛЮЧЕЙ API
// ============================================================

/**
 * Получить 32-байтный ключ шифрования из AUTH_KEY (wp-config.php).
 * hex2bin преобразует 64 hex-символа в 32 реальных байта для AES-256.
 */
function ysc_get_encryption_key() {
    $salt = defined('AUTH_KEY') ? AUTH_KEY : 'ysc-fallback-salt-change-me';
    return hex2bin(substr(hash('sha256', $salt . 'ysc_v1'), 0, 64));
}

/**
 * Зашифровать строку (AES-256-CBC).
 * Возвращает base64-строку: IV + зашифрованные данные.
 */
function ysc_encrypt($plaintext) {
    if (empty($plaintext)) return '';

    if (!function_exists('openssl_encrypt')) {
        // Fallback: простое base64 если openssl недоступен
        return base64_encode($plaintext);
    }

    $key    = ysc_get_encryption_key();
    $iv_len = openssl_cipher_iv_length('AES-256-CBC');
    $iv     = openssl_random_pseudo_bytes($iv_len);

    $encrypted = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) return '';

    // Сохраняем IV + зашифрованные данные как единую строку
    return base64_encode($iv . $encrypted);
}

/**
 * Расшифровать строку.
 */
function ysc_decrypt($ciphertext) {
    if (empty($ciphertext)) return '';

    if (!function_exists('openssl_decrypt')) {
        return base64_decode($ciphertext);
    }

    $data = base64_decode($ciphertext, true);
    if ($data === false || strlen($data) === 0) return '';

    $key    = ysc_get_encryption_key();
    $iv_len = openssl_cipher_iv_length('AES-256-CBC');

    // Данные должны быть длиннее IV
    if (strlen($data) <= $iv_len) return '';

    $iv        = substr($data, 0, $iv_len);
    $encrypted = substr($data, $iv_len);

    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $decrypted !== false ? $decrypted : '';
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
