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

    function admin_icon(string $name, string $class = ''): string {
        $paths = [
            'home' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v10h13V10"/><path d="M9.5 20v-6h5v6"/>',
            'package' => '<path d="m3.5 7 8.5-4 8.5 4-8.5 4-8.5-4Z"/><path d="M3.5 7v10l8.5 4 8.5-4V7"/><path d="M12 11v10"/>',
            'layers' => '<path d="m12 2.8 9 4.7-9 4.7-9-4.7 9-4.7Z"/><path d="m3 12 9 4.7 9-4.7"/><path d="m3 16.5 9 4.7 9-4.7"/>',
            'shopping-cart' => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2.5 3.5h2.3l2.1 10.1a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 1.9-1.4L21 8H6"/>',
            'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
            'file-text' => '<path d="M6 2.8h8l4 4V21H6z"/><path d="M14 2.8V7h4"/><path d="M9 12h6M9 16h6"/>',
            'tag' => '<path d="M20.5 13.5 13 21l-10-10V3h8l9.5 9.5a1.4 1.4 0 0 1 0 2Z"/><circle cx="7.5" cy="7.5" r="1.2"/>',
            'credit-card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 9.5h19M6.5 15h4"/>',
            'telegram' => '<path d="m21 3-7.7 18-4.4-6.4L3 11.2 21 3Z"/><path d="m8.9 14.6 4.1-3.8"/>',
            'refresh' => '<path d="M20 6v5h-5"/><path d="M4 18v-5h5"/><path d="M6.1 9a7 7 0 0 1 11.7-2.6L20 11M4 13l2.2 4.6A7 7 0 0 0 17.9 15"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.6v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
            'layout' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 9v12"/>',
            'external-link' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M19 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'log-out' => '<path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5"/><path d="m16 16 4-4-4-4M20 12H9"/>',
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
            'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
            'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.5 2.5 0 1 1 4.3 1.7c-1.1 1-2.1 1.5-2.1 3.3M12 18h.01"/>',
            'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
            'moon' => '<path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5 8.5 8.5 0 1 0 20.5 14.5Z"/>',
            'chevron-down' => '<path d="m7 9.5 5 5 5-5"/>',
            'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
            'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20V7"/>',
            'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
            'box' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 8h8v8H8z"/>',
            'store' => '<path d="m5 4-2 6a3 3 0 0 0 5 2 3 3 0 0 0 4 0 3 3 0 0 0 4 0 3 3 0 0 0 5-2l-2-6Z"/><path d="M5 12v8h14v-8"/><path d="M9 20v-5h6v5"/>',
            'check-card' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m8 12 2.2 2.2L16 9"/>',
            'wallet' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H18v16H6.5A2.5 2.5 0 0 1 4 17.5z"/><path d="M4 7h14M14 12h7v5h-7a2.5 2.5 0 0 1 0-5Z"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'trash' => '<path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14M10 11v6M14 11v6"/>',
            'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/>',
            'save' => '<path d="M5 3h12l3 3v15H4V4a1 1 0 0 1 1-1Z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/>',
            'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
            'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>',
            'filter' => '<path d="M4 5h16M7 12h10M10 19h4"/>',
            'eye' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/>',
            'shield' => '<path d="M12 3 20 6v5c0 5-3.4 8.4-8 10-4.6-1.6-8-5-8-10V6z"/><path d="m9 12 2 2 4-4"/>',
            'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2L12 17.3 6.4 20.2l1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
        ];
        $path = $paths[$name] ?? $paths['box'];
        $classAttr = $class !== '' ? ' class="'.e($class).'"' : '';
        return '<svg'.$classAttr.' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$path.'</svg>';
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
    <link rel="stylesheet" href="/admin/admin-modern.css?v=20261026-admin-modal-fixes">
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
<script src="/admin/admin-modern.js?v=20261026-admin-modal-fixes"></script>
</body>
</html>
        <?php
    }
}
