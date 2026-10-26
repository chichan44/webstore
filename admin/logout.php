<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    http_response_code(405);
    header('Allow: POST');
    exit('Logout harus menggunakan POST. Silakan kembali ke dashboard lalu klik Logout.');
}
csrf_check();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: /admin/login.php');
exit;
?>
