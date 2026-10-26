<?php
require_once __DIR__.'/helpers.php';
if(session_status()!==PHP_SESSION_ACTIVE){
    ini_set('session.use_strict_mode','1');
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off')]);
    session_start();
}
function admin_user(){ return $_SESSION['admin'] ?? null; }

function ensure_login_attempts_table(): void { global $pdo; try { $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (id INT AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(80) NOT NULL, username VARCHAR(120) NOT NULL, attempts INT DEFAULT 0, locked_until DATETIME NULL, UNIQUE KEY uniq_attempt (ip,username)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Throwable $e) {} }
function require_admin(){ if(!admin_user()){ header('Location: /admin/login.php'); exit; } }
function login_attempt_blocked($username){ ensure_login_attempts_table(); global $pdo; $ip=$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; $st=$pdo->prepare('SELECT * FROM login_attempts WHERE ip=? AND username=?'); $st->execute([$ip,$username]); $r=$st->fetch(); return $r && !empty($r['locked_until']) && strtotime($r['locked_until'])>time(); }
function record_login_fail($username){ ensure_login_attempts_table(); global $pdo; $ip=$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; $st=$pdo->prepare('SELECT * FROM login_attempts WHERE ip=? AND username=?'); $st->execute([$ip,$username]); $r=$st->fetch(); $attempts=($r['attempts']??0)+1; $locked=$attempts>=3 ? date('Y-m-d H:i:s', time()+300) : null; $pdo->prepare('INSERT INTO login_attempts(ip,username,attempts,locked_until) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE attempts=?, locked_until=?')->execute([$ip,$username,$attempts,$locked,$attempts,$locked]); }
function clear_login_fail($username){ ensure_login_attempts_table(); global $pdo; $ip=$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; $pdo->prepare('DELETE FROM login_attempts WHERE ip=? AND username=?')->execute([$ip,$username]); }
?>
