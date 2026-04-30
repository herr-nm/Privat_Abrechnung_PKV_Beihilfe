<?php
/**
 * PKV & Beihilfe Tracker V9 - Mit Dokumenten-Link (Paperless)
 */

$PROFILES = [
    'andre'  => ['name' => 'André',  'prefix' => 'A', 'pkv' => 0.50, 'bh' => 0.50],
    'mattis' => ['name' => 'Mattis', 'prefix' => 'M', 'pkv' => 0.30, 'bh' => 0.70],
    'linnea' => ['name' => 'Linnea', 'prefix' => 'L', 'pkv' => 0.30, 'bh' => 0.70],
];

$currentKey = $_GET['person'] ?? 'andre';
if (!array_key_exists($currentKey, $PROFILES)) { $currentKey = 'andre'; }

$activeProfile = $PROFILES[$currentKey];
$jsonFile = "data_{$currentKey}.json";
$data = file_exists($jsonFile) ? json_decode(file_get_contents($jsonFile), true) : [];

// --- AUTO-INKREMENT NR ---
$nextNr = 1;
foreach ($data as $r) {
    if (isset($r['intern_nr'])) {
        $num = (int)preg_replace('/[^0-9]/', '', $r['intern_nr']);
        if ($num >= $nextNr) { $nextNr = $num + 1; }
    }
}
$suggestedNr = $activeProfile['prefix'] . $nextNr;

// --- SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $id = !empty($_POST['id']) ? $_POST['id'] : uniqid();
    $data[$id] = [
        'intern_nr'    => $_POST['intern_nr'],
        'rg_datum'     => $_POST['rg_datum'],
        'arzt'         => $_POST['arzt'],
        'beschreibung' => $_POST['beschreibung'],
        'doc_link'     => $_POST['doc_link'], // Neues Feld für Paperless-Link
        'gesamt'       => (float)str_replace(',', '.', $_POST['gesamt']),
        'z_status'     => $_POST['z_status'],
        's_pkv'        => $_POST['s_pkv'],
        's_bh'         => $_POST['s_bh'],
        'e_pkv'        => (float)str_replace(',', '.', $_POST['e_pkv'] ?: 0),
        'e_bh'         => (float)str_replace(',', '.', $_POST['e_bh'] ?: 0)
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

// --- STATS LOGIK ---
$stats = ['gesamt' => 0, 'offenSum' => 0, 'offenAnz' => 0, 'altDate' => null, 'eigenJahr' => 0];
$currentYear = date('Y');
foreach ($data as $r) {
    $stats['gesamt'] += $r['gesamt'];
    $ausstehend = 0;
    if ($r['s_pkv'] !== 'beglichen') { $ausstehend += $r['e_pkv']; }
    if ($r['s_bh'] !== 'beglichen') { $ausstehend += $r['e_bh']; }
    if ($ausstehend > 0) {
        $stats['offenSum'] += $ausstehend;
        $stats['offenAnz']++;
        if (!$stats['altDate'] || $r['rg_datum'] < $stats['altDate']) $stats['altDate'] = $r['rg_datum'];
    }
    if (date('Y', strtotime($r['rg_datum'])) == $currentYear) {
        $stats['eigenJahr'] += ($r['gesamt'] - $r['e_pkv'] - $r['e_bh']);
    }
}

function getStatusClass($status) {
    return ($status === 'beglichen' || $status === 'bezahlt') ? 'bg-success' : (($status === 'offen') ? 'bg-danger' : 'bg-warning');
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>PKV/BH Tracker - <?= $activeProfile['name'] ?></title>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; background: #f4f7f6; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1450px; margin: 0 auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        .person-nav { display: flex; gap: 10px; margin-bottom: 25px; }
        .person-nav a { text-decoration: none; padding: 10px 20px; border-radius: 8px; color: #555; background: #eee; font-weight: 600; }
        .person-nav a.active { background: #007bff; color: white; }
        .dashboard { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .card { background: #fff; padding: 15px; border-radius: 10px; border: 1px solid #e0e6ed; border-top: 4px solid #007bff; }
        .card h3 { margin: 0; font-size: 0.75rem; text-transform: uppercase; color: #888; }
        .card p { margin: 8px 0 0; font-size: 1.4rem; font-weight: 700; }

        form { background: #f8f9fa; padding: 20px; border-radius: 10px; border: 1px solid #eee; margin-bottom: 25px; }
        .form-row { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; margin-bottom: 15px; }
        .input-group { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 120px; }
        .input-group.small { flex: 0 0 80px; }
        .input-group.med { flex: 0 0 150px; }
        label { font-weight: 600; font-size: 0.75rem; color: #666; }
        input, select, button { padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 0.9rem; width: 100%; box-sizing: border-box; }
        
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-size: 0.8rem; border-bottom: 2px solid #dee2e6; color: #666; }
        td { padding: 12px; border-bottom: 1px solid #eee; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 700; color: white; text-transform: uppercase; display: inline-block; }
        .bg-success { background: #28a745; } .bg-warning { background: #fd7e14; } .bg-danger { background: #dc3545; }
        
        .amount { text-align: right; font-weight: 600; }
        .secondary { color: #888; font-size: 0.8rem; display: block; }
        .btn-save { background: #28a745; color: white; border: none; font-weight: 700; cursor: pointer; flex: 0 0 120px; }
        .btn-reset { background: #6c757d; color: white; text-decoration: none; text-align: center; padding: 8px; border-radius: 6px; font-size: 0.9rem; flex: 0 0 100px; }
        .id-cell { font-family: monospace; font-weight: bold; color: #007bff; background: #f0f7ff; padding: 2px 6px; border-radius: 4px; }
        
        .doc-icon { text-decoration: none; font-size: 1.2rem; filter: grayscale(1); transition: 0.2s; }
        .doc-icon:hover { filter: grayscale(0); transform: scale(1.2); }
    </style>
</head>
<body>

<div class="container">
    <div class="person-nav">
        <?php foreach ($PROFILES as $key => $p): ?>
            <a href="?person=<?= $key ?>" class="<?= ($currentKey === $key) ? 'active' : '' ?>"><?= $p['name'] ?></a>
        <?php endforeach; ?>
    </div>

    <div class="dashboard">
        <div class="card"><h3>Gesamt</h3><p><?= number_format($stats['gesamt'], 2, ',', '.') ?> €</p></div>
        <div class="card" style="border-top-color: #dc3545;"><h3>Erstattung offen</h3><p><?= number_format($stats['offenSum'], 2, ',', '.') ?> €</p></div>
        <div class="card" style="border-top-color: #dc3545;"><h3>Älteste Offene</h3><p><?= $stats['altDate'] ? date('d.m.Y', strtotime($stats['altDate'])) : '--' ?></p></div>
        <div class="card"><h3>Eigenanteil <?= $currentYear ?></h3><p><?= number_format($stats['eigenJahr'], 2, ',', '.') ?> €</p></div>
    </div>

    <form method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="f_id" value="">
        
        <div class="form-row">
            <div class="input-group small"><label>Nr.</label><input type="text" name="intern_nr" id="f_nr" value="<?= $suggestedNr ?>" style="text-align:center; font-weight:bold;" required></div>
            <div class="input-group med"><label>Datum</label><input type="date" name="rg_datum" id="f_date" value="<?= date('Y-m-d') ?>" required></div>
            <div class="input-group"><label>Arzt</label><input type="text" name="arzt" id="f_arzt" required></div>
            <div class="input-group"><label>Grund</label><input type="text" name="beschreibung" id="f_desc"></div>
            <div class="input-group"><label>Beleg-Link (Paperless)</label><input type="url" name="doc_link" id="f_link" placeholder="https://paperless..."></div>
        </div>

        <div class="form-row">
            <div class="input-group med"><label>Betrag €</label><input type="number" step="0.01" name="gesamt" id="f_gesamt" oninput="calculateAmounts()" required></div>
            <div class="input-group med"><label>Zahlung Arzt</label>
                <select name="z_status" id="f_z_status"><option value="offen">Offen</option><option value="termin">Termin</option><option value="bezahlt">Bezahlt</option></select>
            </div>
            <div class="input-group small"><label>PKV €</label><input type="number" step="0.01" name="e_pkv" id="f_e_pkv"></div>
            <div class="input-group med"><label>PKV</label>
                <select name="s_pkv" id="f_s_pkv"><option value="offen">Offen</option><option value="eingereicht">Eingereicht</option><option value="beglichen">Beglichen</option></select>
            </div>
            <div class="input-group small"><label>BH €</label><input type="number" step="0.01" name="e_bh" id="f_e_bh"></div>
            <div class="input-group med"><label>BH</label>
                <select name="s_bh" id="f_s_bh"><option value="offen">Offen</option><option value="eingereicht">Eingereicht</option><option value="beglichen">Beglichen</option></select>
            </div>
            <button type="submit" class="btn-save" id="saveBtn">Speichern</button>
            <a href="?person=<?= $currentKey ?>" class="btn-reset">Reset</a>
        </div>
    </form>

    <table>
        <thead>
            <tr>
                <th>Nr.</th><th>Datum</th><th>Beleg</th><th>Arzt / Details</th><th class="amount">Summe</th><th>Zahlung</th><th>PKV</th><th>BH</th><th class="amount">Eigen</th><th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $id => $r): $rest = $r['gesamt'] - $r['e_pkv'] - $r['e_bh']; ?>
            <tr>
                <td><span class="id-cell"><?= htmlspecialchars($r['intern_nr'] ?? '-') ?></span></td>
                <td><?= date('d.m.y', strtotime($r['rg_datum'])) ?></td>
                <td style="text-align:center;">
                    <?php if(!empty($r['doc_link'])): ?>
                        <a href="<?= htmlspecialchars($r['doc_link']) ?>" target="_blank" class="doc-icon" title="Dokument öffnen">📄</a>
                    <?php else: ?>
                        <span style="color:#eee;">-</span>
                    <?php endif; ?>
                </td>
                <td><strong><?= htmlspecialchars($r['arzt']) ?></strong><span class="secondary"><?= htmlspecialchars($r['beschreibung']) ?></span></td>
                <td class="amount"><?= number_format($r['gesamt'], 2, ',', '.') ?> €</td>
                <td><span class="badge <?= getStatusClass($r['z_status']) ?>"><?= $r['z_status'] ?></span></td>
                <td><span class="badge <?= getStatusClass($r['s_pkv']) ?>"><?= $r['s_pkv'] ?></span><span class="secondary"><?= number_format($r['e_pkv'], 2, ',', '.') ?> €</span></td>
                <td><span class="badge <?= getStatusClass($r['s_bh']) ?>"><?= $r['s_bh'] ?></span><span class="secondary"><?= number_format($r['e_bh'], 2, ',', '.') ?> €</span></td>
                <td class="amount" style="color: <?= ($rest > 0.1) ? '#dc3545' : '#28a745' ?>;"><?= number_format($rest, 2, ',', '.') ?> €</td>
                <td>
                    <a href="javascript:void(0)" onclick='editRow(<?= json_encode(array_merge(['id' => $id], $r)) ?>)'>✏️</a>
                    <a href="?person=<?= $currentKey ?>&delete=<?= $id ?>" style="color:#ddd; text-decoration:none; margin-left:8px;" onclick="return confirm('Löschen?')">✖</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
const pkvRatio = <?= $activeProfile['pkv'] ?>;
const bhRatio = <?= $activeProfile['bh'] ?>;

function calculateAmounts() {
    const total = parseFloat(document.getElementById('f_gesamt').value) || 0;
    document.getElementById('f_e_pkv').value = (total * pkvRatio).toFixed(2);
    document.getElementById('f_e_bh').value = (total * bhRatio).toFixed(2);
}

function editRow(data) {
    window.scrollTo(0,0);
    document.getElementById('f_id').value = data.id;
    document.getElementById('f_nr').value = data.intern_nr;
    document.getElementById('f_date').value = data.rg_datum;
    document.getElementById('f_arzt').value = data.arzt;
    document.getElementById('f_desc').value = data.beschreibung;
    document.getElementById('f_link').value = data.doc_link || '';
    document.getElementById('f_gesamt').value = data.gesamt;
    document.getElementById('f_z_status').value = data.z_status;
    document.getElementById('f_s_pkv').value = data.s_pkv;
    document.getElementById('f_e_pkv').value = data.e_pkv;
    document.getElementById('f_s_bh').value = data.s_bh;
    document.getElementById('f_e_bh').value = data.e_bh;
    document.getElementById('saveBtn').innerText = "Update";
    document.getElementById('saveBtn').style.background = "#007bff";
}
</script>

</body>
</html>