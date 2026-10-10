<?php
declare(strict_types=1);

require_method('GET');
$user = require_pos_enabled_user(database_connection());
$taxBps = filter_var(getenv('POS_TAX_BPS') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
$cashierDiscountBps = filter_var(getenv('POS_CASHIER_MAX_DISCOUNT_BPS') ?: '500', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
if ($taxBps === false || $cashierDiscountBps === false) {
    throw new RuntimeException('Invalid POS tax or discount configuration.');
}
$currency = $user['business']['currency'] ?? 'PHP';
if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
    throw new RuntimeException('POS_CURRENCY must be a three-letter uppercase currency code.');
}

json_response(200, [
    'storeName' => $user['business']['name'],
    'currency' => $currency,
    'taxBps' => $taxBps,
    'discountMaxBps' => in_array($user['role'], ['owner', 'admin', 'manager'], true) ? 10000 : $cashierDiscountBps,
    'paymentMethods' => ['cash', 'card', 'ewallet'],
    'externalPaymentsIntegrated' => false,
]);