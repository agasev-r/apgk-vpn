<?php
// index.php
// Web Administration Panel for APGK VPN

session_start();
require_once 'db.php';

// AJAX stats endpoint
if (isset($_GET['action']) && $_GET['action'] === 'get_stats' && isset($_GET['client_id'])) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['admin_logged'])) {
        header('HTTP/1.1 403 Forbidden');
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    $clientId = substr(preg_replace('/[^0-9]/', '', $_GET['client_id']), 0, 6);

    try {
        // Fetch traffic history (last 90 days)
        $stmt = $pdo->prepare("SELECT `date`, `rx_bytes`, `tx_bytes`, `duration_seconds`, `public_ip` FROM `traffic_history` WHERE `client_id` = ? ORDER BY `date` DESC LIMIT 90");
        $stmt->execute([$clientId]);
        $traffic = $stmt->fetchAll();

        // Fetch connection history (last 50 logs)
        $stmt = $pdo->prepare("SELECT `event_type`, `ip`, `created_at` FROM `connection_history` WHERE `client_id` = ? ORDER BY `created_at` DESC LIMIT 50");
        $stmt->execute([$clientId]);
        $connections = $stmt->fetchAll();

        // Fetch recent commands
        $stmt = $pdo->prepare("SELECT `id`, `command`, `status`, `created_at` FROM `commands` WHERE `client_id` = ? ORDER BY `created_at` DESC LIMIT 5");
        $stmt->execute([$clientId]);
        $commands = $stmt->fetchAll();

        echo json_encode([
            'status' => 'ok',
            'traffic' => $traffic,
            'connections' => $connections,
            'commands' => $commands
        ]);
    } catch (PDOException $e) {
        header('HTTP/1.1 500 Internal Server Error');
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Simple Router / Actions
$action = $_GET['action'] ?? 'dashboard';

// Handle Logout
if ($action === 'logout') {
    unset($_SESSION['admin_logged']);
    unset($_SESSION['admin_user']);
    unset($_SESSION['admin_role']);
    header('Location: index.php');
    exit;
}

// Handle Login POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM `admins` WHERE `username` = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_logged'] = true;
        $_SESSION['admin_user'] = $admin['username'];
        $_SESSION['admin_role'] = $admin['role'];
        header('Location: index.php');
        exit;
    } else {
        $login_error = "Невірне ім'я користувача або пароль.";
    }
}

// Authentication Guard
if (!isset($_SESSION['admin_logged'])) {
    $action = 'login';
}

// Handle Admin Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['admin_logged'])) {
    
    // 1. Register Unregistered Client (set Name and Enterprise)
    if (isset($_POST['register_client'])) {
        $clientId = $_POST['client_id'];
        $name = trim($_POST['name']);
        $enterprise = trim($_POST['enterprise']);

        $stmt = $pdo->prepare("UPDATE `clients` SET `name` = ?, `enterprise` = ? WHERE `client_id` = ?");
        $stmt->execute([$name, $enterprise, $clientId]);
        header('Location: index.php?msg=client_registered');
        exit;
    }

    // 2. Add New Admin (Superadmin only)
    if (isset($_POST['add_admin']) && $_SESSION['admin_role'] === 'superadmin') {
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $role = $_POST['role'] ?? 'admin';

        if (!empty($username) && !empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            try {
                $stmt = $pdo->prepare("INSERT INTO `admins` (`username`, `password_hash`, `role`) VALUES (?, ?, ?)");
                $stmt->execute([$username, $hash, $role]);
                header('Location: index.php?tab=admins&msg=admin_added');
                exit;
            } catch (PDOException $e) {
                $admin_error = "Користувач вже існує.";
            }
        }
    }

    // 3. Delete Admin (Superadmin only)
    if (isset($_POST['delete_admin']) && $_SESSION['admin_role'] === 'superadmin') {
        $adminId = (int)$_POST['admin_id'];
        $stmt = $pdo->prepare("SELECT * FROM `admins` WHERE `id` = ?");
        $stmt->execute([$adminId]);
        $target = $stmt->fetch();

        if ($target && $target['username'] !== 'roman' && $target['username'] !== $_SESSION['admin_user']) {
            $stmt = $pdo->prepare("DELETE FROM `admins` WHERE `id` = ?");
            $stmt->execute([$adminId]);
            header('Location: index.php?tab=admins&msg=admin_deleted');
            exit;
        }
    }

    // 3b. Edit Admin (Superadmin only)
    if (isset($_POST['edit_admin']) && $_SESSION['admin_role'] === 'superadmin') {
        $adminId = (int)$_POST['admin_id'];
        $username = trim($_POST['username']);
        $password = $_POST['password'];
        $role = $_POST['role'] ?? 'admin';

        if (!empty($username)) {
            try {
                // Check if username is already taken by another user
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM `admins` WHERE `username` = ? AND `id` != ?");
                $stmtCheck->execute([$username, $adminId]);
                if ($stmtCheck->fetchColumn() > 0) {
                    $admin_error = "Користувач з таким логіном вже існує.";
                } else {
                    // Get old username to check if we need to update session
                    $stmtOldUser = $pdo->prepare("SELECT `username` FROM `admins` WHERE `id` = ?");
                    $stmtOldUser->execute([$adminId]);
                    $oldUsername = $stmtOldUser->fetchColumn();

                    // Update username and role
                    $stmt = $pdo->prepare("UPDATE `admins` SET `username` = ?, `role` = ? WHERE `id` = ?");
                    $stmt->execute([$username, $role, $adminId]);

                    // If a new password was provided, update it as well
                    if (!empty($password)) {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmtPass = $pdo->prepare("UPDATE `admins` SET `password_hash` = ? WHERE `id` = ?");
                        $stmtPass->execute([$hash, $adminId]);
                    }

                    // If editing own account, update session username and role
                    if ($oldUsername && $oldUsername === $_SESSION['admin_user']) {
                        $_SESSION['admin_user'] = $username;
                        $_SESSION['admin_role'] = $role;
                    }

                    header('Location: index.php?tab=admins&msg=admin_updated');
                    exit;
                }
            } catch (PDOException $e) {
                $admin_error = "Помилка оновлення: " . $e->getMessage();
            }
        }
    }

    // 4. Send Config File Command
    if (isset($_POST['send_config'])) {
        $clientId = $_POST['client_id'];
        $configData = $_POST['config_data'];

        // Save config in client table so it is preloaded next time
        $stmtClient = $pdo->prepare("UPDATE `clients` SET `config` = ? WHERE `client_id` = ?");
        $stmtClient->execute([$configData, $clientId]);

        // Clean up previous pending commands to prevent build-up
        $stmtClean = $pdo->prepare("DELETE FROM `commands` WHERE `client_id` = ? AND `status` = 'pending'");
        $stmtClean->execute([$clientId]);

        // Add command to queue
        $stmt = $pdo->prepare("INSERT INTO `commands` (`client_id`, `command`, `payload`, `status`) VALUES (?, 'update_config', ?, 'pending')");
        $stmt->execute([$clientId, $configData]);
        header('Location: index.php?msg=command_queued');
        exit;
    }

    // 5. Send Control Action Command
    if (isset($_POST['send_control'])) {
        $clientId = $_POST['client_id'];
        $cmd = $_POST['control_cmd']; // connect, disconnect, restart

        // Clean up previous pending commands to prevent build-up
        $stmtClean = $pdo->prepare("DELETE FROM `commands` WHERE `client_id` = ? AND `status` = 'pending'");
        $stmtClean->execute([$clientId]);

        $stmt = $pdo->prepare("INSERT INTO `commands` (`client_id`, `command`, `status`) VALUES (?, ?, 'pending')");
        $stmt->execute([$clientId, $cmd]);
        header('Location: index.php?msg=command_queued');
        exit;
    }

    // 6. Send Settings Toggles Command
    if (isset($_POST['send_settings'])) {
        $clientId = $_POST['client_id'];
        $autostart = isset($_POST['autostart']) ? 1 : 0;
        $autoconnect = isset($_POST['autoconnect']) ? 1 : 0;
        $minimizeToTray = isset($_POST['minimize_to_tray']) ? 1 : 0;

        // Update clients table immediately so the UI reflects the change on page reload
        $stmtClient = $pdo->prepare("UPDATE `clients` SET `autostart` = ?, `autoconnect` = ?, `minimize_to_tray` = ? WHERE `client_id` = ?");
        $stmtClient->execute([$autostart, $autoconnect, $minimizeToTray, $clientId]);

        $settingsPayload = json_encode([
            'autostart' => $autostart,
            'autoconnect' => $autoconnect,
            'minimize_to_tray' => $minimizeToTray
        ]);

        // Clean up previous pending commands to prevent build-up
        $stmtClean = $pdo->prepare("DELETE FROM `commands` WHERE `client_id` = ? AND `status` = 'pending'");
        $stmtClean->execute([$clientId]);

        $stmt = $pdo->prepare("INSERT INTO `commands` (`client_id`, `command`, `payload`, `status`) VALUES (?, 'update_settings', ?, 'pending')");
        $stmt->execute([$clientId, $settingsPayload]);
        header('Location: index.php?msg=command_queued');
        exit;
    }

    // 7. Delete Client
    if (isset($_POST['delete_client'])) {
        $clientId = $_POST['client_id'];
        $stmt = $pdo->prepare("DELETE FROM `clients` WHERE `client_id` = ?");
        $stmt->execute([$clientId]);
        header('Location: index.php?msg=client_deleted');
        exit;
    }
}

// Fetch Stats for Dashboard
$totalClients = 0;
$activeClients = 0;
$newClients = 0;

if (isset($_SESSION['admin_logged'])) {
    $totalClients = $pdo->query("SELECT COUNT(*) FROM `clients` WHERE `name` IS NOT NULL")->fetchColumn();
    $activeClients = $pdo->query("SELECT COUNT(*) FROM `clients` WHERE `status` = 'connected' AND `last_seen` > NOW() - INTERVAL 2 MINUTE")->fetchColumn();
    $newClients = $pdo->query("SELECT COUNT(*) FROM `clients` WHERE `name` IS NULL")->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>APGK VPN — Панель керування</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #f4f5f8;
            --card-bg: #ffffff;
            --border-color: rgba(0, 0, 0, 0.08);
            --primary: #007aff;
            --primary-hover: #005ecb;
            --text-color: #1d1d1f;
            --text-muted: #86868b;
            --success: #34c759;
            --danger: #ff3b30;
            --warning: #ff9500;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(0, 122, 255, 0.05) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(52, 199, 89, 0.03) 0px, transparent 50%);
            background-attachment: fixed;
            color: var(--text-color);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Login Layout */
        .login-container {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 1;
            padding: 20px;
        }

        .login-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 18px;
            width: 100%;
            max-width: 400px;
            padding: 40px 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            text-align: center;
        }

        .logo-area {
            margin-bottom: 30px;
        }

        .logo-icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .logo-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.5px;
            color: var(--text-color);
        }

        .input-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .input-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        .input-field {
            width: 100%;
            padding: 12px 16px;
            background: #fafafa;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            color: var(--text-color);
            font-size: 15px;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .input-field:focus {
            outline: none;
            border-color: var(--primary);
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(0,122,255,0.1);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 12px 24px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn:hover {
            background: var(--primary-hover);
        }

        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #d32f2f; }

        .btn-secondary { background: rgba(0,0,0,0.04); border: 1px solid var(--border-color); color: var(--text-color); }
        .btn-secondary:hover { background: rgba(0,0,0,0.08); }

        .error-banner {
            background: rgba(255, 59, 48, 0.1);
            border: 1px solid rgba(255, 59, 48, 0.15);
            color: #ff3b30;
            padding: 12px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
            text-align: left;
        }

        /* Admin Dashboard Layout */
        header {
            background: rgba(255, 255, 255, 0.8);
            border-bottom: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            padding: 15px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .header-logo span {
            font-weight: 700;
            font-size: 18px;
            letter-spacing: -0.5px;
        }

        .nav-tabs {
            display: flex;
            gap: 10px;
        }

        .tab-link {
            padding: 8px 16px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .tab-link:hover, .tab-link.active {
            color: var(--primary);
            background: rgba(0, 122, 255, 0.05);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 14px;
        }

        .user-name {
            font-weight: 600;
        }

        .user-role {
            font-size: 11px;
            background: rgba(0, 122, 255, 0.1);
            color: var(--primary);
            padding: 2px 8px;
            border-radius: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .logout-link {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 14px;
        }

        .logout-link:hover {
            color: var(--danger);
        }

        main {
            flex: 1;
            padding: 40px;
            max-width: 1400px;
            width: 100%;
            margin: 0 auto;
        }

        /* Grid stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
        }

        .stat-icon {
            font-size: 30px;
            background: rgba(0,0,0,0.02);
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.04);
        }

        .stat-info h3 {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .stat-info p {
            font-size: 28px;
            font-weight: 700;
        }

        /* Client Section List */
        .section-title {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 40px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th {
            background: rgba(0,0,0,0.01);
            padding: 16px 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }

        td {
            padding: 16px 20px;
            font-size: 14px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.04);
        }

        tr:last-child td {
            border-bottom: none;
        }

        .client-id-badge {
            font-family: monospace;
            font-size: 14px;
            background: rgba(0,0,0,0.04);
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: bold;
            white-space: nowrap;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
        }

        .status-connected {
            background: rgba(52, 199, 89, 0.15);
            color: var(--success);
        }

        .status-ready {
            background: rgba(0, 122, 255, 0.15);
            color: var(--primary);
        }

        .status-disconnected {
            background: rgba(142, 155, 178, 0.15);
            color: var(--text-muted);
        }

        .status-offline {
            background: rgba(255, 59, 48, 0.15);
            color: var(--danger);
        }

        /* Action triggers */
        .actions-cell {
            display: flex;
            gap: 8px;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
            width: auto;
        }

        /* Modal styling */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }

        .modal-content {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 18px;
            width: 100%;
            max-width: 650px;
            padding: 30px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.1);
            position: relative;
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 24px;
            cursor: pointer;
        }

        .modal-close:hover {
            color: var(--text-color);
        }

        .modal-tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 20px;
        }

        .modal-tab-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            padding: 10px 16px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 2px solid transparent;
        }

        .modal-tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .settings-toggle-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .toggle-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0,0,0,0.01);
            padding: 12px 16px;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .toggle-info h4 {
            font-size: 14px;
            font-weight: 600;
        }

        .toggle-info p {
            font-size: 12px;
            color: var(--text-muted);
        }

        /* Checkbox Switch styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.1);
            transition: .3s;
            border-radius: 24px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        input:checked + .slider {
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(22px);
        }
    </style>
</head>
<body>

<?php if ($action === 'login'): ?>
    <div class="login-container">
        <div class="login-card">
            <div class="logo-area">
                <div class="logo-icon">🛡️</div>
                <div class="logo-title">APGK VPN PANEL</div>
            </div>
            
            <?php if (isset($login_error)): ?>
                <div class="error-banner"><?= htmlspecialchars($login_error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="input-group">
                    <label>Логін адміністратора</label>
                    <input type="text" name="username" class="input-field" required autocomplete="username">
                </div>
                <div class="input-group">
                    <label>Пароль</label>
                    <input type="password" name="password" class="input-field" required autocomplete="current-password">
                </div>
                <button type="submit" name="login" class="btn">Увійти в кабінет</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- Header Dashboard -->
    <header>
        <div class="header-logo">
            <div style="font-size: 24px;">🛡️</div>
            <span>APGK VPN</span>
        </div>
        
        <div class="nav-tabs">
            <a href="index.php?tab=clients" class="tab-link <?= ($_GET['tab'] ?? 'clients') === 'clients' ? 'active' : '' ?>">Клієнти</a>
            <?php if ($_SESSION['admin_role'] === 'superadmin'): ?>
                <a href="index.php?tab=admins" class="tab-link <?= ($_GET['tab'] ?? '') === 'admins' ? 'active' : '' ?>">Адміністратори</a>
            <?php endif; ?>
            <a href="APGK_VPN_Setup_1.0.0.exe" class="tab-link" download style="color: var(--success); font-weight: 600;">📥 Скачати клієнт</a>
        </div>

        <div class="user-info">
            <span class="user-name"><?= htmlspecialchars($_SESSION['admin_user']) ?></span>
            <span class="user-role"><?= htmlspecialchars($_SESSION['admin_role']) ?></span>
            <a href="index.php?action=logout" class="logout-link">Вийти</a>
        </div>
    </header>

    <main>
        <!-- Dashboard Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-info">
                    <h3>Зареєстровані клієнти</h3>
                    <p><?= $totalClients ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: var(--success);">🟢</div>
                <div class="stat-info">
                    <h3>Активні з'єднання</h3>
                    <p><?= $activeClients ?></p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="color: var(--warning);">🔔</div>
                <div class="stat-info">
                    <h3>Нові (незареєстровані)</h3>
                    <p><?= $newClients ?></p>
                </div>
            </div>
        </div>

        <?php if (($_GET['tab'] ?? 'clients') === 'clients'): ?>
            
            <!-- Unregistered Clients Section -->
            <?php if ($newClients > 0): ?>
                <div class="section-title">Незареєстровані пристрої <span style="font-size: 13px; color: var(--warning);">Потребують активації</span></div>
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Код клієнта (ID)</th>
                                <th>Останній зв'язок</th>
                                <th>Поточний статус</th>
                                <th>Дія</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT * FROM `clients` WHERE `name` IS NULL ORDER BY `last_seen` DESC");
                            while ($client = $stmt->fetch()):
                                $isOffline = (strtotime($client['last_seen']) < time() - 120);
                            ?>
                                <tr>
                                    <td><span class="client-id-badge"><?= substr($client['client_id'], 0, 3) . ' ' . substr($client['client_id'], 3, 3) ?></span></td>
                                    <td><?= date('d.m.Y H:i:s', strtotime($client['last_seen'])) ?></td>
                                    <td>
                                        <?php if ($isOffline): ?>
                                            <span class="status-badge status-offline">Вимкнено</span>
                                        <?php else: ?>
                                            <span class="status-badge status-connected">Увімкнено</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-cell">
                                        <button class="btn btn-sm" onclick="openRegisterModal('<?= $client['client_id'] ?>')">Активувати</button>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('Ви впевнені, що хочете видалити цей пристрій?');">
                                            <input type="hidden" name="client_id" value="<?= $client['client_id'] ?>">
                                            <button type="submit" name="delete_client" class="btn btn-sm btn-danger" style="width: auto;">Видалити</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Registered Clients Section -->
            <div class="section-title">Список зареєстрованих клієнтів</div>
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ФІО</th>
                            <th>Підприємство</th>
                            <th>VPN тунель</th>
                            <th>IP адреса</th>
                            <th>Останній зв'язок</th>
                            <th>Трафік (Завантажено / Віддано)</th>
                            <th>Статус ПК</th>
                            <th>Статус VPN</th>
                            <th>Керування</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $pdo->query("SELECT * FROM `clients` WHERE `name` IS NOT NULL ORDER BY `last_seen` DESC");
                        if ($stmt->rowCount() === 0):
                        ?>
                            <tr>
                                <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 30px;">Немає зареєстрованих клієнтів.</td>
                            </tr>
                        <?php
                        endif;
                        while ($client = $stmt->fetch()):
                            $isOffline = (strtotime($client['last_seen']) < time() - 120);
                            
                            // Format traffic
                            $rx = $client['rx_bytes'];
                            $tx = $client['tx_bytes'];
                            $rx_formatted = $rx > 1073741824 ? round($rx/1073741824, 1) . ' GB' : round($rx/1048576, 1) . ' MB';
                            $tx_formatted = $tx > 1073741824 ? round($tx/1073741824, 1) . ' GB' : round($tx/1048576, 1) . ' MB';
                        ?>
                            <tr>
                                <td><span class="client-id-badge"><?= substr($client['client_id'], 0, 3) . ' ' . substr($client['client_id'], 3, 3) ?></span></td>
                                <td><strong><?= htmlspecialchars($client['name']) ?></strong></td>
                                <td><?= htmlspecialchars($client['enterprise']) ?></td>
                                <td><?= htmlspecialchars($client['tunnel_name'] ?? '—') ?></td>
                                <td><span style="font-family: monospace;"><?= htmlspecialchars($client['ip'] ?? '—') ?></span></td>
                                <td><?= date('d.m.Y H:i:s', strtotime($client['last_seen'])) ?></td>
                                <td style="font-size: 13px; color: var(--text-muted);">
                                    📥 <?= $rx_formatted ?> / 📤 <?= $tx_formatted ?>
                                </td>
                                <td>
                                    <?php if ($isOffline): ?>
                                        <span class="status-badge status-offline">Вимкнено</span>
                                    <?php else: ?>
                                        <span class="status-badge status-connected">Увімкнено</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isOffline): ?>
                                        <span class="status-badge status-disconnected" style="background: rgba(142, 155, 178, 0.08);">—</span>
                                    <?php else: ?>
                                        <?php if ($client['status'] === 'connected'): ?>
                                            <span class="status-badge status-connected">Підключено</span>
                                        <?php else: ?>
                                            <span class="status-badge status-disconnected">Відключено</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-cell">
                                    <button class="btn btn-sm btn-secondary" onclick='openControlModal(<?= json_encode($client) ?>)'>Налаштування</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif (($_GET['tab'] ?? '') === 'admins' && $_SESSION['admin_role'] === 'superadmin'): ?>
            <!-- Admins management section -->
            <div class="section-title">Керування адміністраторами</div>
            
            <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 30px; align-items: start;">
                <!-- Add admin form -->
                <div class="table-card" style="padding: 24px;">
                    <h3 style="margin-bottom: 20px; font-size: 16px;">Додати адміністратора</h3>
                    <?php if (isset($admin_error)): ?>
                        <div class="error-banner"><?= htmlspecialchars($admin_error) ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="input-group">
                            <label>Логін</label>
                            <input type="text" name="username" class="input-field" required>
                        </div>
                        <div class="input-group">
                            <label>Пароль</label>
                            <input type="password" name="password" class="input-field" required>
                        </div>
                        <div class="input-group">
                            <label>Роль</label>
                            <select name="role" class="input-field">
                                <option value="admin">Адміністратор</option>
                                <option value="superadmin">Супер-адміністратор</option>
                            </select>
                        </div>
                        <button type="submit" name="add_admin" class="btn">Зберегти</button>
                    </form>
                </div>

                <!-- Admin list -->
                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Логін</th>
                                <th>Роль</th>
                                <th>Створено</th>
                                <th>Дія</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $stmt = $pdo->query("SELECT * FROM `admins` ORDER BY `created_at` DESC");
                            while ($adm = $stmt->fetch()):
                            ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($adm['username']) ?></strong></td>
                                    <td><span class="user-role"><?= htmlspecialchars($adm['role']) ?></span></td>
                                    <td><?= date('d.m.Y H:i', strtotime($adm['created_at'])) ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-secondary" style="width: auto; display: inline-block; margin-right: 4px; padding: 4px 10px;" onclick="openEditAdminModal(<?= $adm['id'] ?>, '<?= htmlspecialchars($adm['username'], ENT_QUOTES) ?>', '<?= htmlspecialchars($adm['role'], ENT_QUOTES) ?>')">Редагувати</button>
                                        <?php if ($adm['username'] !== 'roman' && $adm['username'] !== $_SESSION['admin_user']): ?>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Ви впевнені?');">
                                                <input type="hidden" name="admin_id" value="<?= $adm['id'] ?>">
                                                <button type="submit" name="delete_admin" class="btn btn-sm btn-danger" style="width: auto; display: inline-block; padding: 4px 10px;">Видалити</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </main>
<?php endif; ?>

<!-- Edit Admin Modal -->
<div id="editAdminModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Редагувати адміністратора</h3>
            <button class="modal-close" onclick="closeEditAdminModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="admin_id" id="edit_admin_id">
            <div class="input-group">
                <label>Логін</label>
                <input type="text" name="username" id="edit_admin_username" class="input-field" required>
            </div>
            <div class="input-group">
                <label>Новий пароль (залиште порожнім, щоб не змінювати)</label>
                <input type="password" name="password" class="input-field">
            </div>
            <div class="input-group">
                <label>Роль</label>
                <select name="role" id="edit_admin_role" class="input-field">
                    <option value="admin">Адміністратор</option>
                    <option value="superadmin">Супер-адміністратор</option>
                </select>
            </div>
            <button type="submit" name="edit_admin" class="btn">Зберегти</button>
        </form>
    </div>
</div>

<!-- Registration Modal -->
<div id="registerModal" class="modal">
    <div class="modal-content" style="max-width: 400px;">
        <div class="modal-header">
            <h3>Активація пристрою</h3>
            <button class="modal-close" onclick="closeRegisterModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="client_id" id="reg_client_id">
            <div class="input-group">
                <label>Код клієнта</label>
                <input type="text" id="reg_client_code" class="input-field" readonly style="background: rgba(255,255,255,0.02); color: var(--text-muted);">
            </div>
            <div class="input-group">
                <label>ФІО користувача</label>
                <input type="text" name="name" class="input-field" required placeholder="напр. Іванов Іван Іванович">
            </div>
            <div class="input-group">
                <label>Підприємство</label>
                <input type="text" name="enterprise" class="input-field" required placeholder="напр. Агро-Союз">
            </div>
            <button type="submit" name="register_client" class="btn">Активувати та зареєструвати</button>
        </form>
    </div>
</div>

<!-- Control Panel Modal -->
<div id="controlModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h3 id="ctrl_title" style="margin-bottom: 5px;">Налаштування клієнта</h3>
                <p id="ctrl_subtitle" style="font-size: 13px; color: var(--text-muted);"></p>
            </div>
            <button class="modal-close" onclick="closeControlModal()">&times;</button>
        </div>

        <div class="modal-tabs">
            <button class="modal-tab-btn active" onclick="switchModalTab('control')">Керування VPN</button>
            <button class="modal-tab-btn" onclick="switchModalTab('config')">Конфігурація</button>
            <button class="modal-tab-btn" onclick="switchModalTab('settings')">Налаштування</button>
            <button class="modal-tab-btn" onclick="switchModalTab('stats')">Статистика</button>
        </div>

        <!-- TAB: CONTROL -->
        <div id="tab_control" class="modal-tab-content">
            <div style="display: flex; gap: 15px; margin-bottom: 25px;">
                <form method="POST" style="flex: 1;">
                    <input type="hidden" name="client_id" class="ctrl_client_id_val">
                    <input type="hidden" name="control_cmd" value="connect">
                    <button type="submit" name="send_control" class="btn" style="background: var(--success);">Підключити VPN</button>
                </form>
                <form method="POST" style="flex: 1;">
                    <input type="hidden" name="client_id" class="ctrl_client_id_val">
                    <input type="hidden" name="control_cmd" value="disconnect">
                    <button type="submit" name="send_control" class="btn btn-danger">Відключити VPN</button>
                </form>
                <form method="POST" style="flex: 1;">
                    <input type="hidden" name="client_id" class="ctrl_client_id_val">
                    <input type="hidden" name="control_cmd" value="restart">
                    <button type="submit" name="send_control" class="btn" style="background: var(--warning);">Перезапустити</button>
                </form>
            </div>
            <p style="font-size: 12px; color: var(--text-muted); text-align: center;">
                💡 Команда буде надіслана та виконана на клієнті протягом 5 секунд.
            </p>

            <div style="margin-top: 20px;">
                <h4 style="margin-bottom: 10px; font-size: 14px;">Статус команд</h4>
                <table class="table" style="font-size: 13px;" id="commands_table">
                    <thead>
                        <tr>
                            <th>Час</th>
                            <th>Команда</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 10px;">Завантаження...</td></tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 30px; border-top: 1px solid var(--border-color); padding-top: 20px; text-align: right;">
                <form method="POST" onsubmit="return confirm('Ви впевнені, що хочете повністю видалити цей пристрій з бази даних?');" style="display: inline-block;">
                    <input type="hidden" name="client_id" class="ctrl_client_id_val">
                    <button type="submit" name="delete_client" class="btn btn-danger" style="background: #ff3b30; width: auto; padding: 8px 16px; font-size: 13px;">Видалити пристрій з бази</button>
                </form>
            </div>
        </div>

        <!-- TAB: CONFIG -->
        <div id="tab_config" class="modal-tab-content" style="display: none;">
            <form method="POST">
                <input type="hidden" name="client_id" class="ctrl_client_id_val">
                <div class="input-group">
                    <label>Вміст файлу WireGuard конфігурації (.conf)</label>
                    <textarea name="config_data" class="input-field" rows="10" style="font-family: monospace; font-size: 13px;" required placeholder="[Interface]&#10;PrivateKey = ...&#10;&#10;[Peer]&#10;PublicKey = ..."></textarea>
                </div>
                <button type="submit" name="send_config" class="btn">Надіслати конфіг у клієнт</button>
            </form>
        </div>

        <!-- TAB: SETTINGS -->
        <div id="tab_settings" class="modal-tab-content" style="display: none;">
            <form method="POST">
                <input type="hidden" name="client_id" class="ctrl_client_id_val">
                
                <div class="settings-toggle-grid">
                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>Автозапуск (Windows Run)</h4>
                            <p>Запуск клієнта при старті Windows</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="autostart" id="toggle_autostart">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>Автопідключення</h4>
                            <p>Автоматичний старт тунелю при запуску програми</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="autoconnect" id="toggle_autoconnect">
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="toggle-item">
                        <div class="toggle-info">
                            <h4>Згортання в трей</h4>
                            <p>При закритті вікна згортати у системний трей</p>
                        </div>
                        <label class="switch">
                            <input type="checkbox" name="minimize_to_tray" id="toggle_minimize_to_tray">
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

                <button type="submit" name="send_settings" class="btn">Зберегти налаштування клієнта</button>
            </form>
        </div>

        <!-- TAB: STATS -->
        <div id="tab_stats" class="modal-tab-content" style="display: none;">
            <div style="margin-bottom: 20px;">
                <h4 style="font-size: 14px; margin-bottom: 10px;">Сумарний трафік за останні 90 днів</h4>
                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <div style="flex: 1; background: rgba(0, 122, 255, 0.05); padding: 12px; border-radius: 8px; text-align: center; border: 1px solid rgba(0, 122, 255, 0.1);">
                        <div style="font-size: 12px; color: var(--text-muted);">Всього завантажено</div>
                        <div id="stats_total_rx" style="font-size: 18px; font-weight: 700; color: var(--primary); margin-top: 5px;">0 B</div>
                    </div>
                    <div style="flex: 1; background: rgba(52, 199, 89, 0.05); padding: 12px; border-radius: 8px; text-align: center; border: 1px solid rgba(52, 199, 89, 0.1);">
                        <div style="font-size: 12px; color: var(--text-muted);">Всього віддано</div>
                        <div id="stats_total_tx" style="font-size: 18px; font-weight: 700; color: var(--success); margin-top: 5px;">0 B</div>
                    </div>
                </div>
            </div>

            <div style="display: flex; flex-direction: column; gap: 20px;">
                <div>
                    <h4 style="font-size: 13px; margin-bottom: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 4px;">Щоденна статистика (Трафік та Час у мережі)</h4>
                    <div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; background: rgba(0,0,0,0.01);">
                        <table style="width: 100%; font-size: 11px;" id="stats_traffic_table">
                            <thead>
                                <tr style="background: rgba(0,0,0,0.02);">
                                    <th style="padding: 6px 10px; font-size: 11px;">Дата</th>
                                    <th style="padding: 6px 10px; font-size: 11px;">Час у мережі</th>
                                    <th style="padding: 6px 10px; font-size: 11px;">Вхідний</th>
                                    <th style="padding: 6px 10px; font-size: 11px;">Вихідний</th>
                                    <th style="padding: 6px 10px; font-size: 11px;">Білий IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h4 style="font-size: 13px; margin-bottom: 8px; border-bottom: 1px solid var(--border-color); padding-bottom: 4px;">Журнал підключень VPN</h4>
                    <div style="max-height: 180px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 8px; background: rgba(0,0,0,0.01);">
                        <table style="width: 100%; font-size: 11px;" id="stats_connections_table">
                            <thead>
                                <tr style="background: rgba(0,0,0,0.02);">
                                    <th style="padding: 6px 10px; font-size: 11px;">Час</th>
                                    <th style="padding: 6px 10px; font-size: 11px;">Подія</th>
                                    <th style="padding: 6px 10px; font-size: 11px;">Внутрішній IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Dynamic rows -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Edit Admin Modal Actions
    function openEditAdminModal(id, username, role) {
        document.getElementById('edit_admin_id').value = id;
        document.getElementById('edit_admin_username').value = username;
        document.getElementById('edit_admin_role').value = role;
        document.getElementById('editAdminModal').style.display = 'flex';
    }

    function closeEditAdminModal() {
        document.getElementById('editAdminModal').style.display = 'none';
    }

    // Registration Modal Actions
    function openRegisterModal(clientId) {
        document.getElementById('reg_client_id').value = clientId;
        document.getElementById('reg_client_code').value = clientId.substring(0, 3) + ' ' + clientId.substring(3);
        document.getElementById('registerModal').style.display = 'flex';
    }

    function closeRegisterModal() {
        document.getElementById('registerModal').style.display = 'none';
    }

    // Control Modal Actions
    let activeClientData = null;

    function openControlModal(client) {
        activeClientData = client;
        
        // Populate inputs
        const idElements = document.querySelectorAll('.ctrl_client_id_val');
        idElements.forEach(el => el.value = client.client_id);
        
        document.getElementById('ctrl_title').textContent = client.name;
        document.getElementById('ctrl_subtitle').textContent = client.enterprise + ' (ID: ' + client.client_id.substring(0, 3) + ' ' + client.client_id.substring(3) + ')';
        
        // Populate settings checkboxes
        document.getElementById('toggle_autostart').checked = parseInt(client.autostart) === 1;
        document.getElementById('toggle_autoconnect').checked = parseInt(client.autoconnect) === 1;
        document.getElementById('toggle_minimize_to_tray').checked = parseInt(client.minimize_to_tray) === 1;

        // Populate config textarea if saved
        document.querySelector('textarea[name="config_data"]').value = client.config || '';

        // Reset to first tab
        switchModalTab('control');

        document.getElementById('controlModal').style.display = 'flex';
    }

    function closeControlModal() {
        document.getElementById('controlModal').style.display = 'none';
    }

    function switchModalTab(tabName) {
        // Switch tab buttons style
        const buttons = document.querySelectorAll('.modal-tab-btn');
        buttons.forEach(btn => btn.classList.remove('active'));
        
        // Switch active content
        const contents = document.querySelectorAll('.modal-tab-content');
        contents.forEach(content => content.style.display = 'none');

        // Find active button
        let targetBtn = Array.from(buttons).find(btn => btn.getAttribute('onclick').includes(tabName));
        if (targetBtn) targetBtn.classList.add('active');

        document.getElementById('tab_' + tabName).style.display = 'block';

        if (tabName === 'stats' && activeClientData) {
            loadClientStats(activeClientData.client_id);
        }
    }

    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    function formatDuration(seconds) {
        if (!seconds || seconds <= 0) return '0 хв';
        const mins = Math.round(seconds / 60);
        if (mins < 60) {
            return mins + ' хв';
        }
        const hours = Math.floor(mins / 60);
        const remainingMins = mins % 60;
        return hours + ' год ' + remainingMins + ' хв';
    }

    async function loadClientStats(clientId) {
        const connBody = document.querySelector('#stats_connections_table tbody');
        const trafficBody = document.querySelector('#stats_traffic_table tbody');
        connBody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 10px;">Завантаження...</td></tr>';
        trafficBody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 10px;">Завантаження...</td></tr>';
        document.getElementById('stats_total_rx').textContent = '—';
        document.getElementById('stats_total_tx').textContent = '—';

        try {
            const response = await fetch('index.php?action=get_stats&client_id=' + clientId);
            if (!response.ok) throw new Error('Failed to load stats');
            const data = await response.json();
            
            if (data.status === 'ok') {
                // Connections History
                if (data.connections.length === 0) {
                    connBody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 10px;">Немає подій</td></tr>';
                } else {
                    connBody.innerHTML = data.connections.map(c => {
                        const date = new Date(c.created_at);
                        const dateStr = date.toLocaleDateString('uk-UA', {day: '2-digit', month: '2-digit'}) + ' ' + date.toLocaleTimeString('uk-UA', {hour: '2-digit', minute: '2-digit'});
                        let badgeClass = 'status-disconnected';
                        let badgeText = c.event_type;
                        if (c.event_type === 'connect') {
                            badgeClass = 'status-connected';
                            badgeText = 'VPN: Підключено';
                        } else if (c.event_type === 'disconnect') {
                            badgeClass = 'status-disconnected';
                            badgeText = 'VPN: Відключено';
                        } else if (c.event_type === 'pc_on') {
                            badgeClass = 'status-ready';
                            badgeText = 'ПК: Увімкнено';
                        } else if (c.event_type === 'pc_off') {
                            badgeClass = 'status-offline';
                            badgeText = 'ПК: Вимкнено';
                        }
                        return `<tr>
                            <td style="padding: 6px 10px;">${dateStr}</td>
                            <td style="padding: 6px 10px;"><span class="status-badge ${badgeClass}" style="font-size: 10px; padding: 2px 6px;">${badgeText}</span></td>
                            <td style="padding: 6px 10px; font-family: monospace;">${c.ip || '—'}</td>
                        </tr>`;
                    }).join('');
                }

                // Daily Traffic
                let totalRx = 0;
                let totalTx = 0;
                if (data.traffic.length === 0) {
                    trafficBody.innerHTML = '<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 10px;">Немає записів</td></tr>';
                } else {
                    trafficBody.innerHTML = data.traffic.map(t => {
                        const rx = parseInt(t.rx_bytes);
                        const tx = parseInt(t.tx_bytes);
                        const duration = parseInt(t.duration_seconds || 0);
                        const publicIp = t.public_ip || '—';
                        totalRx += rx;
                        totalTx += tx;
                        
                        const date = new Date(t.date);
                        const dateStr = date.toLocaleDateString('uk-UA', {day: '2-digit', month: '2-digit'});
                        
                        return `<tr>
                            <td style="padding: 6px 10px;">${dateStr}</td>
                            <td style="padding: 6px 10px; color: var(--text-color); font-weight: 500;">${formatDuration(duration)}</td>
                            <td style="padding: 6px 10px; color: var(--primary); font-weight: 500;">${formatBytes(rx)}</td>
                            <td style="padding: 6px 10px; color: var(--success); font-weight: 500;">${formatBytes(tx)}</td>
                            <td style="padding: 6px 10px; font-family: monospace; color: var(--text-muted);">${publicIp}</td>
                        </tr>`;
                    }).join('');
                }

                // Recent Commands
                const cmdBody = document.querySelector('#commands_table tbody');
                if (!data.commands || data.commands.length === 0) {
                    cmdBody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 10px;">Немає команд</td></tr>';
                } else {
                    cmdBody.innerHTML = data.commands.map(cmd => {
                        const date = new Date(cmd.created_at);
                        const dateStr = date.toLocaleTimeString('uk-UA', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
                        let badgeClass = 'status-offline'; // pending
                        let badgeText = 'В черзі';
                        if (cmd.status === 'sent') {
                            badgeClass = 'status-ready';
                            badgeText = 'Відправлено';
                        } else if (cmd.status === 'executed') {
                            badgeClass = 'status-connected';
                            badgeText = 'Виконано';
                        } else if (cmd.status === 'failed') {
                            badgeClass = 'status-disconnected';
                            badgeText = 'Помилка';
                        }
                        return `<tr>
                            <td style="padding: 6px 10px;">${dateStr}</td>
                            <td style="padding: 6px 10px;"><b>${cmd.command}</b></td>
                            <td style="padding: 6px 10px;"><span class="status-badge ${badgeClass}" style="font-size: 10px; padding: 2px 6px;">${badgeText}</span></td>
                        </tr>`;
                    }).join('');
                }

                document.getElementById('stats_total_rx').textContent = formatBytes(totalRx);
                document.getElementById('stats_total_tx').textContent = formatBytes(totalTx);
            }
        } catch (err) {
            connBody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: var(--danger); padding: 10px;">Помилка завантаження</td></tr>';
            trafficBody.innerHTML = '<tr><td colspan="3" style="text-align: center; color: var(--danger); padding: 10px;">Помилка завантаження</td></tr>';
        }
    }

    // Close Modals on click outside
    window.onclick = function(event) {
        const regModal = document.getElementById('registerModal');
        const ctrlModal = document.getElementById('controlModal');
        const editAdmModal = document.getElementById('editAdminModal');
        if (event.target === regModal) {
            closeRegisterModal();
        }
        if (event.target === ctrlModal) {
            closeControlModal();
        }
        if (event.target === editAdmModal) {
            closeEditAdminModal();
        }
    }
</script>
</body>
</html>
