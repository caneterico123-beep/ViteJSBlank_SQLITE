<?php
declare(strict_types=1);

require_method('GET');
$user = require_pos_operator(database_connection());
$taxBps = filter_var(getenv('POS_TAX_BPS') ?: '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
$cashierDiscountBps = filter_var(getenv('POS_CASHIER_MAX_DISCOUNT_BPS') ?: '500', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
if ($taxBps === false || $cashierDiscountBps === false) {
    throw new RuntimeException('Invalid POS tax or discount configuration.');
}

json_response(200, [
    'storeName' => getenv('POS_STORE_NAME') ?: 'Northstar Store',
    'taxBps' => $taxBps,
    'discountMaxBps' => $user['role'] === 'admin' ? 10000 : $cashierDiscountBps,
    'paymentMethods' => ['cash', 'card', 'ewallet'],
    'externalPaymentsIntegrated' => false,
]);