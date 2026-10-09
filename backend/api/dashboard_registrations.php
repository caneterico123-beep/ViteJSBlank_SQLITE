<?php
declare(strict_types=1);

require_method('GET');
require_admin(database_connection());
$pdo = database_connection();
$counts = $pdo->query(
    "SELECT date(created_at) AS registration_date, COUNT(*) AS registrations
     FROM users
     WHERE date(created_at) >= date('now', '-29 days')
     GROUP BY date(created_at)",
)->fetchAll();
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