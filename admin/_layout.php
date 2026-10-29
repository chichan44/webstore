<?php
if (!function_exists('admin_page_start')) {
    function admin_nav_items(): array {
        return [
            ['Dashboard', '/admin/dashboard.php', 'home', 'dashboard.php'],
            ['Produk', '/admin/products.php', 'package', 'products.php'],
            ['Stok Produk', '/admin/stocks.php', 'layers', 'stocks.php'],
            ['Ulasan Produk', '/admin/reviews.php', 'star', 'reviews.php'],
            ['Pesanan', '/admin/orders.php', 'shopping-cart', 'orders.php'],
            ['Kategori', '/admin/categories.php', 'grid', 'categories.php'],
            ['Halaman', '/admin/pages.php', 'file-text', 'pages.php'],
            ['Promo', '/admin/promos.php', 'tag', 'promos.php'],
            ['Payment Gateway', '/admin/tokopay.php', 'credit-card', 'tokopay.php'],
            ['Bot Telegram', '/admin/telegram.php', 'telegram', 'telegram.php'],
            ['Pengaturan', '/admin/settings.php', 'settings', 'settings.php'],
            ['Footer', '/admin/footer.php', 'layout', 'footer.php'],
        ];
    }

    /* Shared icon component — same source as the storefront header (ui_icon in includes/helpers.php). */
    function admin_icon(string $name, string $class = ''): string {
        return ui_icon($name, $class);
    }

    function admin_page_icon_name(string $file): string {
        $map = [
            'dashboard.php' => 'chart', 'products.php' => 'package', 'stocks.php' => 'layers',
            'reviews.php' => 'star',
            'orders.php' => 'shopping-cart', 'categories.php' => 'grid', 'pages.php' => 'file-text',
            'promos.php' => 'tag', 'tokopay.php' => 'credit-card', 'telegram.php' => 'telegram', 'settings.php' => 'settings',
            'footer.php' => 'layout', 'profile.php' => 'user', 'search.php' => 'search',
        ];
        return $map[$file] ?? 'box';
    }

    function admin_status_badge($status): string {
        $status = strtolower(trim((string)$status));
        $class = 'neutral';
        $labels = [
            'pending' => 'Menunggu', 'paid' => 'Dibayar', 'delivered' => 'Terkirim',
            'cancelled' => 'Dibatalkan', 'failed' => 'Gagal', 'aktif' => 'Aktif',
            'nonaktif' => 'Nonaktif', 'available' => 'Tersedia', 'reserved' => 'Dipesan', 'sold' => 'Terjual',
            'queued' => 'Dalam Antrean', 'sending' => 'Mengirim', 'sent' => 'Terkirim', 'completed' => 'Selesai',
            'stok habis' => 'Stok Habis', 'stock habis' => 'Stok Habis',
        ];
        if ($status === 'pending' || $status === 'reserved' || $status === 'queued' || $status === 'sending') $class = 'pending';
        if ($status === 'paid' || $status === 'delivered' || $status === 'sent' || $status === 'completed') $class = 'paid';
        if ($status === 'aktif' || $status === 'available') $class = 'available';
        if ($status === 'sold') $class = 'sold';
        if ($status === 'stok habis' || $status === 'stock habis') $class = 'empty-stock';
        if ($status === 'cancelled' || $status === 'failed') $class = 'danger';
        $label = $labels[$status] ?? ($status !== '' ? ucfirst($status) : '-');
        return '<span class="adm-status '.$class.'"><i aria-hidden="true"></i>'.e($label).'</span>';
    }

    function admin_flash_success(string $message): void { flash('admin_success', $message); }
    function admin_flash_error(string $message): void { flash('admin_error', $message); }
    function admin_redirect(string $url, string $message = '', string $type = 'success'): void {
        if ($message !== '') {
            if ($type === 'error') admin_flash_error($message); else admin_flash_success($message);
        }
        header('Location: '.$url);
        exit;
    }

    function admin_page_start(string $title, string $subtitle = ''): void {
        $me = admin_user();
        $current = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
        $pageSlug = preg_replace('/[^a-z0-9-]/', '', strtolower(pathinfo($current, PATHINFO_FILENAME)));
        $notif = function_exists('admin_notification_summary') ? admin_notification_summary() : ['unread'=>0,'items'=>[]];
        $notifCount = (int)($notif['unread'] ?? 0);
        $brandName = trim((string)setting('site_name', 'Admin Store')) ?: 'Admin Store';
        $favicon = trim((string)setting('site_favicon', ''));
        $globalSuccess = (string)(flash('admin_success') ?? '');
        $globalError = (string)(flash('admin_error') ?? '');
        $searchValue = $current === 'search.php' ? trim((string)($_GET['q'] ?? '')) : '';
        ?>
<!doctype html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title><?= e($title) ?> - <?= e($brandName) ?></title>
    <?php if ($favicon !== ''): ?><link rel="icon" href="<?= e($favicon) ?>"><?php endif; ?>
    <link rel="stylesheet" href="/assets/css/admin-v9.css?v=20260811-order-pin-removed">
    <link rel="stylesheet" href="/admin/admin-modern.css?v=20261029-ui-fixes">
</head>
<body class="admin-modern admin-page-<?= e($pageSlug) ?>" data-admin-page="<?= e($pageSlug) ?>">
<div class="adm-overlay" id="admOverlay"></div>
<div class="adm-shell" id="admShell">
    <aside class="adm-sidebar" id="admSidebar">
        <a class="adm-brand" href="/admin/dashboard.php" aria-label="Ke dashboard">
            <div class="adm-brand-icon"><?= admin_icon('store') ?></div>
            <div class="adm-brand-copy">
                <h1><?= e($brandName) ?></h1>
                <p>Digital Store Admin</p>
            </div>
        </a>
        <div class="adm-sidebar-scroll">
            <?php $sidebarItems = admin_nav_items(); ?>
            <p class="adm-sidebar-label">Menu Utama</p>
            <nav class="adm-nav" aria-label="Menu admin utama">
                <?php foreach ($sidebarItems as $item): if(in_array($item[3], ['dashboard.php','products.php','stocks.php','reviews.php','orders.php','categories.php','pages.php','promos.php'], true)): ?>
                    <a class="<?= $current === $item[3] ? 'active' : '' ?>" href="<?= e($item[1]) ?>" <?= $current === $item[3] ? 'aria-current="page"' : '' ?>>
                        <span><?= admin_icon($item[2]) ?></span><b><?= e($item[0]) ?></b><?php if ($current === $item[3]): ?><i><?= admin_icon('chevron-right') ?></i><?php endif; ?>
                    </a>
                <?php endif; endforeach; ?>
            </nav>
            <div class="adm-sidebar-sep"></div>
            <nav class="adm-nav adm-nav-secondary" aria-label="Menu admin tambahan">
                <?php foreach ($sidebarItems as $item): if(in_array($item[3], ['tokopay.php','telegram.php','settings.php','footer.php'], true)): ?>
                    <a class="<?= $current === $item[3] ? 'active' : '' ?>" href="<?= e($item[1]) ?>" <?= $current === $item[3] ? 'aria-current="page"' : '' ?>>
                        <span><?= admin_icon($item[2]) ?></span><b><?= e($item[0]) ?></b><?php if ($current === $item[3]): ?><i><?= admin_icon('chevron-right') ?></i><?php endif; ?>
                    </a>
                <?php endif; endforeach; ?>
            </nav>
            <div class="adm-sidebar-sep"></div>
            <p class="adm-sidebar-label">Lainnya</p>
            <nav class="adm-nav adm-nav-bottom" aria-label="Link cepat">
                <a href="/" target="_blank" rel="noopener"><span><?= admin_icon('external-link') ?></span><b>Lihat Website</b></a>
                <a class="<?= $current === 'profile.php' ? 'active' : '' ?>" href="/admin/profile.php"><span><?= admin_icon('user') ?></span><b>Profil Admin</b></a>
            </nav>
        </div>
        <form class="adm-logout-form" action="/admin/logout.php" method="post"><?=csrf_field()?><button class="logout" type="submit"><span><?= admin_icon('log-out') ?></span><b>Logout</b></button></form>
    </aside>

    <section class="adm-main">
        <header class="adm-topbar">
            <button class="adm-toggle" type="button" id="admToggle" aria-label="Buka atau tutup sidebar" aria-controls="admSidebar"><?= admin_icon('menu') ?></button>
            <div class="adm-mobile-title"><b><?= e($brandName) ?></b><small><?= e($title) ?></small></div>
            <form class="adm-search adm-global-search" id="admGlobalSearchForm" action="/admin/search.php" method="get" role="search">
                <span><?= admin_icon('search') ?></span>
                <input id="admGlobalSearch" type="search" name="q" value="<?= e($searchValue) ?>" placeholder="Cari produk, pesanan, kategori, atau halaman..." autocomplete="off" aria-label="Pencarian admin">
                <kbd>⌘ K</kbd>
            </form>
            <div class="adm-top-actions">
                <button class="adm-icon-btn adm-mobile-search-btn" type="button" id="admMobileSearchBtn" title="Pencarian" aria-label="Buka pencarian" aria-controls="admGlobalSearchForm" aria-expanded="false"><?= admin_icon('search') ?></button>
                <div class="adm-notif-wrap">
                    <button class="adm-icon-btn adm-notif-btn<?= $notifCount>0 ? ' has-unread' : '' ?>" type="button" id="admNotifBtn" title="Notifikasi" aria-label="<?= $notifCount>0 ? 'Notifikasi, '.$notifCount.' belum dibaca' : 'Notifikasi, semua sudah dibaca' ?>" aria-expanded="false"><?= admin_icon('bell') ?><?php if($notifCount>0): ?><em><?= e(min($notifCount,99)) ?></em><?php endif; ?></button>
                    <div class="adm-notif-panel" id="admNotifPanel" role="dialog" aria-label="Notifikasi pesanan">
                        <div class="adm-notif-head"><b>Notifikasi</b><div><button class="adm-notif-read" type="button" data-mark-read aria-label="Tandai semua notifikasi sudah dibaca"<?= $notifCount<=0 ? ' disabled' : '' ?>>Sudah dibaca</button><a href="/admin/orders.php">Lihat semua</a></div></div>
                        <?php if(!empty($notif['items'])): foreach($notif['items'] as $n): ?>
                            <a class="adm-notif-item <?= !empty($n['unread']) ? 'unread' : 'read' ?>" href="/admin/orders.php?detail=<?= e($n['invoice']) ?>">
                                <span class="<?= e($n['status']) ?>"></span>
                                <div><b><?= e($n['label']) ?></b><small><?= e($n['invoice']) ?> · <?= e($n['buyer']) ?> · <?= rupiah($n['total']) ?></small></div>
                            </a>
                        <?php endforeach; else: ?>
                            <div class="adm-notif-empty">Belum ada notifikasi pesanan.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="adm-help-wrap">
                    <button class="adm-icon-btn adm-help-btn" type="button" id="admHelpBtn" title="Bantuan" aria-label="Bantuan" aria-expanded="false"><?= admin_icon('help') ?></button>
                    <div class="adm-help-panel" id="admHelpPanel">
                        <div class="adm-help-panel-head"><span><?= admin_icon('help') ?></span><div><b>Pusat Bantuan</b><small>Akses cepat pengelolaan toko</small></div></div>
                        <a href="/admin/products.php?add=1"><span><?= admin_icon('plus') ?></span><div><b>Tambah produk</b><small>Buat produk digital baru</small></div></a>
                        <a href="/admin/settings.php"><span><?= admin_icon('settings') ?></span><div><b>Pengaturan website</b><small>Logo, identitas, dan banner</small></div></a>
                        <a href="/" target="_blank" rel="noopener"><span><?= admin_icon('external-link') ?></span><div><b>Lihat website</b><small>Buka tampilan publik toko</small></div></a>
                        <p>Tekan <kbd>Ctrl/⌘ + K</kbd> untuk fokus ke pencarian.</p>
                    </div>
                </div>
                <button class="adm-icon-btn" type="button" id="admThemeBtn" title="Ganti tema" aria-label="Ganti tema"><?= admin_icon('sun') ?></button>
                <div class="adm-profile-wrap">
                    <button class="adm-profile" type="button" id="admProfileBtn" title="Profil admin" aria-expanded="false">
                        <div class="adm-avatar"><?= e(strtoupper(substr($me['username'] ?? 'A', 0, 1))) ?></div>
                        <div><b><?= e($me['username'] ?? 'Admin') ?></b><small><?= e($me['role'] ?? 'Administrator') ?></small></div>
                        <span class="adm-profile-arrow" aria-hidden="true"><?= admin_icon('chevron-down') ?></span>
                    </button>
                    <div class="adm-profile-menu" id="admProfileMenu">
                        <div class="adm-profile-menu-head"><b><?= e($me['username'] ?? 'Admin') ?></b><small><?= e($me['role'] ?? 'Administrator') ?></small></div>
                        <a href="/admin/profile.php"><span><?= admin_icon('user') ?></span><div><b>Profil & keamanan</b><small>Ubah data akun, password, dan PIN</small></div></a>
                        <a href="/admin/settings.php"><span><?= admin_icon('settings') ?></span><div><b>Pengaturan website</b><small>Kelola identitas dan konfigurasi toko</small></div></a>
                        <form action="/admin/logout.php" method="post"><?=csrf_field()?><button type="submit"><span><?= admin_icon('log-out') ?></span><div><b>Logout</b><small>Keluar dari admin panel</small></div></button></form>
                    </div>
                </div>
            </div>
        </header>

        <main class="adm-content">
            <section class="adm-page-head">
                <div class="adm-page-title-wrap">
                    <span class="adm-page-icon"><?= admin_icon(admin_page_icon_name($current)) ?></span>
                    <div><p class="adm-breadcrumb">Admin / <?= e($title) ?></p><h2><?= e($title) ?></h2><?php if ($subtitle): ?><p class="adm-page-subtitle"><?= e($subtitle) ?></p><?php endif; ?></div>
                </div>
            </section>
            <?php if ($globalSuccess !== ''): ?><div class="alert ok" role="status"><span>✓</span><div><?= e($globalSuccess) ?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>
            <?php if ($globalError !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?= e($globalError) ?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>
        <?php
    }

    function admin_page_end(): void {
        $current = basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
        $bottom = [
            ['Dashboard','/admin/dashboard.php','home','dashboard.php'],
            ['Produk','/admin/products.php','package','products.php'],
            ['Pesanan','/admin/orders.php','shopping-cart','orders.php'],
            ['Kategori','/admin/categories.php','grid','categories.php'],
            ['Lainnya','/admin/settings.php','settings','settings.php'],
        ];
        ?>
        <footer class="adm-footer">© <?= e(date('Y')) ?> <?= e(trim((string)setting('site_name','Admin Store')) ?: 'Admin Store') ?>. Semua hak dilindungi.</footer>
        </main>
        <nav class="adm-bottom-nav" aria-label="Menu mobile admin">
            <?php foreach($bottom as $b): ?><a class="<?= $current===$b[3]?'active':'' ?>" href="<?= e($b[1]) ?>"><span><?= admin_icon($b[2]) ?></span><b><?= e($b[0]) ?></b></a><?php endforeach; ?>
        </nav>
    </section>
</div>
<script>window.adminCsrf=<?=json_encode(csrf_token())?>;window.adminIconSun=<?=json_encode(admin_icon('sun'))?>;window.adminIconMoon=<?=json_encode(admin_icon('moon'))?>;</script>
<script src="/admin/admin-modern.js?v=20261029-ui-fixes"></script>
</body>
</html>
        <?php
    }
}
