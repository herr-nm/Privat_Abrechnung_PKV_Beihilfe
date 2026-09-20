# Privat-Abrechnung PKV & Beihilfe

Ein schlankes, PHP-basiertes Tool zur Verwaltung von Arztrechnungen, Erstattungen der privaten Krankenversicherung (PKV) und der Beihilfe (BH). Die Anwendung ermöglicht es, Rechnungen zu erfassen, Zahlungsstatus zu überwachen und den Überblick über offene Erstattungen sowie den jährlichen Eigenanteil zu behalten.

## Features

- **Dashboard-Übersicht:** Direkter Blick auf Gesamtvolumen, getrennte offene Erstattungsbeträge (PKV & Beihilfe) und den aktuellen Eigenanteil des laufenden Jahres.
- **Profil-Verwaltung:** Unterstützung für mehrere Personen über eine `.env`-Konfiguration.
- **Automatisierte Berechnung:** Automatische Aufteilung des Rechnungsbetrags basierend auf individuellen Erstattungssätzen (z. B. 30% PKV / 70% Beihilfe).
- **Status-Tracking:** Verfolgung des Zahlungsstatus (offen, bezahlt, Terminüberweisung) sowie des Einreichungsstatus (eingereicht, beglichen).
- **Bescheid-Erfassung:** Eigene Masken für Beihilfe- und PKV-Bescheide. Ein Bescheid mit mehreren Positionen wird in einem Schritt erfasst und aktualisiert alle betroffenen Belege, auch personenübergreifend.
- **Dokumenten-Management:** Verlinkung von Rechnungs-PDFs und Erstattungsbescheiden direkt in der Tabelle.
- **Live-Suche:** Filtern der gesamten Tabelle nach Arzt, Zweck oder Belegnummer in Echtzeit.
- **Datenhaltung:** Speicherung in lokalen JSON-Dateien – keine Datenbank (MySQL etc.) erforderlich.

## Screenshots

![Screenshot](screenshot.png)

## Dateien

| Datei | Zweck |
|---|---|
| `index.php` | Hauptansicht: Dashboard, Rechnungen erfassen/bearbeiten, Tabelle mit Suche |
| `bescheid.php` | Erfassung eines **Beihilfebescheids** mit mehreren Positionen |
| `bescheid_pkv.php` | Erfassung eines **PKV-Bescheids** (Leistungsabrechnung) mit mehreren Positionen |
| `.env` | Profile der Personen und Erstattungssätze |
| `data_<person>.json` | Wird automatisch erzeugt, ein Datenbestand pro Person |
| `logo.png` | Optional, wird im Seitenkopf angezeigt |

## Installation

1. **Dateien kopieren:** Lade `index.php`, `bh.php`, `pkv.php` und die `logo.png` (optional) in dasselbe Verzeichnis auf deinen Webserver (z. B. Apache mit PHP-Unterstützung). Alle Seiten müssen im selben Verzeichnis liegen, da sie dieselbe `.env` und dieselben `data_*.json`-Dateien verwenden.
2. **Konfiguration:** Erstelle eine Datei namens `.env` im Hauptverzeichnis.
3. **Schreibrechte:** Stelle sicher, dass das Skript Schreibrechte im Verzeichnis hat, um die `data_*.json` Dateien zu erstellen und zu aktualisieren.

## Konfiguration (.env)

In der `.env` Datei definierst du die Profile der Personen. Das Format ist:
`PERSON_ID=ID,Name,Präfix_RechnungsNr,PKV_Satz,BH_Satz`

**Beispiel:**
```env
PERSON_1=max,Max Mustermann,M,0.3,0.7
PERSON_2=erika,Erika Mustermann,E,0.5,0.5
```
* `0.3` entspricht 30% Erstattung durch die PKV.
* `0.7` entspricht 70% Erstattung durch die Beihilfe.

## Nutzung

### Rechnungen erfassen
Geben Sie den Gesamtbetrag der Rechnung ein. Das Skript berechnet automatisch die erwarteten Anteile für PKV und Beihilfe. Sie können diese Beträge bei Bedarf manuell anpassen (z. B. wenn bestimmte Leistungen nicht erstattungsfähig sind).

### Status aktualisieren
Sobald Sie eine Rechnung eingereicht oder eine Erstattung erhalten haben, können Sie den Eintrag über das ✏️-Symbol bearbeiten. Das Dashboard aktualisiert sich sofort und zeigt Ihnen, welche Beträge noch ausstehen.

### Bescheide erfassen (Beihilfe und PKV)

Für den Eingang eines Bescheids gibt es zwei eigene Seiten mit identischem Ablauf: `bescheid.php` für die Beihilfe und `bescheid_pkv.php` für die PKV. Die Bearbeitung einzelner Belege über das ✏️-Symbol ist dafür nicht nötig.

**Ablauf:**

1. **Kopfdaten eingeben:** Link zur Bescheid-PDF, Bescheid-Datum (Standard: heute) und Bescheidnummer.
2. **Position wählen:** Im Dropdown erscheinen alle Belege **aller Personen**, die für die jeweilige Stelle (Beihilfe bzw. PKV) den Status „eingereicht“ haben. Angezeigt werden Person, interne Nummer und Rechnungsbetrag, z. B. `Max Mustermann · M12 · 120,00 €`.
3. **Erstattungsbetrag prüfen:** Nach der Auswahl wird der erwartete Erstattungsbetrag (aus dem Erstattungssatz der Person) vorbelegt. Es müssen also nur Abweichungen erfasst werden. Manuell geänderte Beträge bleiben erhalten, wenn danach der Beleg gewechselt wird.
4. **Weitere Positionen:** Über das **+** wird eine weitere Position ergänzt, über das 🗑️ eine Position entfernt. Bereits gewählte Belege sind in den übrigen Dropdowns gesperrt, sodass kein Beleg doppelt verwendet werden kann.
5. **Speichern:** Unten wird die Summe der Erstattungen angezeigt. Erst nach dem Speichern und einer Sicherheitsabfrage werden alle betroffenen Belege gemeinsam aktualisiert.

**Was beim Speichern geändert wird** (je Position, in der jeweiligen Datei der Person):

| Feld Beihilfe | Feld PKV | Neuer Wert |
|---|---|---|
| `e_bh` | `e_pkv` | tatsächlicher Erstattungsbetrag aus dem Bescheid |
| `s_bh` | `s_pkv` | `beglichen` |
| `bh_date` | `pkv_date` | Bescheid-Datum |
| `bh_belegnr` | `pkv_belegnr` | Bescheidnummer |
| `bh_link` | `pkv_link` | Link zur Bescheid-PDF |

Das Einreichdatum (`bh_sub_date` / `pkv_sub_date`) bleibt unverändert.

**Sicherheitsprüfungen:** Vor dem Schreiben wird geprüft, ob jeder Beleg noch den Status „eingereicht“ hat, ob kein Beleg doppelt vorkommt und ob die Beträge gültig sind. Tritt ein Fehler auf, wird nichts geändert und die Eingaben bleiben im Formular stehen.

**Hinweise:**

- Der berechnete Erstattungsbetrag wird durch den tatsächlichen Betrag aus dem Bescheid überschrieben, damit Dashboard und Eigenanteil korrekt sind.
- Belege mit Status „offen“ (noch nicht eingereicht) erscheinen nicht im Dropdown. Sie müssen zuvor in `index.php` auf „eingereicht“ gesetzt werden.
- Beihilfe und PKV sind voneinander unabhängig: Ein Beleg kann bei der einen Stelle bereits beglichen und bei der anderen noch offen sein.
- Ein Beleg mit erwartetem Betrag `0,00` wird ebenfalls mit `0,00` vorbelegt. Diesen Wert vor dem Speichern bitte prüfen.

### Dashboard-Logik
- **Offen PKV/BH:** Summiert alle Beträge, deren Status nicht auf "beglichen" steht.
- **Eigenanteil:** Berechnet sich aus `Gesamtbetrag - (PKV_Erstattung + BH_Erstattung)` für alle Rechnungen des aktuellen Kalenderjahres.

### Links zu den Dokumenten

Wenn die Dokumente digital vorgehalten werden, können Links zu diesen hinterlegt und später in der Web-Ansicht aufgerufen werden. Ich nutze dazu ein Paperless ndx, in dem die PDF-Dateien der Rechnungen, der Beihilfebescheide sowie der Leistungsnachweise der PKV enthalten sind. Die Links zu den Bescheiden werden über die Bescheid-Seiten hinterlegt und erscheinen anschließend in der Tabelle von `index.php` als „📥 Bescheid“.

## Technische Details

- **Sprache:** PHP 8.x
- **Frontend:** HTML5, CSS3 (Flexbox/Grid), JavaScript (Vanilla)
- **Icons:** FontAwesome 6.0 (via CDN)
- **Datenformat:** JSON (eine Datei `data_<person>.json` pro Person, Schreibzugriffe mit Dateisperre)

## Lizenz

Dieses Projekt ist unter der **GNU AGPL-3.0** lizenziert. Weitere Details findest du im GitHub-Repository.

---

**Source:** [herr-nm/Privat_Abrechnung_PKV_Beihilfe](https://github.com/herr-nm/Privat_Abrechnung_PKV_Beihilfe)
