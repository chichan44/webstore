<?php
require_once __DIR__.'/../includes/auth.php';
require_admin();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    admin_notifications_mark_read();
}
$data = admin_notification_summary();
foreach ($data['items'] as &$item) {
    $item['total_formatted'] = rupiah($item['total'] ?? 0);
    $item['url'] = '/admin/orders.php?detail='.rawurlencode((string)$item['invoice']);
}
json_out(['ok'=>true,'unread'=>(int)($data['unread'] ?? 0),'items'=>$data['items']]);
?>
