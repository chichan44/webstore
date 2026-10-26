<?php
/**
 * NowPayments IPN (Instant Payment Notification) webhook handler.
 *
 * Verifies HMAC-SHA512 signature, finds the order, and triggers fulfillment.
 *
 * IPN payload fields:
 *   - payment_id, payment_status, pay_address, pay_amount, pay_currency
 *   - price_amount, price_currency, order_id, order_description
 */
if (!defined('DEFER_EXTERNAL_DELIVERY')) define('DEFER_EXTERNAL_DELIVERY', true);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/nowpayments.php';
require_once __DIR__.'/../includes/telegram_bot.php';
ensure_order_security_columns();
ensure_product_extra_columns();
telegram_ensure_schema();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    json_out(['ok'=>false,'message'=>'Method not allowed'],405);
}

$raw = (string)file_get_contents('php://input');
$signature = '';
foreach (['HTTP_X_NOWPAYMENTS_SIG','REDIRECT_HTTP_X_NOWPAYMENTS_SIG'] as $key) {
    if (!empty($_SERVER[$key])) { $signature = trim((string)$_SERVER[$key]); break; }
}
if ($signature === '' && function_exists('getallheaders')) {
    foreach ((array)getallheaders() as $name => $value) {
        if (strcasecmp((string)$name, 'x-nowpayments-sig') === 0) { $signature = trim((string)$value); break; }
    }
}

$payload = [];
$eventKey = '';
$invoice = '';
$paymentId = '';
$npStatus = '';

try {
    if ($raw === '') throw new RuntimeException('NowPayments IPN payload kosong.');

    // Verify HMAC-SHA512
    if (!verify_nowpayments_ipn($raw, $signature)) {
        log_payment_audit(null, null, null, null, false, 'NowPayments IPN signature tidak valid', [], 'nowpayments');
        json_out(['ok'=>false,'message'=>'Invalid signature'], 403);
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) throw new RuntimeException('NowPayments IPN payload bukan JSON.');

    $paymentId = trim((string)($payload['payment_id'] ?? ''));
    $npStatus = strtolower(trim((string)($payload['payment_status'] ?? '')));
    $orderId = trim((string)($payload['order_id'] ?? ''));
    $payAmount = (float)($payload['pay_amount'] ?? 0);
    $payCurrency = trim((string)($payload['pay_currency'] ?? ''));
    $priceAmount = (float)($payload['price_amount'] ?? 0);
    $priceCurrency = trim((string)($payload['price_currency'] ?? ''));

    if ($paymentId === '') throw new RuntimeException('NowPayments payment_id kosong.');

    // Only process finished/confirmed payments
    $mappedStatus = nowpayments_map_status($npStatus);
    if ($mappedStatus !== 'paid') {
        log_payment_audit(null, $paymentId, $npStatus, $priceAmount, true, 'NowPayments IPN valid tetapi bukan status paid (' . $npStatus . ')', $payload, 'nowpayments');
        json_out(['ok'=>true,'ignored'=>true,'status'=>$npStatus]);
    }

    // Find order by order_id (our invoice) or payment_reference (payment_id)
    $order = null;
    if ($orderId !== '') {
        $st = $pdo->prepare("SELECT * FROM orders WHERE invoice=? LIMIT 1");
        $st->execute([$orderId]);
        $order = $st->fetch();
    }
    if (!$order) {
        $st = $pdo->prepare("SELECT * FROM orders WHERE payment_provider='nowpayments' AND payment_reference=? LIMIT 1");
        $st->execute([$paymentId]);
        $order = $st->fetch();
    }
    if (!$order) {
        log_payment_audit(null, $paymentId, $npStatus, $priceAmount, true, 'NowPayments IPN valid tetapi order tidak ditemukan', $payload, 'nowpayments');
        json_out(['ok'=>false,'message'=>'Order not found'], 404);
    }

    $invoice = (string)$order['invoice'];

    // IPN valid — process directly
    $verified = [
        'invoice' => $invoice,
        'reference' => $paymentId,
        'status' => 'paid',
        'amount' => $priceAmount,
        'signature_valid' => true,
    ];
    log_payment_audit($invoice, $paymentId, 'paid', $priceAmount, true, 'NowPayments IPN HMAC valid + payment confirmed', $payload, 'nowpayments');

    $claim = claim_payment_webhook_event($verified, $payload, 'nowpayments');
    $eventKey = (string)$claim['event_key'];
    if (!$claim['claimed']) {
        $latest = $pdo->prepare('SELECT * FROM orders WHERE id=? LIMIT 1');
        $latest->execute([(int)$order['id']]);
        payment_webhook_success(['ok'=>true,'duplicate'=>true], $latest->fetch() ?: []);
    }

    $paidOrder = mark_order_paid($invoice, $paymentId, true);
    if (!empty($paidOrder['_fulfillment_new']) && (string)($paidOrder['sales_channel'] ?? '') === 'telegram') {
        telegram_queue_order_delivery((int)$paidOrder['id']);
    }
    complete_payment_webhook_event($eventKey, 'nowpayments');
    payment_webhook_success(['ok'=>true,'duplicate'=>empty($paidOrder['_fulfillment_new'])], $paidOrder);

} catch (Throwable $e) {
    if ($eventKey !== '') fail_payment_webhook_event($eventKey, $e->getMessage(), 'nowpayments');
    log_payment_audit($invoice ?: null, $paymentId ?: null, $npStatus ?: null, null, false, $e->getMessage(), is_array($payload) ? $payload : [], 'nowpayments');
    $code = str_contains(strtolower($e->getMessage()), 'signature') ? 403 : 503;
    json_out(['ok'=>false,'message'=>$e->getMessage()], $code);
}
