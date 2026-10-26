<?php
if (!defined('DEFER_EXTERNAL_DELIVERY')) define('DEFER_EXTERNAL_DELIVERY', true);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/casaku.php';
require_once __DIR__.'/../includes/telegram_bot.php';
ensure_order_security_columns();
ensure_product_extra_columns();
telegram_ensure_schema();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_out(['ok'=>false,'message'=>'Method not allowed'],405);
}

$raw = (string)file_get_contents('php://input');
$signature = casaku_request_signature_header();
$payload = [];
$eventKey = '';
$invoice = '';
$transactionId = '';
$amount = null;
$status = '';

try {
    if ($raw === '') throw new RuntimeException('Payload webhook kosong.');
    if (!casaku_callback_ip_allowed()) {
        log_payment_audit(null,null,null,null,false,'Callback Casaku ditolak: IP tidak diizinkan',[],'casaku');
        json_out(['ok'=>false,'message'=>'IP callback tidak diizinkan'],403);
    }

    // HMAC-SHA256 wajib dan dihitung terhadap RAW body sesuai dokumentasi Casaku.
    $payload = verify_casaku_webhook_raw($raw, $signature);
    $transactionId = trim((string)($payload['transactionId'] ?? ''));
    $status = strtolower(trim((string)($payload['status'] ?? '')));
    $amount = $payload['amount'] ?? null;
    if ($transactionId === '') throw new RuntimeException('transactionId Casaku kosong.');
    if ($status !== 'paid') {
        log_payment_audit(null,$transactionId,$status,$amount,true,'Webhook Casaku valid tetapi bukan status paid',$payload,'casaku');
        json_out(['ok'=>true,'ignored'=>true]);
    }
    if (!is_numeric($amount)) throw new RuntimeException('Nominal webhook Casaku tidak valid.');
    $amount = (float)$amount;

    $st = $pdo->prepare("SELECT * FROM orders WHERE payment_provider='casaku' AND payment_reference=? LIMIT 1");
    $st->execute([$transactionId]);
    $order = $st->fetch();
    if (!$order) {
        log_payment_audit(null,$transactionId,$status,$amount,true,'Webhook Casaku valid tetapi order tidak ditemukan',$payload,'casaku');
        json_out(['ok'=>false,'message'=>'Order tidak ditemukan'],404);
    }
    $invoice = (string)$order['invoice'];
    $requiredAmount = (float)($order['payment_total'] ?: $order['total']);
    if (abs($amount - $requiredAmount) > 0.0001) {
        log_payment_audit($invoice,$transactionId,$status,$amount,true,'Nominal webhook Casaku tidak cocok dengan payment_total order',$payload,'casaku');
        json_out(['ok'=>false,'message'=>'Nominal pembayaran tidak sesuai'],403);
    }

    // Webhook valid diproses langsung; polling tetap menjadi jalur recovery.
    $packageName = trim((string)($payload['packageName'] ?? ''));
    $allowedPackages = casaku_package_ids();
    $packageNote = $packageName !== '' ? ' package='.$packageName : '';
    if ($packageName !== '' && $allowedPackages && !in_array($packageName, $allowedPackages, true)) {
        // Package mismatch hanya dicatat; HMAC, transactionId, dan nominal tetap wajib cocok.
        log_payment_audit($invoice,$transactionId,'paid',$amount,true,'Webhook Casaku package di luar konfigurasi: '.$packageName,$payload,'casaku');
    }
    $remoteNote = 'Webhook HMAC valid + transactionId tepat + nominal tepat; diproses langsung'.$packageNote;
    $verified = [
        'invoice'=>$invoice,
        'reference'=>$transactionId,
        'status'=>'paid',
        'amount'=>$amount,
        'signature_valid'=>true,
    ];
    log_payment_audit($invoice,$transactionId,'paid',$amount,true,$remoteNote,$payload,'casaku');

    $claim = claim_payment_webhook_event($verified,$payload,'casaku');
    $eventKey = (string)$claim['event_key'];
    if (!$claim['claimed']) {
        $latest=$pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $latest->execute([(int)$order['id']]);
        payment_webhook_success(['ok'=>true,'duplicate'=>true], $latest->fetch() ?: []);
    }

    $paidOrder = mark_order_paid($invoice,$transactionId,true);
    if (!empty($paidOrder['_fulfillment_new']) && (string)($paidOrder['sales_channel'] ?? '') === 'telegram') {
        telegram_queue_order_delivery((int)$paidOrder['id']);
    }
    complete_payment_webhook_event($eventKey,'casaku');
    payment_webhook_success(['ok'=>true,'duplicate'=>empty($paidOrder['_fulfillment_new'])], $paidOrder);
} catch (Throwable $e) {
    if ($eventKey !== '') fail_payment_webhook_event($eventKey,$e->getMessage(),'casaku');
    log_payment_audit($invoice ?: null,$transactionId ?: null,$status ?: null,$amount,false,$e->getMessage(),is_array($payload)?$payload:[],'casaku');
    // 503 untuk error verifikasi/status agar provider melakukan retry; 403 untuk signature invalid.
    $code = str_contains(strtolower($e->getMessage()), 'signature') ? 403 : 503;
    json_out(['ok'=>false,'message'=>$e->getMessage()],$code);
}
