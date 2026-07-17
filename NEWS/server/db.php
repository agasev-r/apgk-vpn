<?php
// db.php
// Database connection helper for APGK VPN Panel

$db_host = 'localhost';
$db_name = 'apgk_apivpn';
$db_user = 'apgk_apivpn';
$db_pass = 'orrz20054Q+';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    
    // Set default timezone for PHP
    date_default_timezone_set('Europe/Kyiv');
    
    // Dynamically sync MySQL session timezone with PHP timezone (handles DST winter/summer shifts)
    $offset = date('P');
    $pdo->exec("SET time_zone = '$offset'");
    
    // Detect and log clients that went offline (PC off)
    try {
        $pdo->exec("
            INSERT INTO `connection_history` (`client_id`, `event_type`, `created_at`)
            SELECT c.`client_id`, 'pc_off', c.`last_seen`
            FROM `clients` c
            WHERE c.`last_seen` < DATE_SUB(NOW(), INTERVAL 3 MINUTE)
              AND (
                SELECT h.`event_type`
                FROM `connection_history` h
                WHERE h.`client_id` = c.`client_id` AND h.`event_type` IN ('pc_on', 'pc_off')
                ORDER BY h.`created_at` DESC, h.`id` DESC LIMIT 1
              ) = 'pc_on'
        ");
    } catch (PDOException $e) {}
} catch (PDOException $e) {
    // Return API error if request is API, otherwise show HTML error
    if (strpos($_SERVER['REQUEST_URI'], 'api.php') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
        exit;
    }
    die("Database connection failed: " . $e->getMessage());
}
