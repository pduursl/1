<?php
/**
 * Экспорт данных с AJAX-прогрессом
 * Пошаговая выгрузка по 2000 записей
 */

session_start();

$config = [
    'host' => 'mysql-pms-prod-hotelogix.com',
    'username' => 'prod_one',
    'password' => 'A/|{8c0MgPz)_<=I',
    'database' => 'hudblive',
    'charset' => 'utf8mb4'
];

// Подключение к БД
function getDB() {
    global $config;
    try {
        return new PDO(
            "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}",
            $config['username'],
            $config['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 600
            ]
        );
    } catch (PDOException $e) {
        die(json_encode(['error' => 'Ошибка подключения: ' . $e->getMessage()]));
    }
}

// Получаем общее количество записей
function getTotalCount($pdo) {
    $sql = "
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
    $stmt = $pdo->query($sql);
    return (int)$stmt->fetch()['total'];
}

// Получаем порцию данных
function getBatch($pdo, $offset, $limit = 2000) {
    $sql = "
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
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll();
}

// AJAX запрос на прогресс
if (isset($_GET['action']) && $_GET['action'] === 'progress') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['export_total']) || !isset($_SESSION['export_offset'])) {
        echo json_encode(['error' => 'Сессия не инициализирована']);
        exit;
    }
    
    $total = $_SESSION['export_total'];
    $offset = $_SESSION['export_offset'];
    $processed = $_SESSION['export_processed'] ?? 0;
    $batchSize = 2000;
    
    $percent = $total > 0 ? round(($processed / $total) * 100) : 0;
    $isComplete = $processed >= $total;
    
    echo json_encode([
        'total' => $total,
        'processed' => $processed,
        'offset' => $offset,
        'percent' => $percent,
        'isComplete' => $isComplete,
        'batchSize' => $batchSize,
        'remaining' => $total - $processed
    ]);
    exit;
}

// AJAX запрос на следующую порцию
if (isset($_GET['action']) && $_GET['action'] === 'next') {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['export_total']) || !isset($_SESSION['export_offset'])) {
        echo json_encode(['error' => 'Сессия не инициализирована']);
        exit;
    }
    
    $pdo = getDB();
    $offset = $_SESSION['export_offset'];
    $limit = 2000;
    $total = $_SESSION['export_total'];
    
    // Получаем порцию
    $data = getBatch($pdo, $offset, $limit);
    $count = count($data);
    
    // Сохраняем данные в CSV файл
    $filename = $_SESSION['export_filename'] ?? 'export.csv';
    $filepath = sys_get_temp_dir() . '/' . $filename;
    
    // Если первый раз, создаем файл с заголовками
    if ($offset === 0) {
        $fp = fopen($filepath, 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM для UTF-8
        fputcsv($fp, [
            'reservation_id', 'hotelId', 'hotelName', 'fio', 
            'arrival', 'departure', 'price', 'email', 'phone', 
            'status', 'currency'
        ], '|');
    } else {
        $fp = fopen($filepath, 'a');
    }
    
    // Записываем данные
    foreach ($data as $row) {
        fputcsv($fp, $row, '|');
    }
    fclose($fp);
    
    // Обновляем сессию
    $_SESSION['export_offset'] += $count;
    $_SESSION['export_processed'] = min($_SESSION['export_offset'], $total);
    $_SESSION['export_last_batch'] = $count;
    
    $processed = $_SESSION['export_processed'];
    $percent = $total > 0 ? round(($processed / $total) * 100) : 0;
    $isComplete = $processed >= $total;
    
    echo json_encode([
        'success' => true,
        'processed' => $processed,
        'total' => $total,
        'percent' => $percent,
        'offset' => $_SESSION['export_offset'],
        'batchSize' => $limit,
        'lastBatch' => $count,
        'isComplete' => $isComplete,
        'remaining' => $total - $processed,
        'filename' => $filename
    ]);
    exit;
}

// AJAX запрос на скачивание
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $filename = $_SESSION['export_filename'] ?? 'export.csv';
    $filepath = sys_get_temp_dir() . '/' . $filename;
    
    if (file_exists($filepath)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        
        // Очищаем сессию после скачивания
        unlink($filepath);
        session_destroy();
        exit;
    } else {
        die('Файл не найден');
    }
}

// AJAX запрос на сброс
if (isset($_GET['action']) && $_GET['action'] === 'reset') {
    $filename = $_SESSION['export_filename'] ?? 'export.csv';
    $filepath = sys_get_temp_dir() . '/' . $filename;
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    session_destroy();
    header('Content-Type: application/json');
    echo json_encode(['success' => true]);
    exit;
}

// Инициализация экспорта
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start'])) {
    header('Content-Type: application/json');
    
    $pdo = getDB();
    $total = getTotalCount($pdo);
    
    if ($total === 0) {
        echo json_encode(['error' => 'Нет данных для экспорта']);
        exit;
    }
    
    // Инициализируем сессию
    $_SESSION['export_total'] = $total;
    $_SESSION['export_offset'] = 0;
    $_SESSION['export_processed'] = 0;
    $_SESSION['export_filename'] = 'reservations_' . date('Y-m-d_H-i-s') . '.csv';
    $_SESSION['export_started'] = time();
    
    echo json_encode([
        'success' => true,
        'total' => $total,
        'message' => 'Экспорт начат'
    ]);
    exit;
}

// HTML интерфейс
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Экспорт данных с прогрессом</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body { padding: 30px; background: #f5f5f5; }
        .container { max-width: 800px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .progress { height: 30px; margin: 20px 0; }
        .progress-bar { transition: width 0.3s; }
        .status-box { background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
        .log-area { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-family: monospace; height: 200px; overflow-y: auto; font-size: 12px; }
        .log-area .log-success { color: #4caf50; }
        .log-area .log-info { color: #2196f3; }
        .log-area .log-warning { color: #ff9800; }
        .log-area .log-error { color: #f44336; }
        .btn-export { padding: 10px 40px; font-size: 18px; }
        .stats { display: flex; justify-content: space-around; flex-wrap: wrap; }
        .stat-item { text-align: center; padding: 10px; }
        .stat-item .number { font-size: 24px; font-weight: bold; color: #007bff; }
        .stat-item .label { font-size: 12px; color: #666; }
        #downloadBtn { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h2 class="mb-4">📊 Экспорт данных</h2>
        <p class="text-muted">Порционная выгрузка по 2000 записей с отображением прогресса</p>
        
        <div id="statusMessages"></div>
        
        <div class="text-center mb-3">
            <button id="startBtn" class="btn btn-primary btn-export">🚀 Начать экспорт</button>
            <button id="downloadBtn" class="btn btn-success btn-export">📥 Скачать CSV</button>
            <button id="resetBtn" class="btn btn-danger btn-export" style="display:none;">🔄 Сброс</button>
        </div>
        
        <div class="status-box">
            <div class="stats">
                <div class="stat-item">
                    <div class="number" id="totalRecords">-</div>
                    <div class="label">Всего записей</div>
                </div>
                <div class="stat-item">
                    <div class="number" id="processedRecords">0</div>
                    <div class="label">Обработано</div>
                </div>
                <div class="stat-item">
                    <div class="number" id="remainingRecords">-</div>
                    <div class="label">Осталось</div>
                </div>
                <div class="stat-item">
                    <div class="number" id="percentComplete">0%</div>
                    <div class="label">Готово</div>
                </div>
            </div>
        </div>
        
        <div class="progress">
            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%;"></div>
        </div>
        
        <div class="log-area" id="logArea">
            <div class="log-info">📌 Ожидание начала экспорта...</div>
        </div>
        
        <div class="mt-3 text-muted small" id="timeInfo"></div>
    </div>

    <script>
    let isRunning = false;
    let isComplete = false;
    let totalRecords = 0;
    let processedRecords = 0;
    let startTime = null;
    let statusCheckInterval = null;
    
    function addLog(message, type = 'info') {
        const logArea = document.getElementById('logArea');
        const time = new Date().toLocaleTimeString();
        const colors = {
            'success': 'log-success',
            'info': 'log-info',
            'warning': 'log-warning',
            'error': 'log-error'
        };
        const colorClass = colors[type] || 'log-info';
        logArea.innerHTML += `<div class="${colorClass}">[${time}] ${message}</div>`;
        logArea.scrollTop = logArea.scrollHeight;
    }
    
    function updateStats(processed, total, percent) {
        document.getElementById('processedRecords').textContent = processed;
        document.getElementById('totalRecords').textContent = total;
        document.getElementById('percentComplete').textContent = percent + '%';
        document.getElementById('remainingRecords').textContent = total - processed;
        document.getElementById('progressBar').style.width = percent + '%';
        document.getElementById('progressBar').textContent = percent + '%';
        
        // Обновляем время
        if (startTime && processed > 0) {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const minutes = Math.floor(elapsed / 60);
            const seconds = elapsed % 60;
            const speed = Math.round(processed / (elapsed / 60));
            document.getElementById('timeInfo').textContent = 
                `⏱ Время: ${minutes}м ${seconds}с | Скорость: ${speed} зап/мин | Размер порции: 2000`;
        }
    }
    
    function checkProgress() {
        if (!isRunning && !isComplete) return;
        
        fetch('?action=progress')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    addLog('❌ ' + data.error, 'error');
                    return;
                }
                
                const total = data.total || 0;
                const processed = data.processed || 0;
                const percent = data.percent || 0;
                
                totalRecords = total;
                processedRecords = processed;
                updateStats(processed, total, percent);
                
                if (data.isComplete && !isComplete) {
                    isComplete = true;
                    isRunning = false;
                    addLog('✅ Экспорт завершен! Всего обработано: ' + processed + ' записей', 'success');
                    document.getElementById('downloadBtn').style.display = 'inline-block';
                    document.getElementById('resetBtn').style.display = 'inline-block';
                    document.getElementById('startBtn').disabled = true;
                    if (statusCheckInterval) {
                        clearInterval(statusCheckInterval);
                    }
                }
            })
            .catch(error => {
                addLog('❌ Ошибка проверки прогресса: ' + error, 'error');
            });
    }
    
    function processNextBatch() {
        if (!isRunning || isComplete) return;
        
        fetch('?action=next')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    addLog('❌ ' + data.error, 'error');
                    isRunning = false;
                    return;
                }
                
                if (data.success) {
                    const percent = data.percent || 0;
                    addLog(`📦 Загружена порция ${data.lastBatch} записей (${data.processed}/${data.total})`, 'info');
                    updateStats(data.processed, data.total, percent);
                    
                    // Если не завершено, продолжаем
                    if (!data.isComplete) {
                        setTimeout(processNextBatch, 100);
                    } else {
                        isComplete = true;
                        isRunning = false;
                        addLog('✅ Экспорт завершен! Всего обработано: ' + data.processed + ' записей', 'success');
                        document.getElementById('downloadBtn').style.display = 'inline-block';
                        document.getElementById('resetBtn').style.display = 'inline-block';
                        document.getElementById('startBtn').disabled = true;
                        if (statusCheckInterval) {
                            clearInterval(statusCheckInterval);
                        }
                        updateStats(data.processed, data.total, data.percent);
                    }
                }
            })
            .catch(error => {
                addLog('❌ Ошибка загрузки порции: ' + error, 'error');
                isRunning = false;
            });
    }
    
    function startExport() {
        if (isRunning) return;
        
        addLog('🚀 Запуск экспорта...', 'info');
        document.getElementById('startBtn').disabled = true;
        document.getElementById('startBtn').textContent = '⏳ Выполняется...';
        
        const formData = new FormData();
        formData.append('start', '1');
        
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                addLog('❌ ' + data.error, 'error');
                document.getElementById('startBtn').disabled = false;
                document.getElementById('startBtn').textContent = '🚀 Начать экспорт';
                return;
            }
            
            if (data.success) {
                totalRecords = data.total;
                startTime = Date.now();
                addLog(`📊 Найдено ${data.total} записей для экспорта`, 'success');
                isRunning = true;
                isComplete = false;
                
                // Запускаем проверку прогресса
                if (statusCheckInterval) {
                    clearInterval(statusCheckInterval);
                }
                statusCheckInterval = setInterval(checkProgress, 2000);
                
                // Начинаем загрузку порций
                setTimeout(processNextBatch, 500);
            }
        })
        .catch(error => {
            addLog('❌ Ошибка запуска: ' + error, 'error');
            document.getElementById('startBtn').disabled = false;
            document.getElementById('startBtn').textContent = '🚀 Начать экспорт';
        });
    }
    
    function downloadFile() {
        window.location.href = '?action=download';
        addLog('📥 Скачивание файла...', 'info');
        setTimeout(() => {
            document.getElementById('downloadBtn').style.display = 'none';
        }, 1000);
    }
    
    function resetExport() {
        if (!confirm('Вы уверены, что хотите сбросить экспорт?')) return;
        
        fetch('?action=reset')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
    }
    
    // Инициализация кнопок
    document.getElementById('startBtn').addEventListener('click', startExport);
    document.getElementById('downloadBtn').addEventListener('click', downloadFile);
    document.getElementById('resetBtn').addEventListener('click', resetExport);
    
    // Автоматически проверяем статус при загрузке
    setTimeout(checkProgress, 1000);
    
    addLog('💡 Интерфейс загружен. Нажмите "Начать экспорт" для запуска.', 'info');
    </script>
</body>
</html>
