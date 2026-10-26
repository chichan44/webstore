<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';

$keys = [
    'footer_brand_title','footer_brand_desc','footer_facebook_url','footer_telegram_url','footer_whatsapp_url',
    'footer_help_title','footer_help_links','footer_contact_title','footer_contact_email','footer_contact_hours','footer_contact_location',
];
$defaults = [
    'footer_brand_title' => setting('site_name','Digital Store'),
    'footer_brand_desc' => setting('site_description','Toko online produk digital yang aman dan terpercaya.'),
    'footer_facebook_url' => '',
    'footer_telegram_url' => '',
    'footer_whatsapp_url' => '',
    'footer_help_title' => 'Menu Bantuan',
    'footer_help_links' => "Syarat Layanan|/page.php?slug=syarat-layanan\nKebijakan Pengembalian|/page.php?slug=kebijakan-pengembalian\nHubungi Kami|/page.php?slug=hubungi-kami",
    'footer_contact_title' => 'Kontak Kami',
    'footer_contact_email' => '',
    'footer_contact_hours' => 'Setiap hari 08:00 - 22:00 WIB',
    'footer_contact_location' => 'Indonesia',
];

function footer_admin_value(string $key, array $defaults, array $values): string {
    if (array_key_exists($key, $values)) return (string)$values[$key];
    $value = (string)setting($key, $defaults[$key] ?? '');
    if (in_array($key, ['footer_facebook_url','footer_telegram_url','footer_whatsapp_url'], true) && $value === '#') return '';
    return $value;
}

$values = [];
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $key) $values[$key] = trim((string)($_POST[$key] ?? ''));

    try {
        if ($values['footer_brand_title'] === '') throw new RuntimeException('Nama brand footer wajib diisi.');
        if ($values['footer_contact_email'] !== '' && !filter_var($values['footer_contact_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Alamat email kontak tidak valid.');
        }
        foreach (['footer_facebook_url'=>'Facebook','footer_telegram_url'=>'Telegram','footer_whatsapp_url'=>'WhatsApp'] as $key=>$label) {
            if ($values[$key] !== '' && (!filter_var($values[$key], FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $values[$key]))) {
                throw new RuntimeException('URL '.$label.' tidak valid. Gunakan alamat lengkap yang diawali http:// atau https://.');
            }
        }

        $lines = preg_split('/\R/u', $values['footer_help_links']) ?: [];
        $cleanLines = [];
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') continue;
            [$label, $url] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            if ($label === '' || $url === '') throw new RuntimeException('Format link bantuan pada baris '.($index+1).' tidak valid. Gunakan Nama Menu|URL.');
            $isRelative = str_starts_with($url, '/');
            $isAbsolute = filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url);
            if (!$isRelative && !$isAbsolute) throw new RuntimeException('URL link bantuan pada baris '.($index+1).' tidak valid.');
            $cleanLines[] = $label.'|'.$url;
        }
        $values['footer_help_links'] = implode("\n", $cleanLines);

        $pdo->beginTransaction();
        foreach ($keys as $key) save_setting($key, $values[$key]);
        $pdo->commit();
        admin_redirect('/admin/footer.php', 'Pengaturan footer berhasil disimpan.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
    }
}

admin_page_start('Footer', 'Atur informasi brand, menu bantuan, tautan sosial, dan kontak pada footer website.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" class="znl-footer-admin-page">
    <?=csrf_field()?>
    <div class="adm-card-head adm-card" style="margin-bottom:14px">
        <div><h3>Konten Footer</h3><p>Kolom yang dikosongkan tidak akan menampilkan tautan atau informasi terkait.</p></div>
        <a class="adm-btn soft" href="/" target="_blank" rel="noopener"><?=admin_icon('external-link')?> Preview Website</a>
    </div>
    <div class="znl-footer-admin-grid">
        <section class="znl-footer-admin-card">
            <div class="znl-footer-admin-card-head"><span>1</span><h4>Brand & Sosial Media</h4></div>
            <div class="field"><label>Nama Brand</label><input type="text" name="footer_brand_title" required maxlength="120" value="<?=e(footer_admin_value('footer_brand_title',$defaults,$values))?>" placeholder="Digital Store"></div>
            <div class="field"><label>Deskripsi</label><textarea name="footer_brand_desc" rows="4" maxlength="500" placeholder="Deskripsi singkat toko"><?=e(footer_admin_value('footer_brand_desc',$defaults,$values))?></textarea></div>
            <div class="field"><label>Facebook</label><input type="url" name="footer_facebook_url" value="<?=e(footer_admin_value('footer_facebook_url',$defaults,$values))?>" placeholder="https://facebook.com/..."></div>
            <div class="field"><label>Telegram</label><input type="url" name="footer_telegram_url" value="<?=e(footer_admin_value('footer_telegram_url',$defaults,$values))?>" placeholder="https://t.me/..."></div>
            <div class="field"><label>WhatsApp</label><input type="url" name="footer_whatsapp_url" value="<?=e(footer_admin_value('footer_whatsapp_url',$defaults,$values))?>" placeholder="https://wa.me/628..."></div>
        </section>

        <section class="znl-footer-admin-card">
            <div class="znl-footer-admin-card-head"><span>2</span><h4>Menu Bantuan</h4></div>
            <div class="field"><label>Judul Kolom</label><input type="text" name="footer_help_title" maxlength="100" value="<?=e(footer_admin_value('footer_help_title',$defaults,$values))?>" placeholder="Menu Bantuan"></div>
            <div class="field"><label>Daftar Link</label><textarea class="znl-footer-link-textarea" name="footer_help_links" rows="10" spellcheck="false" placeholder="Syarat Layanan|/page.php?slug=syarat-layanan&#10;Hubungi Kami|/page.php?slug=hubungi-kami"><?=e(footer_admin_value('footer_help_links',$defaults,$values))?></textarea><small>Format: <b>Nama Menu|URL</b>, satu menu per baris. URL boleh relatif atau alamat HTTPS lengkap.</small></div>
        </section>

        <section class="znl-footer-admin-card">
            <div class="znl-footer-admin-card-head"><span>3</span><h4>Kontak</h4></div>
            <div class="field"><label>Judul Kolom</label><input type="text" name="footer_contact_title" maxlength="100" value="<?=e(footer_admin_value('footer_contact_title',$defaults,$values))?>" placeholder="Kontak Kami"></div>
            <div class="field"><label>Email</label><input type="email" name="footer_contact_email" value="<?=e(footer_admin_value('footer_contact_email',$defaults,$values))?>" placeholder="support@domainanda.com"></div>
            <div class="field"><label>Jam Layanan</label><input type="text" name="footer_contact_hours" maxlength="160" value="<?=e(footer_admin_value('footer_contact_hours',$defaults,$values))?>" placeholder="Setiap hari 08:00 - 22:00 WIB"></div>
            <div class="field"><label>Lokasi</label><input type="text" name="footer_contact_location" maxlength="160" value="<?=e(footer_admin_value('footer_contact_location',$defaults,$values))?>" placeholder="Indonesia"></div>
        </section>
    </div>
    <div class="znl-footer-admin-actions"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> Simpan Footer</button></div>
</form>
<?php admin_page_end(); ?>
