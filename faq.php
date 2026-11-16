<?php
require_once __DIR__.'/includes/helpers.php';
$site=setting('site_name','Digital Store');
$desc=setting('site_description','Toko Produk Digital Terlengkap');
$cats=$pdo->query('SELECT * FROM categories WHERE status=1 ORDER BY sort_order,name')->fetchAll();

/* FAQ / Pusat Bantuan ala premifystore.id/faq: accordion 8 pertanyaan + kartu kontak support.
   Jawaban memakai markup statis (<strong>/<a>) — string terpercaya dari kode & lang file. */
$wa=footer_clean_url((string)setting('footer_whatsapp_url',''));
$tg=footer_clean_url((string)setting('footer_telegram_url',''));
$faqItems=[
    ['icon'=>'shopping-cart','tint'=>'rose',
     'q'=>t('Bagaimana cara melakukan pemesanan?'),
     'a'=>t('Pilih produk yang Anda inginkan di halaman katalog, tentukan varian, lalu klik tombol <strong>Beli Sekarang</strong> atau tambahkan ke keranjang. Lengkapi data pembeli (<strong>Nama Lengkap</strong>, <strong>Nomor WhatsApp</strong>, dan email aktif), pilih metode pembayaran yang tersedia, lalu selesaikan pembayaran. Setelah pembayaran terkonfirmasi, pesanan akan diproses secara otomatis.')],
    ['icon'=>'clock','tint'=>'amber',
     'q'=>t('Berapa lama proses pengiriman?'),
     'a'=>t('Sistem kami bekerja otomatis 24/7. Setelah pembayaran terverifikasi, produk digital akan langsung tersedia di halaman <a href="/track-order.php">Lacak Pesanan</a> dan dikirimkan ke kontak yang Anda daftarkan (email/WhatsApp). Proses biasanya memakan waktu kurang dari 5 menit.')],
    ['icon'=>'search','tint'=>'blue',
     'q'=>t('Di mana saya bisa melihat pesanan saya?'),
     'a'=>t('Buka halaman <a href="/track-order.php">Lacak Pesanan</a> lalu masukkan <strong>nomor invoice</strong> Anda (dan kata sandi produk bila diminta). Di sana Anda dapat melihat status pesanan beserta detail produk digital yang sudah dibeli — tanpa perlu login.')],
    ['icon'=>'shield','tint'=>'green',
     'q'=>t('Apakah ada garansi?'),
     'a'=>t('Ya! Kami memberikan garansi sesuai durasi yang tertera pada setiap varian produk — detail lengkapnya ada di halaman <a href="/ketentuan-garansi.php">Ketentuan Garansi</a>. Jika terjadi masalah pada produk (tidak bisa digunakan, akun bermasalah, dll), hubungi admin kami dan kami akan segera membantu atau mengganti sesuai ketentuan yang berlaku.')],
    ['icon'=>'credit-card','tint'=>'purple',
     'q'=>t('Metode pembayaran apa saja yang tersedia?'),
     'a'=>t('Kami mendukung pembayaran melalui <strong>QRIS</strong> (semua e-wallet dan mobile banking), transfer bank, serta metode pembayaran lainnya yang tersedia di halaman checkout. Pilih metode yang paling nyaman untuk Anda.')],
    ['icon'=>'user','tint'=>'indigo',
     'q'=>t('Apakah akun bisa digunakan bersama-sama?'),
     'a'=>t('Tergantung jenis produk yang dibeli. Beberapa produk dijual dalam bentuk <strong>slot sharing</strong> yang memang dirancang untuk digunakan bersama, sedangkan untuk akun private hanya untuk satu pengguna saja. Detail penggunaan dijelaskan pada deskripsi produk.')],
    ['icon'=>'refresh','tint'=>'orange',
     'q'=>t('Apakah bisa refund jika tidak jadi beli?'),
     'a'=>t('Untuk produk digital yang sudah terkirim, <strong>tidak dapat di-refund</strong> karena sifat produk yang langsung dapat digunakan. Namun jika terjadi kesalahan sistem atau produk bermasalah, kami akan memberikan penggantian atau kompensasi sesuai ketentuan garansi kami.')],
    ['icon'=>'box','tint'=>'teal',
     'q'=>t('Bagaimana jika produk yang saya inginkan tidak tersedia?'),
     'a'=>t('Jika produk sedang <strong>habis stok</strong>, Anda dapat menghubungi admin kami untuk request ketersediaan. Kami akan segera mengupdate stok atau memberikan estimasi waktu produk tersedia kembali.')],
];
?>
<!doctype html><html lang="id" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php render_seo_meta(['site'=>$site,'title'=>t('Pusat Bantuan (FAQ)').' - '.$site,'description'=>t('Pertanyaan yang sering ditanyakan (FAQ) seputar pemesanan, pembayaran, pengiriman, dan garansi produk digital.'),'path'=>'/faq.php','robots'=>'index, follow']); render_storefront_styles('page'); ?>
<link rel="stylesheet" href="/assets/css/faq-v1.css?v=20261116-faq1">
</head><body>
<?php render_storefront_header($site,$desc,$cats); ?>
<main class="ds-shell page-wrap">
    <section class="page-hero faq-hero">
        <h1><?=ui_icon('help','faq-hero-icon')?> <?=e(t('Pusat Bantuan (FAQ)'))?></h1>
        <p><?=e(t('Jawaban atas pertanyaan yang sering ditanyakan sebelum berbelanja.'))?></p>
    </section>
    <section class="faq-card">
        <?php foreach($faqItems as $i=>$item): $fid='faqA'.($i+1); ?>
        <div class="faq-item">
            <button type="button" class="faq-head" aria-expanded="false" aria-controls="<?=$fid?>">
                <span class="faq-ico tint-<?=$item['tint']?>"><?=ui_icon($item['icon'])?></span>
                <span class="faq-q"><?=$item['q']?></span>
                <span class="faq-chev"><?=ui_icon('chevron-down')?></span>
            </button>
            <div class="faq-answer" id="<?=$fid?>">
                <div class="faq-a"><?=$item['a']?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </section>
    <section class="faq-cta">
        <div class="faq-cta-icon"><?=ui_icon('help')?></div>
        <h2><?=e(t('Masih Ada Pertanyaan?'))?></h2>
        <p><?=e(t('Tim support kami siap membantu Anda.'))?></p>
        <div class="faq-cta-actions">
            <?php /* Tombol selalu tampil (fallback '#' seperti footer); target=_blank hanya untuk URL asli. */ ?>
            <a class="faq-cta-btn primary" href="<?=e($wa)?>"<?=preg_match('~^https?://~i',$wa)?' target="_blank" rel="noopener"':''?>><?=ui_icon('chat')?> <?=e(t('Hubungi WhatsApp'))?></a>
            <a class="faq-cta-btn ghost" href="<?=e($tg)?>"<?=preg_match('~^https?://~i',$tg)?' target="_blank" rel="noopener"':''?>><?=ui_icon('telegram')?> <?=e(t('Hubungi Telegram'))?></a>
        </div>
    </section>
</main>
<?php render_footer_columns($site,$desc); ?>
<?php render_storefront_mobile_menu($site,$desc,$cats); ?>
<div class="cart-drawer" id="cartDrawer"><div class="cart-backdrop" data-close-cart></div><aside><div class="drawer-top"><b>Keranjang Belanja (<span data-cart-count>0</span>)</b><button data-close-cart>×</button></div><div class="cart-items" id="cartItems"></div><div class="drawer-foot"><p><span>Subtotal</span><b data-cart-total><?=e(format_money(0))?></b></p><a href="/checkout.php" class="btn primary">Lanjut Checkout</a></div></aside></div>
<script id="productsJson" type="application/json">[]</script>
<script src="/assets/js/front-mobile-v7.js?v=20261112-feat1"></script>
<script src="/assets/js/faq-v1.js?v=20261116-faq1"></script>
</body></html>
