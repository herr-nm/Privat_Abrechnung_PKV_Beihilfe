<?php
require __DIR__ . '/common.php';

$PROFILES = loadProfiles();
// --- ALLE DATEN LADEN UND VERFÜGBARE JAHRE ERMITTELN ---
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

$year = (int)($_GET['year'] ?? date('Y'));
if (!in_array($year, $years, true)) $year = $years[0];

// --- SPEICHERN: ERWARTETE RÜCKERSTATTUNG JE PERSON ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_bre') {
    $y = (int)($_POST['year'] ?? $year);
    foreach ($PROFILES as $pKey => $p) {
        saveBre($y, (string)$pKey,
            parseAmount($_POST['bre'][$pKey] ?? '') ?? 0.0,
            parseAmount($_POST['est'][$pKey] ?? '') ?? 0.0);
    }
    header('Location: ' . basename(__FILE__) . '?year=' . $y . '&saved=1');
    exit;
}
$bre = loadBre();

// --- BERECHNUNG JE PERSON (Zuordnung nach Rechnungsdatum) ---
$calc = [];
foreach ($PROFILES as $pKey => $p) {
    $c = ['count' => 0, 'gesamt' => 0.0, 'eingereicht' => 0.0, 'offen' => 0.0, 'rows' => []];
    foreach ($all[$pKey] as $id => $r) {
        if ((int)date('Y', strtotime($r['rg_datum'])) !== $year) continue;
        $c['count']++;
        $c['gesamt'] += (float)$r['gesamt'];
        $e = (float)($r['e_pkv'] ?? 0);
        if (($r['s_pkv'] ?? '') === 'offen') $c['offen'] += $e; else $c['eingereicht'] += $e;
        $c['rows'][] = $r;
    }
    usort($c['rows'], function ($a, $b) { return (float)$b['e_pkv'] <=> (float)$a['e_pkv']; });
    $c['bre'] = (float)($bre[$year][$pKey]['bre'] ?? 0);
    $c['est'] = (float)($bre[$year][$pKey]['est'] ?? 0);
    $c['erstattung'] = $c['eingereicht'] + $c['offen'] + $c['est'];
    $c['diff'] = $c['bre'] - $c['erstattung']; // > 0: Verzicht lohnt sich rechnerisch
    $calc[$pKey] = $c;
}

pageStart('Beitragsrückerstattung', 'beitragsrueckerstattung.php', '
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 15px 0; }
    .kpi { background: #f8f9fa; border-radius: 8px; padding: 10px 14px; }
    .kpi small { display: block; font-size: 0.65rem; color: #666; text-transform: uppercase; font-weight: bold; }
    .kpi strong { font-size: 1.15rem; }
    details summary { cursor: pointer; color: #007bff; font-size: 0.85rem; margin-top: 10px; }
    details table { margin-top: 10px; box-shadow: none; }
');
?>
    <div class="person-nav">
        <?php foreach ($years as $y): ?>
            <a href="?year=<?= $y ?>" class="<?= $y === $year ? 'active' : '' ?>"><?= $y ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (isset($_GET['saved'])): ?><div class="msg ok">Angaben gespeichert.</div><?php endif; ?>

    <div class="msg info">
        Vergleicht je Person die erwartete Beitragsrückerstattung mit den PKV-Erstattungen des Jahres. Verzichtest du auf die PKV-Einreichung,
        trägst du die PKV-Anteile selbst, erhältst aber (falls der Tarif es vorsieht) die Rückerstattung. Angenommen wird, dass die Beihilfe unverändert eingereicht wird.
    </div>

    <?php if (!$PROFILES): ?>
        <div class="msg err">Keine Personen in der .env gefunden.</div>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="action" value="save_bre">
        <input type="hidden" name="year" value="<?= $year ?>">

        <?php foreach ($PROFILES as $pKey => $p): $c = $calc[$pKey]; ?>
        <div class="panel">
            <h2 style="margin:0 0 5px;"><?= h($p['name']) ?> – <?= $year ?></h2>
            <div class="muted" style="font-size:0.85rem;"><?= $c['count'] ?> Rechnung(en), Rechnungssumme <?= eur($c['gesamt']) ?></div>

            <div class="form-row" style="margin-top:15px;">
                <div class="field" style="width:220px;">
                    <label>Beitragsrückerstattung € (erwartet)</label>
                    <input type="number" step="0.01" min="0" name="bre[<?= h($pKey) ?>]" value="<?= $c['bre'] > 0 ? h(number_format($c['bre'], 2, '.', '')) : '' ?>">
                </div>
                <div class="field" style="width:260px;">
                    <label>Geschätzte weitere PKV-Erstattungen € (Restjahr)</label>
                    <input type="number" step="0.01" min="0" name="est[<?= h($pKey) ?>]" value="<?= $c['est'] > 0 ? h(number_format($c['est'], 2, '.', '')) : '' ?>">
                </div>
            </div>

            <div class="kpis">
                <div class="kpi"><small>PKV bereits eingereicht</small><strong><?= eur($c['eingereicht']) ?></strong></div>
                <div class="kpi"><small>PKV noch nicht eingereicht</small><strong><?= eur($c['offen']) ?></strong></div>
                <div class="kpi"><small>Geschätzt weitere</small><strong><?= eur($c['est']) ?></strong></div>
                <div class="kpi"><small>PKV-Erstattungen gesamt</small><strong><?= eur($c['erstattung']) ?></strong></div>
                <div class="kpi"><small>Beitragsrückerstattung</small><strong><?= eur($c['bre']) ?></strong></div>
            </div>

            <?php if ($c['bre'] <= 0): ?>
                <div class="msg warn" style="margin-bottom:0;">Bitte die erwartete Beitragsrückerstattung eintragen und speichern, um den Vergleich zu sehen.</div>
            <?php else: ?>
                <?php if ($c['eingereicht'] > 0): ?>
                    <div class="msg warn">
                        Für <?= $year ?> wurden bereits PKV-Leistungen im Wert von <?= eur($c['eingereicht']) ?> eingereicht. Je nach Tarif entfällt die Rückerstattung dann bereits – bitte in den Tarifbedingungen prüfen.
                    </div>
                <?php endif; ?>
                <?php if ($c['diff'] > 0): ?>
                    <div class="msg ok" style="margin-bottom:0;">
                        <strong>Verzicht rechnerisch günstiger:</strong> Die Rückerstattung übersteigt die PKV-Erstattungen um <strong><?= eur($c['diff']) ?></strong>.
                    </div>
                <?php elseif ($c['diff'] < 0): ?>
                    <div class="msg err" style="margin-bottom:0;">
                        <strong>Einreichen rechnerisch günstiger:</strong> Die PKV-Erstattungen übersteigen die Rückerstattung um <strong><?= eur(-$c['diff']) ?></strong>.
                    </div>
                <?php else: ?>
                    <div class="msg info" style="margin-bottom:0;">Beide Varianten sind rechnerisch gleich.</div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($c['rows']): ?>
            <details>
                <summary>Belege <?= $year ?> anzeigen (nach PKV-Anteil sortiert)</summary>
                <table>
                    <thead><tr><th>Nr.</th><th>Datum</th><th>Arzt / Zweck</th><th class="num">Betrag</th><th class="num">PKV-Anteil</th><th>PKV-Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($c['rows'] as $r): ?>
                        <tr>
                            <td><strong><?= h($r['intern_nr']) ?></strong></td>
                            <td><?= dmy($r['rg_datum']) ?></td>
                            <td><?= h($r['arzt']) ?><br><small><?= h($r['beschreibung'] ?? '') ?></small></td>
                            <td class="num"><?= eur((float)$r['gesamt']) ?></td>
                            <td class="num"><?= eur((float)($r['e_pkv'] ?? 0)) ?></td>
                            <td><span class="badge <?= statusClass($r['s_pkv'] ?? '') ?>"><?= h($r['s_pkv'] ?? '') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <button type="submit" class="btn-save">Speichern &amp; neu berechnen</button>
    </form>

    <p class="muted" style="font-size:0.8rem; margin-top:25px;">
        Hinweise: Die Zuordnung zum Jahr erfolgt nach Rechnungsdatum; manche Tarife zählen stattdessen nach Einreichdatum oder Leistungsdatum.
        Ob eine Rückerstattung teilweise, gestaffelt oder nur bei völliger Leistungsfreiheit gezahlt wird, hängt vom Tarif ab und ist hier nicht abgebildet.
        Steuerliche Effekte bleiben unberücksichtigt. Es handelt sich um eine reine Rechenhilfe.
    </p>
    <?php endif; ?>
<?php
pageEnd();
