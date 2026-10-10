<?php
declare(strict_types=1);

function pos_route_id(): int
{
    $id = filter_var($_GET['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        json_response(400, ['message' => 'A valid resource ID is required.']);
    }
    return $id;
}

function pos_int(mixed $value, int $minimum, int $maximum): ?int
{
    if (!is_int($value) || $value < $minimum || $value > $maximum) {
        return null;
    }
    return $value;
}

function pos_image_path(mixed $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) || strlen($value) > 500 || str_contains($value, "\0") || str_contains($value, '..')) {
        return null;
    }
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        return $value;
    }
    if (filter_var($value, FILTER_VALIDATE_URL) && parse_url($value, PHP_URL_SCHEME) === 'https') {
        return $value;
    }
    return null;
}

function pos_sale(PDO $pdo, int $saleId, int $businessId): ?array
{
    $saleQuery = $pdo->prepare(
        'SELECT sales.*, users.name AS cashier_name
         FROM sales JOIN users ON users.id = sales.cashier_id
         WHERE sales.id = :id AND sales.business_id = :business_id',
    );
    $saleQuery->execute(['id' => $saleId, 'business_id' => $businessId]);
    $sale = $saleQuery->fetch();
    if ($sale === false) {
        return null;
    }

    $items = $pdo->prepare(
        'SELECT id, product_id, product_name, unit_price_cents, quantity, line_total_cents
         FROM sale_items WHERE sale_id = :sale_id AND business_id = :business_id ORDER BY id',
    );
    $items->execute(['sale_id' => $saleId, 'business_id' => $businessId]);
    $sale['items'] = $items->fetchAll();
    $industryQuery = $pdo->prepare('SELECT industry FROM businesses WHERE id = :business_id');
    $industryQuery->execute(['business_id' => $businessId]);
    if ($industryQuery->fetchColumn() === 'pharmacy') {
        $allocationQuery = $pdo->prepare(
            'SELECT allocations.sale_item_id, allocations.quantity, batches.id AS batch_id,
                    batches.batch_number, batches.expires_on
             FROM medicine_batch_allocations AS allocations
             JOIN medicine_batches AS batches
               ON batches.id = allocations.batch_id AND batches.business_id = allocations.business_id
             WHERE allocations.business_id = :business_id AND allocations.sale_id = :sale_id
             ORDER BY allocations.id',
        );
        $allocationQuery->execute(['business_id' => $businessId, 'sale_id' => $saleId]);
        $allocationsByItem = [];
        foreach ($allocationQuery->fetchAll() as $allocation) {
            foreach (['sale_item_id', 'quantity', 'batch_id'] as $field) $allocation[$field] = (int) $allocation[$field];
            $allocationsByItem[$allocation['sale_item_id']][] = [
                'batch_id' => $allocation['batch_id'],
                'batch_number' => $allocation['batch_number'],
                'expires_on' => $allocation['expires_on'],
                'quantity' => $allocation['quantity'],
            ];
        }
        foreach ($sale['items'] as &$item) {
            $item['batch_allocations'] = $allocationsByItem[(int) $item['id']] ?? [];
        }
        unset($item);
    }
    return $sale;
}

function pos_authorize_sale(PDO $pdo, array $user, int $saleId): array
{
    $sale = pos_sale($pdo, $saleId, (int) $user['business_id']);
    if ($sale === null) {
        json_response(404, ['message' => 'Transaction not found.']);
    }
    if (!in_array($user['role'], ['owner', 'admin', 'manager'], true) && (int) $sale['cashier_id'] !== (int) $user['id']) {
        json_response(403, ['message' => 'You do not have access to this transaction.']);
    }
    return $sale;
}

function pos_money(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}