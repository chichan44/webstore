<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
require_once __DIR__.'/../includes/telegram_bot.php';
telegram_ensure_schema();

$telegramCronSecret=(string)setting('telegram_cron_secret','');
if($telegramCronSecret===''){ $telegramCronSecret=telegram_random_token(24); save_setting('telegram_cron_secret',$telegramCronSecret); }
$err='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=(string)($_POST['action'] ?? '');
    try{
        if($action==='add_bot'){
            $label=trim((string)($_POST['bot_name'] ?? 'Bot Telegram')) ?: 'Bot Telegram';
            $token=trim((string)($_POST['bot_token'] ?? ''));
            if($token==='' || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/',$token))throw new RuntimeException('Format token bot tidak valid. Salin token lengkap dari BotFather.');
            $pdo->prepare("INSERT INTO telegram_bots(bot_name,bot_username,token_enc,token_hint,webhook_secret,webhook_key,status,created_at,updated_at) VALUES(?,NULL,?,?,?,?, 'inactive',NOW(),NOW())")
                ->execute([$label,telegram_secret_encrypt($token),telegram_bot_token_hint($token),telegram_random_token(24),telegram_random_token(18)]);
            $id=(int)$pdo->lastInsertId();$bot=telegram_get_bot($id,true);$me=telegram_test_connection($bot);$bot=telegram_get_bot($id,true);
            $hasActive=(int)$pdo->query("SELECT COUNT(*) FROM telegram_bots WHERE status='active'")->fetchColumn()>0;
            if(!$hasActive){$pdo->prepare("UPDATE telegram_bots SET status='active' WHERE id=?")->execute([$id]);$bot=telegram_get_bot($id,true);telegram_set_webhook($bot);}
            admin_redirect('/admin/telegram.php','Bot @'.($me['username'] ?? '').' berhasil ditambahkan'.(!$hasActive?' dan diaktifkan.':'.'));
        }
        if($action==='replace_token'){
            $id=(int)($_POST['bot_id'] ?? 0);$token=trim((string)($_POST['bot_token'] ?? ''));
            if(!$id || $token==='' || !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/',$token))throw new RuntimeException('Bot atau token pengganti tidak valid.');
            $pdo->prepare('UPDATE telegram_bots SET token_enc=?,token_hint=?,webhook_secret=?,webhook_key=?,last_error=NULL,updated_at=NOW() WHERE id=?')->execute([telegram_secret_encrypt($token),telegram_bot_token_hint($token),telegram_random_token(24),telegram_random_token(18),$id]);
            $bot=telegram_get_bot($id,true);telegram_test_connection($bot);$bot=telegram_get_bot($id,true);if($bot['status']==='active')telegram_set_webhook($bot);
            admin_redirect('/admin/telegram.php','Token bot berhasil diganti dan koneksi diperbarui.');
        }
        if($action==='delete_bot'){
            $id=(int)($_POST['bot_id'] ?? 0);
            if(!$id) throw new RuntimeException('Bot tidak valid.');
            telegram_delete_bot_permanently($id);
            admin_redirect('/admin/telegram.php','Bot berhasil dihapus. Riwayat pesanan tetap disimpan.');
        }
        if(in_array($action,['test_bot','set_webhook','repair_bot','activate_bot','disable_bot','sync_commands'],true)){
            $id=(int)($_POST['bot_id'] ?? 0);$bot=telegram_get_bot($id,true);if(!$bot)throw new RuntimeException('Bot tidak ditemukan.');
            if($action==='test_bot'){telegram_test_connection($bot);admin_redirect('/admin/telegram.php','Koneksi bot berhasil diuji.');}
            if($action==='set_webhook'){telegram_set_webhook($bot);admin_redirect('/admin/telegram.php','Webhook dan command bot berhasil dipasang.');}
            if($action==='repair_bot'){
                $info=telegram_repair_webhook($bot);
                $pending=(int)($info['pending_update_count'] ?? 0);
                $lastError=trim((string)($info['last_error_message'] ?? ''));
                $msg='Bot, webhook, dan command sudah diperiksa. Pending update: '.$pending.'.';
                if($lastError!=='') $msg.=' Telegram masih melaporkan: '.$lastError;
                admin_redirect('/admin/telegram.php',$msg,$lastError!==''?'error':'success');
            }
            if($action==='sync_commands'){telegram_sync_commands($bot);admin_redirect('/admin/telegram.php','Command bot berhasil disinkronkan.');}
            if($action==='activate_bot'){
                $pdo->beginTransaction();$pdo->exec("UPDATE telegram_bots SET status='inactive' WHERE status='active'");$pdo->prepare("UPDATE telegram_bots SET status='active',updated_at=NOW() WHERE id=?")->execute([$id]);$pdo->commit();
                $bot=telegram_get_bot($id,true);telegram_set_webhook($bot);admin_redirect('/admin/telegram.php','Bot baru berhasil dijadikan bot aktif.');
            }
            if($action==='disable_bot'){
                try{telegram_delete_webhook($bot);}catch(Throwable $e){}
                $pdo->prepare("UPDATE telegram_bots SET status='disabled',updated_at=NOW() WHERE id=?")->execute([$id]);
                admin_redirect('/admin/telegram.php','Bot berhasil dinonaktifkan.');
            }
        }
if ($action === 'save_messages') {
    $welcomeImage = trim(
        (string)($_POST['telegram_welcome_image'] ?? '')
    );

    if (
        $welcomeImage !== ''
        && !filter_var($welcomeImage, FILTER_VALIDATE_URL)
    ) {
        throw new RuntimeException(
            'URL gambar selamat datang tidak valid.'
        );
    }

    $fields = [
        'telegram_welcome_message',
        'telegram_welcome_image',
        'telegram_help_message',
        'telegram_info_message',
        'telegram_button_products',
        'telegram_button_orders',
        'telegram_button_help',
        'telegram_button_info',
        'telegram_button_website',
    ];

    foreach ($fields as $field) {
        save_setting(
            $field,
            trim((string)($_POST[$field] ?? ''))
        );
    }

    admin_redirect(
        '/admin/telegram.php',
        'Menu dan template pesan bot berhasil disimpan.'
    );
}
        if($action==='run_worker'){
            $result=telegram_process_worker(50);
            admin_redirect('/admin/telegram.php','Worker selesai: '.$result['deliveries'].' pengiriman, '.$result['broadcasts'].' broadcast, '.$result['migrations'].' migrasi, '.$result['failed'].' gagal.',$result['failed']?'error':'success');
        }
        if($action==='create_broadcast'){
            $botId=(int)($_POST['bot_id'] ?? 0);$message=trim((string)($_POST['message'] ?? ''));
            $image=trim((string)($_POST['image_url'] ?? ''));$buttonText=trim((string)($_POST['button_text'] ?? ''));$buttonUrl=trim((string)($_POST['button_url'] ?? ''));
            if($image!==''&&!filter_var($image,FILTER_VALIDATE_URL))throw new RuntimeException('URL gambar broadcast tidak valid.');
            if(($buttonText!==''||$buttonUrl!=='')&&($buttonText===''||!filter_var($buttonUrl,FILTER_VALIDATE_URL)))throw new RuntimeException('Teks dan URL tombol broadcast harus diisi dengan benar.');
            $id=telegram_create_broadcast($botId,trim((string)($_POST['title'] ?? '')),$message,$image,$buttonText,$buttonUrl);
            admin_redirect('/admin/telegram.php','Broadcast #'.$id.' masuk antrean pengiriman.');
        }
        if ($action === 'delete_broadcast') {
    $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
    $broadcastPage = max(
        1,
        (int)($_POST['broadcast_page'] ?? 1)
    );

    if ($broadcastId < 1) {
        throw new RuntimeException('Broadcast tidak valid.');
    }

    $pdo->beginTransaction();

    try {
        $st = $pdo->prepare(
            'SELECT status
             FROM telegram_broadcasts
             WHERE id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $st->execute([$broadcastId]);

        $status = $st->fetchColumn();

        if ($status === false) {
            throw new RuntimeException('Broadcast tidak ditemukan.');
        }

        // Hindari penghapusan saat worker masih mengirim.
        if (in_array($status, ['queued', 'sending'], true)) {
            throw new RuntimeException(
                'Broadcast yang masih dalam antrean atau sedang dikirim tidak dapat dihapus.'
            );
        }

        // Hapus daftar penerima terlebih dahulu.
        $pdo->prepare(
            'DELETE FROM telegram_broadcast_recipients
             WHERE broadcast_id = ?'
        )->execute([$broadcastId]);

        // Kemudian hapus data broadcast.
        $pdo->prepare(
            'DELETE FROM telegram_broadcasts
             WHERE id = ?'
        )->execute([$broadcastId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    admin_redirect(
        '/admin/telegram.php?broadcast_page='
        . $broadcastPage
        . '#broadcast-history',
        'Riwayat broadcast berhasil dihapus.'
    );
}
        if($action==='prepare_migration'){
            $old=(int)($_POST['old_bot_id'] ?? 0);$new=(int)($_POST['new_bot_id'] ?? 0);$days=max(1,min(30,(int)($_POST['expires_days'] ?? 7)));
            $count=telegram_prepare_migration($old,$new,$days);
            admin_redirect('/admin/telegram.php',$count.' pengguna masuk antrean pemberitahuan migrasi bot.');
        }
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$err=$e->getMessage();}
}

$bots=$pdo->query("SELECT b.*,(SELECT COUNT(*) FROM telegram_bot_users u WHERE u.bot_id=b.id AND u.is_active=1) user_count FROM telegram_bots b ORDER BY FIELD(b.status,'active','inactive','disabled'),b.id DESC")->fetchAll();
$activeBot=null;foreach($bots as $b)if($b['status']==='active'){$activeBot=$b;break;}
$stats=[
    'users'=>(int)$pdo->query('SELECT COUNT(*) FROM telegram_users')->fetchColumn(),
    'orders'=>(int)$pdo->query("SELECT COUNT(*) FROM orders WHERE sales_channel='telegram'")->fetchColumn(),
    'sales'=>(float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE sales_channel='telegram' AND status='delivered'")->fetchColumn(),
    'queue'=>(int)$pdo->query("SELECT COUNT(*) FROM telegram_delivery_queue WHERE status IN ('pending','failed')")->fetchColumn(),
];
$broadcastPerPage = 10;

$broadcastTotal = (int)$pdo
    ->query('SELECT COUNT(*) FROM telegram_broadcasts')
    ->fetchColumn();

$broadcastPages = max(
    1,
    (int)ceil($broadcastTotal / $broadcastPerPage)
);

$broadcastPage = max(
    1,
    (int)($_GET['broadcast_page'] ?? 1)
);

$broadcastPage = min(
    $broadcastPage,
    $broadcastPages
);

$broadcastOffset = (
    $broadcastPage - 1
) * $broadcastPerPage;

$broadcasts = $pdo->query("
    SELECT
        b.*,
        t.bot_username
    FROM telegram_broadcasts b
    LEFT JOIN telegram_bots t
        ON t.id = b.bot_id
    ORDER BY b.id DESC
    LIMIT {$broadcastPerPage}
    OFFSET {$broadcastOffset}
")->fetchAll();
$deliveries=$pdo->query("SELECT q.*,o.invoice,o.telegram_delivery_status FROM telegram_delivery_queue q JOIN orders o ON o.id=q.order_id WHERE q.status IN ('pending','failed') ORDER BY q.id DESC LIMIT 10")->fetchAll();
$site=rtrim(seo_site_url(),'/');$cronUrl=$site.'/cron/telegram_worker.php?key='.rawurlencode($telegramCronSecret);
admin_page_start('Bot Telegram','Kelola bot auto-order, QRIS, pengiriman stok, broadcast, dan pergantian bot.');
?>
<?php if($err!==''):?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>

<section class="adm-stats telegram-stat-grid">
    <div class="adm-stat"><div class="adm-stat-icon"><?=admin_icon('telegram')?></div><div><span>Bot Aktif</span><strong><?=e($activeBot?'@'.$activeBot['bot_username']:'Belum ada')?></strong></div></div>
    <div class="adm-stat"><div class="adm-stat-icon"><?=admin_icon('user')?></div><div><span>Pengguna Bot</span><strong><?=e($stats['users'])?></strong></div></div>
    <div class="adm-stat"><div class="adm-stat-icon"><?=admin_icon('shopping-cart')?></div><div><span>Order Telegram</span><strong><?=e($stats['orders'])?></strong></div></div>
    <div class="adm-stat"><div class="adm-stat-icon"><?=admin_icon('wallet')?></div><div><span>Omzet Telegram</span><strong><?=rupiah($stats['sales'])?></strong></div></div>
</section>

<div class="telegram-admin-grid">
<section class="form-card">
    <div class="adm-card-head"><div><h3>Tambahkan Bot</h3><p>Token disimpan terenkripsi dan tidak akan ditampilkan kembali.</p></div></div>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="add_bot">
        <div class="form-grid"><div class="field"><label>Nama Internal</label><input name="bot_name" value="Auto Order Bot" required></div><div class="field"><label>Token dari BotFather</label><input type="password" name="bot_token" autocomplete="new-password" placeholder="123456789:AA..." required></div></div>
        <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('plus')?> Tambah & Uji Bot</button></div>
    </form>
</section>

<section class="form-card">
    <div class="adm-card-head"><div><h3>Worker Otomatis</h3><p>Memproses pengiriman gagal, broadcast, migrasi, dan invoice kedaluwarsa.</p></div></div>
    <div class="telegram-cron-box">
        <div class="telegram-cron-heading">
            <div class="telegram-cron-title">
                <span class="telegram-cron-title-icon" aria-hidden="true"><?=admin_icon('external-link')?></span>
                <div>
                    <label for="telegramCronUrl">URL Cron Worker</label>
                    <small>Salin alamat ini ke cron job cPanel/aaPanel.</small>
                </div>
            </div>
            <span class="telegram-cron-frequency"><?=admin_icon('refresh')?> Setiap 1 menit</span>
        </div>
        <div class="telegram-cron-field telegram-secret-field" data-secret-field>
            <span class="telegram-cron-leading" aria-hidden="true"><?=admin_icon('lock')?></span>
            <input id="telegramCronUrl" type="password" readonly spellcheck="false" autocomplete="off" value="<?=e($cronUrl)?>" onclick="this.select()" aria-label="URL Cron Bot Telegram">
            <button class="telegram-secret-toggle telegram-secret-toggle-icon" type="button" data-secret-toggle data-target="#telegramCronUrl" aria-pressed="false" title="Tampilkan URL Cron" aria-label="Tampilkan URL Cron">
                <span data-secret-icon><?=admin_icon('eye')?></span>
                <span class="sr-only" data-secret-label>Tampilkan</span>
            </button>
            <button class="telegram-cron-copy" type="button" data-copy-target="#telegramCronUrl" title="Salin URL Cron" aria-label="Salin URL Cron">
                <?=admin_icon('copy')?>
                <span>Salin URL</span>
            </button>
        </div>
        <small class="telegram-cron-help"><?=admin_icon('shield')?> Rahasiakan URL ini karena memuat kunci akses worker.</small>
    </div>
    <form method="post" style="margin-top:16px"><?=csrf_field()?><input type="hidden" name="action" value="run_worker"><button class="adm-btn soft" type="submit"><?=admin_icon('refresh')?> Jalankan Worker Sekarang</button> <span class="telegram-queue-note"><?=$stats['queue']?> antrean pengiriman</span></form>
</section>
</div>

<section class="form-card">
    <div class="adm-card-head"><div><h3>Daftar Bot</h3><p>Bot dapat diganti tanpa mengubah stok, pembayaran, pesanan, atau analitik.</p></div></div>
    <div class="table-wrap responsive-list telegram-bots-wrap"><table class="telegram-bots-table"><thead><tr><th>Bot</th><th>Status</th><th>Pengguna</th><th>Webhook</th><th>Aksi</th></tr></thead><tbody>
    <?php if($bots):foreach($bots as $bot):?>
        <tr><td data-label="Bot"><div class="cell-value"><b><?=e($bot['bot_username']?'@'.$bot['bot_username']:$bot['bot_name'])?></b><small><?=e($bot['token_hint'] ?: 'Token tersimpan')?> · <?=e($bot['last_test_at']?display_datetime_gmt7($bot['last_test_at']):'Belum diuji')?></small><?php if(!empty($bot['last_error'])):?><small class="telegram-error-text"><?=e($bot['last_error'])?></small><?php endif;?></div></td>
        <td data-label="Status"><div class="cell-value"><?=admin_status_badge($bot['status']==='active'?'aktif':($bot['status']==='disabled'?'nonaktif':'pending'))?></div></td>
        <td data-label="Pengguna"><div class="cell-value"><b><?=e($bot['user_count'])?></b></div></td>
        <td data-label="Webhook"><div class="cell-value"><div class="telegram-webhook-secret telegram-secret-field" data-secret-field>
            <input id="telegramWebhookUrl<?=$bot['id']?>" class="telegram-webhook-input" type="password" readonly spellcheck="false" autocomplete="off" value="<?=e(telegram_webhook_url($bot))?>" aria-label="URL Webhook <?=e($bot['bot_username'] ?: $bot['bot_name'])?>">
            <button class="telegram-secret-toggle telegram-secret-toggle-icon" type="button" data-secret-toggle data-target="#telegramWebhookUrl<?=$bot['id']?>" aria-pressed="false" title="Tampilkan URL Webhook" aria-label="Tampilkan URL Webhook">
                <span data-secret-icon><?=admin_icon('eye')?></span>
                <span class="sr-only" data-secret-label>Tampilkan</span>
            </button>
        </div></div></td>
        <td data-label="Aksi"><div class="cell-value"><div class="table-actions telegram-actions">
            <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="test_bot"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><button class="adm-btn soft" type="submit">Uji Token</button></form>
            <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="repair_bot"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><button class="adm-btn primary" type="submit">Periksa & Perbaiki</button></form>
            <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="set_webhook"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><button class="adm-btn soft" type="submit">Pasang Webhook</button></form>
            <?php if($bot['status']!=='active'):?><form method="post" onsubmit="return confirm('Jadikan bot ini sebagai bot aktif?')"><?=csrf_field()?><input type="hidden" name="action" value="activate_bot"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><button class="adm-btn primary" type="submit">Aktifkan</button></form><?php endif;?>
            <?php if($bot['status']!=='disabled'):?><form method="post" onsubmit="return confirm('Nonaktifkan bot ini?')"><?=csrf_field()?><input type="hidden" name="action" value="disable_bot"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><button class="adm-btn danger" type="submit">Nonaktifkan</button></form><?php endif;?>
            <form method="post" onsubmit="return confirm('Hapus bot <?=e(addslashes($bot['bot_username']?'@'.$bot['bot_username']:$bot['bot_name']))?> secara permanen? Koneksi webhook dan data pengguna khusus bot ini akan dibersihkan. Riwayat pesanan tetap disimpan.')"><?=csrf_field()?><input type="hidden" name="action" value="delete_bot"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><button class="adm-btn danger telegram-delete-bot" type="submit" <?=$bot['status']==='active'?'disabled title="Nonaktifkan bot aktif atau aktifkan bot pengganti terlebih dahulu"':''?>><?=admin_icon('trash')?> Hapus</button></form>
        </div><details class="telegram-token-replace"><summary>Ganti token</summary><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="replace_token"><input type="hidden" name="bot_id" value="<?=$bot['id']?>"><input type="password" name="bot_token" placeholder="Token baru" required><button class="adm-btn soft" type="submit">Simpan</button></form></details></div></td></tr>
    <?php endforeach;else:?><tr><td colspan="5"><div class="adm-empty"><div><b>Belum ada bot</b><p>Tambahkan token dari BotFather untuk memulai.</p></div></div></td></tr><?php endif;?>
    </tbody></table></div>
</section>

<div class="telegram-admin-grid">
<section class="form-card">
    <div class="adm-card-head"><div><h3>Menu & Pesan Bot</h3><p>Atur teks yang tampil tanpa mengubah kode bot.</p></div></div>
    <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save_messages">
        <div class="field"><label>Pesan Selamat Datang</label><textarea name="telegram_welcome_message" rows="4"><?=e(setting('telegram_welcome_message','Selamat datang di {store}, {name}!\n\nPilih menu di bawah untuk melihat produk dan melakukan pembelian otomatis.'))?></textarea><small>Variabel: <code>{store}</code> dan <code>{name}</code>.</small></div>
        <div class="field" style="margin-top:14px">
    <label>URL Gambar Selamat Datang</label>

    <input
        type="url"
        name="telegram_welcome_image"
        value="<?=e(setting('telegram_welcome_image', ''))?>"
        placeholder="https://domainanda.com/uploads/telegram/welcome.jpg"
    >

    <small>
        Gunakan URL gambar lengkap. Kosongkan jika pesan tidak memakai gambar.
    </small>
</div>
        <div class="form-grid" style="margin-top:14px"><div class="field"><label>Cara Order</label><textarea name="telegram_help_message" rows="5"><?=e(setting('telegram_help_message','1. Pilih List Produk.\n2. Pilih produk dan jumlah.\n3. Pindai QRIS yang dikirim bot.\n4. Produk dikirim otomatis setelah pembayaran berhasil.'))?></textarea></div><div class="field"><label>Informasi</label><textarea name="telegram_info_message" rows="5"><?=e(setting('telegram_info_message','Bot auto-order resmi. Pastikan username bot sesuai informasi pada website resmi.'))?></textarea></div></div>
        <div class="form-grid" style="margin-top:14px"><div class="field"><label>Tombol Produk</label><input name="telegram_button_products" value="<?=e(setting('telegram_button_products','📦 List Produk'))?>"></div><div class="field"><label>Tombol Riwayat</label><input name="telegram_button_orders" value="<?=e(setting('telegram_button_orders','📜 Riwayat'))?>"></div><div class="field"><label>Tombol Cara Order</label><input name="telegram_button_help" value="<?=e(setting('telegram_button_help','❓ Cara Order'))?>"></div><div class="field"><label>Tombol Informasi</label><input name="telegram_button_info" value="<?=e(setting('telegram_button_info','ℹ️ Informasi'))?>"></div><div class="field"><label>Tombol Website</label><input name="telegram_button_website" value="<?=e(setting('telegram_button_website','🌐 Website'))?>"></div></div>
        <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> Simpan Template</button></div>
    </form>
</section>

<section class="form-card">
    <div class="adm-card-head"><div><h3>Broadcast</h3><p>Pesan dikirim bertahap oleh worker agar lebih stabil.</p></div></div>
    <?php if($bots):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create_broadcast">
        <div class="form-grid"><div class="field"><label>Kirim melalui</label><select name="bot_id"><?php foreach($bots as $bot):if($bot['status']==='disabled')continue;?><option value="<?=$bot['id']?>" <?=$activeBot&&$activeBot['id']===$bot['id']?'selected':''?>><?=e('@'.($bot['bot_username'] ?: $bot['bot_name']))?></option><?php endforeach;?></select></div><div class="field"><label>Judul Internal</label><input name="title" placeholder="Restock produk"></div></div>
        <div class="field" style="margin-top:14px"><label>Isi Pesan</label><textarea name="message" rows="6" required></textarea></div>
        <div class="form-grid" style="margin-top:14px"><div class="field"><label>URL Gambar</label><input type="url" name="image_url" placeholder="https://..."></div><div class="field"><label>Teks Tombol</label><input name="button_text" placeholder="Beli Sekarang"></div><div class="field"><label>URL Tombol</label><input type="url" name="button_url" placeholder="https://..."><small>Contoh: https://t.me/username_bot?start=products</small></div></div>
        <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('telegram')?> Masukkan Antrean</button></div>
    </form><?php else:?><div class="adm-empty"><div><p>Tambahkan bot terlebih dahulu.</p></div></div><?php endif;?>
</section>
</div>

<?php if(count($bots)>=2):?>
<section class="form-card">
    <div class="adm-card-head"><div><h3>Migrasi ke Bot Baru</h3><p>Pengguna bot lama menerima tombol menuju bot baru dengan token penghubung satu kali.</p></div></div>
    <form method="post" class="form-grid telegram-migration-form"><?=csrf_field()?><input type="hidden" name="action" value="prepare_migration">
        <div class="field"><label>Bot Lama</label><select name="old_bot_id"><?php foreach($bots as $bot):?><option value="<?=$bot['id']?>"><?=e('@'.($bot['bot_username'] ?: $bot['bot_name']))?></option><?php endforeach;?></select></div>
        <div class="field"><label>Bot Baru</label><select name="new_bot_id"><?php foreach($bots as $bot):?><option value="<?=$bot['id']?>" <?=$activeBot&&$activeBot['id']===$bot['id']?'selected':''?>><?=e('@'.($bot['bot_username'] ?: $bot['bot_name']))?></option><?php endforeach;?></select></div>
        <div class="field"><label>Masa Berlaku Tautan</label><input type="number" name="expires_days" min="1" max="30" value="7"></div>
        <div class="field telegram-migration-submit"><label>&nbsp;</label><button class="adm-btn primary" type="submit" onclick="return confirm('Siapkan dan antrekan pemberitahuan migrasi untuk seluruh pengguna bot lama?')">Siapkan Migrasi</button></div>
    </form>
</section>
<?php endif;?>

<section class="form-card">
    <div class="adm-card-head"><div><h3>Status Antrean</h3><p>Pengiriman yang gagal akan dicoba ulang otomatis oleh worker.</p></div></div>
    <div class="table-wrap responsive-list"><table><thead><tr><th>Invoice</th><th>Status</th><th>Percobaan</th><th>Kesalahan Terakhir</th></tr></thead><tbody>
    <?php if($deliveries):foreach($deliveries as $row):?><tr><td data-label="Invoice"><div class="cell-value"><b><?=e($row['invoice'])?></b></div></td><td data-label="Status"><div class="cell-value"><?=admin_status_badge($row['status'])?></div></td><td data-label="Percobaan"><div class="cell-value"><?=e($row['attempts'])?></div></td><td data-label="Kesalahan"><div class="cell-value"><small><?=e($row['last_error'] ?: '-')?></small></div></td></tr><?php endforeach;else:?><tr><td colspan="4"><div class="adm-empty"><div><p>Tidak ada antrean pengiriman bermasalah.</p></div></div></td></tr><?php endif;?>
    </tbody></table></div>
</section>
<section class="form-card" id="broadcast-history">
    <div class="adm-card-head">
        <div>
            <h3>Riwayat Broadcast</h3>
            <p>
                Ringkasan pengiriman pesan massal terbaru.
                Menampilkan maksimal
                <?=e($broadcastPerPage)?> data per halaman.
            </p>
        </div>
    </div>

    <div class="table-wrap responsive-list">
        <table>
            <thead>
                <tr>
                    <th>Broadcast</th>
                    <th>Bot</th>
                    <th>Status</th>
                    <th>Berhasil</th>
                    <th>Gagal</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>
                <?php if ($broadcasts): ?>
                    <?php foreach ($broadcasts as $row): ?>
                        <tr>
                            <td data-label="Broadcast">
                                <div class="cell-value">
                                    <b>
                                        <?=e(
                                            $row['title']
                                            ?: 'Broadcast #' . $row['id']
                                        )?>
                                    </b>

                                    <small>
                                        <?=e(
                                            display_datetime_gmt7(
                                                $row['created_at']
                                            )
                                        )?>
                                    </small>
                                </div>
                            </td>

                            <td data-label="Bot">
                                <div class="cell-value">
                                    <?=e(
                                        '@'
                                        . ($row['bot_username'] ?: '-')
                                    )?>
                                </div>
                            </td>

                            <td data-label="Status">
                                <div class="cell-value">
                                    <?=admin_status_badge($row['status'])?>
                                </div>
                            </td>

                            <td data-label="Berhasil">
                                <div class="cell-value">
                                    <?=e($row['sent_count'])?>
                                    /
                                    <?=e($row['total_count'])?>
                                </div>
                            </td>

                            <td data-label="Gagal">
                                <div class="cell-value">
                                    <?=e($row['failed_count'])?>
                                </div>
                            </td>

                            <td data-label="Aksi">
                                <div class="cell-value">
                                    <div class="table-actions">
                                        <?php if (
                                            !in_array(
                                                $row['status'],
                                                ['queued', 'sending'],
                                                true
                                            )
                                        ): ?>
                                            <form
                                                method="post"
                                                onsubmit="return confirm(
                                                    'Hapus riwayat broadcast ini? Tindakan ini tidak dapat dibatalkan.'
                                                )"
                                            >
                                                <?=csrf_field()?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="delete_broadcast"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="broadcast_id"
                                                    value="<?=e($row['id'])?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="broadcast_page"
                                                    value="<?=e($broadcastPage)?>"
                                                >

                                                <button
                                                    class="adm-danger adm-linklike"
                                                    type="submit"
                                                >
                                                    <?=admin_icon('trash')?>
                                                    Hapus
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <small>
                                                Sedang diproses
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="6">
                            <div class="adm-empty">
                                <div>
                                    <p>Belum ada broadcast.</p>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($broadcastPages > 1): ?>
        <div
            class="adm-actions"
            style="
                margin-top:16px;
                justify-content:space-between;
                align-items:center;
            "
        >
            <div>
                <?php if ($broadcastPage > 1): ?>
                    <a
                        class="adm-btn soft"
                        href="?broadcast_page=<?=
                            $broadcastPage - 1
                        ?>#broadcast-history"
                    >
                        ← Sebelumnya
                    </a>
                <?php endif; ?>
            </div>

            <small>
                Halaman
                <?=e($broadcastPage)?>
                dari
                <?=e($broadcastPages)?>
                ·
                <?=e($broadcastTotal)?>
                broadcast
            </small>

            <div>
                <?php if ($broadcastPage < $broadcastPages): ?>
                    <a
                        class="adm-btn soft"
                        href="?broadcast_page=<?=
                            $broadcastPage + 1
                        ?>#broadcast-history"
                    >
                        Selanjutnya →
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>


<style>
.telegram-secret-field{display:flex;align-items:center;gap:7px;min-width:0}
.telegram-secret-field input[type="password"],
.telegram-secret-field input[type="text"]{min-width:0;flex:1}
.telegram-secret-toggle{display:inline-flex;align-items:center;justify-content:center;min-height:38px;border:1px solid var(--adm-border,#dfe4ea);border-radius:9px;background:var(--adm-surface,#fff);color:inherit;cursor:pointer;white-space:nowrap;transition:background .2s ease,border-color .2s ease,transform .2s ease}
.telegram-secret-toggle:hover{background:var(--adm-soft,#f5f7fa);border-color:var(--adm-border-strong,#cfd6df)}
.telegram-secret-toggle:active{transform:translateY(1px)}
.telegram-secret-toggle svg{width:17px;height:17px}
.telegram-secret-toggle-icon{width:38px;min-width:38px;padding:0}
.telegram-webhook-secret{width:min(100%,380px);margin-inline:auto}
.telegram-webhook-input{width:100%;height:38px;padding:0 10px;border:1px solid var(--adm-border,#dfe4ea);border-radius:9px;background:var(--adm-soft,#f7f8fa);font:inherit;font-size:12px;color:inherit}
.telegram-bots-table td[data-label="Webhook"] .cell-value{display:flex;justify-content:center;align-items:center;width:100%}
.telegram-bots-wrap{overflow-x:hidden!important}
.telegram-bots-table{width:100%;min-width:0!important;table-layout:fixed}
.telegram-bots-table th:nth-child(1){width:21%}
.telegram-bots-table th:nth-child(2){width:10%}
.telegram-bots-table th:nth-child(3){width:8%}
.telegram-bots-table th:nth-child(4){width:27%}
.telegram-bots-table th:nth-child(5){width:34%}
.telegram-bots-table td{min-width:0;overflow-wrap:anywhere}
.telegram-bots-table td[data-label="Aksi"] .cell-value{display:block;width:100%;min-width:0}
.telegram-actions{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px!important;width:100%;max-width:430px;min-width:0;white-space:normal!important}
.telegram-actions form{display:block!important;width:100%;min-width:0}
.telegram-actions .adm-btn{width:100%;min-width:0;min-height:36px;padding:8px 9px;white-space:normal;line-height:1.2;text-align:center}
.telegram-actions .adm-btn svg{width:14px;height:14px;flex:0 0 auto}
.telegram-actions .adm-btn:disabled{cursor:not-allowed;opacity:.48}
.telegram-token-replace{width:100%;max-width:430px;margin-top:8px}
.telegram-token-replace summary{cursor:pointer;display:inline-flex;align-items:center;min-height:30px;color:var(--adm-muted);font-size:11px;font-weight:700}
.telegram-token-replace form{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:center;width:100%;margin-top:7px}
.telegram-token-replace input{width:100%;min-width:0}
.telegram-token-replace .adm-btn{min-width:76px}
.sr-only{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
@media (max-width:700px){
    .telegram-cron-box{padding:13px!important;border-radius:12px}
    .telegram-cron-heading{gap:8px;margin-bottom:10px}
    .telegram-cron-title{gap:9px}
    .telegram-cron-title-icon{width:32px;height:32px;min-width:32px}
    .telegram-cron-title label{font-size:13px}
    .telegram-cron-title small{font-size:11px;line-height:1.35}
    .telegram-cron-frequency{padding:5px 8px;font-size:10px;white-space:nowrap}
    .telegram-cron-field{display:grid;grid-template-columns:28px minmax(0,1fr) 34px 38px;gap:6px;align-items:center;padding:5px!important;border-radius:10px}
    .telegram-cron-field .telegram-cron-leading{display:flex;align-items:center;justify-content:center;margin:0}
    .telegram-cron-field input{width:100%;height:34px!important;padding-inline:7px!important;font-size:11px!important}
    .telegram-cron-field .telegram-secret-toggle{width:34px;min-width:34px;min-height:34px;border-radius:8px}
    .telegram-cron-field .telegram-cron-copy{width:38px;min-width:38px;height:34px;min-height:34px;padding:0!important;border-radius:8px;display:inline-flex;align-items:center;justify-content:center}
    .telegram-cron-field .telegram-cron-copy span{display:none}
    .telegram-cron-help{font-size:10px;line-height:1.4;margin-top:8px}
    .telegram-webhook-secret{width:min(100%,320px);margin-inline:auto}
    .telegram-webhook-input{height:35px;font-size:11px}
    .telegram-webhook-secret .telegram-secret-toggle{width:35px;min-width:35px;min-height:35px}
    .telegram-bots-table td[data-label="Webhook"] .cell-value{justify-content:center}
    .telegram-bots-table{table-layout:auto}
    .telegram-bots-table td[data-label="Aksi"]{padding:42px 8px 10px!important}
    .telegram-bots-table td[data-label="Aksi"]::before{left:8px;top:11px;width:auto}
    .telegram-actions{grid-template-columns:repeat(2,minmax(0,1fr));max-width:none;width:100%}
    .telegram-token-replace{max-width:none}
}
@media (max-width:430px){
    .telegram-cron-heading{align-items:flex-start}
    .telegram-cron-frequency{margin-left:auto}
    .telegram-cron-field{grid-template-columns:24px minmax(0,1fr) 32px 36px}
    .telegram-cron-field .telegram-secret-toggle{width:32px;min-width:32px}
    .telegram-cron-field .telegram-cron-copy{width:36px;min-width:36px}
    .telegram-actions{grid-template-columns:1fr}
    .telegram-token-replace form{grid-template-columns:1fr}
    .telegram-token-replace .adm-btn{width:100%}
}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('[data-secret-toggle]').forEach(function(button){
        var selector=button.getAttribute('data-target');
        var input=selector?document.querySelector(selector):null;
        if(!input)return;

        input.type='password';
        button.setAttribute('aria-pressed','false');

        button.addEventListener('click',function(){
            var showing=input.type==='text';
            input.type=showing?'password':'text';
            button.setAttribute('aria-pressed',showing?'false':'true');

            var label=button.querySelector('[data-secret-label]');
            if(label)label.textContent=showing?'Tampilkan':'Sembunyikan';

            var title=showing?'Tampilkan':'Sembunyikan';
            var fieldName=input.id==='telegramCronUrl'?'URL Cron':'URL Webhook';
            button.title=title+' '+fieldName;
            button.setAttribute('aria-label',title+' '+fieldName);
        });
    });
});
</script>

<?php admin_page_end(); ?>
