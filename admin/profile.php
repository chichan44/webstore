<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
require_once __DIR__.'/_layout.php';
ensure_order_security_columns();
cleanup_legacy_order_admin_pin_setting();

$me = admin_user();
$adminId = (int)($me['id'] ?? 0);
$hasName = db_column_exists('admins','name');
$hasLastLogin = db_column_exists('admins','last_login');
$err = '';

function load_admin_profile(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM admins WHERE id=? LIMIT 1');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$admin = load_admin_profile($adminId);
if (!$admin) {
    unset($_SESSION['admin']);
    header('Location: /admin/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? 'profile');

    try {
        if ($action === 'profile') {
            $username = trim((string)($_POST['username'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
                throw new RuntimeException('Username harus 3-60 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if ($hasName && ($name === '' || mb_strlen($name) > 100)) throw new RuntimeException('Nama admin wajib diisi dan maksimal 100 karakter.');
            $dup = $pdo->prepare('SELECT id FROM admins WHERE username=? AND id<>? LIMIT 1');
            $dup->execute([$username,$adminId]);
            if ($dup->fetchColumn()) throw new RuntimeException('Username sudah digunakan admin lain.');

            if ($hasName) {
                $pdo->prepare('UPDATE admins SET username=?,name=? WHERE id=?')->execute([$username,$name,$adminId]);
                $_SESSION['admin']['name'] = $name;
            } else {
                $pdo->prepare('UPDATE admins SET username=? WHERE id=?')->execute([$username,$adminId]);
            }
            $_SESSION['admin']['username'] = $username;
            admin_redirect('/admin/profile.php', 'Informasi profil berhasil diperbarui.');
        }

        if ($action === 'password') {
            $current = (string)($_POST['current_password'] ?? '');
            $new = (string)($_POST['new_password'] ?? '');
            $confirm = (string)($_POST['confirm_password'] ?? '');
            if ($current === '' || $new === '' || $confirm === '') throw new RuntimeException('Semua kolom password wajib diisi.');
            if (!password_verify($current, (string)$admin['password_hash'])) throw new RuntimeException('Password lama tidak sesuai.');
            if (strlen($new) < 8) throw new RuntimeException('Password baru minimal 8 karakter.');
            if ($new !== $confirm) throw new RuntimeException('Konfirmasi password baru tidak sama.');
            if (password_verify($new, (string)$admin['password_hash'])) throw new RuntimeException('Password baru tidak boleh sama dengan password lama.');
            $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT),$adminId]);
            admin_redirect('/admin/profile.php', 'Password admin berhasil diperbarui.');
        }

        throw new RuntimeException('Aksi profil tidak dikenali.');
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$admin = load_admin_profile($adminId) ?: $admin;
$displayName = $hasName ? trim((string)($admin['name'] ?? '')) : '';
if ($displayName === '') $displayName = (string)$admin['username'];
$lastLogin = $hasLastLogin && !empty($admin['last_login']) ? date('d M Y, H:i', strtotime((string)$admin['last_login'])) : 'Tidak tersedia';
admin_page_start('Profil Admin', 'Kelola informasi akun dan password login admin.');
?>
<?php if ($err !== ''): ?><div class="alert err" role="alert"><span>!</span><div><?=e($err)?></div><button type="button" data-dismiss-alert aria-label="Tutup">×</button></div><?php endif; ?>

<section class="adm-card admin-profile-card">
    <div class="admin-profile-summary">
        <div class="adm-avatar big"><?=e(strtoupper(mb_substr($displayName,0,1)))?></div>
        <div><b><?=e($displayName)?></b><small>@<?=e($admin['username'])?> · <?=e($admin['role'] ?? 'Administrator')?></small></div>
        <div class="admin-profile-meta"><span>Login terakhir</span><b><?=e($lastLogin)?></b></div>
    </div>
</section>

<form method="post" class="form-card" autocomplete="off">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="profile">
    <div class="adm-card-head"><div><h3>Informasi Akun</h3><p>Data ini digunakan pada profil dan navigasi panel admin.</p></div></div>
    <div class="form-grid">
        <?php if ($hasName): ?><div class="field"><label>Nama Admin</label><input name="name" required maxlength="100" value="<?=e($_POST['name'] ?? ($admin['name'] ?? ''))?>" autocomplete="name"></div><?php endif; ?>
        <div class="field"><label>Username</label><input name="username" required minlength="3" maxlength="60" pattern="[A-Za-z0-9._-]+" value="<?=e($_POST['username'] ?? $admin['username'])?>" autocomplete="username"><small>Huruf, angka, titik, garis bawah, atau tanda hubung.</small></div>
        <div class="field"><label>Role</label><input value="<?=e($admin['role'] ?? 'Administrator')?>" readonly></div>
    </div>
    <div class="adm-actions" style="margin-top:18px"><button class="adm-btn primary" type="submit"><?=admin_icon('save')?> Simpan Profil</button></div>
</form>

<form method="post" class="form-card admin-password-form" autocomplete="off">
    <?=csrf_field()?>
    <input type="hidden" name="action" value="password">
    <div class="adm-card-head"><div><h3>Ubah Password Login</h3><p>Gunakan password unik dengan minimal 8 karakter.</p></div></div>
    <div class="field"><label>Password Lama</label><div class="secret-field"><input id="currentPassword" type="password" name="current_password" required autocomplete="current-password"><button type="button" data-toggle-secret="#currentPassword" aria-label="Tampilkan password"><?=admin_icon('eye')?></button></div></div>
    <div class="form-grid" style="margin-top:14px">
        <div class="field"><label>Password Baru</label><div class="secret-field"><input id="newPassword" type="password" name="new_password" required minlength="8" autocomplete="new-password"><button type="button" data-toggle-secret="#newPassword" aria-label="Tampilkan password"><?=admin_icon('eye')?></button></div></div>
        <div class="field"><label>Konfirmasi Password</label><div class="secret-field"><input id="confirmPassword" type="password" name="confirm_password" required minlength="8" autocomplete="new-password"><button type="button" data-toggle-secret="#confirmPassword" aria-label="Tampilkan password"><?=admin_icon('eye')?></button></div></div>
    </div>
    <div class="adm-actions" style="margin-top:18px"><button class="adm-btn primary" type="submit"><?=admin_icon('lock')?> Simpan Password</button></div>
</form>
<?php admin_page_end(); ?>
