<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
require_once __DIR__.'/../includes/order_delivery.php';

$keys = ['site_name','site_url','site_description','site_default_lang','google_site_verification_meta','telegram_product_button_url','delivery_email_enabled','delivery_whatsapp_enabled','smtp_host','smtp_port','smtp_user','smtp_from_email','smtp_from_name','fonnte_country_code','delivery_whatsapp_template','maintenance_mode','maintenance_message'];
$values = [];
foreach ($keys as $key) $values[$key] = (string)setting($key, '');
$values['telegram_product_button_url'] = telegram_product_button_template($values['telegram_product_button_url']);
$values['delivery_email_enabled'] = $values['delivery_email_enabled'] === '' ? '1' : ($values['delivery_email_enabled'] === '1' ? '1' : '0');
$values['delivery_whatsapp_enabled'] = $values['delivery_whatsapp_enabled'] === '1' ? '1' : '0';
if ($values['smtp_port'] === '') $values['smtp_port'] = '587';
if ($values['fonnte_country_code'] === '') $values['fonnte_country_code'] = '62';
if (trim($values['delivery_whatsapp_template']) === '') $values['delivery_whatsapp_template'] = order_delivery_default_whatsapp_template();
if ($values['site_name'] === '') $values['site_name'] = 'DigiSoft Store';
if ($values['site_default_lang'] === '') $values['site_default_lang'] = 'id';
$values['maintenance_mode'] = $values['maintenance_mode'] === '1' ? '1' : '0';
if (trim($values['maintenance_message']) === '') $values['maintenance_message'] = 'Checkout sedang dalam pemeliharaan. Silakan coba kembali beberapa saat lagi.';

$err = '';
$newUploads = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');

    try {
        if ($action === 'delete_logo') {
            $old = (string)setting('site_logo','');
            save_setting('site_logo','');
            delete_public_upload($old);
            admin_redirect('/admin/settings.php', 'Logo header berhasil dihapus.');
        }
        if ($action === 'delete_favicon') {
            $old = (string)setting('site_favicon','');
            save_setting('site_favicon','');
            delete_public_upload($old);
            admin_redirect('/admin/settings.php', 'Favicon berhasil dihapus.');
        }

        foreach ($keys as $key) $values[$key] = trim((string)($_POST[$key] ?? ''));
        $values['delivery_email_enabled'] = $values['delivery_email_enabled'] === '1' ? '1' : '0';
        $values['delivery_whatsapp_enabled'] = $values['delivery_whatsapp_enabled'] === '1' ? '1' : '0';
        $values['smtp_port'] = (string)max(1, min(65535, (int)$values['smtp_port']));
        $values['fonnte_country_code'] = preg_replace('/\D+/', '', $values['fonnte_country_code']) ?: '62';
        $values['maintenance_mode'] = $values['maintenance_mode'] === '1' ? '1' : '0';
        if (mb_strlen($values['maintenance_message']) > 500) throw new RuntimeException('Pesan maintenance maksimal 500 karakter.');
        if (mb_strlen($values['delivery_whatsapp_template']) > 10000) throw new RuntimeException('Template pesan WhatsApp maksimal 10.000 karakter.');
        if ($values['site_name'] === '') throw new RuntimeException('Nama website wajib diisi.');
        if (mb_strlen($values['site_name']) > 100) throw new RuntimeException('Nama website maksimal 100 karakter.');
        if ($values['site_url'] !== '') {
            $values['site_url'] = rtrim($values['site_url'], '/');
            if (!filter_var($values['site_url'], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $values['site_url'])) {
                throw new RuntimeException('Site URL harus berupa alamat lengkap yang valid, misalnya https://domainanda.com.');
            }
        }
        if ($values['telegram_product_button_url'] !== '') {
            $telegramUrlExample = str_replace(
                ['{product_id}','{product_slug}','{product_name}'],
                ['1','contoh-produk','Contoh%20Produk'],
                $values['telegram_product_button_url']
            );
            if (!filter_var($telegramUrlExample, FILTER_VALIDATE_URL) || !preg_match('~^https://~i', $telegramUrlExample)) {
                throw new RuntimeException('Link tombol Bot Telegram harus berupa URL HTTPS lengkap yang valid.');
            }
        }
        if ($values['smtp_from_email'] !== '' && !filter_var($values['smtp_from_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email pengirim SMTP tidak valid.');
        if (!preg_match('/^\d{1,4}$/', $values['fonnte_country_code'])) throw new RuntimeException('Kode negara WhatsApp tidak valid.');
        $smtpPassInput = (string)($_POST['smtp_pass'] ?? '');
        $fonnteTokenInput = trim((string)($_POST['fonnte_token'] ?? ''));
        if ($values['delivery_whatsapp_enabled'] === '1' && $fonnteTokenInput === '' && trim((string)setting('fonnte_token','')) === '') throw new RuntimeException('Token Fonnte wajib diisi saat pengiriman WhatsApp otomatis diaktifkan.');

        $oldLogo = (string)setting('site_logo','');
        $oldFavicon = (string)setting('site_favicon','');
        $logo = upload_site_asset('site_logo','logo');
        if ($logo) $newUploads[] = $logo;
        $favicon = upload_site_asset('site_favicon','favicon');
        if ($favicon) $newUploads[] = $favicon;

        $pdo->beginTransaction();
        foreach ($keys as $key) save_setting($key, $values[$key]);
        if ($smtpPassInput !== '') save_setting('smtp_pass', $smtpPassInput);
        if ($fonnteTokenInput !== '') save_setting('fonnte_token', $fonnteTokenInput);
        if ($logo) save_setting('site_logo', $logo);
        if ($favicon) save_setting('site_favicon', $favicon);

        $bannerTitle = trim((string)($_POST['banner_title'] ?? ''));
        $bannerSubtitle = trim((string)($_POST['banner_subtitle'] ?? ''));
        if (mb_strlen($bannerTitle) > 180) throw new RuntimeException('Judul banner maksimal 180 karakter.');
        if (mb_strlen($bannerSubtitle) > 255) throw new RuntimeException('Subtitle banner maksimal 255 karakter.');
        $pdo->prepare("INSERT INTO banners(id,title,subtitle,button_text,button_url,status,sort_order)
            VALUES(1,?,?,?,?,?,0)
            ON DUPLICATE KEY UPDATE title=VALUES(title),subtitle=VALUES(subtitle),button_text=VALUES(button_text),button_url=VALUES(button_url),status=VALUES(status)")
            ->execute([$bannerTitle,$bannerSubtitle,'Belanja Sekarang','#produk',$bannerTitle !== '' ? 1 : 0]);
        $pdo->commit();

        if ($logo && $oldLogo !== $logo) delete_public_upload($oldLogo);
        if ($favicon && $oldFavicon !== $favicon) delete_public_upload($oldFavicon);
        admin_redirect('/admin/settings.php', 'Pengaturan website berhasil disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($newUploads as $path) delete_public_upload($path);
        $err = $e->getMessage();
    }
}

$banner = $pdo->query('SELECT * FROM banners WHERE id=1')->fetch() ?: [];
$siteLogo = (string)setting('site_logo','');
$siteFavicon = (string)setting('site_favicon','');
admin_page_start('Pengaturan', 'Atur identitas website, logo, favicon, SEO dasar, dan banner utama.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form-card">
    <?=csrf_field()?>
    <div class="adm-card-head">
        <div><h3>Pengaturan Website</h3><p>Perubahan identitas dan media akan langsung digunakan pada tampilan publik.</p></div>
        <div class="adm-actions" style="margin:0"><a class="adm-btn soft" href="/" target="_blank" rel="noopener"><?=admin_icon('external-link')?> Preview Website</a><a class="adm-btn soft" href="/admin/tokopay.php">Payment Gateway</a><a class="adm-btn soft" href="/admin/footer.php">Footer</a></div>
    </div>

    <h2>Identitas Umum</h2>
    <div class="form-grid">
        <div class="field"><label>Nama Website</label><input name="site_name" required maxlength="100" value="<?=e($values['site_name'])?>" placeholder="Digital Store"></div>
        <div class="field"><label>Site URL</label><input type="url" name="site_url" value="<?=e($values['site_url'])?>" placeholder="https://domainanda.com"><small>Gunakan HTTPS dan jangan tambahkan garis miring di akhir.</small></div>
    </div>
    <div class="field" style="margin-top:16px"><label>Deskripsi Website</label><textarea name="site_description" rows="4" maxlength="500" placeholder="Deskripsi singkat toko digital Anda"><?=e($values['site_description'])?></textarea><small>Digunakan pada beberapa bagian website dan metadata pencarian.</small></div>

    <div class="settings-row" style="margin-top:16px">
        <div class="field"><label><?=t('Bahasa Default')?></label><select name="site_default_lang">
            <option value="id" <?=($values['site_default_lang']==='id'?'selected':'')?>><?=t('Indonesia')?></option>
            <option value="en" <?=($values['site_default_lang']==='en'?'selected':'')?>><?=t('English')?></option>
        </select></div>
    </div>

    <h2>Logo & Favicon</h2>
    <div class="settings-media-grid">
        <section class="settings-upload-card">
            <div class="settings-upload-head"><label>Logo Header</label><?php if($siteLogo):?><button class="adm-btn soft danger" type="submit" name="action" value="delete_logo" formnovalidate data-confirm="Hapus logo header saat ini?">Hapus Logo</button><?php endif;?></div>
            <?php if($siteLogo):?><div class="settings-upload-preview"><span class="settings-upload-thumb"><img src="<?=e($siteLogo)?>" alt="Logo header"></span><small>Logo aktif yang saat ini tampil di website.</small></div><?php else:?><div class="settings-upload-empty">Belum ada logo header.</div><?php endif;?>
            <input class="settings-file-input" type="file" name="site_logo" accept="image/png,image/jpeg,image/webp">
            <small>JPG, PNG, atau WEBP. Maksimal 2MB dan 5000×5000 piksel.</small>
        </section>
        <section class="settings-upload-card">
            <div class="settings-upload-head"><label>Favicon</label><?php if($siteFavicon):?><button class="adm-btn soft danger" type="submit" name="action" value="delete_favicon" formnovalidate data-confirm="Hapus favicon saat ini?">Hapus Favicon</button><?php endif;?></div>
            <?php if($siteFavicon):?><div class="settings-upload-preview"><span class="settings-upload-thumb favicon"><img src="<?=e($siteFavicon)?>" alt="Favicon"></span><small>Ikon aktif pada tab browser.</small></div><?php else:?><div class="settings-upload-empty">Belum ada favicon.</div><?php endif;?>
            <input class="settings-file-input" type="file" name="site_favicon" accept="image/png,image/jpeg,image/webp,image/x-icon,.ico">
            <small>ICO, PNG, JPG, atau WEBP. Rekomendasi 32×32 atau 64×64 piksel.</small>
        </section>
    </div>

    <h2>Verifikasi Google</h2>
    <div class="field">
        <label>Meta Tag / Kode Verifikasi</label>
        <textarea name="google_site_verification_meta" rows="3" placeholder='Tempel meta tag lengkap atau nilai content-nya saja'><?=e($values['google_site_verification_meta'])?></textarea>
        <small>Boleh menempelkan meta tag Google Search Console lengkap atau hanya kode verifikasinya.</small>
    </div>

    <h2>Integrasi Bot Telegram</h2>
    <div class="field">
        <label>Link Tombol “Beli di Bot Telegram”</label>
        <input
            type="text"
            inputmode="url"
            name="telegram_product_button_url"
            value="<?=e($values['telegram_product_button_url'])?>"
            placeholder="https://t.me/username_bot?start=product_{product_id}"
            autocomplete="off"
        >
        <small>
            Tombol akan muncul pada popup detail produk. Kosongkan untuk menyembunyikan tombol.
            Gunakan format <code>https://t.me/username_bot?start=product_{product_id}</code> agar bot langsung membuka produk yang sama.
            Placeholder yang tersedia: <code>{product_id}</code>, <code>{product_slug}</code>, dan <code>{product_name}</code>.
        </small>
    </div>

    <h2>Pengiriman Produk Otomatis</h2>
    <div class="gateway-note" style="margin-bottom:14px"><?=admin_icon('shield')?><div>Setelah pembayaran terverifikasi dan stok berhasil dipenuhi, sistem mengirim data produk ke email dan WhatsApp pembeli. Pengiriman dicatat per invoice agar webhook berulang tidak mengirim produk dua kali. Jika layanan sedang gagal, cron Bot Telegram ikut mencoba ulang antrean.</div></div>
    <div class="form-grid">
        <div class="field"><label>Email Otomatis</label><select name="delivery_email_enabled"><option value="1" <?=$values['delivery_email_enabled']==='1'?'selected':''?>>Aktif</option><option value="0" <?=$values['delivery_email_enabled']==='0'?'selected':''?>>Nonaktif</option></select><small>Email checkout selalu disimpan. Jika SMTP kosong, server mencoba fungsi mail() PHP.</small></div>
        <div class="field"><label>WhatsApp Otomatis</label><select name="delivery_whatsapp_enabled"><option value="1" <?=$values['delivery_whatsapp_enabled']==='1'?'selected':''?>>Aktif — Fonnte</option><option value="0" <?=$values['delivery_whatsapp_enabled']==='0'?'selected':''?>>Nonaktif</option></select><small>Saat aktif, nomor WhatsApp menjadi wajib pada checkout website.</small></div>
    </div>

    <h2>Email / SMTP</h2>
    <div class="form-grid">
        <div class="field"><label>SMTP Host</label><input name="smtp_host" value="<?=e($values['smtp_host'])?>" placeholder="smtp.gmail.com"><small>Kosongkan jika hosting sudah mendukung mail() PHP.</small></div>
        <div class="field"><label>SMTP Port</label><input type="number" name="smtp_port" min="1" max="65535" value="<?=e($values['smtp_port'])?>" placeholder="587"><small>587 = STARTTLS, 465 = SSL.</small></div>
        <div class="field"><label>SMTP Username</label><input name="smtp_user" value="<?=e($values['smtp_user'])?>" autocomplete="off" placeholder="email@domain.com"></div>
        <div class="field"><label>SMTP Password</label><div class="secret-field"><input id="smtpPass" type="password" name="smtp_pass" value="" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password"><button type="button" data-toggle-secret="#smtpPass" aria-label="Tampilkan SMTP Password"><?=admin_icon('eye')?></button></div></div>
        <div class="field"><label>Email Pengirim</label><input type="email" name="smtp_from_email" value="<?=e($values['smtp_from_email'])?>" placeholder="no-reply@domain.com"></div>
        <div class="field"><label>Nama Pengirim</label><input name="smtp_from_name" value="<?=e($values['smtp_from_name'])?>" placeholder="<?=e($values['site_name'])?>"></div>
    </div>

    <h2>WhatsApp / Fonnte</h2>
    <div class="gateway-note" style="margin-bottom:14px"><?=admin_icon('lock')?><div>Token Fonnte hanya digunakan server-to-server dan tidak pernah dikirim ke browser pembeli. Hubungkan perangkat WhatsApp pada akun Fonnte sebelum mengaktifkan pengiriman otomatis.</div></div>
    <div class="form-grid">
        <div class="field"><label>Token Fonnte</label><div class="secret-field"><input id="fonnteToken" type="password" name="fonnte_token" value="" placeholder="Kosongkan jika tidak diubah" autocomplete="new-password"><button type="button" data-toggle-secret="#fonnteToken" aria-label="Tampilkan Token Fonnte"><?=admin_icon('eye')?></button></div></div>
        <div class="field"><label>Kode Negara</label><input name="fonnte_country_code" inputmode="numeric" maxlength="4" value="<?=e($values['fonnte_country_code'])?>" placeholder="62"><small>Indonesia: 62. Nomor 08... dari checkout otomatis dinormalisasi menjadi 628...</small></div>
    </div>

    <div class="field" style="margin-top:16px">
        <label>Template Pesan WhatsApp Setelah Pembayaran Lunas</label>
        <textarea name="delivery_whatsapp_template" rows="16" maxlength="10000" spellcheck="false" placeholder="Tulis pesan WhatsApp otomatis di sini..."><?=e($values['delivery_whatsapp_template'])?></textarea>
        <small>
            Teks ini dikirim setelah pembayaran terverifikasi dan produk berhasil dialokasikan.
            Placeholder: <code>{nama}</code>, <code>{invoice}</code>, <code>{nama_toko}</code>, <code>{produk}</code>,
            <code>{detail_produk}</code>, <code>{data_produk}</code>, <code>{garansi}</code>, <code>{ketentuan}</code>,
            <code>{total}</code>, <code>{password_pesanan}</code>, <code>{link_pesanan}</code>,
            <code>{info_password}</code>, <code>{info_link}</code>, <code>{email}</code>, dan <code>{whatsapp}</code>.
            Gunakan <code>{detail_produk}</code> jika ingin nama produk, data produk, garansi, dan ketentuan muncul sekaligus.
        </small>
    </div>

    <h2>Maintenance Mode</h2>
    <div class="gateway-note" style="margin-bottom:14px"><?=admin_icon('shield')?><div>Maintenance Mode hanya menghentikan pembuatan pesanan baru. Katalog, admin, lacak pesanan, invoice yang sudah dibuat, dan webhook pembayaran tetap berjalan.</div></div>
    <div class="form-grid">
        <div class="field"><label>Status Checkout</label><select name="maintenance_mode"><option value="0" <?=$values['maintenance_mode']==='0'?'selected':''?>>Aktif — Pembeli dapat checkout</option><option value="1" <?=$values['maintenance_mode']==='1'?'selected':''?>>Maintenance — Checkout dinonaktifkan</option></select></div>
        <div class="field"><label>Pesan Maintenance</label><textarea name="maintenance_message" rows="3" maxlength="500" placeholder="Checkout sedang dalam pemeliharaan..."><?=e($values['maintenance_message'])?></textarea><small>Pesan ini ditampilkan di halaman checkout dan Bot Telegram saat transaksi baru dinonaktifkan.</small></div>
    </div>

    <h2>Banner Promo Utama</h2>
    <div class="form-grid">
        <div class="field"><label>Judul Banner</label><input name="banner_title" maxlength="180" value="<?=e($_POST['banner_title'] ?? ($banner['title'] ?? ''))?>" placeholder="Produk digital terbaik untuk kebutuhan Anda"><small>Kosongkan judul untuk menonaktifkan banner.</small></div>
        <div class="field"><label>Subtitle Banner</label><input name="banner_subtitle" maxlength="255" value="<?=e($_POST['banner_subtitle'] ?? ($banner['subtitle'] ?? ''))?>" placeholder="Pengiriman cepat dan aman"></div>
    </div>

    <div class="adm-actions" style="margin-top:20px"><button class="adm-btn primary" type="submit" name="action" value="save"><?=admin_icon('save')?> Simpan Pengaturan</button></div>
</form>
<?php admin_page_end(); ?>
