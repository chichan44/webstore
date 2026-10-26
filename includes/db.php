<?php
$CONFIG_FILE = __DIR__ . '/config.php';
if (!file_exists($CONFIG_FILE)) {
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "Aplikasi belum diinstall. Buka /install/install.php melalui browser.\n");
            exit(1);
        }
        header('Location: /install/install.php');
        exit;
    }
    return;
}

$config = require $CONFIG_FILE;
if (!is_array($config)) {
    http_response_code(500);
    exit('File konfigurasi tidak valid.');
}

date_default_timezone_set((string)($config['timezone'] ?? 'Asia/Jakarta'));
$dbHost = (string)($config['db_host'] ?? 'localhost');
$dbPort = max(1, (int)($config['db_port'] ?? 3306));
$dbName = (string)($config['db_name'] ?? '');
$dbUser = (string)($config['db_user'] ?? '');
$dbPass = (string)($config['db_pass'] ?? '');

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $dbTimezone = (string)($config['db_timezone'] ?? '+07:00');
    $pdo->exec("SET time_zone=".$pdo->quote($dbTimezone));
} catch (PDOException $e) {
    http_response_code(500);
    exit('Database connection failed. Periksa konfigurasi database atau jalankan installer kembali.');
}

function settings_sensitive_keys(): array {
    return [
        'tokopay_secret_key',
        'casaku_license_key',
        'casaku_webhook_secret',
        'nowpayments_api_key',
        'nowpayments_ipn_secret',
        'smtp_pass',
        'fonnte_token',
        'telegram_cron_secret',
    ];
}

function settings_is_sensitive(string $key): bool {
    return in_array($key, settings_sensitive_keys(), true);
}

function settings_crypto_key(): string {
    global $config;
    $appKey = trim((string)($config['app_key'] ?? ''));
    if ($appKey !== '') return hash('sha256', 'digital-store-settings|'.$appKey, true);
    $seed = 'digital-store-settings-legacy|'.($config['db_name'] ?? '').'|'.($config['db_user'] ?? '').'|'.($config['db_pass'] ?? '');
    return hash('sha256', $seed, true);
}

function settings_crypto_supported(): bool {
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) return false;
    return in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
}

function settings_encrypt_value(string $value): string {
    if ($value === '' || str_starts_with($value, 'enc:v2:') || !settings_crypto_supported()) return $value;
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($value, 'aes-256-gcm', settings_crypto_key(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false || strlen($tag) !== 16) return $value;
    return 'enc:v2:'.base64_encode($nonce.$tag.$cipher);
}

function settings_decrypt_value(string $value): string {
    if (!str_starts_with($value, 'enc:v2:')) return $value;
    if (!settings_crypto_supported()) return '';
    $raw = base64_decode(substr($value, 7), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', settings_crypto_key(), OPENSSL_RAW_DATA, $nonce, $tag, '');
    return $plain === false ? '' : $plain;
}

function migrate_sensitive_settings_encryption(): void {
    global $pdo;
    if (!settings_crypto_supported()) return;
    try {
        $placeholders = implode(',', array_fill(0, count(settings_sensitive_keys()), '?'));
        $st = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE `key` IN ($placeholders)");
        $st->execute(settings_sensitive_keys());
        $up = $pdo->prepare('UPDATE settings SET `value`=? WHERE `key`=?');
        foreach ($st->fetchAll() as $row) {
            $value = (string)$row['value'];
            if ($value === '' || str_starts_with($value, 'enc:v2:')) continue;
            $encrypted = settings_encrypt_value($value);
            if ($encrypted !== $value) $up->execute([$encrypted, (string)$row['key']]);
        }
    } catch (Throwable $e) {}
}

migrate_sensitive_settings_encryption();

function setting(string $key, $default='') {
    global $pdo;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT `key`,`value` FROM settings') as $r) {
                $stored = (string)$r['value'];
                $cache[$r['key']] = settings_is_sensitive((string)$r['key']) ? settings_decrypt_value($stored) : $stored;
            }
        } catch (Throwable $e) {}
    }
    return $cache[$key] ?? $default;
}

function save_setting(string $key, string $value): void {
    global $pdo;
    $stored = settings_is_sensitive($key) ? settings_encrypt_value($value) : $value;
    $st = $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $st->execute([$key,$stored]);
}
?>
