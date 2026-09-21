<?php
// Gemeinsame Funktionen: .env, SQLite-Zugriff und Seitengerüst.
// Wird von allen Seiten per require eingebunden. Diese Datei gibt selbst nichts aus.

// ---------------------------------------------------------------
// .env
// ---------------------------------------------------------------
function readEnv(): array {
    static $env = null;
    if ($env !== null) return $env;
    $env = ['profiles' => [], 'config' => []];
    $file = __DIR__ . '/.env';
    if (!file_exists($file)) return $env;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) continue;
        $key = trim($parts[0]); $value = trim($parts[1]);
        if (strpos($key, 'PERSON_') === 0) {
            $p = explode(',', $value);
            if (count($p) < 5) continue;
            $env['profiles'][$p[0]] = ['name' => $p[1], 'prefix' => $p[2], 'pkv' => (float)$p[3], 'bh' => (float)$p[4]];
        } else {
            $env['config'][$key] = $value;
        }
    }
    return $env;
}

function loadProfiles(): array { return readEnv()['profiles']; }

// ---------------------------------------------------------------
// SQLite
// ---------------------------------------------------------------
const BELEG_TEXT = ['intern_nr', 'rg_datum', 'arzt', 'beschreibung', 'doc_link', 'z_status', 'z_datum',
    's_pkv', 'pkv_sub_date', 'pkv_date', 'pkv_belegnr', 'pkv_link',
    's_bh', 'bh_sub_date', 'bh_date', 'bh_belegnr', 'bh_link'];
const BELEG_REAL = ['gesamt', 'e_pkv', 'e_bh'];

function belegColumns(): array { return array_merge(BELEG_TEXT, BELEG_REAL); }

function dbPath(): string {
    $cfg = readEnv()['config']['DB_FILE'] ?? '';
    if ($cfg === '') return __DIR__ . '/abrechnung.sqlite';
    $absolute = $cfg[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $cfg);
    return $absolute ? $cfg : __DIR__ . '/' . $cfg;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        exit('Die PHP-Erweiterung pdo_sqlite ist nicht aktiviert.');
    }
    $pdo = new PDO('sqlite:' . dbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000');

    $stmts = [
        "CREATE TABLE IF NOT EXISTS belege (
            id           TEXT PRIMARY KEY,
            person       TEXT NOT NULL,
            intern_nr    TEXT NOT NULL,
            rg_datum     TEXT NOT NULL,
            arzt         TEXT NOT NULL DEFAULT '',
            beschreibung TEXT NOT NULL DEFAULT '',
            doc_link     TEXT NOT NULL DEFAULT '',
            gesamt       REAL NOT NULL DEFAULT 0,
            z_status     TEXT NOT NULL DEFAULT 'offen',
            z_datum      TEXT NOT NULL DEFAULT '',
            s_pkv        TEXT NOT NULL DEFAULT 'offen',
            e_pkv        REAL NOT NULL DEFAULT 0,
            pkv_sub_date TEXT NOT NULL DEFAULT '',
            pkv_date     TEXT NOT NULL DEFAULT '',
            pkv_belegnr  TEXT NOT NULL DEFAULT '',
            pkv_link     TEXT NOT NULL DEFAULT '',
            s_bh         TEXT NOT NULL DEFAULT 'offen',
            e_bh         REAL NOT NULL DEFAULT 0,
            bh_sub_date  TEXT NOT NULL DEFAULT '',
            bh_date      TEXT NOT NULL DEFAULT '',
            bh_belegnr   TEXT NOT NULL DEFAULT '',
            bh_link      TEXT NOT NULL DEFAULT ''
        )",
        "CREATE INDEX IF NOT EXISTS idx_belege_person_datum ON belege (person, rg_datum)",
        "CREATE INDEX IF NOT EXISTS idx_belege_s_pkv ON belege (s_pkv)",
        "CREATE INDEX IF NOT EXISTS idx_belege_s_bh ON belege (s_bh)",
        "CREATE TABLE IF NOT EXISTS bre (
            jahr   INTEGER NOT NULL,
            person TEXT NOT NULL,
            bre    REAL NOT NULL DEFAULT 0,
            est    REAL NOT NULL DEFAULT 0,
            PRIMARY KEY (jahr, person)
        )",
    ];
    foreach ($stmts as $sql) $pdo->exec($sql);
    return $pdo;
}

/** Führt $fn in einer Schreib-Transaktion aus. $fn liefert eine Fehlerliste; ist sie nicht leer, wird zurückgerollt. */
function transaction(callable $fn): array {
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $errors = $fn($pdo) ?: [];
        if ($errors) { $pdo->exec('ROLLBACK'); return $errors; }
        $pdo->exec('COMMIT');
        return [];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
        throw $e;
    }
}

function normalizeBeleg(array $r): array {
    $out = [];
    foreach (BELEG_TEXT as $c) $out[$c] = trim((string)($r[$c] ?? ''));
    foreach (['s_pkv', 's_bh', 'z_status'] as $c) if ($out[$c] === '') $out[$c] = 'offen';
    foreach (BELEG_REAL as $c) $out[$c] = (float)($r[$c] ?? 0);
    return $out;
}

/** Alle Belege einer Person als id => Datensatz (wie früher aus der JSON-Datei), absteigend nach Nummer. */
function loadData(string $person): array {
    $st = db()->prepare('SELECT * FROM belege WHERE person = ?');
    $st->execute([$person]);
    $data = [];
    foreach ($st as $row) {
        $id = $row['id'];
        unset($row['id'], $row['person']);
        foreach (BELEG_REAL as $c) $row[$c] = (float)$row[$c];
        $data[$id] = $row;
    }
    uasort($data, function ($a, $b) { return strnatcasecmp($b['intern_nr'], $a['intern_nr']); });
    return $data;
}

function getBeleg(string $person, string $id): ?array {
    $st = db()->prepare('SELECT * FROM belege WHERE id = ? AND person = ?');
    $st->execute([$id, $person]);
    return $st->fetch() ?: null;
}

function saveBeleg(string $person, string $id, array $rec): void {
    $rec = normalizeBeleg($rec);
    $cols = array_merge(['id', 'person'], belegColumns());
    $sql = 'INSERT OR REPLACE INTO belege (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $values = [$id, $person];
    foreach (belegColumns() as $c) $values[] = $rec[$c];
    db()->prepare($sql)->execute($values);
}

function deleteBeleg(string $person, string $id): void {
    db()->prepare('DELETE FROM belege WHERE id = ? AND person = ?')->execute([$id, $person]);
}

/** Beitragsrückerstattung: [jahr][person] => ['bre' => €, 'est' => €] */
function loadBre(): array {
    $out = [];
    foreach (db()->query('SELECT jahr, person, bre, est FROM bre') as $r) {
        $out[(int)$r['jahr']][$r['person']] = ['bre' => (float)$r['bre'], 'est' => (float)$r['est']];
    }
    return $out;
}

function saveBre(int $jahr, string $person, float $bre, float $est): void {
    db()->prepare('INSERT OR REPLACE INTO bre (jahr, person, bre, est) VALUES (?, ?, ?, ?)')
        ->execute([$jahr, $person, $bre, $est]);
}

// ---------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------
function parseAmount($v): ?float {
    $v = str_replace(',', '.', trim((string)$v));
    return is_numeric($v) ? round((float)$v, 2) : null;
}

function eur(float $n): string { return number_format($n, 2, ',', '.') . ' €'; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dmy(?string $d): string { return ($d && strtotime($d)) ? date('d.m.Y', strtotime($d)) : ''; }

function statusClass(string $status): string {
    if ($status === 'offen') return 'bg-danger';
    if ($status === 'beglichen') return 'bg-success';
    return 'bg-warning';
}

// ---------------------------------------------------------------
// Seitengerüst (für alle Seiten außer index.php)
// ---------------------------------------------------------------
function pageStart(string $title, string $active = '', string $extraCss = ''): void {
    $nav = [
        'index.php'                   => ['fa-list', 'Belege'],
        'einreichung.php'             => ['fa-paper-plane', 'Einreichen'],
        'bescheid.php'                => ['fa-file-invoice', 'BH-Bescheid'],
        'bescheid_pkv.php'            => ['fa-file-medical', 'PKV-Bescheid'],
        'beitragsrueckerstattung.php' => ['fa-coins', 'Beitragsrückerstattung'],
        'statistik.php'               => ['fa-chart-bar', 'Statistik'],
        'export.php'                  => ['fa-file-pdf', 'Export'],
    ];
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root { --pkv-blue: #007bff; --success-green: #28a745; --danger-red: #dc3545; --bg-gray: #f0f2f5; --dark-gray: #343a40; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg-gray); margin: 0; min-height: 100vh; display: flex; flex-direction: column; }
        .container { max-width: 1400px; margin: 0 auto 20px auto; padding: 0 20px; flex: 1; width: 100%; box-sizing: border-box; }
        .main-header { display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; padding: 10px 30px; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .header-logo img { height: 50px; width: auto; display: block; }
        .header-title-center h1 { margin: 0; font-size: 1.5rem; color: #333; text-align: center; }
        .header-nav-right { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-nav { text-decoration: none; background: var(--pkv-blue); color: #fff; padding: 8px 14px; border-radius: 6px; font-weight: bold; font-size: 0.85rem; transition: background .3s; }
        .btn-nav:hover { background: #0056b3; }
        .btn-nav.active { background: var(--dark-gray); }

        .person-nav { display: flex; gap: 5px; margin-bottom: 20px; background: #ddd; padding: 5px; border-radius: 8px; width: fit-content; flex-wrap: wrap; }
        .person-nav a { text-decoration: none; padding: 8px 15px; color: #555; border-radius: 5px; font-size: 0.9rem; }
        .person-nav a.active { background: var(--pkv-blue); color: #fff; }

        .panel { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .form-row { display: flex; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; align-items: flex-end; }
        .field { display: flex; flex-direction: column; gap: 4px; }
        label { font-size: 0.7rem; color: #666; font-weight: bold; text-transform: uppercase; }
        input, select { border: 1px solid #dee2e6; padding: 8px; border-radius: 6px; font-size: 0.9rem; }

        table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        th { background: #f8f9fa; padding: 12px; text-align: left; font-size: 0.7rem; color: #666; border-bottom: 2px solid #eee; text-transform: uppercase; }
        td { padding: 10px 12px; border-bottom: 1px solid #f1f1f1; font-size: 0.85rem; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }

        .badge { padding: 3px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: bold; color: #fff; text-transform: uppercase; display: inline-block; }
        .bg-success { background: var(--success-green); } .bg-danger { background: var(--danger-red); } .bg-warning { background: #fd7e14; }

        .btn-save { background: var(--success-green); color: #fff; border: none; padding: 12px 30px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 1rem; }
        .btn-save:disabled { background: #aaa; cursor: not-allowed; }
        .btn-secondary { background: var(--pkv-blue); color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; }

        .msg { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
        .msg.ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .msg.err { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .msg.warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .msg.info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .msg ul { margin: 0; padding-left: 20px; }
        .muted { color: #666; }

        footer { background: var(--dark-gray); color: #bbb; padding: 30px; text-align: center; margin-top: 40px; font-size: 0.85rem; }
        footer a { color: #fff; text-decoration: none; border-bottom: 1px solid #555; }

        @media print {
            .main-header, footer, .no-print { display: none !important; }
            body { background: #fff; }
            .container { max-width: none; padding: 0; margin: 0; }
            .panel, table { box-shadow: none; }
            .badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        <?= $extraCss ?>
    </style>
</head>
<body>
<header class="main-header">
    <div class="header-logo"><img src="logo.png" alt="Logo"></div>
    <div class="header-title-center"><h1><?= h($title) ?></h1></div>
    <div class="header-nav-right">
        <?php foreach ($nav as $file => [$icon, $label]): ?>
            <a href="<?= $file ?>" class="btn-nav <?= $file === $active ? 'active' : '' ?>"><i class="fa-solid <?= $icon ?>"></i> <?= h($label) ?></a>
        <?php endforeach; ?>
    </div>
</header>
<div class="container">
<?php
}

function pageEnd(string $script = ''): void {
    ?>
</div>
<footer>
    <p><strong>Abrechnung PKV & BH</strong> | Lizenziert unter <a href="https://www.gnu.org/licenses/agpl-3.0.de.html" target="_blank">AGPL-3.0</a></p>
</footer>
<?php if ($script !== ''): ?>
<script>
<?= $script ?>
</script>
<?php endif; ?>
</body>
</html>
<?php
}
