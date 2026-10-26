<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/_layout.php';
if(admin_user()){ header('Location: /admin/dashboard.php'); exit; }

$error='';
$usernameValue='';
if(empty($_SESSION['admin_login_csrf'])) $_SESSION['admin_login_csrf']=bin2hex(random_bytes(24));

if($_SERVER['REQUEST_METHOD']==='POST'){
    $usernameValue=trim((string)($_POST['username']??''));
    $password=(string)($_POST['password']??'');
    $token=(string)($_POST['_token']??'');
    $dummy='$2y$10$CwTycUXWue0Thq9StjUM0uJ8S7qrlY9wW2lMhX9eZ2QqQUeL7X1Ce';

    if(!hash_equals((string)$_SESSION['admin_login_csrf'], $token)){
        $error='Sesi login tidak valid. Muat ulang halaman lalu coba lagi.';
    } elseif($usernameValue==='' || $password==='') {
        $error='Username dan password wajib diisi.';
    } else {
        try {
            ensure_login_attempts_table();
            $ip=$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $attempt=$pdo->prepare('SELECT attempts,locked_until FROM login_attempts WHERE ip=? AND username=? LIMIT 1');
            $attempt->execute([$ip,$usernameValue]);
            $attemptRow=$attempt->fetch();
            if($attemptRow && !empty($attemptRow['locked_until']) && strtotime((string)$attemptRow['locked_until'])<=time()){
                clear_login_fail($usernameValue);
            }
        } catch(Throwable $e) {}

        if(login_attempt_blocked($usernameValue)){
            $error='Terlalu banyak percobaan login. Silakan coba lagi dalam 5 menit.';
        } else {
            $st=$pdo->prepare('SELECT * FROM admins WHERE username=? LIMIT 1');
            $st->execute([$usernameValue]);
            $admin=$st->fetch();
            $hash=(string)($admin['password_hash'] ?? $dummy);
            $valid=password_verify($password,$hash);
            if($admin && $valid){
                clear_login_fail($usernameValue);
                session_regenerate_id(true);
                $_SESSION['admin']=[
                    'id'=>$admin['id'],
                    'username'=>$admin['username'],
                    'name'=>$admin['name'] ?? $admin['username'],
                    'role'=>$admin['role'] ?? 'Administrator'
                ];
                $_SESSION['admin_login_csrf']=bin2hex(random_bytes(24));
                try { $pdo->prepare('UPDATE admins SET last_login=NOW() WHERE id=?')->execute([$admin['id']]); } catch(Throwable $e) {}
                header('Location: /admin/dashboard.php'); exit;
            }
            record_login_fail($usernameValue);
            $error='Username atau password salah. Setelah 3 kali gagal, login dikunci selama 5 menit.';
        }
    }
}

$brandName=trim((string)setting('site_name','Admin Store')) ?: 'Admin Store';
$favicon=trim((string)setting('site_favicon',''));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <title>Login Admin - <?=e($brandName)?></title>
    <?php if($favicon!==''):?><link rel="icon" href="<?=e($favicon)?>"><?php endif;?>
    <link rel="stylesheet" href="/admin/admin-modern.css?v=20260723-4">
</head>
<body class="adm-login-body">
<main class="adm-login-page">
    <section class="adm-login-shell">
        <aside class="adm-login-visual">
            <div class="adm-login-brand">
                <div class="adm-brand-icon"><?=admin_icon('box')?></div>
                <div><h1><?=e($brandName)?></h1><p>Digital Store Admin</p></div>
            </div>
            <div class="adm-login-copy">
                <span>Panel administrasi</span>
                <h2>Kelola toko digital dengan lebih mudah.</h2>
                <p>Pantau pesanan, stok digital, produk, promosi, dan pengaturan website dari satu dashboard yang aman.</p>
            </div>
            <div class="adm-login-features">
                <div><i>✓</i><span>Pengelolaan stok dan pesanan terpusat</span></div>
                <div><i>✓</i><span>Notifikasi transaksi secara berkala</span></div>
                <div><i>✓</i><span>Login dilindungi pembatasan percobaan</span></div>
            </div>
        </aside>
        <div class="adm-login-form-wrap">
            <form method="post" class="adm-login-card" autocomplete="on">
                <span>Selamat datang</span>
                <h2>Masuk ke Admin</h2>
                <p>Gunakan akun administrator untuk melanjutkan.</p>
                <?php if($error):?><div class="alert err" role="alert"><?=e($error)?></div><?php endif;?>
                <input type="hidden" name="_token" value="<?=e($_SESSION['admin_login_csrf'])?>">
                <div class="field">
                    <label for="username">Username</label>
                    <input id="username" name="username" required autocomplete="username" inputmode="text" value="<?=e($usernameValue)?>" autofocus>
                </div>
                <div class="field">
                    <label for="pass">Password</label>
                    <div class="adm-pass-row">
                        <input id="pass" name="password" type="password" required autocomplete="current-password">
                        <button type="button" id="togglePass" aria-label="Tampilkan password"><?=admin_icon('eye')?></button>
                    </div>
                </div>
                <button class="adm-btn primary" type="submit">Masuk ke Dashboard</button>
                <div class="adm-login-note">Login akan dikunci selama 5 menit setelah 3 kali percobaan gagal.</div>
            </form>
        </div>
    </section>
</main>
<script>
(function(){
  try{document.documentElement.dataset.theme=localStorage.getItem('admTheme')||'light';}catch(e){}
  const pass=document.getElementById('pass'),button=document.getElementById('togglePass');
  button?.addEventListener('click',()=>{
    const reveal=pass.type==='password';
    pass.type=reveal?'text':'password';
    button.setAttribute('aria-label',reveal?'Sembunyikan password':'Tampilkan password');
    button.style.opacity=reveal?'1':'.78';
  });
})();
</script>
</body>
</html>
