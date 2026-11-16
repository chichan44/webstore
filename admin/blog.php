<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_blog_tables();

$err = '';
$edit = null;
$form = [
    'id' => 0,
    'title' => '',
    'slug' => '',
    'excerpt' => '',
    'content' => '',
    'category_id' => 0,
    'tags' => '',
    'author' => 'Admin',
    'read_time' => 3,
    'cover_image' => '',
    'cover_color' => '#6366f1',
    'meta_title' => '',
    'meta_description' => '',
    'meta_keywords' => '',
    'published_at' => '',
    'status' => 1,
];

$catErr = '';
$catEdit = null;
$catForm = ['id' => 0, 'name' => '', 'slug' => '', 'description' => '', 'sort_order' => 0, 'status' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');

    try {
        /* ===== Kategori ===== */
        if ($action === 'cat_save') {
            $catForm = [
                'id' => (int)($_POST['id'] ?? 0),
                'name' => trim((string)($_POST['name'] ?? '')),
                'slug' => slugify((string)(($_POST['slug'] ?? '') ?: ($_POST['name'] ?? ''))),
                'description' => trim((string)($_POST['description'] ?? '')),
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'status' => isset($_POST['status']) ? 1 : 0,
            ];
            if ($catForm['name'] === '') throw new RuntimeException('Nama kategori wajib diisi.');
            if ($catForm['slug'] === '') throw new RuntimeException('Slug kategori wajib diisi.');
            if (mb_strlen($catForm['name']) > 100) throw new RuntimeException('Nama kategori maksimal 100 karakter.');
            if (mb_strlen($catForm['slug']) > 120) throw new RuntimeException('Slug kategori maksimal 120 karakter.');
            $dup = $pdo->prepare('SELECT id FROM blog_categories WHERE slug=? AND id<>? LIMIT 1');
            $dup->execute([$catForm['slug'], $catForm['id']]);
            if ($dup->fetchColumn()) throw new RuntimeException('Slug sudah digunakan kategori lain.');
            if ($catForm['id'] > 0) {
                $st = $pdo->prepare('UPDATE blog_categories SET name=?,slug=?,description=?,sort_order=?,status=?,updated_at=NOW() WHERE id=?');
                $st->execute([$catForm['name'],$catForm['slug'],$catForm['description'],$catForm['sort_order'],$catForm['status'],$catForm['id']]);
                admin_redirect('/admin/blog.php', 'Kategori berhasil diperbarui.');
            }
            $st = $pdo->prepare('INSERT INTO blog_categories(name,slug,description,sort_order,status,created_at,updated_at) VALUES(?,?,?,?,?,NOW(),NOW())');
            $st->execute([$catForm['name'],$catForm['slug'],$catForm['description'],$catForm['sort_order'],$catForm['status']]);
            admin_redirect('/admin/blog.php', 'Kategori baru berhasil dibuat.');
        }

        if ($action === 'cat_delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Kategori tidak valid.');
            $pdo->prepare('UPDATE blog_posts SET category_id=NULL WHERE category_id=?')->execute([$id]);
            $st = $pdo->prepare('DELETE FROM blog_categories WHERE id=?');
            $st->execute([$id]);
            admin_redirect('/admin/blog.php', $st->rowCount() ? 'Kategori berhasil dihapus.' : 'Kategori tidak ditemukan.', $st->rowCount() ? 'success' : 'error');
        }

        /* ===== Artikel ===== */
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Artikel tidak valid.');
            $st = $pdo->prepare('SELECT cover_image FROM blog_posts WHERE id=? LIMIT 1');
            $st->execute([$id]);
            $old = $st->fetch();
            if ($old) delete_public_upload((string)$old['cover_image']);
            $st = $pdo->prepare('DELETE FROM blog_posts WHERE id=?');
            $st->execute([$id]);
            admin_redirect('/admin/blog.php', $st->rowCount() ? 'Artikel berhasil dihapus.' : 'Artikel tidak ditemukan.', $st->rowCount() ? 'success' : 'error');
        }

        if ($action === 'delete_selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            if (!$ids) throw new RuntimeException('Pilih minimal satu artikel yang akan dihapus.');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT cover_image FROM blog_posts WHERE id IN ($in)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $old) delete_public_upload((string)$old['cover_image']);
            $st = $pdo->prepare("DELETE FROM blog_posts WHERE id IN ($in)");
            $st->execute($ids);
            admin_redirect('/admin/blog.php', $st->rowCount().' artikel berhasil dihapus.');
        }

        if ($action === 'delete_all') {
            foreach ($pdo->query('SELECT cover_image FROM blog_posts')->fetchAll() as $old) delete_public_upload((string)$old['cover_image']);
            $count = (int)$pdo->query('SELECT COUNT(*) FROM blog_posts')->fetchColumn();
            $pdo->exec('DELETE FROM blog_posts');
            admin_redirect('/admin/blog.php', $count ? $count.' artikel berhasil dihapus.' : 'Belum ada artikel untuk dihapus.', $count ? 'success' : 'error');
        }

        $pa = trim((string)($_POST['published_at'] ?? ''));
        $paTs = $pa !== '' ? strtotime($pa) : false;
        $form = [
            'id' => (int)($_POST['id'] ?? 0),
            'title' => trim((string)($_POST['title'] ?? '')),
            'slug' => slugify((string)(($_POST['slug'] ?? '') ?: ($_POST['title'] ?? ''))),
            'excerpt' => trim((string)($_POST['excerpt'] ?? '')),
            'content' => (string)($_POST['content'] ?? ''),
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'tags' => trim((string)($_POST['tags'] ?? '')),
            'author' => trim((string)($_POST['author'] ?? '')) ?: 'Admin',
            'read_time' => (int)($_POST['read_time'] ?? 0),
            'cover_image' => trim((string)($_POST['current_cover'] ?? '')),
            'cover_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['cover_color'] ?? '')) ? strtolower((string)$_POST['cover_color']) : '#6366f1',
            'meta_title' => trim((string)($_POST['meta_title'] ?? '')),
            'meta_description' => trim((string)($_POST['meta_description'] ?? '')),
            'meta_keywords' => trim((string)($_POST['meta_keywords'] ?? '')),
            'published_at' => $paTs ? date('Y-m-d\TH:i', $paTs) : '',
            'status' => isset($_POST['status']) ? 1 : 0,
        ];
        if ($form['read_time'] < 1) $form['read_time'] = max(1, (int)ceil(str_word_count(strip_tags($form['content'])) / 200));

        if ($form['title'] === '') throw new RuntimeException('Judul artikel wajib diisi.');
        if ($form['slug'] === '') throw new RuntimeException('Slug artikel wajib diisi.');
        if (mb_strlen($form['title']) > 180) throw new RuntimeException('Judul artikel maksimal 180 karakter.');
        if (mb_strlen($form['slug']) > 190) throw new RuntimeException('Slug artikel maksimal 190 karakter.');
        if (mb_strlen($form['excerpt']) > 500) throw new RuntimeException('Excerpt maksimal 500 karakter.');
        if ($form['category_id'] > 0) {
            $ck = $pdo->prepare('SELECT id FROM blog_categories WHERE id=? LIMIT 1');
            $ck->execute([$form['category_id']]);
            if (!$ck->fetchColumn()) $form['category_id'] = 0;
        }

        $dup = $pdo->prepare('SELECT id FROM blog_posts WHERE slug=? AND id<>? LIMIT 1');
        $dup->execute([$form['slug'], $form['id']]);
        if ($dup->fetchColumn()) throw new RuntimeException('Slug sudah digunakan artikel lain.');

        $newCover = upload_blog_cover('cover_image');
        if (!empty($_POST['remove_cover'])) {
            delete_public_upload($form['cover_image']);
            $form['cover_image'] = '';
        } elseif ($newCover) {
            delete_public_upload($form['cover_image']);
            $form['cover_image'] = $newCover;
        }

        $catId = $form['category_id'] > 0 ? $form['category_id'] : null;
        $pubAt = $form['published_at'] !== '' ? date('Y-m-d H:i:s', strtotime($form['published_at'])) : null;

        if ($form['id'] > 0) {
            $st = $pdo->prepare('UPDATE blog_posts SET title=?,slug=?,excerpt=?,content=?,cover_image=?,cover_color=?,author=?,read_time=?,tags=?,category_id=?,meta_title=?,meta_description=?,meta_keywords=?,status=?,published_at=?,updated_at=NOW() WHERE id=?');
            $st->execute([$form['title'],$form['slug'],$form['excerpt'],$form['content'],$form['cover_image'],$form['cover_color'],$form['author'],$form['read_time'],$form['tags'],$catId,$form['meta_title'],$form['meta_description'],$form['meta_keywords'],$form['status'],$pubAt,$form['id']]);
            admin_redirect('/admin/blog.php?edit='.$form['id'], 'Artikel berhasil diperbarui.');
        }

        $st = $pdo->prepare('INSERT INTO blog_posts(title,slug,excerpt,content,cover_image,cover_color,author,read_time,tags,category_id,meta_title,meta_description,meta_keywords,status,published_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
        $st->execute([$form['title'],$form['slug'],$form['excerpt'],$form['content'],$form['cover_image'],$form['cover_color'],$form['author'],$form['read_time'],$form['tags'],$catId,$form['meta_title'],$form['meta_description'],$form['meta_keywords'],$form['status'],$pubAt]);
        admin_redirect('/admin/blog.php', 'Artikel baru berhasil dibuat.');
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $mapped = str_contains($message, 'duplicate') ? 'Slug sudah digunakan.' : $e->getMessage();
        if (str_starts_with($action, 'cat_')) { $catErr = $mapped; $catEdit = $catForm; }
        else $err = $mapped;
    }
}

if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM blog_posts WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) {
        $form = array_merge($form, $edit);
        $form['category_id'] = (int)($edit['category_id'] ?? 0);
        $form['published_at'] = !empty($edit['published_at']) ? date('Y-m-d\TH:i', strtotime((string)$edit['published_at'])) : '';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form['id'] > 0) $edit = $form;

if (isset($_GET['cat_edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM blog_categories WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['cat_edit']]);
    $catEdit = $st->fetch() ?: null;
    if ($catEdit) $catForm = array_merge($catForm, $catEdit);
}

$rows = $pdo->query('SELECT p.*, c.name AS category_name FROM blog_posts p LEFT JOIN blog_categories c ON c.id=p.category_id ORDER BY COALESCE(p.published_at,p.created_at) DESC, p.id DESC')->fetchAll();
$blogCats = $pdo->query('SELECT * FROM blog_categories ORDER BY sort_order ASC, name ASC')->fetchAll();
admin_page_start('Blog', 'Tulis dan kelola artikel blog: cover, kategori, tag, jadwal terbit, dan SEO.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form-card">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=e($form['id'])?>">
    <input type="hidden" name="current_cover" value="<?=e($form['cover_image'])?>">
    <div class="adm-card-head">
        <div><h3><?= $edit ? 'Edit Artikel' : 'Tulis Artikel' ?></h3><p>Artikel yang aktif tampil di halaman publik /blog sesuai tanggal terbit.</p></div>
        <?php if ($edit && $form['slug'] !== ''): ?><a class="adm-btn soft" target="_blank" rel="noopener" href="/blog/<?=e($form['slug'])?>"><?=admin_icon('external-link')?> Lihat artikel</a><?php endif; ?>
    </div>
    <div class="form-grid">
        <div class="field"><label>Judul Artikel</label><input name="title" required maxlength="180" placeholder="Contoh: Tips Memilih Akun Premium yang Tepat" value="<?=e($form['title'])?>"></div>
        <div class="field"><label>Slug / URL</label><input name="slug" maxlength="190" placeholder="tips-memilih-akun-premium" value="<?=e($form['slug'])?>"><small>URL publik: /blog/slug-artikel</small></div>
        <div class="field"><label>Kategori</label><select name="category_id">
            <option value="0">— Tanpa kategori —</option>
            <?php foreach ($blogCats as $c): ?><option value="<?=$c['id']?>" <?= (int)$form['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?=e($c['name'])?><?= empty($c['status']) ? ' (nonaktif)' : '' ?></option><?php endforeach; ?>
        </select><small>Kelola kategori di bagian bawah halaman.</small></div>
        <div class="field"><label>Penulis</label><input name="author" maxlength="100" placeholder="Admin" value="<?=e($form['author'])?>"></div>
        <div class="field"><label>Waktu Baca (menit)</label><input type="number" name="read_time" min="1" max="120" value="<?=e($form['read_time'])?>"><small>Kosongkan/0 untuk hitung otomatis dari isi artikel.</small></div>
        <div class="field"><label>Tanggal Terbit</label><input type="datetime-local" name="published_at" value="<?=e($form['published_at'])?>"><small>Kosongkan untuk memakai waktu simpan.</small></div>
        <div class="field"><label>Tag</label><input name="tags" maxlength="255" placeholder="update, tips, bot" value="<?=e($form['tags'])?>"><small>Dipisahkan koma.</small></div>
        <div class="field"><label>Warna Cover</label><input type="color" name="cover_color" value="<?=e($form['cover_color'])?>"><small>Warna latar di balik cover bila gambar kosong.</small></div>
        <div class="field"><label>Cover Artikel</label><input type="file" name="cover_image" accept="image/jpeg,image/png,image/webp"><small>JPG/PNG/WEBP, maksimal 2MB. Rasio 16:9 direkomendasikan.</small>
            <?php if (!empty($form['cover_image'])): ?><label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-weight:400"><input type="checkbox" name="remove_cover" value="1"> Hapus cover saat ini</label><small><?=e($form['cover_image'])?></small><?php endif; ?>
        </div>
    </div>
    <div class="field" style="margin-top:16px"><label>Excerpt</label><textarea name="excerpt" rows="2" maxlength="500" placeholder="Ringkasan singkat artikel untuk kartu blog dan hasil pencarian."><?=e($form['excerpt'])?></textarea></div>
    <div class="field" style="margin-top:16px"><label>Isi Artikel</label><textarea name="content" class="page-content-editor" rows="14" placeholder="Tulis isi artikel di sini. HTML sederhana diperbolehkan (heading, paragraf, list, bold, gambar, tabel)."><?=e($form['content'])?></textarea><small>Gunakan heading (h2/h3), paragraf, dan daftar secukupnya agar mudah dibaca.</small></div>
    <div class="form-grid" style="margin-top:16px">
        <div class="field"><label>Meta Title (SEO)</label><input name="meta_title" maxlength="180" placeholder="Opsional — default: judul artikel" value="<?=e($form['meta_title'])?>"></div>
        <div class="field"><label>Meta Description (SEO)</label><input name="meta_description" maxlength="300" placeholder="Opsional — default: excerpt" value="<?=e($form['meta_description'])?>"></div>
        <div class="field"><label>Meta Keywords (SEO)</label><input name="meta_keywords" maxlength="300" placeholder="Opsional — pisahkan koma" value="<?=e($form['meta_keywords'])?>"></div>
    </div>
    <div class="adm-checks"><label><input type="checkbox" name="status" <?=!empty($form['status'])?'checked':''?>> Aktif dan tampil di website</label></div>
    <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> <?= $edit ? 'Perbarui Artikel' : 'Simpan Artikel' ?></button><?php if($edit):?><a class="adm-btn soft" href="/admin/blog.php">Batal Edit</a><?php endif;?></div>
</form>

<form id="blogBulkForm" method="post" class="bulk-form" data-bulk-form>
    <?=csrf_field()?>
    <div class="bulk-toolbar">
        <label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="blogBulkForm"> Pilih semua</label>
        <div class="bulk-actions"><button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" data-confirm="Hapus artikel terpilih?">Hapus terpilih</button><button class="adm-btn danger" type="submit" name="action" value="delete_all" data-confirm="Hapus semua artikel?">Hapus semua</button></div>
    </div>
</form>

<div class="table-wrap responsive-list">
<table>
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Artikel</th><th>Kategori</th><th>Status</th><th>Terbit</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="admin-empty-state"><span><?=admin_icon('newspaper')?></span><b>Belum ada artikel</b><small>Tulis artikel pertama melalui formulir di atas.</small></div></td></tr>
    <?php else: foreach($rows as $r): ?>
        <tr>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><input form="blogBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$r['id']?>"></div></td>
            <td data-label="Artikel"><div class="cell-value"><b><?=e($r['title'])?></b><small><?=e(mb_strimwidth(trim(strip_tags((string)($r['excerpt'] ?: (string)$r['content']))),0,90,'…'))?></small><a class="table-secondary-link" href="/blog/<?=e($r['slug'])?>" target="_blank" rel="noopener">/blog/<?=e($r['slug'])?></a><small><?=e($r['author'])?> · <?=e((int)$r['read_time'])?> min baca<?= $r['tags'] !== '' && $r['tags'] !== null ? ' · '.e($r['tags']) : '' ?></small></div></td>
            <td data-label="Kategori"><div class="cell-value"><?= $r['category_name'] ? e($r['category_name']) : '—' ?></div></td>
            <td data-label="Status"><div class="cell-value"><?= $r['status'] ? admin_status_badge('aktif') : admin_status_badge('nonaktif') ?></div></td>
            <td data-label="Terbit"><div class="cell-value"><?= !empty($r['published_at']) ? e(date('d M Y, H:i', strtotime((string)$r['published_at']))) : 'Saat disimpan' ?></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions"><a href="?edit=<?=$r['id']?>"><?=admin_icon('edit')?> Edit</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="adm-danger adm-linklike" type="submit" data-confirm="Hapus artikel ini?"><?=admin_icon('trash')?></button></form></div></div></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<?php if ($catErr !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($catErr)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" class="form-card">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="cat_save">
    <input type="hidden" name="id" value="<?=e($catForm['id'])?>">
    <div class="adm-card-head">
        <div><h3><?= $catEdit ? 'Edit Kategori' : 'Kategori Blog' ?></h3><p>Kategori tampil sebagai chip filter di halaman blog.</p></div>
        <?php if ($catEdit): ?><a class="adm-btn soft" href="/admin/blog.php">Batal Edit</a><?php endif; ?>
    </div>
    <div class="form-grid">
        <div class="field"><label>Nama Kategori</label><input name="name" required maxlength="100" placeholder="Contoh: Tips & Panduan" value="<?=e($catForm['name'])?>"></div>
        <div class="field"><label>Slug / URL</label><input name="slug" maxlength="120" placeholder="tips-panduan" value="<?=e($catForm['slug'])?>"><small>Dipakai pada filter ?cat=slug.</small></div>
        <div class="field"><label>Deskripsi</label><input name="description" maxlength="255" placeholder="Opsional" value="<?=e($catForm['description'])?>"></div>
        <div class="field"><label>Urutan</label><input type="number" name="sort_order" value="<?=e($catForm['sort_order'])?>"><small>Angka lebih kecil tampil lebih awal.</small></div>
    </div>
    <div class="adm-checks"><label><input type="checkbox" name="status" <?=!empty($catForm['status'])?'checked':''?>> Aktif dan tampil di chip filter</label></div>
    <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> <?= $catEdit ? 'Perbarui Kategori' : 'Simpan Kategori' ?></button></div>
</form>

<div class="table-wrap responsive-list">
<table>
    <thead><tr><th>Kategori</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$blogCats): ?>
        <tr><td colspan="3"><div class="admin-empty-state"><span><?=admin_icon('grid')?></span><b>Belum ada kategori</b><small>Buat kategori pertama melalui formulir di atas.</small></div></td></tr>
    <?php else: foreach($blogCats as $c): ?>
        <tr>
            <td data-label="Kategori"><div class="cell-value"><b><?=e($c['name'])?></b><small><?=e($c['description'] !== null && $c['description'] !== '' ? $c['description'] : '/blog?cat='. $c['slug'])?></small><small>Urutan <?=e($c['sort_order'])?></small></div></td>
            <td data-label="Status"><div class="cell-value"><?= $c['status'] ? admin_status_badge('aktif') : admin_status_badge('nonaktif') ?></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions"><a href="?cat_edit=<?=$c['id']?>"><?=admin_icon('edit')?> Edit</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="cat_delete"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="adm-danger adm-linklike" type="submit" data-confirm="Hapus kategori ini? Artikel terkait akan menjadi tanpa kategori."><?=admin_icon('trash')?></button></form></div></div></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php admin_page_end(); ?>
