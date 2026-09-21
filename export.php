<?php
require __DIR__ . '/common.php';

$PROFILES = loadProfiles();

$filterPerson = $_GET['person'] ?? 'alle';
if ($filterPerson !== 'alle' && !isset($PROFILES[$filterPerson])) $filterPerson = 'alle';
$filterStatus = ($_GET['status'] ?? 'alle') === 'offen' ? 'offen' : 'alle';

// Verfügbare Jahre sammeln
$all = [];
$years = [(int)date('Y')];
foreach ($PROFILES as $pKey => $p) {
    $all[$pKey] = loadData($pKey);
    foreach ($all[$pKey] as $r) {
        $y = (int)date('Y', strtotime($r['rg_datum']));
        if ($y > 2000) $years[] = $y;
    }
}
$years = array_values(array_unique($years));
rsort($years);

$filterYear = $_GET['year'] ?? (string)date('Y');
if ($filterYear !== 'alle' && !in_array((int)$filterYear, $years, true)) $filterYear = (string)$years[0];

// Abschnitte je Person aufbauen
$sections = [];
$total = ['gesamt' => 0.0, 'pkv' => 0.0, 'bh' => 0.0, 'eigen' => 0.0, 'offen_pkv' => 0.0, 'offen_bh' => 0.0, 'count' => 0];
foreach ($PROFILES as $pKey => $p) {
    if ($filterPerson !== 'alle' && $filterPerson !== $pKey) continue;
    $sec = ['name' => $p['name'], 'rows' => [], 'gesamt' => 0.0, 'pkv' => 0.0, 'bh' => 0.0, 'eigen' => 0.0, 'offen_pkv' => 0.0, 'offen_bh' => 0.0];
    foreach ($all[$pKey] as $r) {
        if ($filterYear !== 'alle' && date('Y', strtotime($r['rg_datum'])) !== $filterYear) continue;
        $pkvOffen = ($r['s_pkv'] ?? '') !== 'beglichen';
        $bhOffen  = ($r['s_bh'] ?? '') !== 'beglichen';
        if ($filterStatus === 'offen' && !$pkvOffen && !$bhOffen) continue;
        $sec['rows'][] = $r;
        $sec['gesamt'] += (float)$r['gesamt'];
        $sec['pkv']    += (float)($r['e_pkv'] ?? 0);
        $sec['bh']     += (float)($r['e_bh'] ?? 0);
        $sec['eigen']  += (float)$r['gesamt'] - (float)($r['e_pkv'] ?? 0) - (float)($r['e_bh'] ?? 0);
        if ($pkvOffen) $sec['offen_pkv'] += (float)($r['e_pkv'] ?? 0);
        if ($bhOffen)  $sec['offen_bh']  += (float)($r['e_bh'] ?? 0);
    }
    usort($sec['rows'], function ($a, $b) {
        return strcmp($a['rg_datum'], $b['rg_datum']) ?: strnatcasecmp($a['intern_nr'], $b['intern_nr']);
    });
    foreach (['gesamt', 'pkv', 'bh', 'eigen', 'offen_pkv', 'offen_bh'] as $k) $total[$k] += $sec[$k];
    $total['count'] += count($sec['rows']);
    $sections[$pKey] = $sec;
}

$titleParts = [];
$titleParts[] = $filterPerson === 'alle' ? 'Alle Personen' : $PROFILES[$filterPerson]['name'];
$titleParts[] = $filterYear === 'alle' ? 'alle Jahre' : $filterYear;
if ($filterStatus === 'offen') $titleParts[] = 'nur offene Erstattungen';

function cellStatus(array $r, string $typ): string {
    $st = $r['s_' . $typ] ?? '';
    $out = '<span class="badge ' . statusClass($st) . '">' . h($st) . '</span><br><strong>' . eur((float)($r['e_' . $typ] ?? 0)) . '</strong>';
    $meta = [];
    if (!empty($r[$typ . '_sub_date'])) $meta[] = 'Eing. ' . dmy($r[$typ . '_sub_date']);
    if (!empty($r[$typ . '_date']))     $meta[] = 'Begl. ' . dmy($r[$typ . '_date']);
    if (!empty($r[$typ . '_belegnr']))  $meta[] = 'Bescheid ' . h($r[$typ . '_belegnr']);
    if ($meta) $out .= '<div class="meta">' . implode('<br>', $meta) . '</div>';
    return $out;
}

pageStart('Export / PDF', 'export.php', '
    .report-title { margin: 0 0 4px; font-size: 1.3rem; }
    .report-sub { color: #666; font-size: 0.85rem; margin-bottom: 20px; }
    .section-title { margin: 25px 0 8px; font-size: 1.05rem; }
    .meta { font-size: 0.68rem; color: #666; margin-top: 3px; }
    tfoot td { font-weight: bold; background: #f8f9fa; border-top: 2px solid #ddd; }
    .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 10px; }
    .summary div { background: #f8f9fa; border-radius: 6px; padding: 8px 12px; }
    .summary small { display: block; font-size: 0.62rem; text-transform: uppercase; color: #666; font-weight: bold; }
    @media print {
        @page { size: A4 landscape; margin: 12mm; }
        body { font-size: 11px; }
        td, th { padding: 5px 6px; font-size: 0.72rem; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .section-title { page-break-after: avoid; }
        .summary div { border: 1px solid #ddd; }
        tfoot td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
');
?>
    <form method="GET" class="panel no-print">
        <div class="form-row" style="margin-bottom:0;">
            <div class="field">
                <label>Person</label>
                <select name="person">
                    <option value="alle">Alle</option>
                    <?php foreach ($PROFILES as $key => $p): ?>
                        <option value="<?= h($key) ?>" <?= $filterPerson === $key ? 'selected' : '' ?>><?= h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Jahr (Rechnungsdatum)</label>
                <select name="year">
                    <option value="alle" <?= $filterYear === 'alle' ? 'selected' : '' ?>>Alle Jahre</option>
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $filterYear === (string)$y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Umfang</label>
                <select name="status">
                    <option value="alle" <?= $filterStatus === 'alle' ? 'selected' : '' ?>>Alle Belege</option>
                    <option value="offen" <?= $filterStatus === 'offen' ? 'selected' : '' ?>>Nur mit offener Erstattung</option>
                </select>
            </div>
            <button type="submit" class="btn-secondary">Anzeigen</button>
            <button type="button" class="btn-save" style="padding:10px 20px; font-size:0.9rem;" onclick="window.print()">
                <i class="fa-solid fa-file-pdf"></i> Als PDF speichern / drucken
            </button>
        </div>
        <div class="muted" style="font-size:0.8rem; margin-top:10px;">
            Im Druckdialog „Als PDF speichern“ wählen (A4 quer ist voreingestellt). Kopf- und Fußzeilen des Browsers lassen sich dort abschalten.
        </div>
    </form>

    <h2 class="report-title">Abrechnung PKV &amp; Beihilfe</h2>
    <div class="report-sub"><?= h(implode(' · ', $titleParts)) ?> · Stand: <?= date('d.m.Y') ?></div>

    <?php if ($total['count'] === 0): ?>
        <div class="msg info">Für diese Auswahl gibt es keine Belege.</div>
    <?php endif; ?>

    <?php foreach ($sections as $sec): if (!$sec['rows']) continue; ?>
        <h3 class="section-title"><?= h($sec['name']) ?></h3>
        <table>
            <thead>
                <tr><th>Nr.</th><th>Datum</th><th>Arzt / Zweck</th><th class="num">Betrag</th><th>PKV</th><th>Beihilfe</th><th class="num">Eigenanteil</th></tr>
            </thead>
            <tbody>
            <?php foreach ($sec['rows'] as $r): ?>
                <tr>
                    <td><strong><?= h($r['intern_nr']) ?></strong></td>
                    <td><?= dmy($r['rg_datum']) ?></td>
                    <td><strong><?= h($r['arzt']) ?></strong><br><small><?= h($r['beschreibung'] ?? '') ?></small></td>
                    <td class="num"><?= eur((float)$r['gesamt']) ?></td>
                    <td><?= cellStatus($r, 'pkv') ?></td>
                    <td><?= cellStatus($r, 'bh') ?></td>
                    <td class="num"><?= eur((float)$r['gesamt'] - (float)($r['e_pkv'] ?? 0) - (float)($r['e_bh'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3">Summe <?= h($sec['name']) ?></td>
                    <td class="num"><?= eur($sec['gesamt']) ?></td>
                    <td><?= eur($sec['pkv']) ?><br><span class="meta">offen: <?= eur($sec['offen_pkv']) ?></span></td>
                    <td><?= eur($sec['bh']) ?><br><span class="meta">offen: <?= eur($sec['offen_bh']) ?></span></td>
                    <td class="num"><?= eur($sec['eigen']) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endforeach; ?>

    <?php if ($total['count'] > 0): ?>
        <h3 class="section-title">Gesamtübersicht</h3>
        <div class="summary">
            <div><small>Belege</small><strong><?= $total['count'] ?></strong></div>
            <div><small>Rechnungssumme</small><strong><?= eur($total['gesamt']) ?></strong></div>
            <div><small>PKV-Erstattung</small><strong><?= eur($total['pkv']) ?></strong><br><span class="meta">davon offen: <?= eur($total['offen_pkv']) ?></span></div>
            <div><small>Beihilfe-Erstattung</small><strong><?= eur($total['bh']) ?></strong><br><span class="meta">davon offen: <?= eur($total['offen_bh']) ?></span></div>
            <div><small>Eigenanteil</small><strong><?= eur($total['eigen']) ?></strong></div>
        </div>
    <?php endif; ?>
<?php
pageEnd();
