<?php
/**
 * TMNT ALLIANCE TOOL - POWER & DECIMAL EDITION
 * Author: Gemini (For Daniel)
 * Version: 9.0
 */

session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- KONFIGURATION ---
define('DATA_DIR', __DIR__ . '/data');
define('FILE_USERS', DATA_DIR . '/users.json');
define('FILE_PLAYERS', DATA_DIR . '/players.json');
define('FILE_LOGS', DATA_DIR . '/logs.json');
date_default_timezone_set('Europe/Berlin');

// --- HILFSFUNKTIONEN ---
function loadJson($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?? [];
}

function saveJson($file, $data) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    if (!file_exists(DATA_DIR . '/.htaccess')) file_put_contents(DATA_DIR . '/.htaccess', "Deny from all");
    $fp = fopen($file, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function isLogged() { return isset($_SESSION['user']); }
function isAdmin() { return isset($_SESSION['user']) && $_SESSION['user']['role'] === 'Admin'; }

// ZAHLE UMWANDELN (Komma zu Punkt für PHP)
function toFloat($val) {
    if ($val === '') return 0;
    // Ersetze Komma durch Punkt, entferne Tausender-Punkte falls vorhanden (einfache Logik)
    $val = str_replace(',', '.', $val); 
    return floatval($val);
}

// Icon Zuweisung
function getIcon($t) { 
    $map = ['Panzer'=>'🛡️','Flugzeug'=>'✈️','Raketenwerfer'=>'🚀','Mischtrupp'=>'⚔️','Unbekannt'=>'❓'];
    return $map[$t] ?? '❓'; 
}

// --- LOGIK & ROUTING ---
$page = $_GET['p'] ?? 'dashboard';
$act = $_GET['act'] ?? '';
$msg = '';
$msgType = 'success';

// CHECK INSTALLATION
$isInstalled = file_exists(FILE_USERS);

if (!$isInstalled) {
    $page = 'install'; 
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $act === 'install') {
        $adminUser = trim($_POST['admin_user']);
        $adminPass = trim($_POST['admin_pass']);
        if ($adminUser && $adminPass) {
            if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
            saveJson(FILE_USERS, [['id'=>1, 'username'=>$adminUser, 'password'=>$adminPass, 'role'=>'Admin']]);
            saveJson(FILE_PLAYERS, [
                ['id'=>'101', 'name'=>'Donatello', 'rank'=>'R4', 'squad'=>'Mischtrupp', 'status'=>'Active'],
                ['id'=>'102', 'name'=>'Leonardo', 'rank'=>'R5', 'squad'=>'Panzer', 'status'=>'Active']
            ]);
            saveJson(FILE_LOGS, []);
            $_SESSION['user'] = ['username'=>$adminUser, 'role'=>'Admin'];
            header("Location: " . $_SERVER['PHP_SELF']); exit;
        } else { $msg = "Bitte alle Felder ausfüllen!"; $msgType = 'danger'; }
    }
}

if ($isInstalled) {
    if ($page === 'logout') { session_destroy(); header("Location: " . $_SERVER['PHP_SELF']); exit; }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Login
        if ($act === 'login') {
            $users = loadJson(FILE_USERS); $found = false;
            foreach ($users as $u) {
                if ($u['username'] === $_POST['user'] && $u['password'] === $_POST['pass']) {
                    $_SESSION['user'] = $u; $found = true; header("Location: " . $_SERVER['PHP_SELF']); exit;
                }
            }
            if (!$found) { $msg = "Falsche Daten!"; $msgType = 'danger'; $page = 'login'; }
        }

        // SPEICHERN (Jetzt mit Float Support)
        if ($act === 'save_stats' && isLogged()) {
            $logs = loadJson(FILE_LOGS);
            $players = loadJson(FILE_PLAYERS);
            $ts = $_POST['ts'];
            $count = 0;
            foreach ($players as $p) {
                $pid = $p['id'];
                $pow_raw = $_POST["pow_$pid"] ?? '';
                $sq1_raw = $_POST["sq1_$pid"] ?? '';
                
                $eb = isset($_POST["eb_$pid"]); $cap = isset($_POST["cap_$pid"]);
                
                // Speichern wenn Power gefüllt oder Events
                if ($pow_raw !== '' || $eb || $cap) {
                    $logs[] = [
                        'id' => uniqid(), 
                        'ts' => $ts, 
                        'p_id' => $pid, 
                        'p_name' => $p['name'],
                        'rec' => $_SESSION['user']['username'],
                        // Hier nutzen wir die neue toFloat Funktion
                        'pow' => toFloat($pow_raw), 
                        'sq1' => toFloat($sq1_raw),
                        'vs' => (int)($_POST["vs_$pid"]??0), 
                        'tech' => (int)($_POST["tech_$pid"]??0),
                        'eb' => $eb, 
                        'cap' => $cap
                    ];
                    $count++;
                }
            }
            saveJson(FILE_LOGS, $logs);
            $msg = "$count Einträge gespeichert!";
        }
        
        // Settings Actions
        if ($act === 'add_user' && isAdmin()) {
            $users = loadJson(FILE_USERS);
            $users[] = ['id'=>time(), 'username'=>$_POST['new_user'], 'password'=>$_POST['new_pass'], 'role'=>$_POST['new_role']];
            saveJson(FILE_USERS, $users);
        }
        if ($act === 'del_user' && isAdmin()) {
            $users = array_values(array_filter(loadJson(FILE_USERS), function($u){ return $u['username'] !== $_POST['target']; }));
            saveJson(FILE_USERS, $users);
        }
        if ($act === 'add_player' && isAdmin()) {
            $pl = loadJson(FILE_PLAYERS);
            $pl[] = ['id'=>uniqid(), 'name'=>$_POST['p_name'], 'rank'=>$_POST['p_rank'], 'squad'=>$_POST['p_squad'], 'status'=>'Active'];
            saveJson(FILE_PLAYERS, $pl);
        }
        if ($act === 'del_player' && isAdmin()) {
            $pl = array_values(array_filter(loadJson(FILE_PLAYERS), function($p){ return $p['id'] !== $_POST['target']; }));
            saveJson(FILE_PLAYERS, $pl);
        }
    }
    
    // Export
    if ($page === 'export' && isLogged()) {
        $logs = loadJson(FILE_LOGS);
        header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="tmnt_export.csv"');
        $out = fopen('php://output', 'w'); 
        fputcsv($out, ['Zeitstempel','Spieler','Gesamtstaerke','Squad 1','VS Punkte','Forschung','Enemy Buster','Kapitol','Erfasser']);
        // Beim Export Komma statt Punkt für Excel-Kompatibilität in DE
        foreach($logs as $l) {
            $pow_ex = str_replace('.', ',', $l['pow']);
            $sq1_ex = str_replace('.', ',', $l['sq1']);
            fputcsv($out, [$l['ts'],$l['p_name'],$pow_ex,$sq1_ex,$l['vs'],$l['tech'],$l['eb']?'1':'0',$l['cap']?'1':'0',$l['rec']]);
        }
        fclose($out); exit;
    }

    if (!isLogged()) $page = 'login';
}
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TMNT Command</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #121212; color: #e0e0e0; font-family: sans-serif; padding-bottom: 80px; }
        .tmnt-green { color: #00ff41; font-weight: bold; }
        .card { background: #1e1e1e; border: 1px solid #333; }
        .form-control, .form-select { background: #2b2b2b; border: 1px solid #444; color: #fff; }
        .form-control:focus { background: #333; color: #fff; border-color: #00ff41; }
        .nav-tabs .nav-link.active { background: #1e1e1e; color: #00ff41; border-color: #444 #444 #1e1e1e; }
        .sticky-save { position: fixed; bottom: 0; left: 0; right: 0; padding: 10px; background: #121212; border-top: 1px solid #00ff41; z-index: 99; }
    </style>
</head>
<body>

<?php if($page !== 'login' && $page !== 'install'): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary mb-4">
    <div class="container-fluid">
        <a class="navbar-brand tmnt-green" href="?p=dashboard">🐢 TMNT</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="?p=dashboard">📝 Input</a></li>
                <li class="nav-item"><a class="nav-link" href="?p=report">📊 Report</a></li>
                <?php if(isAdmin()): ?><li class="nav-item"><a class="nav-link text-warning" href="?p=settings">⚙️ Settings</a></li><?php endif; ?>
                <li class="nav-item"><a class="nav-link text-danger" href="?p=logout">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>

<div class="container">
    <?php if($msg): ?><div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <?php if ($page === 'install'): ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-6">
            <div class="card p-4 border-success">
                <h2 class="text-center tmnt-green mb-3">🚀 TMNT SETUP</h2>
                <?php if(!is_writable(__DIR__)): ?><div class="alert alert-danger">⚠️ <strong>Schreibrechte fehlen!</strong> (CHMOD 777)</div><?php endif; ?>
                <form method="POST" action="?p=install&act=install">
                    <div class="mb-3"><label>Admin Username</label><input type="text" name="admin_user" class="form-control" required></div>
                    <div class="mb-3"><label>Admin Passwort</label><input type="password" name="admin_pass" class="form-control" required></div>
                    <button class="btn btn-success w-100 fw-bold btn-lg">INSTALLIEREN</button>
                </form>
            </div>
        </div>
    </div>

    <?php elseif($page === 'login'): ?>
    <div class="row justify-content-center mt-5">
        <div class="col-md-4">
            <div class="card p-4">
                <h3 class="text-center tmnt-green mb-3">ZUGANGSKONTROLLE</h3>
                <form method="POST" action="?p=login&act=login">
                    <div class="mb-3"><label>Benutzer</label><input type="text" name="user" class="form-control" required></div>
                    <div class="mb-3"><label>Passwort</label><input type="password" name="pass" class="form-control" required></div>
                    <button class="btn btn-success w-100 fw-bold">LOGIN</button>
                </form>
            </div>
        </div>
    </div>

    <?php elseif($page === 'dashboard'): 
        $pl = loadJson(FILE_PLAYERS);
        usort($pl, function($a, $b) { return strcmp($b['rank'], $a['rank']) ?: strcmp($a['name'], $b['name']); });
    ?>
    <form method="POST" action="?p=dashboard&act=save_stats">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="m-0">Datenerfassung</h4>
            <input type="datetime-local" name="ts" value="<?= date('Y-m-d\TH:i') ?>" class="form-control w-auto form-control-sm">
        </div>
        <ul class="nav nav-tabs mb-3" id="inputTab">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#t1" type="button">💪 Stärke</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t2" type="button">📅 Daily</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#t3" type="button">⚔️ Krieg</button></li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane fade show active" id="t1">
                <div class="table-responsive"><table class="table table-dark table-sm align-middle">
                    <thead><tr><th>Name</th><th>Gesamtstärke (Mio)</th><th>Squad 1 (Mio)</th></tr></thead><tbody>
                    <?php foreach($pl as $p): ?><tr><td><span class="badge bg-secondary"><?= $p['rank']?></span> <?= $p['name'] ?> <small><?= getIcon($p['squad'])?></small></td>
                    <td><input type="number" step="0.01" name="pow_<?= $p['id'] ?>" class="form-control form-control-sm input-mini" placeholder="z.B. 41,3"></td>
                    <td><input type="number" step="0.01" name="sq1_<?= $p['id'] ?>" class="form-control form-control-sm input-mini" placeholder="z.B. 8,5"></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
            <div class="tab-pane fade" id="t2">
                <div class="table-responsive"><table class="table table-dark table-sm align-middle">
                    <thead><tr><th>Name</th><th>VS Punkte</th><th>Forschung</th></tr></thead><tbody>
                    <?php foreach($pl as $p): ?><tr><td><?= $p['name'] ?></td>
                    <td><input type="number" name="vs_<?= $p['id'] ?>" class="form-control form-control-sm input-mini"></td>
                    <td><input type="number" name="tech_<?= $p['id'] ?>" class="form-control form-control-sm input-mini"></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div>
            <div class="tab-pane fade" id="t3">
                <div class="row g-2"><?php foreach($pl as $p): ?><div class="col-6 col-md-3"><div class="card p-2"><div class="fw-bold"><?= $p['name'] ?></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="eb_<?= $p['id'] ?>"><label class="form-check-label">🧟 Buster</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="cap_<?= $p['id'] ?>"><label class="form-check-label">🏛️ Kapitol</label></div>
                </div></div><?php endforeach; ?></div>
            </div>
        </div>
        <div class="sticky-save"><button class="btn btn-success w-100 fw-bold">💾 SPEICHERN</button></div>
    </form>

    <?php elseif($page === 'report'): 
        $logs = loadJson(FILE_LOGS); $cw = date('W'); 
        $stats = []; // Array: [Name => ['vs'=>sum, 'pow'=>last_val, 'sq1'=>last_val, 'ts_pow'=>timestamp]]
        
        foreach($logs as $l) { 
            if(date('W', strtotime($l['ts'])) == $cw) { 
                $n = $l['p_name']; 
                if(!isset($stats[$n])) $stats[$n] = ['vs'=>0, 'pow'=>0, 'sq1'=>0, 'ts_pow'=>''];
                
                // VS summieren
                $stats[$n]['vs'] += $l['vs'];
                
                // Power & Squad: Nur den aktuellsten Wert der Woche nehmen
                if($l['pow'] > 0 && $l['ts'] >= $stats[$n]['ts_pow']) {
                    $stats[$n]['pow'] = $l['pow'];
                    $stats[$n]['sq1'] = $l['sq1'];
                    $stats[$n]['ts_pow'] = $l['ts'];
                }
            } 
        }
        // Sortieren nach VS (Standard)
        uasort($stats, function($a, $b) { return $b['vs'] <=> $a['vs']; });
        
        $topN = array_slice(array_keys($stats),0,5); 
        $topV = array_slice(array_column($stats, 'vs'),0,5);
    ?>
    <div class="d-flex justify-content-between mb-3"><h3>Wochenbericht KW <?= $cw ?></h3><a href="?p=export" class="btn btn-outline-success btn-sm">CSV Export</a></div>
    
    <div class="card p-3 mb-3"><canvas id="vsChart" style="max-height:250px"></canvas></div>
    
    <div class="table-responsive">
        <table class="table table-dark table-striped">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th class="text-end">Gesamtstärke</th>
                    <th class="text-end">Squad 1</th>
                    <th class="text-end text-success">VS Gesamt</th>
                </tr>
            </thead>
            <tbody>
            <?php $i=1; foreach($stats as $n=>$data): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $n ?></td>
                    <td class="text-end"><?= number_format($data['pow'], 2, ',', '.') ?> Mio</td>
                    <td class="text-end"><?= number_format($data['sq1'], 2, ',', '.') ?> Mio</td>
                    <td class="text-end text-success fw-bold"><?= number_format($data['vs'], 0, ',', '.') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <script>new Chart(document.getElementById('vsChart'),{type:'bar',data:{labels:<?=json_encode($topN)?>,datasets:[{label:'Punkte',data:<?=json_encode($topV)?>,backgroundColor:'#00ff41'}]},options:{plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#fff'}},y:{ticks:{color:'#fff'}}}}});</script>

    <?php elseif($page === 'settings' && isAdmin()): $us=loadJson(FILE_USERS); $pl=loadJson(FILE_PLAYERS); ?>
    <h3>⚙️ Einstellungen</h3>
    <div class="card p-3 mb-4"><h5>Benutzerverwaltung</h5>
        <table class="table table-dark table-sm"><thead><tr><th>Benutzer</th><th>Rolle</th><th>Löschen</th></tr></thead><tbody>
        <?php foreach($us as $u): ?><tr><td><?= $u['username'] ?></td><td><?= $u['role'] ?></td><td><?php if($u['username']!==$_SESSION['user']['username']): ?>
        <form method="POST" action="?p=settings&act=del_user" class="d-inline"><input type="hidden" name="target" value="<?= $u['username'] ?>"><button class="btn btn-danger btn-sm p-0 px-2">X</button></form>
        <?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
        <form method="POST" action="?p=settings&act=add_user" class="row g-2"><div class="col-5"><input type="text" name="new_user" class="form-control form-control-sm" placeholder="Name"></div>
        <div class="col-4"><input type="text" name="new_pass" class="form-control form-control-sm" placeholder="Passwort"></div>
        <div class="col-2"><select name="new_role" class="form-select form-select-sm"><option>Officer</option><option>Admin</option></select></div>
        <div class="col-1"><button class="btn btn-success btn-sm w-100">+</button></div></form>
    </div>
    <div class="card p-3"><h5>Mitgliederverwaltung</h5>
        <div style="max-height:300px;overflow-y:auto;"><table class="table table-dark table-sm"><thead><tr><th>Name</th><th>Rang</th><th>Squad</th><th>Löschen</th></tr></thead><tbody>
        <?php foreach($pl as $p): ?><tr><td><?= $p['name'] ?></td><td><?= $p['rank'] ?></td><td><?= getIcon($p['squad']) ?> <?= $p['squad'] ?></td><td>
        <form method="POST" action="?p=settings&act=del_player" class="d-inline"><input type="hidden" name="target" value="<?= $p['id'] ?>"><button class="btn btn-outline-danger btn-sm p-0 px-2">X</button></form>
        </td></tr><?php endforeach; ?></tbody></table></div>
        <form method="POST" action="?p=settings&act=add_player" class="row g-2 mt-2"><div class="col-4"><input type="text" name="p_name" class="form-control form-control-sm" placeholder="Name"></div>
        <div class="col-2"><select name="p_rank" class="form-select form-select-sm"><option>R4</option><option>R1</option><option>R2</option><option>R3</option><option>R5</option></select></div>
        <div class="col-4"><select name="p_squad" class="form-select form-select-sm"><option value="Panzer">Panzer</option><option value="Flugzeug">Flugzeug</option><option value="Raketenwerfer">Raketenwerfer</option><option value="Mischtrupp">Mischtrupp</option><option value="Unbekannt">Unbekannt</option></select></div>
        <div class="col-2"><button class="btn btn-success btn-sm w-100">+</button></div></form>
    </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
