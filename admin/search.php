<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_pages_table();

$q = trim((string)($_GET['q'] ?? ''));
$groups = [
    'products' => ['title'=>'Produk','icon'=>'package','items'=>[]],
    'orders' => ['title'=>'Pesanan','icon'=>'shopping-cart','items'=>[]],
    'categories' => ['title'=>'Kategori','icon'=>'grid','items'=>[]],
    'pages' => ['title'=>'Halaman','icon'=>'file-text','items'=>[]],
    'promos' => ['title'=>'Promo','icon'=>'tag','items'=>[]],
];
$searchError = '';

if ($q !== '' && mb_strlen($q) >= 2) {
    $like = '%'.$q.'%';
    try {
        $st = $pdo->prepare('SELECT id,name,slug,price,status FROM products WHERE name LIKE ? OR slug LIKE ? OR description LIKE ? ORDER BY id DESC LIMIT 10');
        $st->execute([$like,$like,$like]);
        foreach ($st->fetchAll() as $row) {
            $groups['products']['items'][] = [
                'icon'=>'package','title'=>$row['name'],'meta'=>rupiah($row['price']).' · '.((int)$row['status'] ? 'Aktif' : 'Nonaktif'),
                'url'=>'/admin/products.php?edit='.(int)$row['id'],
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare('SELECT invoice,buyer_name,buyer_email,total,status,created_at FROM orders WHERE invoice LIKE ? OR buyer_name LIKE ? OR buyer_email LIKE ? ORDER BY id DESC LIMIT 10');
        $st->execute([$like,$like,$like]);
        foreach ($st->fetchAll() as $row) {
            $groups['orders']['items'][] = [
                'icon'=>'shopping-cart','title'=>$row['invoice'].' · '.$row['buyer_name'],
                'meta'=>rupiah($row['total']).' · '.ucfirst((string)$row['status']).(!empty($row['created_at'])?' · '.date('d M Y',strtotime($row['created_at'])):''),
                'url'=>'/admin/orders.php?detail='.rawurlencode((string)$row['invoice']),
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare('SELECT id,name,slug,status FROM categories WHERE name LIKE ? OR slug LIKE ? ORDER BY sort_order,name LIMIT 10');
        $st->execute([$like,$like]);
        foreach ($st->fetchAll() as $row) {
            $groups['categories']['items'][] = [
                'icon'=>'grid','title'=>$row['name'],'meta'=>'/'.$row['slug'].' · '.((int)$row['status'] ? 'Aktif' : 'Nonaktif'),
                'url'=>'/admin/categories.php?edit='.(int)$row['id'],
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare('SELECT id,title,slug,status,updated_at FROM pages WHERE title LIKE ? OR slug LIKE ? OR content LIKE ? ORDER BY id DESC LIMIT 10');
        $st->execute([$like,$like,$like]);
        foreach ($st->fetchAll() as $row) {
            $groups['pages']['items'][] = [
                'icon'=>'file-text','title'=>$row['title'],'meta'=>'/'.$row['slug'].' · '.((int)$row['status'] ? 'Aktif' : 'Nonaktif'),
                'url'=>'/admin/pages.php?edit='.(int)$row['id'],
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare('SELECT id,code,type,value,status FROM promos WHERE code LIKE ? ORDER BY id DESC LIMIT 10');
        $st->execute([$like]);
        foreach ($st->fetchAll() as $row) {
            $discount = $row['type']==='percent' ? plain_number($row['value']).'%' : rupiah($row['value']);
            $groups['promos']['items'][] = [
                'icon'=>'tag','title'=>$row['code'],'meta'=>$discount.' · '.((int)$row['status'] ? 'Aktif' : 'Nonaktif'),
                'url'=>'/admin/promos.php?edit='.(int)$row['id'],
            ];
        }
    } catch (Throwable $e) {}
} elseif ($q !== '') {
    $searchError = 'Masukkan minimal 2 karakter untuk melakukan pencarian.';
}

$total = array_sum(array_map(fn($group)=>count($group['items']), $groups));
admin_page_start('Pencarian', 'Temukan produk, pesanan, kategori, halaman, dan promo dari satu tempat.');
?>
<?php if ($searchError !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($searchError)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<div class="admin-search-summary">
    <div><?php if($q===''):?><b>Mulai pencarian</b><span>Gunakan kolom pencarian di bagian atas atau tekan Ctrl/⌘ + K.</span><?php else:?><b>Hasil untuk “<?=e($q)?>”</b><span><?=e($total)?> hasil ditemukan pada seluruh data admin.</span><?php endif;?></div>
    <?php if($q!==''):?><a class="adm-btn soft" href="/admin/search.php">Bersihkan pencarian</a><?php endif;?>
</div>

<?php if ($q === ''): ?>
<section class="adm-card"><div class="admin-empty-state"><span><?=admin_icon('search')?></span><b>Cari data admin</b><small>Masukkan nama produk, invoice, pembeli, kategori, halaman, atau kode promo.</small></div></section>
<?php elseif ($total === 0 && $searchError === ''): ?>
<section class="adm-card"><div class="admin-empty-state"><span><?=admin_icon('search')?></span><b>Tidak ada hasil yang cocok</b><small>Coba kata kunci yang lebih singkat atau periksa kembali ejaannya.</small></div></section>
<?php else: ?>
<div class="admin-search-grid">
    <?php foreach ($groups as $group): ?>
    <section class="adm-card admin-search-section">
        <header class="admin-search-section-head"><div><span><?=admin_icon($group['icon'])?></span><h3><?=e($group['title'])?></h3></div><b><?=count($group['items'])?></b></header>
        <div class="admin-search-list">
            <?php if (!$group['items']): ?><div class="admin-search-empty">Tidak ada hasil pada bagian ini.</div><?php else: foreach($group['items'] as $item): ?>
            <a class="admin-search-item" href="<?=e($item['url'])?>"><span><?=admin_icon($item['icon'])?></span><div><b><?=e($item['title'])?></b><small><?=e($item['meta'])?></small></div><i><?=admin_icon('chevron-right')?></i></a>
            <?php endforeach; endif; ?>
        </div>
    </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php admin_page_end(); ?>
