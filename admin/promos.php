<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';

$err = '';
$edit = null;
$form = [
    'id' => 0,
    'code' => '',
    'type' => 'fixed',
    'value' => 0,
    'min_purchase' => 0,
    'max_discount' => 0,
    'usage_limit' => 0,
    'starts_at' => '',
    'ends_at' => '',
    'status' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'save');

    try {
        if ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) throw new RuntimeException('Promo tidak valid.');
            $st = $pdo->prepare('DELETE FROM promos WHERE id=?');
            $st->execute([$id]);
            admin_redirect('/admin/promos.php', $st->rowCount() ? 'Promo berhasil dihapus.' : 'Promo tidak ditemukan.', $st->rowCount() ? 'success' : 'error');
        }

        if ($action === 'delete_selected') {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
            if (!$ids) throw new RuntimeException('Pilih minimal satu promo yang akan dihapus.');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("DELETE FROM promos WHERE id IN ($in)");
            $st->execute($ids);
            admin_redirect('/admin/promos.php', $st->rowCount().' promo berhasil dihapus.');
        }

        if ($action === 'delete_all') {
            $count = (int)$pdo->query('SELECT COUNT(*) FROM promos')->fetchColumn();
            $pdo->exec('DELETE FROM promos');
            admin_redirect('/admin/promos.php', $count ? $count.' promo berhasil dihapus.' : 'Belum ada promo untuk dihapus.', $count ? 'success' : 'error');
        }

        $type = in_array((string)($_POST['type'] ?? ''), ['percent','fixed'], true) ? (string)$_POST['type'] : 'fixed';
        $form = [
            'id' => (int)($_POST['id'] ?? 0),
            'code' => strtoupper(trim((string)($_POST['code'] ?? ''))),
            'type' => $type,
            'value' => max(0, (float)parse_local_number($_POST['value'] ?? 0)),
            'min_purchase' => max(0, (float)parse_local_number($_POST['min_purchase'] ?? 0)),
            'max_discount' => max(0, (float)parse_local_number($_POST['max_discount'] ?? 0)),
            'usage_limit' => max(0, (int)parse_local_number($_POST['usage_limit'] ?? 0)),
            'starts_at' => trim((string)($_POST['starts_at'] ?? '')),
            'ends_at' => trim((string)($_POST['ends_at'] ?? '')),
            'status' => isset($_POST['status']) ? 1 : 0,
        ];

        if ($form['code'] === '') throw new RuntimeException('Kode promo wajib diisi.');
        if (!preg_match('/^[A-Z0-9_-]{2,60}$/', $form['code'])) throw new RuntimeException('Kode promo hanya boleh berisi huruf, angka, tanda hubung, atau garis bawah.');
        if ($form['value'] <= 0) throw new RuntimeException('Nilai diskon harus lebih dari 0.');
        if ($form['type'] === 'percent' && $form['value'] > 100) throw new RuntimeException('Diskon persen tidak boleh lebih dari 100%.');

        $startsAt = null;
        $endsAt = null;
        if ($form['starts_at'] !== '') {
            $time = strtotime($form['starts_at']);
            if ($time === false) throw new RuntimeException('Tanggal mulai promo tidak valid.');
            $startsAt = date('Y-m-d H:i:s', $time);
        }
        if ($form['ends_at'] !== '') {
            $time = strtotime($form['ends_at']);
            if ($time === false) throw new RuntimeException('Tanggal berakhir promo tidak valid.');
            $endsAt = date('Y-m-d H:i:s', $time);
        }
        if ($startsAt && $endsAt && strtotime($endsAt) <= strtotime($startsAt)) throw new RuntimeException('Tanggal berakhir harus setelah tanggal mulai.');

        $dup = $pdo->prepare('SELECT id FROM promos WHERE code=? AND id<>? LIMIT 1');
        $dup->execute([$form['code'], $form['id']]);
        if ($dup->fetchColumn()) throw new RuntimeException('Kode promo sudah digunakan.');

        $data = [$form['code'],$form['type'],$form['value'],$form['min_purchase'],$form['max_discount'],$form['usage_limit'],$startsAt,$endsAt,$form['status']];
        if ($form['id'] > 0) {
            $data[] = $form['id'];
            $pdo->prepare('UPDATE promos SET code=?,type=?,value=?,min_purchase=?,max_discount=?,usage_limit=?,starts_at=?,ends_at=?,status=? WHERE id=?')->execute($data);
            admin_redirect('/admin/promos.php?edit='.$form['id'], 'Promo berhasil diperbarui.');
        }

        $pdo->prepare('INSERT INTO promos(code,type,value,min_purchase,max_discount,usage_limit,starts_at,ends_at,status) VALUES(?,?,?,?,?,?,?,?,?)')->execute($data);
        admin_redirect('/admin/promos.php', 'Promo baru berhasil dibuat.');
    } catch (Throwable $e) {
        $message = strtolower($e->getMessage());
        $err = str_contains($message, 'duplicate') ? 'Kode promo sudah digunakan.' : $e->getMessage();
    }
}

if (isset($_GET['edit']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $st = $pdo->prepare('SELECT * FROM promos WHERE id=? LIMIT 1');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) $form = array_merge($form, $edit);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $form['id'] > 0) $edit = $form;

$rows = $pdo->query('SELECT * FROM promos ORDER BY id DESC')->fetchAll();
admin_page_start('Promo', 'Kelola kode promo, nilai diskon, batas penggunaan, dan periode aktif.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<form method="post" class="form-card">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?=e($form['id'])?>">
    <div class="adm-card-head"><div><h3><?= $edit ? 'Edit Promo' : 'Tambah Promo' ?></h3><p>Atur kode, jenis diskon, syarat transaksi, dan masa berlaku promo.</p></div></div>
    <div class="form-grid">
        <div class="field"><label>Kode Promo</label><input name="code" value="<?=e($form['code'])?>" required maxlength="60" placeholder="HEMAT20" autocapitalize="characters"><small>Huruf, angka, tanda hubung, dan garis bawah.</small></div>
        <div class="field"><label>Jenis Diskon</label><select name="type"><option value="percent" <?=$form['type']==='percent'?'selected':''?>>Persentase</option><option value="fixed" <?=$form['type']==='fixed'?'selected':''?>>Nominal rupiah</option></select></div>
        <div class="field"><label>Nilai Diskon</label><input type="text" inputmode="decimal" name="value" value="<?=e(plain_number($form['value']))?>" placeholder="10 atau 10000" required></div>
        <div class="field"><label>Minimum Pembelian</label><input type="text" inputmode="decimal" name="min_purchase" value="<?=e(plain_number($form['min_purchase']))?>" placeholder="0 = tanpa minimum"></div>
        <div class="field"><label>Maksimum Diskon</label><input type="text" inputmode="decimal" name="max_discount" value="<?=e(plain_number($form['max_discount']))?>" placeholder="0 = tanpa batas"><small>Umumnya dipakai untuk promo persentase.</small></div>
        <div class="field"><label>Batas Penggunaan</label><input type="text" inputmode="numeric" name="usage_limit" value="<?=e(plain_number($form['usage_limit']))?>" placeholder="0 = tidak terbatas"></div>
        <div class="field"><label>Mulai Berlaku</label><input type="datetime-local" name="starts_at" value="<?=!empty($form['starts_at'])?e(date('Y-m-d\TH:i',strtotime((string)$form['starts_at']))):''?>"></div>
        <div class="field"><label>Berakhir</label><input type="datetime-local" name="ends_at" value="<?=!empty($form['ends_at'])?e(date('Y-m-d\TH:i',strtotime((string)$form['ends_at']))):''?>"></div>
    </div>
    <div class="adm-checks"><label><input type="checkbox" name="status" <?=!empty($form['status'])?'checked':''?>> Promo aktif dan dapat digunakan</label></div>
    <p class="adm-help">Gunakan angka polos, misalnya <b>10000</b> untuk Rp 10.000 atau <b>10</b> untuk 10%. Format lokal tetap akan dinormalisasi saat disimpan.</p>
    <div class="adm-actions" style="margin-top:16px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> <?= $edit ? 'Perbarui Promo' : 'Simpan Promo' ?></button><?php if($edit):?><a class="adm-btn soft" href="/admin/promos.php">Batal Edit</a><?php endif;?></div>
</form>

<form id="promosBulkForm" method="post" class="bulk-form">
    <?=csrf_field()?>
    <div class="bulk-toolbar"><label class="bulk-check"><input type="checkbox" data-bulk-check-all data-bulk-target="promosBulkForm"> Pilih semua</label><div class="bulk-actions"><button class="adm-btn soft danger" type="submit" name="action" value="delete_selected" data-confirm="Hapus promo terpilih?">Hapus terpilih</button><button class="adm-btn danger" type="submit" name="action" value="delete_all" data-confirm="Hapus semua promo?">Hapus semua</button></div></div>
</form>

<div class="table-wrap responsive-list">
<table>
    <thead><tr><th class="check-col"><span class="sr-only">Pilih</span></th><th>Promo</th><th>Penawaran</th><th>Penggunaan</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="6"><div class="admin-empty-state"><span><?=admin_icon('tag')?></span><b>Belum ada promo</b><small>Buat kode promo pertama melalui formulir di atas.</small></div></td></tr>
    <?php else: foreach($rows as $r):
        $valueLabel = $r['type'] === 'percent' ? plain_number($r['value']).'%' : rupiah($r['value']);
        $usageLabel = (int)$r['usage_limit'] > 0 ? (int)$r['used_count'].' / '.(int)$r['usage_limit'] : (int)$r['used_count'].' / ∞';
        $period = 'Tanpa batas waktu';
        if (!empty($r['starts_at']) || !empty($r['ends_at'])) {
            $period = (!empty($r['starts_at']) ? date('d M Y H:i',strtotime($r['starts_at'])) : 'Sekarang').' — '.(!empty($r['ends_at']) ? date('d M Y H:i',strtotime($r['ends_at'])) : 'Seterusnya');
        }
    ?>
        <tr>
            <td class="check-col" data-label="Pilih"><div class="cell-value"><input form="promosBulkForm" class="bulk-row-check" type="checkbox" name="ids[]" value="<?=$r['id']?>"></div></td>
            <td data-label="Promo"><div class="cell-value"><b><?=e($r['code'])?></b><small><?=e($r['type']==='percent'?'Persentase':'Nominal rupiah')?></small></div></td>
            <td data-label="Penawaran"><div class="cell-value"><b><?=e($valueLabel)?></b><small>Min. <?=rupiah($r['min_purchase'])?> · Maks. <?=((float)$r['max_discount']>0)?rupiah($r['max_discount']):'tanpa batas'?></small></div></td>
            <td data-label="Penggunaan"><div class="cell-value"><b><?=e($usageLabel)?></b><small><?=e($period)?></small></div></td>
            <td data-label="Status"><div class="cell-value"><?= $r['status'] ? admin_status_badge('aktif') : admin_status_badge('nonaktif') ?></div></td>
            <td data-label="Aksi"><div class="cell-value"><div class="table-actions"><a href="?edit=<?=$r['id']?>"><?=admin_icon('edit')?> Edit</a><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="adm-danger adm-linklike" type="submit" data-confirm="Hapus promo ini?"><?=admin_icon('trash')?> Hapus</button></form></div></div></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
<?php admin_page_end(); ?>
