<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_category_icon_column();

$msg='';
$err='';
$edit=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=(string)($_POST['action'] ?? 'save');
    try{
        if($action==='delete' || $action==='delete_selected' || $action==='delete_all'){
            if($action==='delete'){
                $ids=[(int)($_POST['id'] ?? 0)];
            }elseif($action==='delete_selected'){
                $ids=array_values(array_filter(array_map('intval',$_POST['ids'] ?? [])));
                if(!$ids) throw new RuntimeException('Pilih minimal satu kategori terlebih dahulu.');
            }else{
                $ids=array_map('intval',array_column($pdo->query('SELECT id FROM categories')->fetchAll(),'id'));
            }
            $ids=array_values(array_filter(array_unique($ids)));
            if(!$ids) admin_redirect('/admin/categories.php','Tidak ada kategori yang perlu dihapus.');
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE products SET category_id=NULL WHERE category_id IN ($ph)")->execute($ids);
            $st=$pdo->prepare("DELETE FROM categories WHERE id IN ($ph)");
            $st->execute($ids);
            $deleted=$st->rowCount();
            $pdo->commit();
            admin_redirect('/admin/categories.php',$deleted.' kategori berhasil dihapus. Produk terkait dipindahkan ke tanpa kategori.');
        }

        $id=(int)($_POST['id'] ?? 0);
        $name=trim((string)($_POST['name'] ?? ''));
        $slug=slugify((string)(($_POST['slug'] ?? '') ?: $name));
        $icon=trim((string)($_POST['icon'] ?? ''));
        $icon=preg_replace('/[\x00-\x1F\x7F]/u','',$icon) ?? '';
        $sort=(int)($_POST['sort_order'] ?? 0);
        $status=isset($_POST['status'])?1:0;
        if($name==='') throw new InvalidArgumentException('Nama kategori wajib diisi.');
        if($slug==='') throw new InvalidArgumentException('Slug kategori tidak valid.');
        if(mb_strlen($icon)>24) throw new InvalidArgumentException('Simbol / icon maksimal 24 karakter.');
        if($id){
            $st=$pdo->prepare('UPDATE categories SET name=?,slug=?,icon=?,sort_order=?,status=? WHERE id=?');
            $st->execute([$name,$slug,$icon,$sort,$status,$id]);
        }else{
            $st=$pdo->prepare('INSERT INTO categories(name,slug,icon,sort_order,status) VALUES(?,?,?,?,?)');
            $st->execute([$name,$slug,$icon,$sort,$status]);
        }
        admin_redirect('/admin/categories.php','Kategori berhasil disimpan.');
    }catch(Throwable $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        $message=$e->getMessage();
        $err=str_contains(strtolower($message),'duplicate')?'Slug kategori sudah digunakan.':$message;
        if(!in_array($action,['delete','delete_selected','delete_all'],true)) $edit=$_POST;
    }
}

if(!$edit && isset($_GET['edit'])){
    $st=$pdo->prepare('SELECT * FROM categories WHERE id=?');
    $st->execute([(int)$_GET['edit']]);
    $edit=$st->fetch() ?: null;
    if(!$edit) $err='Kategori yang ingin diedit tidak ditemukan.';
}
$rows=$pdo->query('SELECT c.*,(SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) product_count FROM categories c ORDER BY c.sort_order,c.name')->fetchAll();

admin_page_start('Kategori','Kelompokkan produk agar pelanggan lebih mudah menemukan produk yang dibutuhkan.');
?>
<?php if($msg):?><div class="alert ok"><span>✓</span><div><?=e($msg)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>
<?php if($err):?><div class="alert err"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert>×</button></div><?php endif;?>

<form method="post" class="form-card">
    <?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id'] ?? '')?>">
    <div class="adm-card-head">
        <div><h3><?=$edit?'Edit Kategori':'Tambah Kategori'?></h3><p>Atur nama, alamat URL, simbol / icon, urutan, dan status kategori.</p></div>
    </div>
    <div class="form-grid">
        <div class="field"><label>Nama Kategori</label><input name="name" required value="<?=e($edit['name'] ?? '')?>" placeholder="Contoh: Akun Premium" data-category-name-input></div>
        <div class="field"><label>Slug / URL</label><input name="slug" value="<?=e($edit['slug'] ?? '')?>" placeholder="akun-premium"><small>Kosongkan untuk membuat slug otomatis dari nama.</small></div>
        <div class="field category-icon-field"><label>Simbol / Icon</label><div class="category-icon-control"><span class="category-symbol-preview" data-category-icon-preview aria-hidden="true"><?=e(category_icon_symbol((string)($edit['name'] ?? ''),(string)($edit['icon'] ?? '')))?></span><input name="icon" maxlength="24" value="<?=e($edit['icon'] ?? '')?>" placeholder="Contoh: 🎮 atau ☁" aria-label="Simbol atau icon kategori" data-category-icon-input></div><small>Isi dengan satu emoji atau simbol singkat. Kosongkan untuk menggunakan icon otomatis berdasarkan nama kategori.</small></div>
        <div class="field"><label>Urutan</label><input class="no-wheel-number" type="number" name="sort_order" value="<?=e($edit['sort_order'] ?? 0)?>"></div>
    </div>
    <div class="adm-checks"><label><input type="checkbox" name="status" <?=($edit['status'] ?? 1)?'checked':''?>> Aktif dan tampil di website</label></div>
    <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?>Simpan Kategori</button><?php if($edit):?><a class="adm-btn soft" href="/admin/categories.php">Batal Edit</a><?php endif;?></div>
</form>

<form id="categoriesBulkForm" method="post" class="bulk-form">
    <?=csrf_field()?>
    <div class="bulk-toolbar">
        <label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="categoriesBulkForm"> Pilih semua</label>
        <div class="bulk-actions">
            <button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" onclick="return confirm('Hapus kategori terpilih? Produk terkait akan menjadi tanpa kategori.')">Hapus Terpilih</button>
            <button class="adm-btn danger" type="submit" name="action" value="delete_all" onclick="return confirm('Hapus semua kategori? Semua produk akan menjadi tanpa kategori.')">Hapus Semua</button>
        </div>
    </div>
</form>

<div class="table-wrap responsive-list categories-table-wrap">
<table class="categories-table">
    <colgroup><col class="category-col-check"><col class="category-col-name"><col class="category-col-icon"><col class="category-col-products"><col class="category-col-status"><col class="category-col-actions"></colgroup>
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Kategori</th><th>Simbol / Icon</th><th>Produk</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if($rows):foreach($rows as $row):?>
        <tr>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><input form="categoriesBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$row['id']?>"></div></td>
            <td data-label="Kategori"><div class="cell-value"><b><?=e($row['name'])?></b><small><?=e($row['slug'])?> · urutan <?=e($row['sort_order'])?></small></div></td>
            <td data-label="Icon"><div class="cell-value"><span class="category-symbol-table" title="<?=trim((string)($row['icon'] ?? ''))!==''?'Icon khusus':'Icon otomatis dari nama kategori'?>"><?=e(category_icon_symbol((string)$row['name'],(string)($row['icon'] ?? '')))?></span></div></td>
            <td data-label="Produk"><div class="cell-value"><b><?=e($row['product_count'])?></b></div></td>
            <td data-label="Status"><div class="cell-value"><?=$row['status']?admin_status_badge('aktif'):admin_status_badge('nonaktif')?></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions"><a href="?edit=<?=$row['id']?>">Edit</a><form method="post" onsubmit="return confirm('Hapus kategori ini? Produk terkait akan menjadi tanpa kategori.')"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$row['id']?>"><button class="adm-danger adm-linklike" type="submit">Hapus</button></form></div></div></td>
        </tr>
    <?php endforeach;else:?>
        <tr><td colspan="6"><div class="adm-empty"><div><div class="adm-empty-icon"><?=admin_icon('grid')?></div><b>Belum ada kategori</b><p>Tambahkan kategori pertama untuk mengelompokkan produk.</p></div></div></td></tr>
    <?php endif;?>
    </tbody>
</table>
</div>
<script>
(function(){
    const iconInput=document.querySelector('[data-category-icon-input]');
    const nameInput=document.querySelector('[data-category-name-input]');
    const preview=document.querySelector('[data-category-icon-preview]');
    if(!iconInput||!preview) return;

    function automaticIcon(name){
        name=(name||'').toLocaleLowerCase('id-ID');
        if(name.includes('akun')||name.includes('premium')) return '♛';
        if(name.includes('book')||name.includes('ebook')) return '▭';
        if(name.includes('template')) return '▤';
        if(name.includes('tool')) return '⌘';
        if(name.includes('voucher')) return '⌁';
        return '▦';
    }
    function updatePreview(){
        preview.textContent=iconInput.value.trim()||automaticIcon(nameInput?nameInput.value:'');
    }
    iconInput.addEventListener('input',updatePreview);
    if(nameInput) nameInput.addEventListener('input',updatePreview);
    updatePreview();
})();
</script>
<?php admin_page_end(); ?>
