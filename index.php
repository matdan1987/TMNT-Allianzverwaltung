<?php
/**
 * TMNT Allianz Management System - All-in-One Version
 * Alle Funktionen in einer einzigen index.php
 * Version 2.0
 */

// ============================================================================
// KONFIGURATION
// ============================================================================

session_start();
date_default_timezone_set('Europe/Berlin');

// Konstanten
define('ALLIANCE_NAME', 'TMNT');
define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('MEMBERS_FILE', DATA_DIR . '/members.json');
define('COMBAT_POWER_FILE', DATA_DIR . '/combat_power.json');
define('POINTS_FILE', DATA_DIR . '/points.json');
define('DONATIONS_FILE', DATA_DIR . '/donations.json');

// Rollen
define('ROLE_ADMIN', 'admin');
define('ROLE_OFFICER', 'officer');
define('ROLE_MEMBER', 'member');

// Ingame-Ränge
define('RANKS', ['R1', 'R2', 'R3', 'R4', 'R5']);

// Squad-Typen
define('SQUAD_TANK', 'tank');
define('SQUAD_AIRCRAFT', 'aircraft');
define('SQUAD_ROCKET', 'rocket');
define('SQUAD_UNKNOWN', 'unknown');
define('SQUAD_TYPES', [
    SQUAD_TANK => '🚜 Panzer',
    SQUAD_AIRCRAFT => '✈️ Flugzeuge',
    SQUAD_ROCKET => '🚀 Raketenwerfer',
    SQUAD_UNKNOWN => '❓ Unbekannt'
]);

// Datenverzeichnis erstellen
if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

// ============================================================================
// HILFSFUNKTIONEN
// ============================================================================

function loadJSON($file) {
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    return json_decode($content, true) ?: [];
}

function saveJSON($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    $users = loadJSON(USERS_FILE);
    return $users[$_SESSION['user_id']] ?? null;
}

function hasPermission($requiredRole) {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    $roleHierarchy = [
        ROLE_ADMIN => 3,
        ROLE_OFFICER => 2,
        ROLE_MEMBER => 1
    ];
    
    return ($roleHierarchy[$user['role']] ?? 0) >= ($roleHierarchy[$requiredRole] ?? 999);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ?page=login');
        exit;
    }
}

function requirePermission($role) {
    requireLogin();
    if (!hasPermission($role)) {
        die('Keine Berechtigung!');
    }
}

function initializeSystem() {
    $users = loadJSON(USERS_FILE);
    if (empty($users)) {
        $users = [
            'admin' => [
                'username' => 'admin',
                'password' => password_hash('admin123', PASSWORD_DEFAULT),
                'role' => ROLE_ADMIN,
                'created' => date('Y-m-d H:i:s')
            ]
        ];
        saveJSON(USERS_FILE, $users);
    }
}

initializeSystem();

// ============================================================================
// ROUTING
// ============================================================================

$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

// Logout-Handler
if ($page === 'logout') {
    session_destroy();
    header('Location: ?page=login');
    exit;
}

// Login-Check (außer für Login-Seite)
if ($page !== 'login' && !isLoggedIn()) {
    $page = 'login';
}

// ============================================================================
// CONTROLLER / LOGIC
// ============================================================================

$success = '';
$error = '';
$data = [];

// Login Logic
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $users = loadJSON(USERS_FILE);
    
    if (isset($users[$username]) && password_verify($password, $users[$username]['password'])) {
        $_SESSION['user_id'] = $username;
        header('Location: ?page=dashboard');
        exit;
    } else {
        $error = 'Ungültiger Benutzername oder Passwort';
    }
}

// Dashboard Data
if ($page === 'dashboard' && isLoggedIn()) {
    $members = loadJSON(MEMBERS_FILE);
    $combatPower = loadJSON(COMBAT_POWER_FILE);
    $points = loadJSON(POINTS_FILE);
    $donations = loadJSON(DONATIONS_FILE);
    
    $data['total_members'] = count($members);
    $data['total_combat_power'] = array_sum(array_column($combatPower, 'total_power'));
    
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $weekEnd = date('Y-m-d', strtotime('sunday this week'));
    $today = date('Y-m-d');
    
    $data['week_points'] = 0;
    foreach ($points as $entry) {
        if ($entry['date'] >= $weekStart && $entry['date'] <= $weekEnd) {
            $data['week_points'] += $entry['total_points'] ?? 0;
        }
    }
    
    $data['today_donations'] = 0;
    foreach ($donations as $entry) {
        if ($entry['date'] === $today) {
            $data['today_donations'] += $entry['amount'] ?? 0;
        }
    }
}

// Members Logic
if ($page === 'members' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission(ROLE_OFFICER);
    
    if ($_POST['action'] === 'add') {
        $name = trim($_POST['name'] ?? '');
        $ingame_id = trim($_POST['ingame_id'] ?? '');
        $rank = $_POST['rank'] ?? 'R1';
        $mentor = trim($_POST['mentor'] ?? '');
        $squad_type = $_POST['squad_type'] ?? SQUAD_UNKNOWN;
        $enemy_buster = isset($_POST['enemy_buster']) ? 1 : 0;
        $capitol_fight = isset($_POST['capitol_fight']) ? 1 : 0;
        $absence_status = isset($_POST['absence_status']) ? 1 : 0;
        
        if ($name && $ingame_id) {
            $members = loadJSON(MEMBERS_FILE);
            $id = uniqid('member_');
            $members[$id] = [
                'id' => $id,
                'name' => $name,
                'ingame_id' => $ingame_id,
                'rank' => $rank,
                'mentor' => $mentor,
                'squad_type' => $squad_type,
                'enemy_buster' => $enemy_buster,
                'capitol_fight' => $capitol_fight,
                'absence_status' => $absence_status,
                'active' => true,
                'created' => date('Y-m-d H:i:s')
            ];
            saveJSON(MEMBERS_FILE, $members);
            $success = 'Mitglied erfolgreich hinzugefügt!';
        } else {
            $error = 'Bitte alle Pflichtfelder ausfüllen!';
        }
    }
}

if ($page === 'members' && $action === 'delete' && hasPermission(ROLE_ADMIN)) {
    $members = loadJSON(MEMBERS_FILE);
    if (isset($members[$_GET['id']])) {
        unset($members[$_GET['id']]);
        saveJSON(MEMBERS_FILE, $members);
        $success = 'Mitglied gelöscht!';
    }
}

if ($page === 'members' && $action === 'toggle' && hasPermission(ROLE_ADMIN)) {
    $members = loadJSON(MEMBERS_FILE);
    if (isset($members[$_GET['id']])) {
        $members[$_GET['id']]['active'] = !($members[$_GET['id']]['active'] ?? false);
        saveJSON(MEMBERS_FILE, $members);
        $success = 'Status geändert!';
    }
}

// Quick Entry Logic
if ($page === 'quick_entry' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePermission(ROLE_OFFICER);
    
    $type = $_POST['type'] ?? 'combat';
    $bulkData = $_POST['bulk_data'] ?? [];
    $savedCount = 0;
    
    if ($type === 'combat') {
        $combatPower = loadJSON(COMBAT_POWER_FILE);
        foreach ($bulkData as $memberId => $values) {
            if (!empty($values['total_power']) && !empty($values['squad_power'])) {
                $combatPower[uniqid('cp_')] = [
                    'id' => uniqid('cp_'),
                    'member_id' => $memberId,
                    'total_power' => floatval($values['total_power']),
                    'first_squad_power' => floatval($values['squad_power']),
                    'date' => date('Y-m-d'),
                    'time' => date('H:i'),
                    'datetime' => date('Y-m-d H:i'),
                    'recorded_by' => $_SESSION['user_id'],
                    'recorded_at' => date('Y-m-d H:i:s')
                ];
                $savedCount++;
            }
        }
        saveJSON(COMBAT_POWER_FILE, $combatPower);
    } elseif ($type === 'points') {
        $points = loadJSON(POINTS_FILE);
        $isVsDay = isset($_POST['is_vs_day']) ? 1 : 0;
        foreach ($bulkData as $memberId => $values) {
            if (!empty($values['vs_points']) || !empty($values['total_points'])) {
                $points[uniqid('pt_')] = [
                    'id' => uniqid('pt_'),
                    'member_id' => $memberId,
                    'vs_points' => floatval($values['vs_points'] ?? 0),
                    'total_points' => floatval($values['total_points'] ?? 0),
                    'is_vs_day' => $isVsDay,
                    'date' => date('Y-m-d'),
                    'time' => date('H:i'),
                    'datetime' => date('Y-m-d H:i'),
                    'week' => date('Y-W'),
                    'recorded_by' => $_SESSION['user_id'],
                    'recorded_at' => date('Y-m-d H:i:s')
                ];
                $savedCount++;
            }
        }
        saveJSON(POINTS_FILE, $points);
    } elseif ($type === 'donations') {
        $donations = loadJSON(DONATIONS_FILE);
        foreach ($bulkData as $memberId => $values) {
            if (!empty($values['amount'])) {
                $donations[uniqid('don_')] = [
                    'id' => uniqid('don_'),
                    'member_id' => $memberId,
                    'amount' => floatval($values['amount']),
                    'date' => date('Y-m-d'),
                    'time' => date('H:i'),
                    'datetime' => date('Y-m-d H:i'),
                    'week' => date('Y-W'),
                    'recorded_by' => $_SESSION['user_id'],
                    'recorded_at' => date('Y-m-d H:i:s')
                ];
                $savedCount++;
            }
        }
        saveJSON(DONATIONS_FILE, $donations);
    }
    
    $success = "$savedCount Einträge gespeichert!";
}

// ============================================================================
// VIEW / HTML
// ============================================================================

$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ALLIANCE_NAME ?> - Allianz Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            font-size: 14px;
        }
        
        .header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 15px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h1 {
            font-size: 24px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .logout-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 30px;
        }
        
        .nav-menu {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .nav-menu ul {
            list-style: none;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .nav-menu a {
            display: block;
            padding: 10px 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .nav-menu a:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .card h2 {
            color: #059669;
            margin-bottom: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
        }
        
        .stat-card .label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #10b981;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }
        
        .btn {
            padding: 12px 30px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
        }
        
        .success {
            background: #d1fae5;
            color: #065f46;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #10b981;
        }
        
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        table th {
            background: #f8f9fa;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e0e0e0;
        }
        
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        table tr:hover {
            background: #f8f9fa;
        }
        
        .login-container {
            max-width: 400px;
            margin: 100px auto;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo h1 {
            color: #10b981;
            font-size: 28px;
        }
        
        /* Quick Entry Styles */
        .member-row {
            display: grid;
            grid-template-columns: 30px 1fr 150px 150px;
            gap: 10px;
            padding: 10px;
            border-bottom: 1px solid #e0e0e0;
            align-items: center;
        }
        
        .member-row:hover {
            background: #f8f9fa;
        }
        
        .member-row.header {
            font-weight: 600;
            background: #f0f4ff;
            border-radius: 10px 10px 0 0;
        }
        
        input[type="number"] {
            padding: 8px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
        }
        
        input[type="number"]:focus {
            border-color: #10b981;
            outline: none;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 10px;
            }
            
            .nav-menu ul {
                flex-direction: column;
            }
            
            .member-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<?php if ($page === 'login'): ?>
    <!-- LOGIN PAGE -->
    <div class="login-container">
        <div class="logo">
            <h1>🐢 <?= ALLIANCE_NAME ?></h1>
            <p>Allianz Management System</p>
        </div>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Benutzername</label>
                <input type="text" name="username" required autofocus>
            </div>
            
            <div class="form-group">
                <label>Passwort</label>
                <input type="password" name="password" required>
            </div>
            
            <button type="submit" class="btn" style="width: 100%;">Anmelden</button>
        </form>
        
        <div style="margin-top: 20px; padding: 15px; background: #f0f4ff; border-radius: 10px; font-size: 13px;">
            <strong>Standard-Login:</strong><br>
            Benutzername: admin<br>
            Passwort: admin123
        </div>
    </div>

<?php else: ?>
    <!-- MAIN LAYOUT -->
    <div class="header">
        <div class="header-content">
            <h1>🐢 <?= ALLIANCE_NAME ?> - Allianz Management</h1>
            <div class="user-info">
                <span><?= htmlspecialchars($currentUser['username']) ?> (<?= htmlspecialchars($currentUser['role']) ?>)</span>
                <a href="?page=logout" class="logout-btn">Abmelden</a>
            </div>
        </div>
    </div>
    
    <div class="container">
        <div class="nav-menu">
            <ul>
                <li><a href="?page=dashboard">📊 Dashboard</a></li>
                <li><a href="?page=members">👥 Mitglieder</a></li>
                <li><a href="?page=quick_entry">⚡ Schnelleingabe</a></li>
                <?php if (hasPermission(ROLE_ADMIN)): ?>
                    <li><a href="?page=users">🔧 Benutzer</a></li>
                <?php endif; ?>
            </ul>
        </div>
        
        <?php if ($success): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- PAGES -->
        <?php if ($page === 'dashboard'): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="label">Mitglieder</div>
                    <div class="value"><?= $data['total_members'] ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Gesamt-Kampfkraft</div>
                    <div class="value"><?= number_format($data['total_combat_power'], 0, ',', '.') ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Punkte diese Woche</div>
                    <div class="value"><?= number_format($data['week_points'], 0, ',', '.') ?></div>
                </div>
                <div class="stat-card">
                    <div class="label">Spenden heute</div>
                    <div class="value"><?= number_format($data['today_donations'], 0, ',', '.') ?></div>
                </div>
            </div>
            
            <div class="card">
                <h2>Willkommen, <?= htmlspecialchars($currentUser['username']) ?>!</h2>
                <p>Nutze das Menü oben für Navigation.</p>
                <p><strong>🚀 Tipp:</strong> Verwende die Schnelleingabe für super-schnelle Datenerfassung!</p>
            </div>
        
        <?php elseif ($page === 'members'): ?>
            <?php requirePermission(ROLE_OFFICER); ?>
            
            <div class="card">
                <h2>Neues Mitglied hinzufügen</h2>
                
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="form-group">
                        <label>Spielername *</label>
                        <input type="text" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Ingame-ID *</label>
                        <input type="text" name="ingame_id" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Rang</label>
                        <select name="rank">
                            <?php foreach (RANKS as $rank): ?>
                                <option value="<?= $rank ?>"><?= $rank ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Mentor</label>
                        <input type="text" name="mentor" placeholder="Optional">
                    </div>
                    
                    <div class="form-group">
                        <label>Squad-Typ</label>
                        <select name="squad_type">
                            <?php foreach (SQUAD_TYPES as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="enemy_buster" id="enemy_buster">
                        <label for="enemy_buster">Enemy Buster</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="capitol_fight" id="capitol_fight">
                        <label for="capitol_fight">Kapitolfight</label>
                    </div>
                    
                    <div class="checkbox-group">
                        <input type="checkbox" name="absence_status" id="absence_status">
                        <label for="absence_status">Abwesend</label>
                    </div>
                    
                    <button type="submit" class="btn">Mitglied hinzufügen</button>
                </form>
            </div>
            
            <div class="card">
                <h2>Mitgliederliste</h2>
                <?php
                $members = loadJSON(MEMBERS_FILE);
                usort($members, function($a, $b) {
                    return strcmp($a['name'], $b['name']);
                });
                ?>
                
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>ID</th>
                            <th>Rang</th>
                            <th>Squad</th>
                            <th>Status</th>
                            <?php if (hasPermission(ROLE_ADMIN)): ?>
                                <th>Aktionen</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $member): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($member['name']) ?></strong></td>
                                <td><?= htmlspecialchars($member['ingame_id']) ?></td>
                                <td><?= $member['rank'] ?? 'R1' ?></td>
                                <td><?= SQUAD_TYPES[$member['squad_type'] ?? SQUAD_UNKNOWN] ?></td>
                                <td><?= ($member['active'] ?? true) ? '🟢 Aktiv' : '🔴 Inaktiv' ?></td>
                                <?php if (hasPermission(ROLE_ADMIN)): ?>
                                    <td>
                                        <a href="?page=members&action=toggle&id=<?= $member['id'] ?>">Toggle</a> |
                                        <a href="?page=members&action=delete&id=<?= $member['id'] ?>" onclick="return confirm('Wirklich löschen?')">Löschen</a>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        
        <?php elseif ($page === 'quick_entry'): ?>
            <?php
            requirePermission(ROLE_OFFICER);
            $type = $_GET['type'] ?? 'combat';
            $members = loadJSON(MEMBERS_FILE);
            $activeMembers = array_filter($members, function($m) {
                return ($m['active'] ?? true) && !($m['absence_status'] ?? false);
            });
            usort($activeMembers, function($a, $b) {
                return strcmp($a['name'], $b['name']);
            });
            ?>
            
            <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                <a href="?page=quick_entry&type=combat" class="btn" style="text-decoration: none;">⚔️ Kampfkraft</a>
                <a href="?page=quick_entry&type=points" class="btn" style="text-decoration: none;">🏆 Punkte</a>
                <a href="?page=quick_entry&type=donations" class="btn" style="text-decoration: none;">💰 Spenden</a>
            </div>
            
            <div class="card">
                <h2>🚀 Schnelleingabe - <?= ['combat' => 'Kampfkraft', 'points' => 'Punkte', 'donations' => 'Spenden'][$type] ?></h2>
                <p><strong>Aktive Mitglieder:</strong> <?= count($activeMembers) ?></p>
                <p>Nutze Tab-Taste zum Durchspringen. Enter springt zum nächsten Feld.</p>
                
                <form method="POST">
                    <input type="hidden" name="type" value="<?= $type ?>">
                    
                    <?php if ($type === 'points'): ?>
                        <div class="checkbox-group" style="margin: 20px 0; padding: 15px; background: #f0f4ff; border-radius: 10px;">
                            <input type="checkbox" name="is_vs_day" id="is_vs_day" checked>
                            <label for="is_vs_day"><strong>Dies ist ein VS-Tag</strong></label>
                        </div>
                    <?php endif; ?>
                    
                    <div class="member-row header">
                        <div>#</div>
                        <div>Mitglied</div>
                        <?php if ($type === 'combat'): ?>
                            <div>Gesamtkampfkraft</div>
                            <div>1. Squad</div>
                        <?php elseif ($type === 'points'): ?>
                            <div>VS-Punkte</div>
                            <div>Gesamtpunkte</div>
                        <?php elseif ($type === 'donations'): ?>
                            <div>Spende</div>
                            <div></div>
                        <?php endif; ?>
                    </div>
                    
                    <?php foreach ($activeMembers as $index => $member): ?>
                        <div class="member-row">
                            <div><?= $index + 1 ?></div>
                            <div>
                                <?= SQUAD_TYPES[$member['squad_type'] ?? SQUAD_UNKNOWN] ?>
                                <strong><?= htmlspecialchars($member['name']) ?></strong>
                                <small style="color: #666;"><?= $member['rank'] ?? 'R1' ?></small>
                            </div>
                            
                            <?php if ($type === 'combat'): ?>
                                <div>
                                    <input type="number" name="bulk_data[<?= $member['id'] ?>][total_power]" tabindex="<?= ($index * 2) + 1 ?>">
                                </div>
                                <div>
                                    <input type="number" name="bulk_data[<?= $member['id'] ?>][squad_power]" tabindex="<?= ($index * 2) + 2 ?>">
                                </div>
                            <?php elseif ($type === 'points'): ?>
                                <div>
                                    <input type="number" name="bulk_data[<?= $member['id'] ?>][vs_points]" tabindex="<?= ($index * 2) + 1 ?>">
                                </div>
                                <div>
                                    <input type="number" name="bulk_data[<?= $member['id'] ?>][total_points]" tabindex="<?= ($index * 2) + 2 ?>">
                                </div>
                            <?php elseif ($type === 'donations'): ?>
                                <div>
                                    <input type="number" name="bulk_data[<?= $member['id'] ?>][amount]" tabindex="<?= $index + 1 ?>">
                                </div>
                                <div></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <button type="submit" class="btn" style="width: 100%; margin-top: 20px;">💾 Alle speichern</button>
                </form>
            </div>
            
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const firstInput = document.querySelector('input[type="number"]');
                    if (firstInput) firstInput.focus();
                    
                    document.querySelectorAll('input[type="number"]').forEach(input => {
                        input.addEventListener('keypress', function(e) {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                const tabindex = parseInt(this.getAttribute('tabindex'));
                                const nextInput = document.querySelector(`[tabindex="${tabindex + 1}"]`);
                                if (nextInput) nextInput.focus();
                            }
                        });
                    });
                });
            </script>
        
        <?php endif; ?>
    </div>
<?php endif; ?>

</body>
</html>
