<?php
declare(strict_types=1);

require_method('GET');
$pdo = database_connection();
$user = require_admin($pdo);
$countsQuery = $pdo->prepare(
    "SELECT date(created_at) AS registration_date, COUNT(*) AS registrations
     FROM business_members
     WHERE business_id = :business_id AND is_active = 1 AND date(created_at) >= date('now', '-29 days')
     GROUP BY date(created_at)",
);
$countsQuery->execute(['business_id' => $user['business_id']]);
$counts = $countsQuery->fetchAll();
$byDate = [];
foreach ($counts as $row) {
    $byDate[$row['registration_date']] = (int) $row['registrations'];
}

$start = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$start = $start->modify('-29 days');
$series = [];
for ($offset = 0; $offset < 30; $offset++) {
    $day = $start->modify('+' . $offset . ' days');
    $date = $day->format('Y-m-d');
    $series[] = [
        'date' => $date,
        'label' => $day->format('M j'),
        'registrations' => $byDate[$date] ?? 0,
    ];
}

json_response(200, ['registrations' => $series]);