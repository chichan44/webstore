<?php
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

date_default_timezone_set('Asia/Jakarta');

$root = dirname(__DIR__);
$configFile = $root.'/includes/config.php';
$lockFile = __DIR__.'/install.lock';
$schemaFile = __DIR__.'/schema.sql';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function detected_site_url(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    return $host !== '' ? (($https ? 'https' : 'http').'://'.$host) : '';
}
function requirement_rows(): array {
    $requiredExtensions = ['pdo','pdo_mysql','mbstring','openssl','fileinfo','curl','json','session'];
    $rows = [];
    $rows[] = ['PHP >= 8.1', version_compare(PHP_VERSION, '8.1.0', '>='), PHP_VERSION];
    foreach ($requiredExtensions as $ext) $rows[] = ['PHP ext: '.$ext, extension_loaded($ext), extension_loaded($ext) ? 'Aktif' : 'Tidak aktif'];
    $rows[] = ['Random bytes', function_exists('random_bytes'), function_exists('random_bytes') ? 'Tersedia' : 'Tidak tersedia'];
    return $rows;
}
function requirements_ok(): bool {
    foreach (requirement_rows() as $row) if (!$row[1]) return false;
    return true;
}
function parse_schema(string $sql): array {
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line)) continue;
        $clean[] = $line;
    }
    $sql = trim(implode("\n", $clean));
    if ($sql === '') return [];
    return array_values(array_filter(array_map('trim', preg_split('/;\s*(?:\R|$)/', $sql) ?: [])));
}
function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}
function setting_value(PDO $pdo, string $key): string {
    if (!table_exists($pdo, 'settings')) return '';
    $st = $pdo->prepare('SELECT `value` FROM settings WHERE `key`=? LIMIT 1');
    $st->execute([$key]);
    return (string)($st->fetchColumn() ?: '');
}
function save_setting_install(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');
    $st->execute([$key,$value]);
}

$installed = file_exists($configFile);
$error = '';
$success = false;
$dbConnected = false;
$csrf = $_SESSION['install_csrf'] ??= bin2hex(random_bytes(24));

$defaults = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_pass' => '',
    'site_name' => 'Digital Store',
    'site_url' => detected_site_url(),
    'admin_name' => 'Administrator',
    'admin_username' => 'admin',
];
$form = $defaults;
foreach (array_keys($form) as $key) if (isset($_POST[$key])) $form[$key] = trim((string)$_POST[$key]);

if (!$installed && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    try {
        if (!hash_equals($csrf, (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Sesi installer tidak valid. Muat ulang halaman lalu coba lagi.');
        if (!requirements_ok()) throw new RuntimeException('Server belum memenuhi seluruh persyaratan wajib. Aktifkan ekstensi yang berstatus Tidak aktif.');
        if (!is_file($schemaFile) || !is_readable($schemaFile)) throw new RuntimeException('File schema database tidak ditemukan atau tidak dapat dibaca.');
        if (!is_writable(dirname($configFile))) throw new RuntimeException('Folder /includes tidak writable. Berikan permission yang memungkinkan installer membuat config.php.');

        $dbHost = $form['db_host'] ?: 'localhost';
        $dbPort = max(1, min(65535, (int)$form['db_port']));
        $dbName = $form['db_name'];
        $dbUser = $form['db_user'];
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $siteName = $form['site_name'];
        $siteUrl = rtrim($form['site_url'], '/');
        $adminName = $form['admin_name'];
        $adminUsername = $form['admin_username'];
        $adminPassword = (string)($_POST['admin_password'] ?? '');
        $adminPassword2 = (string)($_POST['admin_password_confirm'] ?? '');

        if ($dbName === '' || $dbUser === '') throw new RuntimeException('Nama database dan username database wajib diisi.');
        if (!preg_match('/^[A-Za-z0-9_\-.]+$/', $dbHost)) throw new RuntimeException('Database host tidak valid.');
        if ($siteName === '' || mb_strlen($siteName) > 100) throw new RuntimeException('Nama website wajib diisi dan maksimal 100 karakter.');
        if ($siteUrl === '' || !filter_var($siteUrl, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $siteUrl)) throw new RuntimeException('Site URL harus berupa URL lengkap, misalnya https://domainanda.com.');
        if (!preg_match('/^[A-Za-z0-9._-]{3,120}$/', $adminUsername)) throw new RuntimeException('Username admin minimal 3 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
        if (mb_strlen($adminName) < 2 || mb_strlen($adminName) > 160) throw new RuntimeException('Nama administrator harus 2–160 karakter.');
        if (strlen($adminPassword) < 10) throw new RuntimeException('Password admin minimal 10 karakter.');
        if (!hash_equals($adminPassword, $adminPassword2)) throw new RuntimeException('Konfirmasi password admin tidak sama.');

        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
        );
        $pdo->exec("SET time_zone='+07:00'");
        $dbConnected = true;

        if (table_exists($pdo, 'admins')) {
            $adminCount = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
            $state = setting_value($pdo, 'installer_state');
            if ($adminCount > 0 && $state !== 'pending') {
                throw new RuntimeException('Database sudah berisi akun admin dari instalasi lain. Gunakan database kosong untuk mencegah data lama tertimpa.');
            }
        }

        $tmpConfig = $configFile.'.installing';
        $config = [
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_name' => $dbName,
            'db_user' => $dbUser,
            'db_pass' => $dbPass,
            'app_key' => bin2hex(random_bytes(32)),
            'timezone' => 'Asia/Jakarta',
            'db_timezone' => '+07:00',
        ];
        $configPhp = "<?php\nreturn ".var_export($config, true).";\n";
        if (file_put_contents($tmpConfig, $configPhp, LOCK_EX) === false) throw new RuntimeException('Gagal membuat file konfigurasi sementara.');
        @chmod($tmpConfig, 0640);

        try {
            $schema = file_get_contents($schemaFile);
            if ($schema === false) throw new RuntimeException('Gagal membaca schema database.');
            foreach (parse_schema($schema) as $statement) $pdo->exec($statement);

            save_setting_install($pdo, 'installer_state', 'pending');

            $existing = $pdo->prepare('SELECT id FROM admins WHERE username=? LIMIT 1');
            $existing->execute([$adminUsername]);
            $adminId = (int)($existing->fetchColumn() ?: 0);
            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            if ($adminId > 0) {
                $pdo->prepare('UPDATE admins SET name=?,password_hash=?,role=? WHERE id=?')->execute([$adminName,$hash,'Administrator',$adminId]);
            } else {
                $pdo->prepare('INSERT INTO admins(username,password_hash,name,role,created_at) VALUES(?,?,?,?,NOW())')->execute([$adminUsername,$hash,$adminName,'Administrator']);
            }

            $settings = [
                'site_name' => $siteName,
                'site_url' => $siteUrl,
                'site_description' => 'Toko online produk digital dengan proses pembelian yang cepat dan aman.',
                'site_logo' => '',
                'site_favicon' => '',
                'google_site_verification_meta' => '',
                'payment_gateway' => 'qris_static',
                'qris_merchant_name' => strtoupper(mb_strimwidth($siteName, 0, 80, '', 'UTF-8')),
                'qris_payload' => '',
                'tokopay_merchant_id' => '',
                'tokopay_secret_key' => '',
                'tokopay_channel' => 'QRIS',
                'tokopay_api_url' => 'https://api.tokopay.id/v1/order',
                'tokopay_expired_minutes' => '5',
                'tokopay_callback_ip_whitelist' => '0',
                'tokopay_callback_ips' => '',
                'casaku_license_key' => '',
                'casaku_qr_id' => '',
                'casaku_package_ids' => '',
                'casaku_expired_minutes' => '15',
                'casaku_use_unique_code' => '1',
                'casaku_prefix' => 'CSK',
                'casaku_webhook_secret' => '',
                'casaku_callback_ip_whitelist' => '0',
                'casaku_callback_ips' => '',
                'maintenance_mode' => '0',
                'maintenance_message' => 'Checkout sedang dalam pemeliharaan. Silakan coba kembali beberapa saat lagi.',
                'delivery_email_enabled' => '1',
                'delivery_whatsapp_enabled' => '0',
                'smtp_host' => '',
                'smtp_port' => '587',
                'smtp_user' => '',
                'smtp_pass' => '',
                'smtp_from_email' => '',
                'smtp_from_name' => $siteName,
                'fonnte_token' => '',
                'fonnte_country_code' => '62',
                'delivery_whatsapp_template' => "Halo {nama},\n\nPembayaran pesanan *{invoice}* sudah lunas. Berikut produk digital Anda:\n\n{detail_produk}\n\nSimpan data produk dengan aman.\n{info_password}\n{info_link}\n\nTerima kasih,\n{nama_toko}",
                'telegram_product_button_url' => '',
                'telegram_welcome_message' => "Selamat datang di {store}, {name}!\n\nPilih menu di bawah untuk melihat produk dan melakukan pembelian otomatis.",
                'telegram_help_message' => "1. Pilih List Produk.\n2. Pilih produk dan jumlah.\n3. Lakukan pembayaran.\n4. Produk dikirim otomatis setelah pembayaran berhasil.",
                'telegram_info_message' => 'Bot auto-order resmi toko. Pastikan username bot sesuai informasi pada website.',
                'telegram_button_products' => '📦 List Produk',
                'telegram_button_orders' => '📜 Riwayat',
                'telegram_button_help' => '❓ Cara Order',
                'telegram_button_info' => 'ℹ️ Informasi',
                'telegram_button_website' => '🌐 Website',
                'footer_brand_title' => $siteName,
                'footer_brand_desc' => 'Toko online produk digital dengan layanan cepat dan aman.',
                'footer_facebook_url' => '',
                'footer_telegram_url' => '',
                'footer_whatsapp_url' => '',
                'footer_help_title' => 'Menu Bantuan',
                'footer_help_links' => "Lacak Pesanan|/track-order.php",
                'footer_contact_title' => 'Kontak Kami',
                'footer_contact_email' => '',
                'footer_contact_hours' => '',
                'footer_contact_location' => 'Indonesia',
            ];
            foreach ($settings as $key=>$value) save_setting_install($pdo, $key, (string)$value);
            save_setting_install($pdo, 'installer_state', 'installed');

            foreach ([$root.'/uploads/products',$root.'/uploads/site',$root.'/uploads/telegram'] as $dir) {
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Gagal membuat folder upload: '.$dir);
                if (!is_writable($dir)) @chmod($dir, 0755);
            }

            if (!rename($tmpConfig, $configFile)) throw new RuntimeException('Gagal mengaktifkan file config.php. Periksa permission folder /includes.');
            @chmod($configFile, 0640);
            @file_put_contents($lockFile, 'Installed: '.date('c')."\n", LOCK_EX);
            @chmod($lockFile, 0640);
            $_SESSION['install_csrf'] = bin2hex(random_bytes(24));
            $success = true;
            $installed = true;
        } catch (Throwable $e) {
            @unlink($tmpConfig);
            throw $e;
        }
    } catch (Throwable $e) {
        if ($e instanceof PDOException) {
            $error = !$dbConnected
                ? 'Koneksi database gagal. Periksa host, port, nama database, username, password, dan hak akses database. Detail: '.$e->getMessage()
                : 'Proses database gagal setelah koneksi berhasil. Detail: '.$e->getMessage();
        } else {
            $error = $e->getMessage();
        }
    }
}

$requirements = requirement_rows();
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installer Digital Store</title>
<style>
:root{color-scheme:light;--bg:#f4f7fb;--card:#fff;--text:#172033;--muted:#667085;--line:#e5eaf1;--primary:#3157d5;--soft:#eef3ff;--ok:#107c41;--bad:#b42318}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--text)}.wrap{width:min(980px,calc(100% - 28px));margin:38px auto}.head{margin-bottom:18px}.head span{display:inline-block;background:var(--soft);color:var(--primary);padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700}.head h1{font-size:30px;margin:10px 0 6px}.head p{margin:0;color:var(--muted);line-height:1.6}.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px;box-shadow:0 10px 28px rgba(23,32,51,.06);margin-bottom:16px}h2{font-size:18px;margin:0 0 14px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.field{display:flex;flex-direction:column;gap:7px}.field.full{grid-column:1/-1}label{font-size:13px;font-weight:700}input{width:100%;border:1px solid #d8dee8;border-radius:10px;padding:11px 12px;font:inherit;background:#fff;color:inherit;outline:none}input:focus{border-color:#7d96eb;box-shadow:0 0 0 3px #edf1ff}.hint{font-size:12px;color:var(--muted);line-height:1.5}.req{display:grid;grid-template-columns:repeat(2,1fr);gap:9px}.req div{display:flex;align-items:center;justify-content:space-between;border:1px solid var(--line);border-radius:10px;padding:10px 12px;font-size:13px}.yes{color:var(--ok);font-weight:800}.no{color:var(--bad);font-weight:800}.alert{padding:13px 14px;border-radius:11px;margin-bottom:16px;line-height:1.55}.alert.err{background:#fff1f0;color:#8f1d15;border:1px solid #ffc7c2}.alert.ok{background:#ecfdf3;color:#05603a;border:1px solid #abefc6}.actions{display:flex;gap:10px;align-items:center;margin-top:18px}.btn{border:0;border-radius:10px;padding:11px 16px;background:var(--primary);color:#fff;font-weight:800;text-decoration:none;cursor:pointer}.btn.soft{background:var(--soft);color:var(--primary)}.note{font-size:12px;color:var(--muted);line-height:1.55;margin-top:12px}@media(max-width:700px){.wrap{margin:20px auto}.grid,.req{grid-template-columns:1fr}.head h1{font-size:25px}.card{padding:17px}.field.full{grid-column:auto}}
</style>
</head>
<body>
<main class="wrap">
  <div class="head"><span>Fresh Install</span><h1>Installer Digital Store</h1><p>Masukkan database dan akun admin. Installer akan membuat seluruh tabel, konfigurasi, APP_KEY, serta pengaturan awal secara otomatis.</p></div>

  <?php if($success): ?>
    <div class="alert ok"><b>Instalasi berhasil.</b> Database, akun administrator, konfigurasi GMT+7, dan kunci enkripsi sudah dibuat.</div>
    <div class="card"><h2>Selesai</h2><p class="hint">Untuk keamanan installer sudah dikunci. Lanjutkan ke dashboard, lalu atur payment gateway, identitas website, footer, produk, stok, dan Bot Telegram.</p><div class="actions"><a class="btn" href="/admin/login.php">Login Admin</a><a class="btn soft" href="/">Buka Website</a></div></div>
  <?php elseif($installed): ?>
    <div class="alert ok"><b>Aplikasi sudah terinstall.</b> Installer tidak akan menimpa konfigurasi yang sudah ada.</div>
    <div class="card"><div class="actions"><a class="btn" href="/admin/login.php">Login Admin</a><a class="btn soft" href="/">Buka Website</a></div></div>
  <?php else: ?>
    <?php if($error!==''): ?><div class="alert err"><b>Instalasi belum berhasil.</b><br><?=h($error)?></div><?php endif; ?>

    <div class="card"><h2>Pemeriksaan Server</h2><div class="req"><?php foreach($requirements as $r): ?><div><span><?=h($r[0])?></span><b class="<?=$r[1]?'yes':'no'?>"><?=h($r[2])?></b></div><?php endforeach; ?></div><p class="note">PHP 8.3 atau 8.4 direkomendasikan. GD bersifat opsional, tetapi disarankan untuk pemrosesan gambar. Script dipasang pada document root/domain utama, bukan subfolder.</p></div>

    <form method="post" class="card" autocomplete="off">
      <input type="hidden" name="_csrf" value="<?=h($csrf)?>">
      <h2>Database</h2>
      <div class="grid">
        <div class="field"><label>Database Host</label><input name="db_host" required value="<?=h($form['db_host'])?>" placeholder="localhost"></div>
        <div class="field"><label>Database Port</label><input name="db_port" required inputmode="numeric" value="<?=h($form['db_port'])?>" placeholder="3306"></div>
        <div class="field"><label>Nama Database</label><input name="db_name" required value="<?=h($form['db_name'])?>" placeholder="nama_database"></div>
        <div class="field"><label>Username Database</label><input name="db_user" required value="<?=h($form['db_user'])?>" placeholder="user_database"></div>
        <div class="field full"><label>Password Database</label><input type="password" name="db_pass" value="" autocomplete="new-password"><span class="hint">Boleh kosong hanya jika server database Anda memang tidak menggunakan password.</span></div>
      </div>

      <h2 style="margin-top:22px">Website</h2>
      <div class="grid">
        <div class="field"><label>Nama Website</label><input name="site_name" maxlength="100" required value="<?=h($form['site_name'])?>"></div>
        <div class="field"><label>Site URL</label><input type="url" name="site_url" required value="<?=h($form['site_url'])?>" placeholder="https://domainanda.com"></div>
      </div>

      <h2 style="margin-top:22px">Administrator</h2>
      <div class="grid">
        <div class="field"><label>Nama Admin</label><input name="admin_name" required value="<?=h($form['admin_name'])?>"></div>
        <div class="field"><label>Username Admin</label><input name="admin_username" required value="<?=h($form['admin_username'])?>" autocomplete="username"></div>
        <div class="field"><label>Password Admin</label><input type="password" name="admin_password" minlength="10" required autocomplete="new-password"></div>
        <div class="field"><label>Ulangi Password</label><input type="password" name="admin_password_confirm" minlength="10" required autocomplete="new-password"></div>
      </div>
      <div class="actions"><button class="btn" type="submit" <?=requirements_ok()?'':'disabled'?>>Install Sekarang</button></div>
      <p class="note">Gunakan database kosong. Installer tidak menyertakan produk, stok, pesanan, credential payment gateway, token bot, email pelanggan, maupun data website pemilik sebelumnya.</p>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
