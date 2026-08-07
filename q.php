<?php
// Конфигурация подключения к MySQL
$host = 'bd.ruralgest.net';
$user = 'Par_02_2026_tne-PROD';
$password = 'Throw58-mark-Eat-too-very-Cook2';
$database = 'ruralgest30';

// Настройки выгрузки
$table = 'cliente';
$id_start = 9781937;
$id_end = 10417996;
$delimiter = '|';

// Колонки для выгрузки
$columns = ['id_cliente', 'nombre', 'apellido', 'direccion', 'codigo_postal', 'localidad', 'tel1', 'requerir_tarjeta', 'dni'];

// Устанавливаем заголовки для скачивания файла
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="clientes_export_' . date('Y-m-d_H-i-s') . '.txt"');

try {
    // Подключение к базе данных
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Формируем запрос
    $columns_str = implode(', ', $columns);
    $sql = "SELECT $columns_str 
            FROM $table 
            WHERE id_cliente BETWEEN :id_start AND :id_end 
            AND requerir_tarjeta IS NOT NULL 
            AND requerir_tarjeta != '' 
            ORDER BY id_cliente";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_start' => $id_start, ':id_end' => $id_end]);

    // Проверяем, есть ли данные
    if ($stmt->rowCount() == 0) {
        echo "Нет записей, удовлетворяющих условиям";
        exit;
    }

    // Выводим заголовок с названиями колонок
    echo implode($delimiter, $columns) . "\n";

    // Выводим данные
    $count = 0;
    while ($row = $stmt->fetch()) {
        // Экранируем разделитель в полях (если он есть в данных)
        $escaped_row = array_map(function($value) use ($delimiter) {
            if ($value === null) {
                return '';
            }
            // Если в значении есть разделитель, экранируем его
            return str_replace($delimiter, '\\' . $delimiter, $value);
        }, $row);
        
        echo implode($delimiter, $escaped_row) . "\n";
        $count++;
    }

    // Логируем количество выгруженных записей
    error_log("Выгружено записей: $count. Диапазон ID: $id_start - $id_end");

} catch (PDOException $e) {
    // В случае ошибки выводим ее в файл
    error_log("Ошибка подключения: " . $e->getMessage());
    echo "Произошла ошибка при подключении к базе данных. Пожалуйста, проверьте логи.";
    exit;
} catch (Exception $e) {
    error_log("Общая ошибка: " . $e->getMessage());
    echo "Произошла ошибка. Пожалуйста, проверьте логи.";
    exit;
}

// Закрываем соединение (PDO закрывает автоматически при завершении скрипта)
?>
