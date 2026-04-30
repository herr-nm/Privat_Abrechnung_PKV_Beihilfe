<?php
/**
 * PKV & Beihilfe Tracker V27 - Dynamische Terminüberweisung-Farbe
 */

// --- .ENV PARSER ---
$config = [
    'APP_NAME' => 'PKV Tracker 2026',
    'GIT_REPO' => 'https://github.com/dein-repo',
    'APP_LOGO' => 'logo.png' 
];

$PROFILES = [];
if (file_exists('.env')) {
    $lines = file('.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]); $value = trim($parts[1]);
        if (strpos($key, 'PERSON_') === 0) {
            $p = explode(',', $value);
            $PROFILES[$p[0]] = ['name' => $p[1], 'prefix' => $p[2], 'pkv' => (float)$p[3], 'bh' => (float)$p[4]];
        } else { $config[$key] = $value; }
    }
}

$currentKey = $_GET['person'] ?? (array_key_first($PROFILES) ?: '');
$activeProfile = $PROFILES[$currentKey] ?? ['name' => 'Gast', 'prefix' => 'X', 'pkv' => 0.5, 'bh' => 0.5];
$jsonFile = "data_{$currentKey}.json";
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

// --- LOGIK: SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = !empty($_POST['id']) ? $_POST['id'] : uniqid();
    $data[$id] = [
        'intern_nr'    => $_POST['intern_nr'], 
        'rg_datum'     => $_POST['rg_datum'],
        'arzt'         => $_POST['arzt'],      
        'beschreibung' => $_POST['beschreibung'],
        'doc_link'     => $_POST['doc_link'],  
        'gesamt'       => (float)str_replace(',', '.', $_POST['gesamt']),
        'z_status'     => $_POST['z_status'],  
        'z_datum'      => $_POST['z_datum'],
        's_pkv'        => $_POST['s_pkv'],
        'e_pkv'        => (float)str_replace(',', '.', $_POST['e_pkv'] ?: 0),
        'pkv_sub_date' => $_POST['pkv_sub_date'], 
        'pkv_date'     => $_POST['pkv_date'],
        'pkv_belegnr'  => $_POST['pkv_belegnr'],
        'pkv_link'     => $_POST['pkv_link'],
        's_bh'         => $_POST['s_bh'],         
        'e_bh'         => (float)str_replace(',', '.', $_POST['e_bh'] ?: 0),
        'bh_sub_date'  => $_POST['bh_sub_date'],  
        'bh_date'      => $_POST['bh_date'],
        'bh_belegnr'   => $_POST['bh_belegnr'],
        'bh_link'      => $_POST['bh_link']
    ];
    uasort($data, function($a, $b) { return strcmp($b['rg_datum'], $a['rg_datum']); });
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    header("Location: ?person=" . $currentKey); exit;
}

if (isset($_GET['delete'])) {
    unset($data[$_GET['delete']]);
    file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));
    header("Location: ?person=" . $currentKey); exit;
}

// --- STATS CALCULATION ---
$currentYear = date('Y');
$stats = ['gesamt' => 0, 'offen' => 0, 'eigenanteil_jahr' => 0];
foreach ($data as $r) {
    $stats['gesamt'] += $r['gesamt'];
    if ($r['s_pkv'] !== 'beglichen') $stats['offen'] += $r['e_pkv'];
    if ($r['s_bh'] !== 'beglichen') $stats['offen'] += $r['e_bh'];
    if (date('Y', strtotime($r['rg_datum'])) == $currentYear) {
        $stats['eigenanteil_jahr'] += ($r['gesamt'] - ($r['e_pkv'] + $r['e_bh']));
    }
}

/**
 * Hilfsfunktion für Status-Farben
 */
function getStatusClass($status, $date = null) {
    if ($status === 'offen') return 'bg-danger';
    if ($status === 'beglichen' || $status === 'bar bezahlt' || $status === 'Überweisung') return 'bg-success';
    
    if ($status === 'Terminüberweisung') {
        if (!empty($date)) {
            $today = date('Y-m-d');
            // Wenn Datum heute oder in der Vergangenheit liegt -> Grün
            return ($date <= $today) ? 'bg-success' : 'bg-warning';
        }
        return 'bg-warning';
    }
    
    // Fallback für PKV/Beihilfe Status
    if ($status === 'eingereicht') return 'bg-warning';
    return 'bg-warning'; 
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($config['APP_NAME']) ?></title>
    <style>
        :root { --pkv-blue: #007bff; --success-green: #28a745; --danger-red: #dc3545; --bg-gray: #f0f2f5; --dark-gray: #343a40; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg-gray); margin: 0; min-height: 100vh; display: flex; flex-direction: column; }
        header { background: white; padding: 10px 40px; display: grid; grid-template-columns: 220px 1fr 220px; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .logo-container img { width: 200px; height: 150px; object-fit: contain; border: none; background: transparent; }
        .header-title { text-align: center; }
        .header-title h1 { margin: 0; font-size: 1.6rem; color: #333; }
        .live-clock { text-align: right; font-size: 1rem; color: #555; font-weight: 500; }
        .container { max-width: 1600px; margin: 20px auto; padding: 0 20px; flex: 1; width: 100%; box-sizing: border-box; }
        .dashboard { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 25px; }
        .card { background: white; padding: 15px 20px; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-left: 5px solid var(--pkv-blue); }
        .card h3 { margin: 0; font-size: 0.75rem; color: #666; text-transform: uppercase; margin-bottom: 5px; }
        .card p { margin: 0; font-size: 1.4rem; font-weight: bold; }
        .person-nav { display: flex; gap: 5px; margin-bottom: 20px; background: #ddd; padding: 5px; border-radius: 8px; width: fit-content; }
        .person-nav a { text-decoration: none; padding: 8px 15px; color: #555; border-radius: 5px; font-size: 0.9rem; }
        .person-nav a.active { background: var(--pkv-blue); color: white; }
        form { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .form-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .field { display: flex; flex-direction: column; gap: 4px; }
        label { font-size: 0.7rem; color: #666; font-weight: bold; text-transform: uppercase; }
        input, select { border: 1px solid #dee2e6; padding: 8px; border-radius: 6px; font-size: 0.9rem; }
        .box { flex: 1; padding: 15px; border-radius: 8px; border: 1px solid #eee; }
        .box-pkv { background: #f8fbff; } .box-bh { background: #fff9f0; }
        table { width: 100%; border-collapse: collapse; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-size: 0.7rem; color: #666; border-bottom: 2px solid #eee; }
        td { padding: 12px; border-bottom: 1px solid #f1f1f1; font-size: 0.85rem; vertical-align: top; }
        .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: bold; color: white; text-transform: uppercase; display: inline-block; margin-bottom: 4px; }
        .bg-success { background: var(--success-green); } .bg-danger { background: var(--danger-red); } .bg-warning { background: #fd7e14; }
        .btn-doc-inline { text-decoration: none; font-size: 1.1rem; margin-left: 5px; vertical-align: middle; }
        .btn-refund-dl { text-decoration: none; background: #e9ecef; padding: 2px 6px; border-radius: 4px; font-size: 0.8rem; color: #333; display: inline-block; margin-top: 5px; border: 1px solid #ccc; font-weight: bold; }
        footer { background: var(--dark-gray); color: #bbb; padding: 30px; text-align: center; margin-top: 40px; font-size: 0.85rem; }
        footer a { color: white; text-decoration: none; border-bottom: 1px solid #555; }
        .btn-del { color: var(--danger-red); text-decoration: none; margin-left: 10px; }
    </style>
</head>
<body onload="startTime()">

<header>
    <div class="logo-container"><img src="<?= htmlspecialchars($config['APP_LOGO']) ?>" alt="Logo"></div>
    <div class="header-title">
        <h1><?= htmlspecialchars($config['APP_NAME']) ?></h1>
        <div style="font-size: 1rem; color: var(--pkv-blue); font-weight: bold; margin-top: 5px;"><?= $activeProfile['name'] ?></div>
    </div>
    <div class="live-clock" id="clock"></div>
</header>

<div class="container">
    <div class="person-nav">
        <?php foreach ($PROFILES as $key => $p): ?>
            <a href="?person=<?= $key ?>" class="<?= ($currentKey === $key) ? 'active' : '' ?>"><?= $p['name'] ?></a>
        <?php endforeach; ?>
    </div>

    <div class="dashboard">
        <div class="card"><h3>Gesamtvolumen</h3><p><?= number_format($stats['gesamt'], 2, ',', '.') ?> €</p></div>
        <div class="card" style="border-left-color: var(--danger-red);"><h3>Erstattungen offen</h3><p><?= number_format($stats['offen'], 2, ',', '.') ?> €</p></div>
        <div class="card" style="border-left-color: #6c757d;"><h3>Eigenanteil <?= $currentYear ?></h3><p><?= number_format($stats['eigenanteil_jahr'], 2, ',', '.') ?> €</p></div>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="save"><input type="hidden" name="id" id="f_id">
        <div class="form-row">
            <div class="field" style="width:80px;"><label>Nr.</label><input type="text" name="intern_nr" id="f_nr" value="<?= $activeProfile['prefix'] . (count($data)+1) ?>" required></div>
            <div class="field" style="width:140px;"><label>Datum</label><input type="date" name="rg_datum" id="f_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="field" style="flex:1.5;"><label>Arzt / Einrichtung</label><input type="text" name="arzt" id="f_arzt" required></div>
            <div class="field" style="flex:1.5;"><label>Inhalt / Zweck</label><input type="text" name="beschreibung" id="f_desc"></div>
            <div class="field" style="flex:2;"><label>Rechnung (Link)</label><input type="url" name="doc_link" id="f_link"></div>
        </div>

        <div class="form-row">
            <div class="field" style="width:120px;"><label>Betrag €</label><input type="number" step="0.01" name="gesamt" id="f_gesamt" oninput="calc()" required></div>
            <div class="field" style="width:150px;"><label>Zahlstatus</label>
                <select name="z_status" id="f_z_status">
                    <option value="offen">Offen</option>
                    <option value="bar bezahlt">Bar bezahlt</option>
                    <option value="Terminüberweisung">Terminüberweisung</option>
                    <option value="Überweisung">Überweisung</option>
                </select>
            </div>
            <div class="field" style="width:140px;"><label>Zahl-Datum</label><input type="date" name="z_datum" id="f_z_datum"></div>
            
            <div class="box box-pkv">
                <label>PKV</label>
                <div style="display:flex; gap:8px; margin-top:5px;">
                    <input type="number" step="0.01" name="e_pkv" id="f_e_pkv" style="width:80px;">
                    <select name="s_pkv" id="f_s_pkv" onchange="toggleExtra('pkv')" style="flex:1;"><option value="offen">Offen</option><option value="eingereicht">Eingereicht</option><option value="beglichen">Beglichen</option></select>
                </div>
                <div id="pkv_extra" style="display:none; margin-top:10px;">
                    <div class="form-row" style="margin-bottom:0; gap:5px;">
                        <div class="field"><label>Eingereicht</label><input type="date" name="pkv_sub_date" id="f_pkv_sub_date" style="width:115px;"></div>
                        <div class="field"><label>Beglichen</label><input type="date" name="pkv_date" id="f_pkv_date" style="width:115px;"></div>
                    </div>
                    <div class="form-row" style="margin-top:5px; gap:5px;">
                        <div class="field"><label>Beleg-Nr</label><input type="text" name="pkv_belegnr" id="f_pkv_belegnr" style="width:90px;"></div>
                        <div class="field"><label>Bescheid (Link)</label><input type="url" name="pkv_link" id="f_pkv_link" style="width:140px;"></div>
                    </div>
                </div>
            </div>
            <div class="box box-bh">
                <label>Beihilfe</label>
                <div style="display:flex; gap:8px; margin-top:5px;">
                    <input type="number" step="0.01" name="e_bh" id="f_e_bh" style="width:80px;">
                    <select name="s_bh" id="f_s_bh" onchange="toggleExtra('bh')" style="flex:1;"><option value="offen">Offen</option><option value="eingereicht">Eingereicht</option><option value="beglichen">Beglichen</option></select>
                </div>
                <div id="bh_extra" style="display:none; margin-top:10px;">
                    <div class="form-row" style="margin-bottom:0; gap:5px;">
                        <div class="field"><label>Eingereicht</label><input type="date" name="bh_sub_date" id="f_bh_sub_date" style="width:115px;"></div>
                        <div class="field"><label>Beglichen</label><input type="date" name="bh_date" id="f_bh_date" style="width:115px;"></div>
                    </div>
                    <div class="form-row" style="margin-top:5px; gap:5px;">
                        <div class="field"><label>Beleg-Nr</label><input type="text" name="bh_belegnr" id="f_bh_belegnr" style="width:90px;"></div>
                        <div class="field"><label>Bescheid (Link)</label><input type="url" name="bh_link" id="f_bh_link" style="width:140px;"></div>
                    </div>
                </div>
            </div>
            <button type="submit" class="bg-success" style="color:white; border:none; padding:0 25px; border-radius:6px; font-weight:bold; cursor:pointer;" id="saveBtn">Speichern</button>
        </div>
    </form>

    <table>
        <thead><tr><th>Nr.</th><th>Datum</th><th>Arzt / Zweck / Rechnung</th><th>Betrag</th><th>Eigenanteil</th><th>Status</th><th>PKV Details</th><th>BH Details</th><th>Aktion</th></tr></thead>
        <tbody>
            <?php foreach ($data as $id => $r): ?>
            <?php $rowEigen = $r['gesamt'] - ($r['e_pkv'] + $r['e_bh']); ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['intern_nr']) ?></strong></td>
                <td><?= date('d.m.y', strtotime($r['rg_datum'])) ?></td>
                <td>
                    <strong><?= htmlspecialchars($r['arzt']) ?></strong>
                    <?php if(!empty($r['doc_link'])): ?><a href="<?= $r['doc_link'] ?>" target="_blank" class="btn-doc-inline" title="Rechnung öffnen">📄</a><?php endif; ?>
                    <br><small><?= htmlspecialchars($r['beschreibung'] ?? '') ?></small>
                </td>
                <td><strong><?= number_format($r['gesamt'], 2, ',', '.') ?> €</strong></td>
                <td style="color: #666; font-weight: 500;"><?= number_format($rowEigen, 2, ',', '.') ?> €</td>
                <td>
                    <span class="badge <?= getStatusClass($r['z_status'], $r['z_datum'] ?? null) ?>"><?= $r['z_status'] ?></span><br>
                    <?php if(!empty($r['z_datum'])): ?><small><?= date('d.m.y', strtotime($r['z_datum'])) ?></small><?php endif; ?>
                </td>
                <td>
                    <span class="badge <?= getStatusClass($r['s_pkv']) ?>"><?= $r['s_pkv'] ?></span><br>
                    <strong><?= number_format($r['e_pkv'],2,',','.') ?>€</strong>
                    <div style="font-size:0.7rem; color:#666; margin-top:3px;">
                        <?php if(!empty($r['pkv_sub_date'])): ?><div>Eing: <?= date('d.m.y', strtotime($r['pkv_sub_date'])) ?></div><?php endif; ?>
                        <?php if(!empty($r['pkv_date'])): ?><div style="color:var(--success-green); font-weight:bold;">Begl: <?= date('d.m.y', strtotime($r['pkv_date'])) ?></div><?php endif; ?>
                        <?php if(!empty($r['pkv_belegnr'])): ?><div style="font-style:italic;">Beleg: <?= htmlspecialchars($r['pkv_belegnr']) ?></div><?php endif; ?>
                        <?php if(!empty($r['pkv_link'])): ?><a href="<?= $r['pkv_link'] ?>" target="_blank" class="btn-refund-dl">📥 Bescheid</a><?php endif; ?>
                    </div>
                </td>
                <td>
                    <span class="badge <?= getStatusClass($r['s_bh']) ?>"><?= $r['s_bh'] ?></span><br>
                    <strong><?= number_format($r['e_bh'],2,',','.') ?>€</strong>
                    <div style="font-size:0.7rem; color:#666; margin-top:3px;">
                        <?php if(!empty($r['bh_sub_date'])): ?><div>Eing: <?= date('d.m.y', strtotime($r['bh_sub_date'])) ?></div><?php endif; ?>
                        <?php if(!empty($r['bh_date'])): ?><div style="color:var(--success-green); font-weight:bold;">Begl: <?= date('d.m.y', strtotime($r['bh_date'])) ?></div><?php endif; ?>
                        <?php if(!empty($r['bh_belegnr'])): ?><div style="font-style:italic;">Beleg: <?= htmlspecialchars($r['bh_belegnr']) ?></div><?php endif; ?>
                        <?php if(!empty($r['bh_link'])): ?><a href="<?= $r['bh_link'] ?>" target="_blank" class="btn-refund-dl">📥 Bescheid</a><?php endif; ?>
                    </div>
                </td>
                <td>
                    <a href="javascript:void(0)" onclick='editRow(<?= json_encode(array_merge(['id'=>$id],$r)) ?>)'>✏️</a>
                    <a href="?person=<?= $currentKey ?>&delete=<?= $id ?>" class="btn-del" onclick="return confirm('Löschen?')">🗑️</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<footer>
    <p><strong><?= htmlspecialchars($config['APP_NAME']) ?></strong> | Lizenziert unter <a href="https://www.gnu.org/licenses/agpl-3.0.de.html" target="_blank">AGPL-3.0</a> | Source: <a href="<?= htmlspecialchars($config['GIT_REPO']) ?>" target="_blank">GitHub</a></p>
</footer>

<script>
function startTime() {
    const today = new Date();
    let d = today.toLocaleDateString('de-DE');
    let h = today.getHours(); let m = today.getMinutes(); let s = today.getSeconds();
    m = checkTime(m); s = checkTime(s);
    document.getElementById('clock').innerHTML = d + " - " + h + ":" + m + ":" + s;
    setTimeout(startTime, 1000);
}
function checkTime(i) { if (i < 10) {i = "0" + i}; return i; }
const ratios = { pkv: <?= $activeProfile['pkv'] ?>, bh: <?= $activeProfile['bh'] ?> };
function calc() {
    const v = parseFloat(document.getElementById('f_gesamt').value) || 0;
    document.getElementById('f_e_pkv').value = (v * ratios.pkv).toFixed(2);
    document.getElementById('f_e_bh').value = (v * ratios.bh).toFixed(2);
}
function toggleExtra(type) {
    const status = document.getElementById('f_s_' + type).value;
    document.getElementById(type + '_extra').style.display = (status !== 'offen') ? 'block' : 'none';
}
function editRow(d) {
    window.scrollTo({top: 0, behavior: 'smooth'});
    document.getElementById('f_id').value = d.id;
    document.getElementById('f_nr').value = d.intern_nr;
    document.getElementById('f_date').value = d.rg_datum;
    document.getElementById('f_arzt').value = d.arzt;
    document.getElementById('f_desc').value = d.beschreibung || '';
    document.getElementById('f_link').value = d.doc_link || '';
    document.getElementById('f_gesamt').value = d.gesamt;
    document.getElementById('f_z_status').value = d.z_status;
    document.getElementById('f_z_datum').value = d.z_datum || '';
    document.getElementById('f_s_pkv').value = d.s_pkv; 
    document.getElementById('f_e_pkv').value = d.e_pkv;
    document.getElementById('f_pkv_sub_date').value = d.pkv_sub_date || '';
    document.getElementById('f_pkv_date').value = d.pkv_date || '';
    document.getElementById('f_pkv_belegnr').value = d.pkv_belegnr || '';
    document.getElementById('f_pkv_link').value = d.pkv_link || '';
    document.getElementById('f_s_bh').value = d.s_bh; 
    document.getElementById('f_e_bh').value = d.e_bh;
    document.getElementById('f_bh_sub_date').value = d.bh_sub_date || '';
    document.getElementById('f_bh_date').value = d.bh_date || '';
    document.getElementById('f_bh_belegnr').value = d.bh_belegnr || '';
    document.getElementById('f_bh_link').value = d.bh_link || '';
    toggleExtra('pkv'); toggleExtra('bh');
    document.getElementById('saveBtn').innerText = "Update";
    document.getElementById('saveBtn').style.background = "#007bff";
}
</script>
</body>
</html>