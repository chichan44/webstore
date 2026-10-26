<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/../includes/casaku.php';
require_once __DIR__.'/../includes/nowpayments.php';
require_once __DIR__.'/_layout.php';

$fields = [
    'payment_gateway' => (string)setting('payment_gateway','qris_static'),
    'tokopay_merchant_id' => (string)setting('tokopay_merchant_id',''),
    'tokopay_channel' => (string)setting('tokopay_channel','QRIS'),
    'tokopay_api_url' => (string)setting('tokopay_api_url','https://api.tokopay.id/v1/order'),
    'tokopay_expired_minutes' => (string)setting('tokopay_expired_minutes','5'),
    'tokopay_callback_ip_whitelist' => (string)setting('tokopay_callback_ip_whitelist','0'),
    'tokopay_callback_ips' => (string)setting('tokopay_callback_ips',''),
    'casaku_qr_id' => (string)setting('casaku_qr_id',''),
    'casaku_package_ids' => (string)setting('casaku_package_ids',''),
    'casaku_expired_minutes' => (string)setting('casaku_expired_minutes','15'),
    'casaku_use_unique_code' => (string)setting('casaku_use_unique_code','1'),
    'casaku_prefix' => (string)setting('casaku_prefix','CSK'),
    'casaku_callback_ip_whitelist' => (string)setting('casaku_callback_ip_whitelist','0'),
    'casaku_callback_ips' => (string)setting('casaku_callback_ips',''),
    'nowpayments_default_currency' => (string)setting('nowpayments_default_currency','btc'),
    'nowpayments_expired_minutes' => (string)setting('nowpayments_expired_minutes','30'),
    'nowpayments_prefix' => (string)setting('nowpayments_prefix','NP'),
    'nowpayments_sandbox' => (string)setting('nowpayments_sandbox','0'),
    'checkout_demo_payment_enabled' => (string)setting('checkout_demo_payment_enabled','0'),
    'qris_merchant_name' => (string)setting('qris_merchant_name','DIGITAL STORE'),
    'qris_payload' => (string)setting('qris_payload',''),
];
$err = '';

function payment_admin_normalize_ips(string $raw): string {
    $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,\s]+/', $raw) ?: []))));
    foreach ($items as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new RuntimeException('Daftar IP callback mengandung alamat tidak valid: '.$ip);
    return implode(',', $items);
}
function payment_admin_normalize_packages(string $raw): string {
    $items = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []))));
    foreach ($items as $id) {
        if (strlen($id) > 120 || !preg_match('/^[A-Za-z0-9._-]+$/', $id)) throw new RuntimeException('Package ID Casaku tidak valid: '.$id);
    }
    // DANA Bisnis menggunakan aplikasi merchant DANA Kasir (id.dana.kasir).
    // Jika konfigurasi lama hanya memakai id.dana, simpan keduanya agar notifikasi
    // merchant dapat dibaca tanpa memutus kompatibilitas dengan aplikasi DANA biasa.
    if (in_array('id.dana', $items, true) && !in_array('id.dana.kasir', $items, true)) $items[] = 'id.dana.kasir';
    return implode(',', $items);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach (array_keys($fields) as $key) $fields[$key] = trim((string)($_POST[$key] ?? ''));
    $fields['payment_gateway'] = in_array($fields['payment_gateway'], ['qris_static','tokopay','casaku','nowpayments'], true) ? $fields['payment_gateway'] : 'qris_static';
    $fields['tokopay_channel'] = strtoupper($fields['tokopay_channel']);
    $fields['tokopay_expired_minutes'] = (string)max(1, min(1440, (int)$fields['tokopay_expired_minutes']));
    $fields['tokopay_callback_ip_whitelist'] = $fields['tokopay_callback_ip_whitelist'] === '1' ? '1' : '0';
    $fields['casaku_expired_minutes'] = (string)max(1, min(1440, (int)$fields['casaku_expired_minutes']));
    $fields['casaku_use_unique_code'] = $fields['casaku_use_unique_code'] === '0' ? '0' : '1';
    $fields['casaku_prefix'] = strtoupper($fields['casaku_prefix']);
    $fields['casaku_callback_ip_whitelist'] = $fields['casaku_callback_ip_whitelist'] === '1' ? '1' : '0';

    try {
        if ($fields['tokopay_api_url'] !== '' && (!filter_var($fields['tokopay_api_url'], FILTER_VALIDATE_URL) || !preg_match('~^https://~i', $fields['tokopay_api_url']))) {
            throw new RuntimeException('Endpoint API Tokopay harus berupa URL HTTPS yang valid.');
        }
        if ($fields['tokopay_channel'] === '') throw new RuntimeException('Channel Tokopay wajib diisi.');

        $tokopaySecretInput = trim((string)($_POST['tokopay_secret_key'] ?? ''));
        $existingTokopaySecret = trim((string)setting('tokopay_secret_key',''));
        if ($fields['payment_gateway'] === 'tokopay') {
            if ($fields['tokopay_merchant_id'] === '') throw new RuntimeException('Merchant ID wajib diisi saat Tokopay digunakan.');
            if ($tokopaySecretInput === '' && $existingTokopaySecret === '') throw new RuntimeException('Secret Key wajib diisi saat Tokopay digunakan.');
            if ($fields['tokopay_api_url'] === '') throw new RuntimeException('Endpoint API Tokopay wajib diisi.');
        }

        $fields['tokopay_callback_ips'] = payment_admin_normalize_ips($fields['tokopay_callback_ips']);
        if ($fields['tokopay_callback_ip_whitelist'] === '1' && $fields['tokopay_callback_ips'] === '') throw new RuntimeException('Isi minimal satu IP callback Tokopay atau nonaktifkan validasi IP.');

        $casakuLicenseInput = trim((string)($_POST['casaku_license_key'] ?? ''));
        $casakuWebhookSecretInput = trim((string)($_POST['casaku_webhook_secret'] ?? ''));
        $existingCasakuLicense = trim((string)setting('casaku_license_key',''));
        $existingCasakuWebhookSecret = trim((string)setting('casaku_webhook_secret',''));
        $fields['casaku_package_ids'] = payment_admin_normalize_packages($fields['casaku_package_ids']);
        $fields['casaku_callback_ips'] = payment_admin_normalize_ips($fields['casaku_callback_ips']);
        if ($fields['casaku_prefix'] === '' || strlen($fields['casaku_prefix']) > 8 || !preg_match('/^[A-Z0-9]+$/', $fields['casaku_prefix'])) throw new RuntimeException('Prefix Casaku wajib 1–8 karakter huruf/angka.');
        if ($fields['casaku_qr_id'] !== '' && !preg_match('/^[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[1-5A-Fa-f0-9][A-Fa-f0-9]{3}-[89ABabA-Fa-f0-9][A-Fa-f0-9]{3}-[A-Fa-f0-9]{12}$/', $fields['casaku_qr_id'])) throw new RuntimeException('QR ID Casaku tidak terlihat seperti UUID yang valid.');
        if ($fields['casaku_callback_ip_whitelist'] === '1' && $fields['casaku_callback_ips'] === '') throw new RuntimeException('Isi minimal satu IP callback Casaku atau nonaktifkan validasi IP.');
        if ($fields['payment_gateway'] === 'casaku') {
            if ($casakuLicenseInput === '' && $existingCasakuLicense === '') throw new RuntimeException('License Key Casaku wajib diisi.');
            if ($fields['casaku_qr_id'] === '') throw new RuntimeException('QR ID Casaku wajib diisi.');
            if ($fields['casaku_package_ids'] === '') throw new RuntimeException('Minimal satu Package ID Casaku wajib diisi.');
            if ($casakuWebhookSecretInput === '' && $existingCasakuWebhookSecret === '') throw new RuntimeException('Webhook Secret Casaku wajib diisi agar callback dapat diverifikasi HMAC-SHA256.');
        }

        $nowpaymentsKeyInput = trim((string)($_POST['nowpayments_api_key'] ?? ''));
        $nowpaymentsIpnInput = trim((string)($_POST['nowpayments_ipn_secret'] ?? ''));
        $existingNowpaymentsKey = trim((string)setting('nowpayments_api_key',''));
        $existingNowpaymentsIpn = trim((string)setting('nowpayments_ipn_secret',''));
        $fields['nowpayments_sandbox'] = $fields['nowpayments_sandbox'] === '1' ? '1' : '0';
        $fields['nowpayments_expired_minutes'] = (string)max(5, min(1440, (int)$fields['nowpayments_expired_minutes']));
        $fields['checkout_demo_payment_enabled'] = $fields['checkout_demo_payment_enabled'] === '1' ? '1' : '0';
        if ($fields['payment_gateway'] === 'nowpayments') {
            if ($nowpaymentsKeyInput === '' && $existingNowpaymentsKey === '') throw new RuntimeException('API Key NowPayments wajib diisi.');
            if ($nowpaymentsIpnInput === '' && $existingNowpaymentsIpn === '') throw new RuntimeException('IPN Secret NowPayments wajib diisi agar webhook dapat diverifikasi HMAC-SHA512.');
        }

        $pdo->beginTransaction();
        foreach ($fields as $key => $value) save_setting($key, $value);
        if ($tokopaySecretInput !== '') save_setting('tokopay_secret_key', $tokopaySecretInput);
        if ($casakuLicenseInput !== '') save_setting('casaku_license_key', $casakuLicenseInput);
        if ($casakuWebhookSecretInput !== '') save_setting('casaku_webhook_secret', $casakuWebhookSecretInput);
        if ($nowpaymentsKeyInput !== '') save_setting('nowpayments_api_key', $nowpaymentsKeyInput);
        if ($nowpaymentsIpnInput !== '') save_setting('nowpayments_ipn_secret', $nowpaymentsIpnInput);
        $pdo->commit();
        admin_redirect('/admin/tokopay.php', 'Pengaturan payment gateway berhasil disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

$siteUrl = rtrim(seo_site_url(), '/');
$tokopayWebhookUrl = $siteUrl . '/api/payment_status.php';
$casakuWebhookUrl = $siteUrl . '/api/casaku_webhook.php';
$nowpaymentsWebhookUrl = $siteUrl . '/api/nowpayments_webhook.php';
admin_page_start('Payment Gateway', 'Atur QRIS manual, Tokopay, Casaku, atau NowPayments beserta keamanan callback dan fulfillment otomatis.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" class="form-card payment-card">
    <?=csrf_field()?>
    <div class="adm-card-head">
        <div><h3>Payment Gateway</h3><p>Produk digital hanya dipenuhi setelah status pembayaran terverifikasi oleh gateway.</p></div>
        <a class="adm-btn soft" href="/checkout.php" target="_blank" rel="noopener"><?=admin_icon('external-link')?> Preview Checkout</a>
    </div>

    <h2>Mode Pembayaran</h2>
    <div class="form-grid">
        <div class="field"><label>Gateway Aktif</label><select name="payment_gateway"><option value="qris_static" <?=$fields['payment_gateway']==='qris_static'?'selected':''?>>QRIS Statis / Manual</option><option value="tokopay" <?=$fields['payment_gateway']==='tokopay'?'selected':''?>>Tokopay Otomatis</option><option value="casaku" <?=$fields['payment_gateway']==='casaku'?'selected':''?>>Casaku Otomatis</option><option value="nowpayments" <?=$fields['payment_gateway']==='nowpayments'?'selected':''?>>NowPayments (Crypto)</option></select><small>Tokopay dan Casaku mendukung verifikasi pembayaran otomatis sebelum produk dikirim. NowPayments memungkinkan pembayaran dengan cryptocurrency (BTC, ETH, USDT, dll). Auto-order Telegram mengikuti gateway otomatis yang aktif.</small></div>
        <div class="field"><label>Mode Pembayaran Demo</label><select name="checkout_demo_payment_enabled"><option value="0" <?=$fields['checkout_demo_payment_enabled']==='0'?'selected':''?>>Nonaktif</option><option value="1" <?=$fields['checkout_demo_payment_enabled']==='1'?'selected':''?>>Aktif</option></select><small>Hanya untuk testing. Saat aktif, checkout wizard menampilkan metode Demo yang langsung melunasi pesanan (tanpa gateway eksternal).</small></div>
    </div>

    <section class="gateway-section" data-gateway-section="casaku">
        <h2>Casaku</h2>
        <div class="gateway-note"><?=admin_icon('shield')?><div>Integrasi menggunakan API production <b>api.casaku.id</b>. License Key hanya dikirim server-to-server ke host Casaku; endpoint tidak dapat diubah dari panel untuk mencegah kebocoran kredensial.</div></div>
        <div class="gateway-note"><?=admin_icon('info')?><div><b>Khusus QRIS DANA Bisnis:</b> pilih <code>id.dana.kasir</code>. Sistem otomatis turut mengirim <code>id.dana</code> ke Casaku sebagai kompatibilitas sumber notifikasi DANA. Aplikasi Casaku di HP merchant tetap harus memiliki izin akses notifikasi dan HP harus online agar transaksi dapat berubah menjadi <b>paid</b>.</div></div>
        <div class="form-grid">
            <div class="field"><label>License Key</label><div class="secret-field"><input id="casakuLicense" type="password" name="casaku_license_key" value="" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password"><button type="button" data-toggle-secret="#casakuLicense" aria-label="Tampilkan License Key"><?=admin_icon('eye')?></button></div></div>
            <div class="field"><label>QR ID Merchant</label><input name="casaku_qr_id" value="<?=e($fields['casaku_qr_id'])?>" placeholder="986b2a0a-5c95-4177-9800-02d84a35338e"><small>UUID QRIS Merchant dari akun Casaku.</small></div>
            <div class="field"><label>Package IDs Aplikasi Merchant</label><input name="casaku_package_ids" value="<?=e($fields['casaku_package_ids'])?>" placeholder="id.dana.kasir, id.dana"><small><b>DANA Bisnis / DANA Kasir: id.dana.kasir.</b> DANA dompet biasa: id.dana. Pisahkan beberapa package dengan koma. Jika Anda memasukkan id.dana, sistem otomatis menambahkan id.dana.kasir untuk kompatibilitas DANA Bisnis.</small></div>
            <div class="field"><label>Masa Berlaku</label><input type="number" name="casaku_expired_minutes" min="1" max="1440" value="<?=e($fields['casaku_expired_minutes'])?>"><small>Menit kedaluwarsa transaksi Casaku.</small></div>
            <div class="field"><label>Kode Unik</label><select name="casaku_use_unique_code"><option value="1" <?=$fields['casaku_use_unique_code']==='1'?'selected':''?>>Aktif — acak 1–999</option><option value="0" <?=$fields['casaku_use_unique_code']==='0'?'selected':''?>>Nonaktif — nominal persis</option></select><small>Jika aktif, sistem membuat kode acak 1–999. Jika nonaktif, total pembayaran tidak ditambah apa pun. Untuk mencegah pembayaran salah terdeteksi, hanya satu transaksi Casaku aktif yang boleh memakai nominal persis yang sama; transaksi sisa yang sudah tidak aktif akan dibersihkan otomatis.</small></div>
            <div class="field"><label>Prefix Transaksi</label><input name="casaku_prefix" maxlength="8" value="<?=e($fields['casaku_prefix'])?>" placeholder="CSK"><small>Maksimal 8 karakter huruf/angka.</small></div>
        </div>

        <h2>Webhook Casaku</h2>
        <div class="gateway-note"><?=admin_icon('lock')?><div><b>HMAC-SHA256 selalu wajib.</b> Sistem menghitung signature dari raw body dan membandingkannya dengan <code>X-Casaku-Signature</code> menggunakan <code>hash_equals()</code>. Webhook <b>paid</b> hanya diproses jika transactionId dan nominal cocok persis dengan order. Polling <code>check-status</code> + daftar transaksi Casaku tetap digunakan sebagai jalur recovery bila webhook terlambat.</div></div>
        <div class="form-grid">
            <div class="field"><label>Webhook Secret</label><div class="secret-field"><input id="casakuWebhookSecret" type="password" name="casaku_webhook_secret" value="" placeholder="Secret dari Webhook Developer Casaku" autocomplete="new-password"><button type="button" data-toggle-secret="#casakuWebhookSecret" aria-label="Tampilkan Webhook Secret"><?=admin_icon('eye')?></button></div><small>Harus sama persis dengan Webhook Secret pada dashboard Casaku.</small></div>
            <div class="field"><label>URL Webhook Casaku</label><div class="secret-field"><input id="casakuWebhookUrl" readonly value="<?=e($casakuWebhookUrl)?>" onclick="this.select()"><button type="button" data-copy-target="#casakuWebhookUrl" aria-label="Salin URL"><?=admin_icon('copy')?></button></div><small>Masukkan URL ini pada menu Webhook Developer Casaku.</small></div>
            <div class="field"><label>Validasi IP Tambahan</label><select name="casaku_callback_ip_whitelist"><option value="0" <?=$fields['casaku_callback_ip_whitelist']==='0'?'selected':''?>>Nonaktif</option><option value="1" <?=$fields['casaku_callback_ip_whitelist']==='1'?'selected':''?>>Aktif</option></select><small>Opsional. Aktifkan hanya bila Casaku memberikan daftar IP callback resmi. HMAC tetap wajib meski whitelist aktif.</small></div>
            <div class="field"><label>IP Callback Casaku</label><input name="casaku_callback_ips" value="<?=e($fields['casaku_callback_ips'])?>" placeholder="IP resmi Casaku, pisahkan koma"></div>
        </div>
        <div class="adm-actions"><a class="adm-btn soft" href="https://casaku.id/docs" target="_blank" rel="noopener noreferrer"><?=admin_icon('external-link')?> Dokumentasi Casaku</a></div>
    </section>

    <section class="gateway-section" data-gateway-section="nowpayments">
        <h2>NowPayments (Crypto)</h2>
        <div class="gateway-note"><?=admin_icon('shield')?><div>API Key dan IPN Secret tidak pernah ditampilkan kembali. Kosongkan kolom untuk mempertahankan nilai yang sudah tersimpan. NowPayments mendukung pembayaran dengan Bitcoin, Ethereum, USDT, dan ratusan cryptocurrency lainnya.</div></div>
        <div class="form-grid">
            <div class="field"><label>API Key</label><div class="secret-field"><input id="nowpaymentsApiKey" type="password" name="nowpayments_api_key" value="" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password"><button type="button" data-toggle-secret="#nowpaymentsApiKey" aria-label="Tampilkan API Key"><?=admin_icon('eye')?></button></div><small>API Key dari dashboard NowPayments.</small></div>
            <div class="field"><label>IPN Secret</label><div class="secret-field"><input id="nowpaymentsIpnSecret" type="password" name="nowpayments_ipn_secret" value="" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password"><button type="button" data-toggle-secret="#nowpaymentsIpnSecret" aria-label="Tampilkan IPN Secret"><?=admin_icon('eye')?></button></div><small>Secret untuk verifikasi webhook HMAC-SHA512.</small></div>
            <div class="field"><label>Mata Uang Kripto Default</label><input name="nowpayments_default_currency" value="<?=e($fields['nowpayments_default_currency'])?>" placeholder="btc"><small>Kode crypto default (btc, eth, usdt, dll). Pelanggan tetap bisa memilih crypto lain saat checkout.</small></div>
            <div class="field"><label>Masa Berlaku</label><input type="number" name="nowpayments_expired_minutes" min="5" max="1440" value="<?=e($fields['nowpayments_expired_minutes'])?>"><small>Menit kedaluwarsa pembayaran crypto.</small></div>
            <div class="field"><label>Prefix Transaksi</label><input name="nowpayments_prefix" maxlength="8" value="<?=e($fields['nowpayments_prefix'])?>" placeholder="NP"><small>Maksimal 8 karakter huruf/angka.</small></div>
            <div class="field"><label>Mode Sandbox</label><select name="nowpayments_sandbox"><option value="0" <?=$fields['nowpayments_sandbox']==='0'?'selected':''?>>Production</option><option value="1" <?=$fields['nowpayments_sandbox']==='1'?'selected':''?>>Sandbox (Testing)</option></select><small>Gunakan sandbox untuk pengujian.</small></div>
        </div>
        <h2>Webhook NowPayments</h2>
        <div class="gateway-note"><?=admin_icon('lock')?><div><b>HMAC-SHA512 wajib.</b> Sistem menghitung signature dari raw body dan membandingkannya dengan <code>x-nowpayments-sig</code> menggunakan <code>hash_equals()</code>. Polling status juga digunakan sebagai jalur recovery bila webhook terlambat.</div></div>
        <div class="form-grid">
            <div class="field"><label>URL Webhook NowPayments</label><div class="secret-field"><input id="nowpaymentsWebhookUrl" readonly value="<?=e($nowpaymentsWebhookUrl)?>" onclick="this.select()"><button type="button" data-copy-target="#nowpaymentsWebhookUrl" aria-label="Salin URL"><?=admin_icon('copy')?></button></div><small>Masukkan URL ini sebagai IPN Callback URL di dashboard NowPayments.</small></div>
        </div>
        <div class="adm-actions"><a class="adm-btn soft" href="https://nowpayments.io/docs" target="_blank" rel="noopener noreferrer"><?=admin_icon('external-link')?> Dokumentasi NowPayments</a></div>
    </section>

    <section class="gateway-section" data-gateway-section="tokopay">
        <h2>Tokopay</h2>
        <div class="gateway-note"><?=admin_icon('shield')?><div>Secret Key tidak pernah ditampilkan kembali. Kosongkan kolom secret untuk mempertahankan nilai yang sudah tersimpan.</div></div>
        <div class="form-grid">
            <div class="field"><label>Merchant ID</label><input name="tokopay_merchant_id" value="<?=e($fields['tokopay_merchant_id'])?>" placeholder="M2023..."></div>
            <div class="field"><label>Channel Tokopay</label><input name="tokopay_channel" value="<?=e($fields['tokopay_channel'])?>" placeholder="QRIS"></div>
            <div class="field"><label>Secret Key</label><div class="secret-field"><input id="tokopaySecret" type="password" name="tokopay_secret_key" value="" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password"><button type="button" data-toggle-secret="#tokopaySecret" aria-label="Tampilkan secret"><?=admin_icon('eye')?></button></div></div>
            <div class="field"><label>Endpoint API Order</label><input type="url" name="tokopay_api_url" value="<?=e($fields['tokopay_api_url'])?>" placeholder="https://api.tokopay.id/v1/order"></div>
            <div class="field"><label>Masa Berlaku Pembayaran</label><input type="number" name="tokopay_expired_minutes" min="1" max="1440" value="<?=e($fields['tokopay_expired_minutes'])?>"></div>
        </div>
        <h2>Webhook Tokopay</h2>
        <div class="form-grid">
            <div class="field"><label>URL Webhook</label><div class="secret-field"><input id="tokopayWebhookUrl" readonly value="<?=e($tokopayWebhookUrl)?>" onclick="this.select()"><button type="button" data-copy-target="#tokopayWebhookUrl" aria-label="Salin URL"><?=admin_icon('copy')?></button></div></div>
            <div class="field"><label>Validasi IP Callback</label><select name="tokopay_callback_ip_whitelist"><option value="1" <?=$fields['tokopay_callback_ip_whitelist']==='1'?'selected':''?>>Aktif</option><option value="0" <?=$fields['tokopay_callback_ip_whitelist']==='0'?'selected':''?>>Nonaktif</option></select><small>Jika aktif, signature Tokopay tetap wajib valid dan IP juga harus cocok.</small></div>
            <div class="field"><label>IP Callback Tokopay</label><input name="tokopay_callback_ips" value="<?=e($fields['tokopay_callback_ips'])?>" placeholder="IP callback resmi provider"></div>
        </div>
    </section>

    <section class="gateway-section" data-gateway-section="qris_static">
        <h2>QRIS Manual</h2>
        <div class="gateway-note"><?=admin_icon('credit-card')?><div>Mode manual tidak menandai order lunas secara otomatis. Untuk pengiriman otomatis gunakan Tokopay atau Casaku.</div></div>
        <div class="form-grid">
            <div class="field"><label>Nama Merchant QRIS</label><input name="qris_merchant_name" maxlength="120" value="<?=e($fields['qris_merchant_name'])?>" placeholder="DIGITAL STORE"></div>
            <div class="field"><label>QRIS Payload / Static QRIS</label><textarea name="qris_payload" rows="5" placeholder="Tempel payload QRIS statis atau URL QR yang digunakan aplikasi"><?=e($fields['qris_payload'])?></textarea></div>
        </div>
    </section>

    <div class="adm-actions" style="margin-top:20px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> Simpan Payment Gateway</button><a class="adm-btn soft" href="/admin/settings.php">Pengaturan Umum</a></div>
</form>
<?php admin_page_end(); ?>
