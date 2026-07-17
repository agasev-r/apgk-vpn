<?php
// api.php
// API endpoint for APGK VPN clients (polls once a minute)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once 'db.php';

// Get JSON POST input
$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);

if (!$data || !isset($data['client_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request data. client_id is required.']);
    exit;
}

$clientId = substr(preg_replace('/[^0-9]/', '', $data['client_id']), 0, 6);
if (strlen($clientId) !== 6) {
    echo json_encode(['status' => 'error', 'message' => 'Client ID must be exactly 6 digits.']);
    exit;
}

// 1. Fetch old client state to calculate traffic delta and log status changes
try {
    $stmtOld = $pdo->prepare("SELECT `status`, `rx_bytes`, `tx_bytes`, `autostart`, `autoconnect`, `minimize_to_tray`, `last_seen` FROM `clients` WHERE `client_id` = ?");
    $stmtOld->execute([$clientId]);
    $oldClient = $stmtOld->fetch();
} catch (PDOException $e) {
    // Treat as no old client data
    $oldClient = null;
}

$status = $data['status'] ?? 'disconnected';
$tunnelName = $data['tunnel_name'] ?? null;
$ip = $data['ip'] ?? null;

// Settings from client payload
$autostart = isset($data['autostart']) ? (int)$data['autostart'] : 0;
$autoconnect = isset($data['autoconnect']) ? (int)$data['autoconnect'] : 0;
$minimizeToTray = isset($data['minimize_to_tray']) ? (int)$data['minimize_to_tray'] : 1;

// Determine public (white) IP address of client
$publicIp = $_SERVER['REMOTE_ADDR'] ?? null;
if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    $publicIp = trim($ips[0]);
} elseif (isset($_SERVER['HTTP_X_REAL_IP'])) {
    $publicIp = $_SERVER['HTTP_X_REAL_IP'];
}
if ($publicIp && !filter_var($publicIp, FILTER_VALIDATE_IP)) {
    $publicIp = null;
}

// Calculate connection duration delta
$durationDelta = 0;
if ($oldClient && $oldClient['status'] === 'connected' && $status === 'connected') {
    $timeDiff = time() - strtotime($oldClient['last_seen']);
    if ($timeDiff > 0 && $timeDiff < 300) {
        $durationDelta = $timeDiff;
    } else {
        $durationDelta = 60; // fallback to polling interval
    }
} elseif ($status === 'connected') {
    $durationDelta = 30; // initial estimate
}

// Check if there is a pending update_settings command.
// If so, do not overwrite the database settings with the client's current settings.
$hasPendingSettingsCmd = false;
try {
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `commands` WHERE `client_id` = ? AND `command` = 'update_settings' AND `status` = 'pending'");
    $stmtCheck->execute([$clientId]);
    $hasPendingSettingsCmd = $stmtCheck->fetchColumn() > 0;
} catch (PDOException $e) {}

if ($hasPendingSettingsCmd && $oldClient) {
    $autostart = (int)$oldClient['autostart'];
    $autoconnect = (int)$oldClient['autoconnect'];
    $minimizeToTray = (int)$oldClient['minimize_to_tray'];
}

$rx = isset($data['rx_bytes']) ? (float)$data['rx_bytes'] : 0;
$tx = isset($data['tx_bytes']) ? (float)$data['tx_bytes'] : 0;

// Log PC Turn On event (pc_on)
try {
    $isOffline = true;
    if ($oldClient) {
        $timeDiff = time() - strtotime($oldClient['last_seen']);
        if ($timeDiff <= 180) { // 3 minutes
            $isOffline = false;
        }
    }
    
    if ($isOffline) {
        // Double check that the last PC status event wasn't already pc_on
        $stmtLast = $pdo->prepare("
            SELECT `event_type` FROM `connection_history` 
            WHERE `client_id` = ? AND `event_type` IN ('pc_on', 'pc_off') 
            ORDER BY `created_at` DESC, `id` DESC LIMIT 1
        ");
        $stmtLast->execute([$clientId]);
        $lastEvent = $stmtLast->fetchColumn();
        if ($lastEvent !== 'pc_on') {
            $stmtLogPc = $pdo->prepare("INSERT INTO `connection_history` (`client_id`, `event_type`) VALUES (?, 'pc_on')");
            $stmtLogPc->execute([$clientId]);
        }
    }
} catch (PDOException $e) {
    // Log pc_on failure should not block the request
}

// Log status transition to connection_history
try {
    $oldStatus = $oldClient ? $oldClient['status'] : 'disconnected';
    if ($status !== $oldStatus) {
        if ($status === 'connected') {
            $stmtLog = $pdo->prepare("INSERT INTO `connection_history` (`client_id`, `event_type`, `ip`) VALUES (?, 'connect', ?)");
            $stmtLog->execute([$clientId, $ip]);
        } elseif ($status === 'disconnected' && $oldStatus === 'connected') {
            $stmtLog = $pdo->prepare("INSERT INTO `connection_history` (`client_id`, `event_type`, `ip`) VALUES (?, 'disconnect', ?)");
            $stmtLog->execute([$clientId, $ip]);
        }
    }
} catch (PDOException $e) {
    // Log transition failure should not block the request
}

// Calculate traffic delta and log to traffic_history
try {
    $deltaRx = 0;
    $deltaTx = 0;
    if ($oldClient) {
        $deltaRx = ($rx >= $oldClient['rx_bytes']) ? ($rx - $oldClient['rx_bytes']) : $rx;
        $deltaTx = ($tx >= $oldClient['tx_bytes']) ? ($tx - $oldClient['tx_bytes']) : $tx;
    } else {
        $deltaRx = $rx;
        $deltaTx = $tx;
    }

    if ($deltaRx > 0 || $deltaTx > 0 || $durationDelta > 0 || $publicIp !== null) {
        $stmtTraffic = $pdo->prepare("
            INSERT INTO `traffic_history` (`client_id`, `date`, `rx_bytes`, `tx_bytes`, `duration_seconds`, `public_ip`)
            VALUES (:id, CURRENT_DATE(), :rx, :tx, :duration, :public_ip)
            ON DUPLICATE KEY UPDATE
            `rx_bytes` = `rx_bytes` + VALUES(`rx_bytes`),
            `tx_bytes` = `tx_bytes` + VALUES(`tx_bytes`),
            `duration_seconds` = `duration_seconds` + VALUES(`duration_seconds`),
            `public_ip` = VALUES(`public_ip`)
        ");
        $stmtTraffic->execute([
            'id' => $clientId,
            'rx' => $deltaRx,
            'tx' => $deltaTx,
            'duration' => $durationDelta,
            'public_ip' => $publicIp
        ]);
    }
} catch (PDOException $e) {
    // Traffic update failure should not block the request
}

// 2. Upsert client status (Fix: use VALUES() in ON DUPLICATE KEY to avoid named param reuse)
try {
    $stmt = $pdo->prepare("
        INSERT INTO `clients` 
        (`client_id`, `status`, `tunnel_name`, `ip`, `autostart`, `autoconnect`, `minimize_to_tray`, `rx_bytes`, `tx_bytes`, `last_seen`) 
        VALUES 
        (:id, :status, :tunnel_name, :ip, :autostart, :autoconnect, :minimize_to_tray, :rx, :tx, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE 
        `status` = VALUES(`status`),
        `tunnel_name` = VALUES(`tunnel_name`),
        `ip` = VALUES(`ip`),
        `autostart` = VALUES(`autostart`),
        `autoconnect` = VALUES(`autoconnect`),
        `minimize_to_tray` = VALUES(`minimize_to_tray`),
        `rx_bytes` = VALUES(`rx_bytes`),
        `tx_bytes` = VALUES(`tx_bytes`),
        `last_seen` = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        'id'               => $clientId,
        'status'           => $status,
        'tunnel_name'      => $tunnelName,
        'ip'               => $ip,
        'autostart'        => $autostart,
        'autoconnect'      => $autoconnect,
        'minimize_to_tray' => $minimizeToTray,
        'rx'               => $rx,
        'tx'               => $tx
    ]);

    // Prune history older than 90 days (approx. 5% chance)
    if (rand(1, 20) === 1) {
        $pdo->exec("DELETE FROM `traffic_history` WHERE `date` < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
        $pdo->exec("DELETE FROM `connection_history` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update client status: ' . $e->getMessage()]);
    exit;
}

// 2. Check for pending commands
try {
    $stmt = $pdo->prepare("SELECT * FROM `commands` WHERE `client_id` = ? AND `status` = 'pending' ORDER BY `created_at` ASC LIMIT 1");
    $stmt->execute([$clientId]);
    $cmd = $stmt->fetch();

    if ($cmd) {
        // Mark as sent immediately to prevent double delivery
        $update = $pdo->prepare("UPDATE `commands` SET `status` = 'sent' WHERE `id` = ?");
        $update->execute([$cmd['id']]);

        echo json_encode([
            'status'  => 'ok',
            'command' => $cmd['command'],
            'payload' => $cmd['payload'],
            'cmd_id'  => $cmd['id']
        ]);
        exit;
    }

} catch (PDOException $e) {
    // Log error but proceed to return OK status
}

// Return OK if no pending commands
echo json_encode(['status' => 'ok', 'command' => null]);
exit;
