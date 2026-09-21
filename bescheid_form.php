<?php
// Gemeinsame Erfassungsmaske für Beihilfe- und PKV-Bescheide.
// Wird von bescheid.php ($TYP = 'bh') und bescheid_pkv.php ($TYP = 'pkv') eingebunden.
if (!isset($TYP) || !in_array($TYP, ['bh', 'pkv'], true)) { http_response_code(404); exit; }
require_once __DIR__ . '/common.php';

$LABEL = $TYP === 'bh' ? 'Beihilfe' : 'PKV';
$SELF  = basename($_SERVER['SCRIPT_NAME']);
$PROFILES = loadProfiles();

$errors = [];
$formLink = ''; $formDatum = date('Y-m-d'); $formNr = '';
$prefillPositions = [];

// --- LOGIK: BESCHEID SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bescheid') {
    $formLink  = trim($_POST['bescheid_link'] ?? '');
    $formDatum = trim($_POST['bescheid_date'] ?? '');
    $formNr    = trim($_POST['bescheid_nr'] ?? '');
    $refs      = is_array($_POST['pos_ref'] ?? null) ? $_POST['pos_ref'] : [];
    $betraege  = is_array($_POST['pos_betrag'] ?? null) ? $_POST['pos_betrag'] : [];

    if (!filter_var($formLink, FILTER_VALIDATE_URL)) $errors[] = 'Bitte einen gültigen Link zur PDF angeben.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $formDatum)) $errors[] = 'Bitte ein gültiges Bescheid-Datum angeben.';
    if ($formNr === '') $errors[] = 'Bitte die Bescheidnummer angeben.';

    $positions = [];
    $seen = [];
    foreach ($refs as $i => $ref) {
        $ref = (string)$ref;
        $betrag = parseAmount($betraege[$i] ?? '');
        $prefillPositions[] = ['ref' => $ref, 'betrag' => (string)($betraege[$i] ?? '')];
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
        // Alle Positionen in EINER Transaktion: entweder werden alle Belege aktualisiert oder keiner
        $errors = transaction(function (PDO $pdo) use ($positions, $TYP, $formLink, $formDatum, $formNr): array {
            $err = [];
            $sel = $pdo->prepare("SELECT intern_nr, s_$TYP AS st FROM belege WHERE id = ? AND person = ?");
            $upd = $pdo->prepare("UPDATE belege SET e_$TYP = ?, s_$TYP = 'beglichen', {$TYP}_date = ?, {$TYP}_belegnr = ?, {$TYP}_link = ? WHERE id = ? AND person = ?");
            foreach ($positions as $pos) {
                $sel->execute([$pos['id'], $pos['person']]);
                $row = $sel->fetch();
                if (!$row) { $err[] = 'Beleg nicht gefunden.'; continue; }
                if ($row['st'] !== 'eingereicht') { $err[] = 'Beleg ' . $row['intern_nr'] . ' ist nicht mehr im Status „eingereicht“.'; continue; }
                $upd->execute([$pos['betrag'], $formDatum, $formNr, $formLink, $pos['id'], $pos['person']]);
            }
            return $err;
        });
    }

    if (!$errors) {
        header('Location: ' . $SELF . '?ok=' . count($positions) . '&sum=' . array_sum(array_column($positions, 'betrag')));
        exit;
    }
}

// --- EINGEREICHTE, NOCH OFFENE BELEGE ALLER PERSONEN ---
$offen = [];
foreach ($PROFILES as $pKey => $p) {
    foreach (loadData($pKey) as $id => $r) {
        if (($r['s_' . $TYP] ?? '') === 'eingereicht') {
            $offen[] = [
                'ref'      => $pKey . '|' . $id,
                'person'   => $p['name'],
                'nr'       => $r['intern_nr'],
                'gesamt'   => (float)$r['gesamt'],
                'erwartet' => (float)($r['e_' . $TYP] ?? 0),
            ];
        }
    }
}
usort($offen, function ($a, $b) {
    return strcmp($a['person'], $b['person']) ?: strnatcasecmp($a['nr'], $b['nr']);
});

$boxColor = $TYP === 'bh' ? '#fff9f0' : '#f8fbff';
pageStart($TYP === 'bh' ? 'Beihilfebescheid erfassen' : 'PKV-Bescheid erfassen', $SELF, "
    .pos-row { display: flex; gap: 10px; align-items: flex-end; margin-bottom: 10px; padding: 12px; background: $boxColor; border: 1px solid #eee; border-radius: 8px; }
    .pos-row .field.sel { flex: 1; }
    .pos-row .field.amt { width: 160px; }
    .btn-remove { background: none; border: none; color: var(--danger-red); font-size: 1.1rem; cursor: pointer; padding: 8px; }
    .btn-add { background: var(--pkv-blue); color: #fff; border: none; width: 40px; height: 40px; border-radius: 50%; font-size: 1.3rem; cursor: pointer; }
    .btn-add:hover { background: #0056b3; } .btn-add:disabled { background: #aaa; cursor: not-allowed; }
    .add-wrap { display: flex; align-items: center; gap: 10px; margin-top: 5px; color: #666; font-size: 0.85rem; }
    .summary { display: flex; justify-content: space-between; align-items: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #eee; }
    .summary .total { font-size: 1.2rem; font-weight: bold; }
    h2.pos-title { font-size: 0.9rem; text-transform: uppercase; color: #666; margin: 25px 0 10px; border-top: 1px solid #eee; padding-top: 20px; }
");
?>
    <?php if (isset($_GET['ok'])): ?>
        <div class="msg ok">
            Bescheid gespeichert: <?= (int)$_GET['ok'] ?> Position(en), Erstattung gesamt
            <?= number_format((float)($_GET['sum'] ?? 0), 2, ',', '.') ?> €. Die Belege wurden bei <?= h($LABEL) ?> auf „beglichen“ gesetzt.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="msg err"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="POST" class="panel" id="bescheidForm">
        <input type="hidden" name="action" value="save_bescheid">

        <div class="form-row">
            <div class="field" style="flex:2; min-width:260px;">
                <label>Bescheid (Link zur PDF)</label>
                <input type="url" name="bescheid_link" value="<?= h($formLink) ?>" required>
            </div>
            <div class="field" style="width:150px;">
                <label>Bescheid-Datum</label>
                <input type="date" name="bescheid_date" value="<?= h($formDatum) ?>" required>
            </div>
            <div class="field" style="width:180px;">
                <label>Bescheidnummer</label>
                <input type="text" name="bescheid_nr" value="<?= h($formNr) ?>" required>
            </div>
        </div>

        <h2 class="pos-title">Positionen</h2>
        <?php if (!$offen): ?>
            <div class="msg err">Es gibt keine bei <?= h($LABEL) ?> eingereichten, noch offenen Belege.</div>
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
<?php
$flags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$js = 'const OFFEN = ' . json_encode($offen, $flags) . ";\n"
    . 'const PREFILL = ' . json_encode($prefillPositions, $flags) . ";\n"
    . 'const LABEL = ' . json_encode($LABEL, $flags) . ";\n"
    . <<<'JS'
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
    OFFEN.forEach(o => sel.add(new Option(`${o.person} · ${o.nr} · ${fmt(o.gesamt)}`, o.ref)));
    sel.value = ref;
    const inp = row.querySelector('input');
    inp.value = betrag;
    sel.addEventListener('change', () => { setExpected(sel, inp); refresh(); });
    inp.addEventListener('input', refresh);
    row.querySelector('.btn-remove').addEventListener('click', () => { row.remove(); refresh(); });
    container.appendChild(row);
    setExpected(sel, inp);
    refresh();
    if (!ref) sel.focus();
}

// erwartete Erstattung vorbelegen; manuell geänderte Werte bleiben beim Belegwechsel erhalten
function setExpected(sel, inp) {
    const o = OFFEN.find(x => x.ref === sel.value);
    inp.placeholder = o ? 'erwartet ' + o.erwartet.toFixed(2) : '';
    const untouched = inp.value === '' || inp.value === inp.dataset.auto;
    if (untouched) {
        const v = o ? o.erwartet.toFixed(2) : '';
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
    if (!confirm(`${n} Position(en) mit ${total} speichern und alle Belege bei ${LABEL} auf „beglichen“ setzen?`)) e.preventDefault();
});

if (PREFILL.length) PREFILL.forEach(p => addRow(p.ref, p.betrag)); else addRow();
JS;
pageEnd($js);
