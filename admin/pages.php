<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_pages_table();

$err = '';
$edit = null;
$form = [
    'id' => 0,
    'title' => '',
    'slug' => '',
    'content' => '',
    'sort_order' => 0,
    'status' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');

    try {
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Halaman tidak valid.');
            $st = $pdo->prepare('DELETE FROM pages WHERE id=?');
            $st->execute([$id]);
            admin_redirect('/admin/pages.php', $st->rowCount() ? 'Halaman berhasil dihapus.' : 'Halaman tidak ditemukan.', $st->rowCount() ? 'success' : 'error');
        }

        if ($action === 'delete_selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            if (!$ids) throw new RuntimeException('Pilih minimal satu halaman yang akan dihapus.');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("DELETE FROM pages WHERE id IN ($in)");
            $st->execute($ids);
            admin_redirect('/admin/pages.php', $st->rowCount().' halaman berhasil dihapus.');
        }

        if ($action === 'delete_all') {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn();
            $pdo->exec('DELETE FROM pages');
            admin_redirect('/admin/pages.php', $count ? $count.' halaman berhasil dihapus.' : 'Belum ada halaman untuk dihapus.', $count ? 'success' : 'error');
        }

        $form = [
            'id' => (int)($_POST['id'] ?? 0),
            'title' => trim((string)($_POST['title'] ?? '')),
            'slug' => slugify((string)(($_POST['slug'] ?? '') ?: ($_POST['title'] ?? ''))),
            'content' => (string)($_POST['content'] ?? ''),
            'sort_order' => (int)($_POST['sort_order'] ?? 0),
            'status' => isset($_POST['status']) ? 1 : 0,
        ];

        if ($form['title'] === '') throw new RuntimeException('Judul halaman wajib diisi.');
        if ($form['slug'] === '') throw new RuntimeException('Slug halaman wajib diisi.');
        if (mb_strlen($form['title']) > 180) throw new RuntimeException('Judul halaman maksimal 180 karakter.');
        if (mb_strlen($form['slug']) > 190) throw new RuntimeException('Slug halaman maksimal 190 karakter.');

        $dup = $pdo->prepare('SELECT id FROM pages WHERE slug=? AND id<>? LIMIT 1');
        $dup->execute([$form['slug'], $form['id']]);
        if ($dup->fetchColumn()) throw new RuntimeException('Slug sudah digunakan halaman lain.');

        if ($form['id'] > 0) {
            $st = $pdo->prepare('UPDATE pages SET title=?,slug=?,content=?,sort_order=?,status=?,updated_at=NOW() WHERE id=?');
            $st->execute([$form['title'],$form['slug'],$form['content'],$form['sort_order'],$form['status'],$form['id']]);
            admin_redirect('/admin/pages.php?edit='.$form['id'], 'Halaman berhasil diperbarui.');
        }

        $st = $pdo->prepare('INSERT INTO pages(title,slug,content,sort_order,status,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())');
        $st->execute([$form['title'],$form['slug'],$form['content'],$form['sort_order'],$form['status']]);
        admin_redirect('/admin/pages.php', 'Halaman baru berhasil dibuat.');
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $err = str_contains($message, 'duplicate') ? 'Slug sudah digunakan halaman lain.' : $e->getMessage();
    }
}

if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM pages WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) $form = array_merge($form, $edit);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form['id'] > 0) $edit = $form;

$rows = $pdo->query('SELECT * FROM pages ORDER BY sort_order ASC, id DESC')->fetchAll();
admin_page_start('Halaman', 'Buat dan kelola halaman statis seperti kontak, kebijakan layanan, garansi, atau refund.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" class="form-card">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=e($form['id'])?>">
    <div class="adm-card-head">
        <div><h3><?= $edit ? 'Edit Halaman' : 'Tambah Halaman' ?></h3><p>Konten akan tersedia melalui URL publik berdasarkan slug halaman.</p></div>
        <?php if ($edit): ?><a class="adm-btn soft" target="_blank" rel="noopener" href="/page.php?slug=<?=e($form['slug'])?>"><?=admin_icon('external-link')?> Lihat halaman</a><?php endif; ?>
    </div>
    <div class="form-grid">
        <div class="field"><label>Judul Halaman</label><input name="title" required maxlength="180" placeholder="Contoh: Kebijakan Pengembalian" value="<?=e($form['title'])?>"></div>
        <div class="field"><label>Slug / URL</label><input name="slug" maxlength="190" placeholder="kebijakan-pengembalian" value="<?=e($form['slug'])?>"><small>URL publik: /page.php?slug=slug-halaman</small></div>
        <div class="field"><label>Urutan</label><input type="number" name="sort_order" value="<?=e($form['sort_order'])?>"><small>Angka lebih kecil ditampilkan lebih awal.</small></div>
    </div>
    <div class="field" style="margin-top:16px"><label>Isi Halaman</label><textarea name="content" class="page-content-editor" rows="12" placeholder="Tulis isi halaman di sini. HTML sederhana diperbolehkan."><?=e($form['content'])?></textarea><small>Gunakan heading, paragraf, dan daftar secukupnya agar mudah dibaca.</small></div>
    <div class="adm-checks"><label><input type="checkbox" name="status" <?=!empty($form['status'])?'checked':''?>> Aktif dan tampil di website</label></div>
    <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> <?= $edit ? 'Perbarui Halaman' : 'Simpan Halaman' ?></button><?php if($edit):?><a class="adm-btn soft" href="/admin/pages.php">Batal Edit</a><?php endif;?></div>
</form>

<form id="pagesBulkForm" method="post" class="bulk-form" data-bulk-form>
    <?=csrf_field()?>
    <div class="bulk-toolbar">
        <label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="pagesBulkForm"> Pilih semua</label>
        <div class="bulk-actions"><button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" data-confirm="Hapus halaman terpilih?">Hapus terpilih</button><button class="adm-btn danger" type="submit" name="action" value="delete_all" data-confirm="Hapus semua halaman?">Hapus semua</button></div>
    </div>
</form>

<div class="table-wrap responsive-list">
<table>
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Halaman</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="4"><div class="admin-empty-state"><span><?=admin_icon('file-text')?></span><b>Belum ada halaman</b><small>Buat halaman statis pertama melalui formulir di atas.</small></div></td></tr>
    <?php else: foreach($rows as $r): ?>
        <tr>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><input form="pagesBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$r['id']?>"></div></td>
            <td data-label="Halaman"><div class="cell-value"><b><?=e($r['title'])?></b><small><?=e(mb_strimwidth(trim(strip_tags((string)$r['content'])),0,90,'…'))?></small><a class="table-secondary-link" href="/page.php?slug=<?=e($r['slug'])?>" target="_blank" rel="noopener">/page.php?slug=<?=e($r['slug'])?></a><small>Urutan <?=e($r['sort_order'])?> · <?=!empty($r['updated_at'])?e(date('d M Y, H:i',strtotime($r['updated_at']))):'Belum diperbarui'?></small></div></td>
            <td data-label="Status"><div class="cell-value"><?= $r['status'] ? admin_status_badge('aktif') : admin_status_badge('nonaktif') ?></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions"><a href="?edit=<?=$r['id']?>"><?=admin_icon('edit')?> Edit</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="adm-danger adm-linklike" type="submit" data-confirm="Hapus halaman ini?"><?=admin_icon('trash')?> Hapus</button></form></div></div></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php admin_page_end(); ?>
