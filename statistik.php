<?php
require __DIR__ . '/common.php';

$PROFILES = loadProfiles();
$cur = (int)date('Y');
$oldestYear = $cur - 5; // laufendes Jahr + bis zu 5 Vorjahre

$typLabel = ['pkv' => 'PKV', 'bh' => 'Beihilfe'];

// --- DATEN LADEN & AUSWERTEN ---
$open = [];   // je Person: Summen offen / eingereicht je Typ
$openTotal = [];
$yearly = []; // je Person: Jahr => Kennzahlen
foreach (array_keys($typLabel) as $t) {
    $openTotal[$t] = ['offen' => 0.0, 'eingereicht' => 0.0, 'cnt_offen' => 0, 'cnt_eingereicht' => 0, 'oldest' => null];
}

foreach ($PROFILES as $pKey => $p) {
    $open[$pKey] = [];
    foreach (array_keys($typLabel) as $t) {
        $open[$pKey][$t] = ['offen' => 0.0, 'eingereicht' => 0.0, 'cnt_offen' => 0, 'cnt_eingereicht' => 0, 'oldest' => null];
    }
    $yearly[$pKey] = [];

    foreach (loadData($pKey) as $r) {
        // Offene Summen (Logik wie im Dashboard: alles außer "beglichen")
        foreach (array_keys($typLabel) as $t) {
            $st = $r['s_' . $t] ?? '';
            if ($st === 'beglichen') continue;
            $e = (float)($r['e_' . $t] ?? 0);
            $k = ($st === 'eingereicht') ? 'eingereicht' : 'offen';
            $open[$pKey][$t][$k] += $e;
            $open[$pKey][$t]['cnt_' . $k]++;
            $openTotal[$t][$k] += $e;
            $openTotal[$t]['cnt_' . $k]++;
            if ($k === 'eingereicht' && !empty($r[$t . '_sub_date'])) {
                $d = $r[$t . '_sub_date'];
                if ($open[$pKey][$t]['oldest'] === null || $d < $open[$pKey][$t]['oldest']) $open[$pKey][$t]['oldest'] = $d;
                if ($openTotal[$t]['oldest'] === null || $d < $openTotal[$t]['oldest']) $openTotal[$t]['oldest'] = $d;
            }
        }

        // Jahreswerte nach Rechnungsdatum
        $y = (int)date('Y', strtotime($r['rg_datum']));
        if ($y < 2000) continue;
        if (!isset($yearly[$pKey][$y])) $yearly[$pKey][$y] = ['count' => 0, 'gesamt' => 0.0, 'pkv' => 0.0, 'bh' => 0.0];
        $yearly[$pKey][$y]['count']++;
        $yearly[$pKey][$y]['gesamt'] += (float)$r['gesamt'];
        $yearly[$pKey][$y]['pkv']    += (float)($r['e_pkv'] ?? 0);
        $yearly[$pKey][$y]['bh']     += (float)($r['e_bh'] ?? 0);
    }
}

// Auswahl: laufendes Jahr + Vorjahre der letzten 5 Jahre, sofern Daten vorhanden
function selectYears(array $byYear, int $cur, int $oldest): array {
    $sel = [];
    foreach ($byYear as $y => $v) {
        if ($y === $cur || ($y >= $oldest && $y < $cur)) $sel[$y] = $v;
    }
    krsort($sel);
    return $sel;
}

// Summe über alle Personen je Jahr
$yearlyAll = [];
foreach ($yearly as $byYear) {
    foreach ($byYear as $y => $v) {
        if (!isset($yearlyAll[$y])) $yearlyAll[$y] = ['count' => 0, 'gesamt' => 0.0, 'pkv' => 0.0, 'bh' => 0.0];
        foreach ($v as $k => $val) $yearlyAll[$y][$k] += $val;
    }
}

function eigen(array $v): float { return $v['gesamt'] - $v['pkv'] - $v['bh']; }

function bar(array $v): string {
    if ($v['gesamt'] <= 0) return '';
    $pkv = max(0, $v['pkv'] / $v['gesamt'] * 100);
    $bh  = max(0, $v['bh'] / $v['gesamt'] * 100);
    $ei  = max(0, 100 - $pkv - $bh);
    return '<div class="bar" title="PKV ' . round($pkv) . ' % · Beihilfe ' . round($bh) . ' % · Eigenanteil ' . round($ei) . ' %">'
         . '<span style="width:' . round($pkv, 1) . '%; background:#007bff"></span>'
         . '<span style="width:' . round($bh, 1) . '%; background:#fd7e14"></span>'
         . '<span style="width:' . round($ei, 1) . '%; background:#cc0a00"></span></div>';
}

function trend(float $now, ?float $before): string {
    if ($before === null || $before <= 0) return '<span class="muted">–</span>';
    $pct = ($now - $before) / $before * 100;
    $cls = $pct > 0.5 ? 'up' : ($pct < -0.5 ? 'down' : 'flat');
    $arrow = $pct > 0.5 ? '▲' : ($pct < -0.5 ? '▼' : '●');
    return '<span class="trend ' . $cls . '">' . $arrow . ' ' . number_format(abs($pct), 1, ',', '.') . ' %</span>';
}

function renderYearTable(array $byYear, array $allYears, int $cur): void {
    $sel = selectYears($byYear, $cur, $cur - 5);
    if (!$sel) { echo '<div class="msg info" style="margin-bottom:0;">Keine Belege in den letzten Jahren vorhanden.</div>'; return; }
    ?>
    <table>
        <thead>
            <tr>
                <th>Jahr</th><th class="num">Belege</th><th class="num">Rechnungssumme</th>
                <th class="num">PKV</th><th class="num">Beihilfe</th><th class="num">Eigenanteil</th>
                <th class="num">Eigenanteil %</th><th class="num">Δ Rechnungssumme zum Vorjahr</th><th>Aufteilung</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sel as $y => $v): $e = eigen($v); $prev = $byYear[$y - 1]['gesamt'] ?? null; ?>
            <tr>
                <td><strong><?= $y ?></strong><?= $y === $cur ? ' <span class="muted" style="font-size:0.7rem;">(laufend)</span>' : '' ?></td>
                <td class="num"><?= $v['count'] ?></td>
                <td class="num"><strong><?= eur($v['gesamt']) ?></strong></td>
                <td class="num"><?= eur($v['pkv']) ?></td>
                <td class="num"><?= eur($v['bh']) ?></td>
                <td class="num"><?= eur($e) ?></td>
                <td class="num"><?= $v['gesamt'] > 0 ? number_format($e / $v['gesamt'] * 100, 1, ',', '.') . ' %' : '–' ?></td>
                <td class="num"><?= trend($v['gesamt'], $prev !== null ? (float)$prev : null) ?></td>
                <td style="min-width:140px;"><?= bar($v) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php
        // Durchschnitt der abgeschlossenen Vorjahre (ohne laufendes Jahr)
        $prior = array_filter($sel, function ($y) use ($cur) { return $y !== $cur; }, ARRAY_FILTER_USE_KEY);
        if ($prior):
            $n = count($prior);
            $avg = ['count' => 0, 'gesamt' => 0.0, 'pkv' => 0.0, 'bh' => 0.0];
            foreach ($prior as $v) foreach ($avg as $k => $_) $avg[$k] += $v[$k];
            foreach ($avg as $k => $_) $avg[$k] /= $n;
            $ea = eigen($avg);
        ?>
        <tfoot>
            <tr>
                <td>Ø Vorjahre (<?= $n ?>)</td>
                <td class="num"><?= number_format($avg['count'], 1, ',', '.') ?></td>
                <td class="num"><?= eur($avg['gesamt']) ?></td>
                <td class="num"><?= eur($avg['pkv']) ?></td>
                <td class="num"><?= eur($avg['bh']) ?></td>
                <td class="num"><?= eur($ea) ?></td>
                <td class="num"><?= $avg['gesamt'] > 0 ? number_format($ea / $avg['gesamt'] * 100, 1, ',', '.') . ' %' : '–' ?></td>
                <td class="num"><?php if (isset($sel[$cur])) echo trend($sel[$cur]['gesamt'], $avg['gesamt']); ?></td>
                <td class="muted" style="font-size:0.7rem;"><?= isset($sel[$cur]) ? '← laufendes Jahr vs. Ø' : '' ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    <?php
}

pageStart('Statistik', 'statistik.php', '
    .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 25px; }
    .kpi { background: #fff; border-radius: 10px; padding: 15px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); border-left: 5px solid #fd7e14; }
    .kpi.total { border-left-color: #cc0a00; }
    .kpi small { display: block; font-size: 0.7rem; color: #666; text-transform: uppercase; font-weight: bold; }
    .kpi strong { font-size: 1.5rem; display: block; margin: 4px 0; }
    .kpi .sub { font-size: 0.78rem; color: #666; line-height: 1.5; }
    h2.sect { font-size: 1.05rem; margin: 30px 0 12px; color: #333; }
    .bar { display: flex; height: 10px; border-radius: 5px; overflow: hidden; background: #eee; min-width: 120px; }
    .bar span { display: block; height: 100%; }
    .trend.up { color: #dc3545; font-weight: bold; } .trend.down { color: #28a745; font-weight: bold; } .trend.flat { color: #666; }
    .legend { font-size: 0.75rem; color: #666; display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 12px; }
    .legend i { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 4px; }
    tfoot td { font-weight: bold; background: #f8f9fa; border-top: 2px solid #ddd; }
');
?>
    <?php if (!$PROFILES): ?>
        <div class="msg err">Keine Personen in der .env gefunden.</div>
    <?php else: ?>

    <h2 class="sect" style="margin-top:0;">Aktuell offene Summen (alle Personen)</h2>
    <div class="kpis">
        <?php foreach ($typLabel as $t => $label): $o = $openTotal[$t]; ?>
            <div class="kpi">
                <small>Offen <?= h($label) ?></small>
                <strong><?= eur($o['offen'] + $o['eingereicht']) ?></strong>
                <div class="sub">
                    Noch nicht eingereicht: <strong style="display:inline; font-size:inherit;"><?= eur($o['offen']) ?></strong> (<?= $o['cnt_offen'] ?> Belege)<br>
                    Eingereicht, wartet auf Erstattung: <strong style="display:inline; font-size:inherit;"><?= eur($o['eingereicht']) ?></strong> (<?= $o['cnt_eingereicht'] ?> Belege)
                    <?php if ($o['oldest']): ?><br>Ältere Einreichung seit <?= dmy($o['oldest']) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="kpi total">
            <small>Offen gesamt (PKV + Beihilfe)</small>
            <strong><?= eur($openTotal['pkv']['offen'] + $openTotal['pkv']['eingereicht'] + $openTotal['bh']['offen'] + $openTotal['bh']['eingereicht']) ?></strong>
            <div class="sub">
                Noch nicht eingereicht: <?= eur($openTotal['pkv']['offen'] + $openTotal['bh']['offen']) ?><br>
                Wartet auf Erstattung: <?= eur($openTotal['pkv']['eingereicht'] + $openTotal['bh']['eingereicht']) ?>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Person</th>
                <th class="num">PKV nicht eingereicht</th><th class="num">PKV eingereicht</th>
                <th class="num">Beihilfe nicht eingereicht</th><th class="num">Beihilfe eingereicht</th>
                <th class="num">Offen gesamt</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($PROFILES as $pKey => $p): $o = $open[$pKey]; ?>
            <?php $sum = $o['pkv']['offen'] + $o['pkv']['eingereicht'] + $o['bh']['offen'] + $o['bh']['eingereicht']; ?>
            <tr>
                <td><strong><?= h($p['name']) ?></strong></td>
                <td class="num"><?= eur($o['pkv']['offen']) ?></td>
                <td class="num"><?= eur($o['pkv']['eingereicht']) ?></td>
                <td class="num"><?= eur($o['bh']['offen']) ?></td>
                <td class="num"><?= eur($o['bh']['eingereicht']) ?></td>
                <td class="num"><strong><?= eur($sum) ?></strong></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if (count($PROFILES) > 1): ?>
        <tfoot>
            <tr>
                <td>Summe</td>
                <td class="num"><?= eur($openTotal['pkv']['offen']) ?></td>
                <td class="num"><?= eur($openTotal['pkv']['eingereicht']) ?></td>
                <td class="num"><?= eur($openTotal['bh']['offen']) ?></td>
                <td class="num"><?= eur($openTotal['bh']['eingereicht']) ?></td>
                <td class="num"><?= eur($openTotal['pkv']['offen'] + $openTotal['pkv']['eingereicht'] + $openTotal['bh']['offen'] + $openTotal['bh']['eingereicht']) ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <h2 class="sect">Jahresvergleich (laufendes Jahr und bis zu 5 Vorjahre, nach Rechnungsdatum)</h2>
    <div class="legend">
        <span><i style="background:#007bff"></i>PKV-Erstattung</span>
        <span><i style="background:#fd7e14"></i>Beihilfe-Erstattung</span>
        <span><i style="background:#cc0a00"></i>Eigenanteil</span>
        <span>▲ / ▼ = Veränderung der Rechnungssumme zum Vorjahr</span>
    </div>

    <?php if (count($PROFILES) > 1): ?>
        <div class="panel">
            <h3 style="margin:0 0 12px;">Alle Personen</h3>
            <?php renderYearTable($yearlyAll, [], $cur); ?>
        </div>
    <?php endif; ?>

    <?php foreach ($PROFILES as $pKey => $p): ?>
        <div class="panel">
            <h3 style="margin:0 0 12px;"><?= h($p['name']) ?></h3>
            <?php renderYearTable($yearly[$pKey], [], $cur); ?>
        </div>
    <?php endforeach; ?>

    <p class="muted" style="font-size:0.8rem;">
        Offene Summen zählen wie im Dashboard alle Erstattungen, deren Status nicht „beglichen“ ist. Das laufende Jahr ist unvollständig und daher nur bedingt mit Vorjahren vergleichbar.
        Erstattungen sind die in den Belegen hinterlegten Beträge (erwartet oder aus dem Bescheid übernommen).
    </p>
    <?php endif; ?>
<?php
pageEnd();
