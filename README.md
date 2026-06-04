# Health Endpoint

**Health-/Uptime-Plugin für WordPress** mit interner Server-Überwachung – by [ProjectMakers](https://projectmakers.de).

Zwei Dinge in einem Plugin:

1. **Öffentlicher `/health`-Endpoint** für Uptime-Monitoring (Uptime Kuma, UptimeRobot, …) –
   bestätigt nur, dass WordPress **und** die Datenbank antworten. **Keine sensiblen Daten.**
2. **Internes Monitoring** (Cron, ~1×/min): prüft Datenbank, Speicherplatz, CPU-Last und RAM und
   schickt dir eine **E-Mail**, sobald ein konfigurierbarer Schwellenwert (anhaltend) überschritten wird.

Dazu eine hübsche **Admin-Seite**, ein **token-geschützter Diagnose-Modus** und
**automatische Updates direkt aus GitHub-Releases**.

> Einmal bauen, auf allen WordPress-Servern einsetzen.

---

## Inhalt

- [Installation](#installation)
- [Admin-Seite](#admin-seite)
- [Endpoints & externe Abfragen](#endpoints--externe-abfragen)
- [Internes Monitoring & E-Mail-Alarme](#internes-monitoring--e-mail-alarme)
- [Echten Cron einrichten (1×/min)](#echten-cron-einrichten-1min)
- [Diagnose-Modus (Token)](#diagnose-modus-token)
- [Automatische Updates aus GitHub](#automatische-updates-aus-github)
- [HTTP-Statuscodes](#http-statuscodes)
- [Konfiguration (Konstanten & Filter)](#konfiguration-konstanten--filter)
- [Sicherheit](#sicherheit)
- [Caching-Hinweise](#caching-hinweise)
- [Changelog](#changelog)

---

## Installation

Das Plugin ist ein normales Plugin-Verzeichnis (`health-endpoint/`).

**A) ZIP über das WP-Backend (empfohlen)**
Lade die `health-endpoint.zip` aus den [GitHub-Releases](https://github.com/ProjectMakersDE/wp-health-endpoint/releases)
unter **Plugins → Installieren → Plugin hochladen** hoch und aktiviere es.

**B) Per SFTP/SSH**
Kopiere den Plugin-Ordner nach `wp-content/plugins/health-endpoint/` und aktiviere ihn im Backend.

```bash
rsync -a health-endpoint/ user@server:/var/www/<site>/wp-content/plugins/health-endpoint/
```

**C) Per WP-CLI**

```bash
wp plugin install https://github.com/ProjectMakersDE/wp-health-endpoint/releases/latest/download/health-endpoint.zip --activate
```

> Beim Aktivieren werden die Rewrite-Regeln automatisch neu geschrieben, damit `/health` sofort geht.
> Falls `/health` mal 404 liefert: einmal **Einstellungen → Permalinks → Speichern** – oder die
> Query-/REST-Variante nutzen (die brauchen keine Rewrite-Regeln).

---

## Admin-Seite

Im WP-Backend erscheint links ein Menüpunkt **„Health"** (Herz-Icon). Dort findest du:

- **Live-Status**: Datenbank, Speicherplatz, CPU, RAM auf einen Blick (grün/rot) + „Jetzt prüfen".
- **Endpoints**: die konkreten URLs dieser Seite zum Kopieren.
- **Monitoring & Alarme**: E-Mail-Adresse(n), Schwellenwerte, Dauer, Cooldown, Token, GitHub-Token.
- **Test-E-Mail senden** zum Prüfen des Mailversands.

---

## Endpoints & externe Abfragen

Alle Varianten liefern dasselbe JSON. Nutze die, die zu deinem Setup passt:

| Variante | URL | Voraussetzung |
|---|---|---|
| **Pretty** | `https://example.com/health` | Permalinks aktiv |
| **Query-Fallback** | `https://example.com/?health_check=1` | immer |
| **REST** | `https://example.com/wp-json/health/v1/check` | immer (bei „Plain"-Permalinks: `?rest_route=/health/v1/check`) |
| **Plain** | `…?health_check=1&format=plain` | gibt nur `OK` / `ERROR` als Text zurück |

```bash
curl -i "https://example.com/?health_check=1"                       # JSON
curl -s "https://example.com/?health_check=1&format=plain"          # -> OK
curl -i https://example.com/wp-json/health/v1/check                 # REST
curl -I https://example.com/health                                  # HEAD (nur Status)
```

**Antwort gesund (HTTP 200):**

```json
{ "status": "ok", "db": "connected", "time": "2026-06-04T09:12:00+00:00" }
```

**Datenbank weg (HTTP 503):**

```json
{ "status": "error", "db": "down", "time": "2026-06-04T09:12:00+00:00" }
```

### Uptime Kuma

1. **Monitor Type:** `HTTP(s)` (oder `HTTP(s) - Keyword`)
2. **URL:** `https://example.com/?health_check=1` oder `…/wp-json/health/v1/check`
   (diese Varianten werden von Page-Caches **nicht** gecacht – siehe [Caching](#caching-hinweise))
3. **Request Timeout:** ≥ 10 s (Core kann bei DB-Ausfall ~5 s für Reconnect brauchen)
4. Optional *Keyword* = `"status": "ok"`
5. **Accepted Status Codes:** `200` (alles andere = down)

---

## Internes Monitoring & E-Mail-Alarme

Auf der Admin-Seite **„Internes Monitoring" aktivieren** und eine **Alarm-E-Mail** hinterlegen.
Dann prüft ein Cron (~1×/min) und schickt eine E-Mail, sobald:

| Check | Auslöser | Konfigurierbar |
|---|---|---|
| **Datenbank** | nicht erreichbar | – (sofort) |
| **Speicherplatz** | belegt ≥ Schwelle | 60/70/80/85/90/95 % |
| **CPU** | Last/Kern ≥ Schwelle, **anhaltend** | Schwelle % + Dauer (Minuten) |
| **RAM** | Belegung ≥ Schwelle, **anhaltend** | Schwelle % + Dauer (Minuten) |

- **Anhaltend** = der Wert liegt über die eingestellte Dauer (z. B. 5 Min.) ununterbrochen über der Schwelle.
  Dafür hält das Plugin eine kurze rollierende Historie (max. 90 Min.) – ein einzelner Spitzenwert löst also keinen Alarm aus.
- **CPU-Wert** = 1-Minuten-Load-Average geteilt durch CPU-Kerne, als Prozent (Load 1,0 pro Kern = 100 %).
  Auf manchen Shared-Hosts sind `sys_getloadavg`/`/proc` deaktiviert – dann wird CPU/RAM als „n/a" angezeigt und übersprungen.
- **Cooldown**: pro Problem wird nicht öfter als alle *N* Minuten gemailt (einstellbar; `0` = genau **eine** Mail pro Vorfall, keine Wiederholung bis zur Erholung). Optional kommt eine **Recovery-Mail**, wenn sich ein Problem wieder normalisiert.
- **Debounce**: DB- und Speicherplatz-Alarme lösen erst nach 2 aufeinanderfolgenden Messungen aus (kurze Aussetzer erzeugen keinen Mail-Spam).

Mehrere Empfänger: kommagetrennt eintragen. Versand läuft über `wp_mail()` – für zuverlässige
Zustellung empfiehlt sich ein SMTP-Plugin (z. B. WP Mail SMTP).

---

## Echten Cron einrichten (1×/min)

WordPress-Cron läuft nur bei Seitenaufrufen. Auf wenig besuchten Seiten ist „1×/min" damit
**nicht garantiert**. Für präzises Monitoring deshalb den WP-Cron per System-Cron antreiben und
den eingebauten Pseudo-Cron abschalten:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
# crontab -e  (jede Minute)
* * * * * curl -s "https://example.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Alternativ per WP-CLI: `* * * * * cd /var/www/<site> && wp cron event run --due-now >/dev/null 2>&1`

---

## Diagnose-Modus (Token)

Erweiterte Werte gibt es nur mit gültigem Token – ohne Token wird **nichts** Sensibles ausgegeben.

**Token setzen** – entweder in `wp-config.php` (sicherste Variante):

```php
define( 'HEALTH_ENDPOINT_TOKEN', 'ein-langes-zufaelliges-secret' );
```

…oder auf der Admin-Seite im Feld „Diagnostics token" (die Konstante hat Vorrang).

**Abfrage** – per Header (empfohlen) oder Query:

```bash
curl -s -H "X-Health-Token: SECRET" https://example.com/health
curl -s "https://example.com/health?token=SECRET"
```

**Antwort mit Diagnose (gekürzt):**

```json
{
  "status": "ok", "db": "connected", "time": "…",
  "detail": {
    "plugin_version": "2.1.0", "php_version": "8.4.0", "wp_version": "6.7",
    "object_cache": "external", "db_latency_ms": 2,
    "disk_used_pct": 41.0, "disk_free_mb": 18342,
    "cpu_pct": 38.0, "cpu_load_1m": 1.52, "cpu_cores": 4,
    "ram_used_pct": 63.0, "ram_avail_mb": 5921,
    "https": "yes", "memory_limit": "512M", "woocommerce": "9.4.2"
  }
}
```

---

## Automatische Updates aus GitHub

Das Plugin meldet neue Versionen direkt im WordPress-Update-Center – ganz ohne wordpress.org.

**Workflow:**

1. Version bumpen (oder einfach taggen) und einen Tag pushen:
   ```bash
   git tag v2.1.1 && git push origin v2.1.1
   ```
2. Die mitgelieferte **GitHub Action** (`.github/workflows/release.yml`) baut automatisch
   `health-endpoint.zip` (mit der Versionsnummer aus dem Tag) und veröffentlicht ein **Release**.
3. WordPress sieht das neue Release (täglicher Update-Check) und bietet das Update unter
   **Plugins** / **Dashboard → Aktualisierungen** an – ein Klick genügt.

**Privates Repo:** Da dieses Repo privat ist, braucht WordPress einen Lesezugriff, um Releases zu
sehen und herunterzuladen. Trage dafür ein **fein-granulares GitHub-PAT** (read-only auf das Repo)
auf der Admin-Seite im Feld „GitHub token (updates)" ein – oder als Konstante:

```php
define( 'HEALTH_ENDPOINT_GITHUB_TOKEN', 'github_pat_xxx' );
```

Ist das Repo öffentlich, ist **kein** Token nötig. Beim Download löst das Plugin den GitHub-Redirect
selbst auf und reicht den Token **nicht** an die GitHub-CDN/S3 weiter.

---

## HTTP-Statuscodes

| Code | Bedeutung |
|---|---|
| `200` | WordPress + DB erreichbar (`status: ok`) |
| `503` | DB-Verbindung **während** des Requests verloren (Plugin antwortet mit `status: error`-JSON) |
| `500` | DB schon **beim Bootstrap** weg → WordPress core (`dead_db()`) stirbt **vor** dem Plugin. Es kommt die WP-DB-Fehlerseite. |

Fürs Monitoring **Accepted Status Codes = `200`** setzen → `500` **und** `503` gelten als down.
Wer auch beim Bootstrap-Ausfall sauberes 503-JSON möchte, nutzt den optionalen
[`db-error.php`-Drop-in](#optional-bootstrap-db-ausfall-abfangen-db-errorphp).

### Optional: Bootstrap-DB-Ausfall abfangen (`db-error.php`)

Ist die DB schon beim Start weg, lädt WordPress **kein** Plugin, sondern `wp-content/db-error.php`
(falls vorhanden). Den mitgelieferten Drop-in dafür ablegen:

```bash
cp db-error.php /var/www/<site>/wp-content/db-error.php
```

Dann liefern die Health-URLs auch im Bootstrap-Ausfall konsistentes **503-JSON**, während normale
Besucher eine schlichte „Service temporarily unavailable"-Seite sehen.

---

## Konfiguration (Konstanten & Filter)

**Konstanten** (`wp-config.php`):

| Konstante | Zweck |
|---|---|
| `HEALTH_ENDPOINT_TOKEN` | Secret für den Diagnose-Modus (Vorrang vor Admin-Feld). |
| `HEALTH_ENDPOINT_SLUG` | Slug der Pretty-URL ändern (Default `health`). |
| `HEALTH_ENDPOINT_GITHUB` | GitHub-Repo `owner/repo` für Updates (Default `ProjectMakersDE/wp-health-endpoint`). |
| `HEALTH_ENDPOINT_GITHUB_TOKEN` | PAT für Updates aus einem privaten Repo. |
| `DISABLE_WP_CRON` | WP-Pseudo-Cron aus, wenn du System-Cron nutzt (siehe oben). |

**Filter:**

| Filter | Zweck |
|---|---|
| `health_endpoint_payload` | Gesamtes Antwort-Array anpassen. |
| `health_endpoint_detail` | Eigene Diagnose-Werte/Checks ergänzen. |
| `health_endpoint_status_code` | HTTP-Status überschreiben. |
| `health_endpoint_token` | Token programmatisch liefern. |

```php
add_filter( 'health_endpoint_detail', function ( $detail ) {
	$detail['queue_pending'] = (int) get_option( 'my_queue_pending', 0 );
	return $detail;
} );
```

---

## Sicherheit

- Der **öffentliche** Endpoint gibt nur `status`, `db` und `time` aus – keine Versionen, Pfade, internen Details.
- Erweiterte Werte nur mit korrektem Token; Vergleich zeitkonstant (`hash_equals`).
- Leeres/whitespace-Token = „nicht gesetzt" → Diagnose bleibt aus (fail-closed).
- **In Produktion den `X-Health-Token`-Header bevorzugen, nicht `?token=`:** Query-Strings landen
  im Klartext in Access-Logs sowie Proxy-/CDN-/WAF-Logs (und können via `Referer` leaken). Wenn ein
  Token je in einer URL stand → **rotieren**.
- GitHub-PAT möglichst fein-granular und nur mit Lesezugriff auf dieses eine Repo.
- Langes Zufalls-Secret nutzen: `openssl rand -hex 24`

---

## Caching-Hinweise

⚠️ Hinter einem Full-Page-Cache (WP Super Cache, W3TC, LiteSpeed, WP Rocket, Varnish, Cloudflare)
kann die **Pretty-URL `/health` zu einem False-Positive** führen: Der Monitor bleibt **grün**
(gecachte `200 {"status":"ok"}`), obwohl die DB weg ist – die gecachte Antwort wird ausgeliefert,
**bevor** PHP/das Plugin läuft.

**Auf Seiten mit Page-Cache deshalb:**

- die **Query-Variante** `?health_check=1` oder die **REST-Route** monitoren (werden i. d. R. nicht gecacht),
- `/health`, `/?health_check=1` und `/wp-json/health/*` in die **Never-Cache-Liste** des Cache-Plugins
  bzw. eine **Cloudflare-Cache-Bypass-Regel** eintragen.

Ohne Page-Cache ist `/health` unproblematisch.

---

## Changelog

**2.1.0**
- Admin-Seite (Live-Status, Endpoint-Liste, Einstellungen) mit ProjectMakers-Branding
- Internes Monitoring per Cron: DB, Speicherplatz, CPU, RAM mit konfigurierbaren Schwellen + Dauer
- E-Mail-Alarme mit Cooldown und optionaler Recovery-Mail; „Test-E-Mail" & „Jetzt prüfen"
- `format=plain` (Antwort `OK`/`ERROR`) für ultraschlanke Monitore
- Automatische Updates aus GitHub-Releases (Private-Repo via PAT) + GitHub-Action-Build
- Token jetzt auch über die Admin-Seite konfigurierbar (Konstante hat weiterhin Vorrang)
- Modularer Aufbau (`includes/`), `uninstall.php`-Cleanup

**2.0.0**
- REST-Route, `?health_check=1`-Fallback, token-geschützte Diagnose
- Fail-fast DB-Check, HEAD-Support, optionaler `db-error.php`-Drop-in

**1.0.3**
- Erste Version: öffentliches `/health` mit DB-Connection-Check
