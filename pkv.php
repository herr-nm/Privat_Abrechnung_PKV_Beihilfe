<?php

// --- .ENV PARSER (identisch zu index.php) ---
$PROFILES = [];
$config = [];
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

function loadData($key) {
    $file = "data_{$key}.json";
    return file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
}
function saveData($key, $data) {
    uasort($data, function($a, $b) { return strnatcasecmp($b['intern_nr'], $a['intern_nr']); });
    return file_put_contents("data_{$key}.json", json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function parseAmount($v) {
    $v = str_replace(',', '.', trim((string)$v));
    return is_numeric($v) ? round((float)$v, 2) : null;
}

$errors = [];
$formLink = ''; $formDatum = date('Y-m-d'); $formNr = '';
$prefillPositions = [];

// --- LOGIK: BESCHEID SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bescheid') {
    $formLink  = trim($_POST['pkv_link'] ?? '');
    $formDatum = trim($_POST['pkv_date'] ?? '');
    $formNr    = trim($_POST['pkv_belegnr'] ?? '');
    $refs      = $_POST['pos_ref'] ?? [];
    $betraege  = $_POST['pos_betrag'] ?? [];

    if (!filter_var($formLink, FILTER_VALIDATE_URL)) $errors[] = 'Bitte einen gültigen Link zur PDF angeben.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDatum)) $errors[] = 'Bitte ein gültiges Bescheid-Datum angeben.';
    if ($formNr === '') $errors[] = 'Bitte die Bescheidnummer angeben.';

    $positions = [];
    $seen = [];
    foreach ($refs as $i => $ref) {
        $betrag = parseAmount($betraege[$i] ?? '');
        $prefillPositions[] = ['ref' => $ref, 'betrag' => $betraege[$i] ?? ''];
        if ($ref === '') continue;
        [$pKey, $rId] = array_pad(explode('|', $ref, 2), 2, '');
        if (!isset($PROFILES[$pKey])) { $errors[] = 'Unbekannte Person in Position ' . ($i + 1) . '.'; continue; }
        if (isset($seen[$ref])) { $errors[] = 'Ein Beleg wurde mehrfach als Position gewählt.'; continue; }
        $seen[$ref] = true;
        if ($betrag === null || $betrag < 0) { $errors[] = 'Ungültiger Erstattungsbetrag in Position ' . ($i + 1) . '.'; continue; }
        $positions[] = ['person' => $pKey, 'id' => $rId, 'betrag' => $betrag];
    }
    if (!$positions) $errors[] = 'Mindestens eine Position ist erforderlich.';

    if (!$errors) {
        // Betroffene Datenbestände laden und prüfen, ob die Belege noch "eingereicht" sind
        $dataByPerson = [];
        foreach ($positions as $pos) {
            if (!isset($dataByPerson[$pos['person']])) $dataByPerson[$pos['person']] = loadData($pos['person']);
            $rec = $dataByPerson[$pos['person']][$pos['id']] ?? null;
            if (!$rec) $errors[] = 'Beleg nicht gefunden (' . htmlspecialchars($pos['id']) . ').';
            elseif (($rec['s_pkv'] ?? '') !== 'eingereicht') $errors[] = 'Beleg ' . htmlspecialchars($rec['intern_nr']) . ' ist nicht mehr im Status „eingereicht“.';
        }
    }

    if (!$errors) {
        // Alles validiert -> jetzt gesammelt aktualisieren
        $sum = 0;
        foreach ($positions as $pos) {
            $r =& $dataByPerson[$pos['person']][$pos['id']];
            $r['e_pkv']       = $pos['betrag'];
            $r['s_pkv']       = 'beglichen';
            $r['pkv_date']    = $formDatum;
            $r['pkv_belegnr'] = $formNr;
            $r['pkv_link']    = $formLink;
            unset($r);
            $sum += $pos['betrag'];
        }
        $ok = true;
        foreach ($dataByPerson as $pKey => $d) { $ok = saveData($pKey, $d) && $ok; }
        if ($ok) {
            header('Location: ' . basename(__FILE__) . '?ok=' . count($positions) . '&sum=' . $sum);
            exit;
        }
        $errors[] = 'Beim Schreiben der Datendateien ist ein Fehler aufgetreten.';
    }
}

// --- OFFENE (eingereichte) BELEGE ALLER PERSONEN ---
$offen = [];
foreach ($PROFILES as $pKey => $p) {
    foreach (loadData($pKey) as $id => $r) {
        if (($r['s_pkv'] ?? '') === 'eingereicht') {
            $offen[] = [
                'ref'    => $pKey . '|' . $id,
                'person' => $p['name'],
                'nr'     => $r['intern_nr'],
                'gesamt' => (float)$r['gesamt'],
                'e_pkv'   => (float)($r['e_pkv'] ?? 0),
            ];
        }
    }
}
usort($offen, function($a, $b) {
    return strcmp($a['person'], $b['person']) ?: strnatcasecmp($a['nr'], $b['nr']);
});
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PKV-Bescheid erfassen</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --pkv-blue: #007bff; --success-green: #28a745; --danger-red: #dc3545; --bg-gray: #f0f2f5; --dark-gray: #343a40; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg-gray); margin: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .container { max-width: 1000px; margin: 0 auto 20px auto; padding: 0 20px; flex: 1; width: 100%; box-sizing: border-box; }

        .main-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 30px; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header-logo img { height: 50px; width: auto; display: block; }
        .header-title-center h1 { margin: 0; font-size: 1.5rem; color: #333; text-align: center; }
        .header-nav-right { display: flex; gap: 10px; }
        .btn-nav { text-decoration: none; background: var(--pkv-blue); color: #fff; padding: 8px 16px; border-radius: 6px; font-weight: bold; transition: background .3s; }
        .btn-nav:hover { background: #0056b3; }

        form { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); margin-bottom: 30px; }
        .form-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        .field { display: flex; flex-direction: column; gap: 4px; }
        label { font-size: 0.7rem; color: #666; font-weight: bold; text-transform: uppercase; }
        input, select { border: 1px solid #dee2e6; padding: 8px; border-radius: 6px; font-size: 0.9rem; }
        h2 { font-size: 0.9rem; text-transform: uppercase; color: #666; margin: 25px 0 10px; border-top: 1px solid #eee; padding-top: 20px; }

        .pos-row { display: flex; gap: 10px; align-items: flex-end; margin-bottom: 10px; padding: 12px; background: #f8fbff; border: 1px solid #eee; border-radius: 8px; }
        .pos-row .field.sel { flex: 1; }
        .pos-row .field.amt { width: 160px; }
        .btn-remove { background: none; border: none; color: var(--danger-red); font-size: 1.1rem; cursor: pointer; padding: 8px; }
        .btn-add { background: var(--pkv-blue); color: #fff; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 1.3rem; cursor: pointer; }
        .btn-add:hover { background: #0056b3; }
        .add-wrap { display: flex; align-items: center; gap: 10px; margin-top: 5px; color: #666; font-size: 0.85rem; }

        .summary { display: flex; justify-content: space-between; align-items: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #eee; }
        .summary .total { font-size: 1.2rem; font-weight: bold; }
        .btn-save { background: var(--success-green); color: #fff; border: none; padding: 12px 30px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 1rem; }
        .btn-save:disabled { background: #aaa; cursor: not-allowed; }

        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .msg.ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg.err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .msg ul { margin: 0; padding-left: 20px; }

        footer { background: var(--dark-gray); color: #bbb; padding: 30px; text-align: center; margin-top: 40px; font-size: 0.85rem; }
        footer a { color: #fff; text-decoration: none; border-bottom: 1px solid #555; }
    </style>
</head>
<body>

<header class="main-header">
    <div class="header-logo"><img src="logo.png" alt="Logo"></div>
    <div class="header-title-center"><h1>PKV-Bescheid erfassen</h1></div>
    <div class="header-nav-right">
        <a href="index.php" class="btn-nav"><i class="fa-solid fa-list"></i> Belege</a>
        <a href="../index.php" class="btn-nav"><i class="fa-solid fa-house"></i> Dashboard</a>
    </div>
</header>

<div class="container">

    <?php if (isset($_GET['ok'])): ?>
        <div class="msg ok">
            Bescheid gespeichert: <?= (int)$_GET['ok'] ?> Position(en), Erstattung gesamt
            <?= number_format((float)($_GET['sum'] ?? 0), 2, ',', '.') ?> €. Die Belege wurden auf „beglichen“ gesetzt.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="msg err"><ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" id="bescheidForm">
        <input type="hidden" name="action" value="save_bescheid">

        <div class="form-row">
            <div class="field" style="flex:2; min-width:260px;">
                <label>Bescheid (Link zur PDF)</label>
                <input type="url" name="pkv_link" value="<?= htmlspecialchars($formLink) ?>" required>
            </div>
            <div class="field" style="width:150px;">
                <label>Bescheid-Datum</label>
                <input type="date" name="pkv_date" value="<?= htmlspecialchars($formDatum) ?>" required>
            </div>
            <div class="field" style="width:180px;">
                <label>Bescheidnummer</label>
                <input type="text" name="pkv_belegnr" value="<?= htmlspecialchars($formNr) ?>" required>
            </div>
        </div>

        <h2>Positionen</h2>
        <?php if (!$offen): ?>
            <div class="msg err">Es gibt keine eingereichten, noch offenen PKV-Belege.</div>
        <?php endif; ?>

        <div id="positions"></div>

        <div class="add-wrap">
            <button type="button" class="btn-add" id="addBtn" title="Weitere Position">+</button>
            <span>Weitere Position hinzufügen</span>
        </div>

        <div class="summary">
            <div>Erstattung gesamt: <span class="total" id="totalSum">0,00 €</span></div>
            <button type="submit" class="btn-save" id="saveBtn" disabled>Bescheid speichern</button>
        </div>
    </form>
</div>

<footer>
    <p><strong>Abrechnung PKV & BH</strong> | Lizenziert unter <a href="https://www.gnu.org/licenses/agpl-3.0.de.html" target="_blank">AGPL-3.0</a></p>
</footer>

<script>
const OFFEN   = <?= json_encode($offen, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const PREFILL = <?= json_encode($prefillPositions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

const fmt = n => n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
const container = document.getElementById('positions');

function addRow(ref = '', betrag = '') {
    const row = document.createElement('div');
    row.className = 'pos-row';
    row.innerHTML = `
        <div class="field sel">
            <label>Beleg (eingereicht &amp; offen)</label>
            <select name="pos_ref[]" required></select>
        </div>
        <div class="field amt">
            <label>Erstattungsbetrag €</label>
            <input type="number" step="0.01" min="0" name="pos_betrag[]" required>
        </div>
        <button type="button" class="btn-remove" title="Position entfernen">🗑️</button>`;
    const sel = row.querySelector('select');
    sel.add(new Option('– Beleg wählen –', ''));
    OFFEN.forEach(o => {
        const opt = new Option(`${o.person} · ${o.nr} · ${fmt(o.gesamt)}`, o.ref);
        sel.add(opt);
    });
    sel.value = ref;
    const inp = row.querySelector('input');
    inp.value = betrag;
    sel.addEventListener('change', () => { setPlaceholder(sel, inp); refresh(); });
    inp.addEventListener('input', refresh);
    row.querySelector('.btn-remove').addEventListener('click', () => { row.remove(); refresh(); });
    container.appendChild(row);
    setPlaceholder(sel, inp);
    refresh();
    if (!ref) sel.focus();
}

// erwartete Erstattung (aus index.php berechnet) vorbelegen;
// manuell geänderte Werte werden beim Wechsel des Belegs nicht überschrieben
function setPlaceholder(sel, inp) {
    const o = OFFEN.find(x => x.ref === sel.value);
    inp.placeholder = o ? 'erwartet ' + o.e_pkv.toFixed(2) : '';
    const untouched = inp.value === '' || inp.value === inp.dataset.auto;
    if (untouched) {
        const v = o ? o.e_pkv.toFixed(2) : '';
        inp.value = v;
        inp.dataset.auto = v;
    }
}

function refresh() {
    const rows = [...container.querySelectorAll('.pos-row')];
    const used = rows.map(r => r.querySelector('select').value).filter(Boolean);
    let sum = 0, complete = rows.length > 0;
    rows.forEach(r => {
        const sel = r.querySelector('select');
        [...sel.options].forEach(opt => { opt.disabled = opt.value !== '' && used.includes(opt.value) && opt.value !== sel.value; });
        const v = parseFloat(r.querySelector('input').value);
        if (!sel.value || isNaN(v) || v < 0) complete = false; else sum += v;
    });
    document.getElementById('totalSum').textContent = fmt(sum);
    document.getElementById('saveBtn').disabled = !complete;
    document.getElementById('addBtn').disabled = OFFEN.length === 0 || used.length >= OFFEN.length;
}

document.getElementById('addBtn').addEventListener('click', () => addRow());
document.getElementById('bescheidForm').addEventListener('submit', e => {
    const n = container.querySelectorAll('.pos-row').length;
    const total = document.getElementById('totalSum').textContent;
    if (!confirm(`${n} Position(en) mit ${total} speichern und alle Belege auf „beglichen“ setzen?`)) e.preventDefault();
});

if (PREFILL.length) PREFILL.forEach(p => addRow(p.ref, p.betrag)); else addRow();
</script>
</body>
</html>
