<?php
/**
 * NowPayments API client for Digital Store.
 *
 * Modes:
 *   - Redirect (invoice): customer redirected to NowPayments hosted page
 *   - Embedded (payment): show crypto address/QR on our page
 *
 * Auth: x-api-key header
 * IPN:  HMAC-SHA512 with API key as secret, verified via x-nowpayments-sig header
 */

if (!function_exists('nowpayments_api_base')) {

    function nowpayments_api_base(): string {
        $sandbox = trim((string)setting('nowpayments_sandbox', '0'));
        if ($sandbox === '1') return 'https://api-sandbox.nowpayments.io/v1';
        return 'api.nowpayments.io/v1';
    }

    function nowpayments_api_key(): string {
        return settings_decrypt_value((string)setting('nowpayments_api_key', ''));
    }

    function nowpayments_ipn_secret(): string {
        return settings_decrypt_value((string)setting('nowpayments_ipn_secret', ''));
    }

    function nowpayments_expired_minutes(): int {
        $v = (int)setting('nowpayments_expired_minutes', '30');
        if ($v < 5) $v = 30;
        if ($v > 1440) $v = 1440;
        return $v;
    }

    function nowpayments_default_currency(): string {
        return strtolower(trim((string)setting('nowpayments_default_currency', 'btc')));
    }

    /**
     * Make an API request to NowPayments.
     */
    function nowpayments_http_request(string $method, string $path, array $payload = [], int $timeout = 20): array {
        $key = nowpayments_api_key();
        if ($key === '') {
            return ['ok' => false, 'error' => 'API key NowPayments belum diatur.', 'status' => 0];
        }

        $url = nowpayments_api_base() . '/' . ltrim($path, '/');
        $headers = [
            'x-api-key: ' . $key,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        } elseif (strtoupper($method) !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        }

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            return ['ok' => false, 'error' => 'NowPayments: tidak ada respons. ' . $curlErr, 'status' => $status];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => 'NowPayments: respons bukan JSON.', 'status' => $status, 'raw' => $body];
        }

        if ($status >= 200 && $status < 300) {
            return array_merge(['ok' => true, 'status' => $status], $data);
        }

        $errMsg = $data['message'] ?? $data['error'] ?? $data['detail'] ?? 'Unknown error';
        return ['ok' => false, 'error' => 'NowPayments (' . $status . '): ' . $errMsg, 'status' => $status, 'raw' => $data];
    }

    /**
     * Check API status.
     */
    function nowpayments_check_api(): array {
        return nowpayments_http_request('GET', '/status');
    }

    /**
     * Get list of available cryptocurrencies.
     */
    function nowpayments_get_currencies(): array {
        $result = nowpayments_http_request('GET', '/merchant/coins');
        if (!$result['ok']) return $result;
        return ['ok' => true, 'currencies' => $result['currencies'] ?? []];
    }

    /**
     * Get estimated price for a crypto amount.
     */
    function nowpayments_estimate(float $amount, string $fromCurrency, string $toCurrency): array {
        return nowpayments_http_request('GET', '/estimate?amount=' . $amount . '&currency_from=' . urlencode($fromCurrency) . '&currency_to=' . urlencode($toCurrency));
    }

    /**
     * Create an invoice (redirect mode).
     * Returns invoice URL to redirect customer to.
     */
    function nowpayments_create_invoice(array $params): array {
        $payload = [
            'price_amount' => (float)$params['amount'],
            'price_currency' => strtolower(trim((string)($params['currency'] ?? 'usd'))),
            'pay_currency' => strtolower(trim((string)($params['pay_currency'] ?? nowpayments_default_currency()))),
            'ipn_callback_url' => (string)($params['callback_url'] ?? ''),
            'order_id' => (string)($params['order_id'] ?? ''),
            'order_description' => (string)($params['description'] ?? 'Digital Store Order'),
        ];

        if (!empty($params['success_url'])) $payload['success_url'] = (string)$params['success_url'];
        if (!empty($params['cancel_url'])) $payload['cancel_url'] = (string)$params['cancel_url'];

        $result = nowpayments_http_request('POST', '/invoice', $payload);
        if (!$result['ok']) return $result;

        return [
            'ok' => true,
            'invoice_id' => $result['id'] ?? '',
            'invoice_url' => $result['invoice_url'] ?? '',
            'status' => $result['invoice_status'] ?? 'new',
        ];
    }

    /**
     * Create a payment (embedded mode).
     * Returns crypto address and amount to pay.
     */
    function nowpayments_create_payment(array $params): array {
        $payload = [
            'price_amount' => (float)$params['amount'],
            'price_currency' => strtolower(trim((string)($params['currency'] ?? 'usd'))),
            'pay_currency' => strtolower(trim((string)($params['pay_currency'] ?? nowpayments_default_currency()))),
            'ipn_callback_url' => (string)($params['callback_url'] ?? ''),
            'order_id' => (string)($params['order_id'] ?? ''),
            'order_description' => (string)($params['description'] ?? 'Digital Store Order'),
        ];

        $result = nowpayments_http_request('POST', '/payment', $payload);
        if (!$result['ok']) return $result;

        return [
            'ok' => true,
            'payment_id' => $result['payment_id'] ?? '',
            'pay_address' => $result['pay_address'] ?? '',
            'pay_amount' => $result['pay_amount'] ?? 0,
            'pay_currency' => $result['pay_currency'] ?? '',
            'payment_status' => $result['payment_status'] ?? 'waiting',
            'created_at' => $result['created_at'] ?? '',
        ];
    }

    /**
     * Check payment status.
     */
    function nowpayments_check_status(string $paymentId): array {
        $result = nowpayments_http_request('GET', '/payment/' . urlencode($paymentId));
        if (!$result['ok']) return $result;

        return [
            'ok' => true,
            'payment_id' => $result['payment_id'] ?? $paymentId,
            'payment_status' => $result['payment_status'] ?? 'unknown',
            'pay_address' => $result['pay_address'] ?? '',
            'pay_amount' => $result['pay_amount'] ?? 0,
            'pay_currency' => $result['pay_currency'] ?? '',
            'price_amount' => $result['price_amount'] ?? 0,
            'price_currency' => $result['price_currency'] ?? '',
        ];
    }

    /**
     * Verify IPN webhook signature (HMAC-SHA512).
     *
     * NowPayments sends the signature in x-nowpayments-sig header.
     * The payload must be sorted by keys (JSON with sorted keys) for verification.
     */
    function verify_nowpayments_ipn(string $rawBody, string $signatureHeader): bool {
        $secret = nowpayments_ipn_secret();
        if ($secret === '' || $signatureHeader === '') return false;

        // Try with raw body first
        $computed = hash_hmac('sha512', $rawBody, $secret);
        if (hash_equals($computed, $signatureHeader)) return true;

        // Try with sorted JSON keys
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            ksort($decoded);
            $sorted = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $computed2 = hash_hmac('sha512', $sorted, $secret);
            if (hash_equals($computed2, $signatureHeader)) return true;
        }

        return false;
    }

    /**
     * Map NowPayments status to our internal status.
     */
    function nowpayments_map_status(string $npStatus): string {
        $npStatus = strtolower(trim($npStatus));
        return match ($npStatus) {
            'finished', 'confirmed' => 'paid',
            'failed', 'expired', 'refunded' => 'failed',
            default => 'pending', // waiting, confirming, sending, etc.
        };
    }

    /**
     * Get human-readable status label for NowPayments status.
     */
    function nowpayments_status_label(string $npStatus): string {
        return match (strtolower(trim($npStatus))) {
            'waiting' => 'Menunggu pembayaran',
            'confirming' => 'Mengonfirmasi',
            'confirmed' => 'Terkonfirmasi',
            'sending' => 'Mengirim',
            'finished' => 'Selesai',
            'failed' => 'Gagal',
            'expired' => 'Kedaluwarsa',
            'refunded' => 'Dikembalikan',
            default => ucfirst($npStatus),
        };
    }

    /**
     * Generate a local unique reference for NowPayments orders.
     */
    function nowpayments_local_unique_code(): string {
        $prefix = strtoupper(trim((string)setting('nowpayments_prefix', 'NP')));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefix) ?? '';
        if ($prefix === '') $prefix = 'NP';
        return substr($prefix, 0, 8) . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}
