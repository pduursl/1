<?php
/**
 * Скрипт с отображением прогресса в браузере
 */

$config = [
    'host' => 'mysql-pms-prod-hotelogix.com',
    'username' => 'prod_one',
    'password' => 'A/|{8c0MgPz)_<=I',
    'database' => 'hudblive',
    'charset' => 'utf8mb4'
];

ini_set('memory_limit', '2048M');
set_time_limit(3600);

// Подключение к БД
try {
    $pdo = new PDO(
        "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 300,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION max_execution_time = 600000"
        ]
    );
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

// Получаем количество записей
$countSql = "
    SELECT COUNT(*) as total
    FROM reservations r
    LEFT JOIN hotels h ON h.id = r.hotelId
    LEFT JOIN rsvRmGuests rg ON rg.rsvId = r.id 
        AND rg.hotelId = r.hotelId 
        AND rg.isOwner = 1
    LEFT JOIN contactInfoMaster ci ON ci.refId = rg.guestId
        AND ci.hotelId = rg.hotelId
        AND ci.tableName = 'guestsMaster'
        AND ci.contactType = 'p'
    WHERE r.checkInDate > '2026-07-11'
        AND COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo) IS NOT NULL
        AND COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo) NOT IN ('.', '0', '00')
        AND LENGTH(COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo)) > 2
";

$stmt = $pdo->query($countSql);
$total = (int)$stmt->fetch()['total'];

if ($total === 0) {
    die("Нет данных для экспорта");
}

// Заголовки для скачивания
$filename = 'reservations_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Заголовки с разделителем |
fputcsv($output, [
    'reservation_id', 'hotelId', 'hotelName', 'fio', 
    'arrival', 'departure', 'price', 'email', 'phone', 
    'status', 'currency'
], '|');

// Основной запрос с пагинацией
$query = "
    SELECT
        r.id AS reservation_id,
        r.hotelId,
        h.hotelName,
        CONCAT_WS(' ', ci.fName, ci.lName) AS fio,
        r.checkInDate AS arrival,
        r.checkOutDate AS departure,
        COALESCE((
            SELECT SUM(rt.amount)
            FROM rsvRooms rr
            JOIN rsvRmTariff rt ON rt.rsvRmId = rr.id AND rt.hotelId = rr.hotelId
            WHERE rr.rsvId = r.id AND rr.hotelId = r.hotelId
        ), 0) AS price,
        ci.email,
        COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo) AS phone,
        r.rsvStatus AS status,
        h.billingCurrency AS currency
    FROM reservations r
    LEFT JOIN hotels h ON h.id = r.hotelId
    LEFT JOIN rsvRmGuests rg ON rg.rsvId = r.id 
        AND rg.hotelId = r.hotelId 
        AND rg.isOwner = 1
    LEFT JOIN contactInfoMaster ci ON ci.refId = rg.guestId
        AND ci.hotelId = rg.hotelId
        AND ci.tableName = 'guestsMaster'
        AND ci.contactType = 'p'
    WHERE r.checkInDate > '2026-07-11'
        AND COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo) IS NOT NULL
        AND COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo) NOT IN ('.', '0', '00')
        AND LENGTH(COALESCE(NULLIF(ci.mobileNo, ''), ci.phoneNo)) > 2
    ORDER BY r.id DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute();

$exported = 0;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, $row, '|');
    $exported++;
    
    // Показываем прогресс каждые 1000 записей
    if ($exported % 1000 === 0) {
        header('X-Progress: ' . round(($exported / $total) * 100) . '%');
        ob_flush();
        flush();
    }
}

fclose($output);
exit;
