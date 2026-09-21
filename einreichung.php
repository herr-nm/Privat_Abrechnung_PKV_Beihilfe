<?php
require __DIR__ . '/common.php';

$PROFILES = loadProfiles();
$filter = $_GET['person'] ?? 'alle';
if ($filter !== 'alle' && !isset($PROFILES[$filter])) $filter = 'alle';
$errors = [];
$typLabel = ['bh' => 'Beihilfe', 'pkv' => 'PKV'];

// --- SAMMEL-EINREICHUNG SPEICHERN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_batch') {
    $subDate = trim($_POST['sub_date'] ?? '');
    $sel = is_array($_POST['sel'] ?? null) ? $_POST['sel'] : [];

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subDate)) $errors[] = 'Bitte ein gültiges Einreichdatum angeben.';
    if (!$sel) $errors[] = 'Bitte mindestens einen Beleg auswählen.';

    $items = [];
    $seen = [];
    if (!$errors) {
        foreach ($sel as $entry) {
            $entry = (string)$entry;
            if (isset($seen[$entry])) continue;
            $seen[$entry] = true;
            $parts = explode('|', $entry, 3);
            if (count($parts) !== 3) { $errors[] = 'Ungültige Auswahl.'; continue; }
            [$pKey, $id, $typ] = $parts;
            if (!isset($PROFILES[$pKey]) || !isset($typLabel[$typ])) { $errors[] = 'Ungültige Auswahl.'; continue; }
            $items[] = [$pKey, $id, $typ];
        }
    }

    if (!$errors) {
        // Alle Einreichungen in EINER Transaktion: entweder alle oder keine
        $errors = transaction(function (PDO $pdo) use ($items, $subDate, $typLabel): array {
            $err = [];
            $info = $pdo->prepare('SELECT intern_nr FROM belege WHERE id = ? AND person = ?');
            foreach ($items as [$pKey, $id, $typ]) {
                $u = $pdo->prepare("UPDATE belege SET s_$typ = 'eingereicht', {$typ}_sub_date = ? WHERE id = ? AND person = ? AND s_$typ = 'offen'");
                $u->execute([$subDate, $id, $pKey]);
                if ($u->rowCount() !== 1) {
                    $info->execute([$id, $pKey]);
                    $nr = $info->fetchColumn();
                    $err[] = $nr === false
                        ? 'Beleg nicht gefunden.'
                        : 'Beleg ' . $nr . ' ist bei ' . $typLabel[$typ] . ' nicht mehr im Status „offen“.';
                }
            }
            return $err;
        });
    }

    if (!$errors) {
        header('Location: ' . basename(__FILE__) . '?person=' . urlencode($filter) . '&ok=' . count($items));
        exit;
    }
}

// --- BELEGE MIT MINDESTENS EINEM OFFENEN STATUS ---
$rows = [];
foreach ($PROFILES as $pKey => $p) {
    if ($filter !== 'alle' && $filter !== $pKey) continue;
    foreach (loadData($pKey) as $id => $r) {
        if (($r['s_bh'] ?? '') === 'offen' || ($r['s_pkv'] ?? '') === 'offen') {
            $rows[] = ['pKey' => $pKey, 'person' => $p['name'], 'id' => $id, 'r' => $r];
        }
    }
}
usort($rows, function ($a, $b) {
    return strcmp($a['r']['rg_datum'], $b['r']['rg_datum']) ?: strnatcasecmp($a['r']['intern_nr'], $b['r']['intern_nr']);
});

pageStart('Sammel-Einreichung', 'einreichung.php', '.chk { text-transform: none; font-size: 0.85rem; font-weight: normal; color: #222; display: flex; gap: 6px; align-items: center; cursor: pointer; } .age { font-size: 0.7rem; color: #888; }');
?>
    <div class="person-nav">
        <a href="?person=alle" class="<?= $filter === 'alle' ? 'active' : '' ?>">Alle</a>
        <?php foreach ($PROFILES as $key => $p): ?>
            <a href="?person=<?= urlencode($key) ?>" class="<?= $filter === $key ? 'active' : '' ?>"><?= h($p['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (isset($_GET['ok'])): ?>
        <div class="msg ok"><?= (int)$_GET['ok'] ?> Einreichung(en) gespeichert. Die Belege stehen jetzt auf „eingereicht“.</div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="msg err"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="msg info">Es gibt keine Belege mit offenem Einreichungsstatus.</div>
    <?php else: ?>
    <form method="POST" id="batchForm">
        <input type="hidden" name="action" value="submit_batch">

        <div class="panel">
            <div class="form-row" style="margin-bottom:0;">
                <div class="field" style="width:160px;">
                    <label>Einreichdatum</label>
                    <input type="date" name="sub_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="muted" style="font-size:0.85rem; padding-bottom:8px;">
                    Häkchen setzen bei den Belegen, die jetzt bei der Beihilfe bzw. PKV eingereicht werden.
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Person</th><th>Nr.</th><th>Datum</th><th>Arzt / Zweck</th><th class="num">Betrag</th>
                    <th><label class="chk"><input type="checkbox" class="cb-all" data-type="bh"> Beihilfe</label></th>
                    <th><label class="chk"><input type="checkbox" class="cb-all" data-type="pkv"> PKV</label></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): $r = $row['r']; ?>
                <?php $age = (int)floor((time() - strtotime($r['rg_datum'])) / 86400); ?>
                <tr>
                    <td><?= h($row['person']) ?></td>
                    <td><strong><?= h($r['intern_nr']) ?></strong></td>
                    <td><?= dmy($r['rg_datum']) ?><br><span class="age">vor <?= $age ?> Tagen</span></td>
                    <td>
                        <strong><?= h($r['arzt']) ?></strong>
                        <?php if (!empty($r['doc_link'])): ?><a href="<?= h($r['doc_link']) ?>" target="_blank" title="Rechnung öffnen">📄</a><?php endif; ?>
                        <br><small><?= h($r['beschreibung'] ?? '') ?></small>
                    </td>
                    <td class="num"><strong><?= eur((float)$r['gesamt']) ?></strong></td>
                    <?php foreach ($typLabel as $typ => $label): $st = $r['s_' . $typ] ?? ''; ?>
                        <td>
                            <?php if ($st === 'offen'): ?>
                                <label class="chk">
                                    <input type="checkbox" class="cb-<?= $typ ?>" name="sel[]"
                                           value="<?= h($row['pKey'] . '|' . $row['id'] . '|' . $typ) ?>"
                                           data-amount="<?= (float)($r['e_' . $typ] ?? 0) ?>">
                                    <?= eur((float)($r['e_' . $typ] ?? 0)) ?>
                                </label>
                            <?php else: ?>
                                <span class="badge <?= statusClass($st) ?>"><?= h($st) ?></span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="panel" style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
            <div>
                Beihilfe: <strong id="sumBh">0,00 €</strong> (<span id="cntBh">0</span> Belege) &nbsp;|&nbsp;
                PKV: <strong id="sumPkv">0,00 €</strong> (<span id="cntPkv">0</span> Belege)
            </div>
            <button type="submit" class="btn-save" id="saveBtn" disabled>Als eingereicht speichern</button>
        </div>
    </form>
    <?php endif; ?>
<?php
pageEnd(<<<'JS'
const fmt = n => n.toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });
function recalc() {
    let total = 0;
    [['bh', 'Bh'], ['pkv', 'Pkv']].forEach(([t, id]) => {
        let sum = 0, n = 0;
        document.querySelectorAll('.cb-' + t + ':checked').forEach(cb => { sum += parseFloat(cb.dataset.amount) || 0; n++; });
        document.getElementById('sum' + id).textContent = fmt(sum);
        document.getElementById('cnt' + id).textContent = n;
        total += n;
        const all = document.querySelector('.cb-all[data-type="' + t + '"]');
        const boxes = document.querySelectorAll('.cb-' + t);
        if (all) all.checked = boxes.length > 0 && n === boxes.length;
    });
    document.getElementById('saveBtn').disabled = total === 0;
}
document.querySelectorAll('.cb-all').forEach(all => {
    all.addEventListener('change', () => {
        document.querySelectorAll('.cb-' + all.dataset.type).forEach(cb => cb.checked = all.checked);
        recalc();
    });
});
document.querySelectorAll('.cb-bh, .cb-pkv').forEach(cb => cb.addEventListener('change', recalc));
const form = document.getElementById('batchForm');
if (form) form.addEventListener('submit', e => {
    const n = document.querySelectorAll('.cb-bh:checked, .cb-pkv:checked').length;
    if (!confirm(n + ' Einreichung(en) mit diesem Datum als „eingereicht“ speichern?')) e.preventDefault();
});
recalc();
JS);
