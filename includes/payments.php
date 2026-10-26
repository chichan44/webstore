<?php
/**
 * Checkout payment method registry.
 * Methods are extensible: add id/name/description/enabled here and branch in api/checkout.php.
 */
function checkout_demo_payment_enabled(): bool {
    return (string)setting('checkout_demo_payment_enabled', '0') === '1';
}

function checkout_available_payment_methods(): array {
    $methods = [];

    // Demo — admin-gated auto-complete for testing only.
    $methods[] = [
        'id' => 'demo',
        'name' => 'Demo',
        'description' => 'Otomatis lunas untuk testing',
        'badge' => 'Testing',
        'enabled' => checkout_demo_payment_enabled(),
    ];

    // Crypto via existing NowPayments integration.
    $npEnabled = function_exists('nowpayments_api_key') ? (nowpayments_api_key() !== '') : (trim((string)setting('nowpayments_api_key', '')) !== '');
    $methods[] = [
        'id' => 'nowpayments',
        'name' => 'Crypto',
        'description' => 'NowPayments — bayar dengan kripto',
        'badge' => 'NowPayments',
        'enabled' => true, // always list; API error surfaces on submit if key missing
    ];

    // Later: tokopay, casaku, qris_static.

    return $methods;
}

function checkout_payment_method_enabled(string $id): bool {
    foreach (checkout_available_payment_methods() as $m) {
        if ((string)$m['id'] === $id) return !empty($m['enabled']);
    }
    return false;
}

function checkout_payment_method_label(string $id): string {
    foreach (checkout_available_payment_methods() as $m) {
        if ((string)$m['id'] === $id) return (string)$m['name'];
    }
    $map = [
        'demo' => 'Demo',
        'nowpayments' => 'Crypto',
        'tokopay' => 'Tokopay',
        'casaku' => 'Casaku',
        'qris_static' => 'QRIS Manual',
    ];
    return $map[$id] ?? $id;
}
