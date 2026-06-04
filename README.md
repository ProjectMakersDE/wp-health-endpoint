# Health Endpoint

Leichtgewichtiges **Health-/Uptime-Plugin für WordPress**. Stellt einen öffentlichen
`/health`-Endpoint bereit, der prüft, ob WordPress **und** die Datenbank antworten – ideal
für Uptime-Monitoring (Uptime Kuma, UptimeRobot, Better Uptime, Pingdom …).

Optional gibt es einen **token-geschützten Diagnose-Modus** mit erweiterten Werten
(DB-Latenz, Object-Cache, freier Speicherplatz, PHP-/WP-Version, Load, HTTPS).

> Ein und dieselbe Datei läuft auf jedem WordPress-Server. Einmal bauen, überall einsetzen.

---

## Inhalt

- [Installation](#installation)
- [Endpoints & externe Abfragen](#endpoints--externe-abfragen)
- [Antwort-Beispiele](#antwort-beispiele)
- [Diagnose-Modus (Token)](#diagnose-modus-token)
- [Uptime Kuma einrichten](#uptime-kuma-einrichten)
- [HTTP-Statuscodes](#http-statuscodes)
- [Konfiguration (Konstanten & Filter)](#konfiguration-konstanten--filter)
- [Sicherheit](#sicherheit)
- [Caching-Hinweise](#caching-hinweise)
- [Changelog](#changelog)

---

## Installation

Es ist ein **Single-File-Plugin** – `health-endpoint.php` ist alles, was zwingend nötig ist.
Drei Wege:

**A) Als Datei hochladen (am portabelsten)**
Kopiere `health-endpoint.php` nach `wp-content/plugins/` (gerne in einen Unterordner
`wp-content/plugins/health-endpoint/`). Danach unter **Plugins → Health Endpoint → Aktivieren**.

```bash
# z. B. per scp/sftp
scp health-endpoint.php user@server:/var/www/<site>/wp-content/plugins/health-endpoint/
```

**B) Als ZIP über das WP-Backend**
Repo als ZIP herunterladen → **Plugins → Installieren → Plugin hochladen** → ZIP wählen → aktivieren.

**C) Per WP-CLI**

```bash
wp plugin activate health-endpoint
```

> **Wichtig:** Beim Aktivieren werden die Rewrite-Regeln automatisch neu geschrieben,
> damit `/health` sofort funktioniert. Falls `/health` mal einen 404 liefert
> (z. B. nach manuellem Kopieren ohne Aktivierung), einmal unter
> **Einstellungen → Permalinks → Speichern** klicken – oder die REST-/Query-Variante nutzen
> (die brauchen keine Rewrite-Regeln).

---

## Endpoints & externe Abfragen

Alle drei liefern dasselbe JSON. Nutze die Variante, die zu deinem Setup passt:

| Variante | URL | Voraussetzung |
|---|---|---|
| **Pretty** | `https://example.com/health` | Permalinks aktiv |
| **Query-Fallback** | `https://example.com/?health_check=1` | immer (keine Permalinks nötig) |
| **REST** | `https://example.com/wp-json/health/v1/check` | immer; bei „Plain“-Permalinks: `?rest_route=/health/v1/check` |

```bash
# Pretty-URL
curl -i https://example.com/health

# Query-Fallback (funktioniert auch ohne hübsche Permalinks)
curl -i "https://example.com/?health_check=1"

# REST-Route
curl -i https://example.com/wp-json/health/v1/check

# REST bei "Plain"-Permalinks
curl -i "https://example.com/?rest_route=/health/v1/check"

# HEAD-Request (viele Monitore nutzen das – nur Status, kein Body)
curl -I https://example.com/health
```

---

## Antwort-Beispiele

**Gesund (HTTP 200):**

```json
{ "status": "ok", "db": "connected", "time": "2026-06-04T09:12:00+00:00" }
```

**Datenbank weg (HTTP 503):**

```json
{ "status": "error", "db": "down", "time": "2026-06-04T09:12:00+00:00" }
```

---

## Diagnose-Modus (Token)

Für interne Checks gibt es erweiterte Werte. Diese sind **nur sichtbar, wenn ein Token
konfiguriert ist** – ohne Token wird nie etwas Sensibles ausgegeben.

**1) Token in `wp-config.php` setzen** (vor `/* That's all, stop editing! */`):

```php
define( 'HEALTH_ENDPOINT_TOKEN', 'ein-langes-zufaelliges-secret' );
```

**2) Token bei der Abfrage mitgeben** – per Query-Parameter **oder** Header:

```bash
# als Query-Parameter
curl -s "https://example.com/health?token=ein-langes-zufaelliges-secret"

# als HTTP-Header (taucht nicht in Server-Logs/Referern auf)
curl -s -H "X-Health-Token: ein-langes-zufaelliges-secret" https://example.com/health
```

**Antwort mit Diagnose (HTTP 200):**

```json
{
  "status": "ok",
  "db": "connected",
  "time": "2026-06-04T09:12:00+00:00",
  "detail": {
    "plugin_version": "2.0.0",
    "php_version": "8.4.0",
    "wp_version": "6.7",
    "object_cache": "external",
    "db_latency_ms": 2,
    "disk_free_mb": 18342,
    "load_1m": 0.41,
    "https": "yes",
    "memory_limit": "512M",
    "server_time": "2026-06-04T09:12:00+00:00",
    "woocommerce": "9.4.2",
    "warnings": []
  }
}
```

> `warnings` listet weiche Hinweise (z. B. `disk_low`, wenn < 256 MB frei sind).
> Der **HTTP-Status bleibt davon unberührt** – er richtet sich nur nach der DB-Erreichbarkeit,
> damit das Uptime-Signal eindeutig bleibt.

---

## Uptime Kuma einrichten

1. **Add New Monitor**
2. **Monitor Type:** `HTTP(s)` (oder `HTTP(s) - Keyword`)
3. **URL:** `https://example.com/?health_check=1` **oder** `https://example.com/wp-json/health/v1/check`
   – diese Varianten werden von Full-Page-Caches standardmäßig **nicht** gecacht.
   Die hübsche `/health`-URL nur verwenden, wenn die Seite **keinen** Page-Cache hat
   (siehe [Caching-Hinweise](#caching-hinweise) – sonst Gefahr eines „grünen" Monitors trotz Ausfall).
4. **Heartbeat Interval:** z. B. 60 s
5. **Request Timeout:** ≥ 10 s (bei DB-Ausfall kann WordPress core bis zu ~5 s für Reconnect-Versuche brauchen, bevor die Antwort kommt)
6. Optional bei *Keyword*-Monitor: **Keyword** = `"status": "ok"`
7. **Accepted Status Codes:** `200` (Default passt – jeder andere Code = down)

Damit ist der Monitor **down**, sobald WordPress nicht mehr ausgeliefert wird **oder** die
Datenbank nicht erreichbar ist (siehe [HTTP-Statuscodes](#http-statuscodes)).

---

## HTTP-Statuscodes

| Code | Bedeutung |
|---|---|
| `200` | WordPress + DB erreichbar (`status: ok`) |
| `503` | DB-Verbindung **während** des Requests verloren / Reconnect fehlgeschlagen (Plugin antwortet mit `status: error`-JSON) |
| `500` | DB schon **beim Bootstrap** nicht erreichbar – dann stirbt WordPress core (`dead_db()`), **bevor** das Plugin lädt. Es kommt die WP-DB-Fehlerseite, nicht das Plugin-JSON. |

Für das Monitoring ist das egal: setze **Accepted Status Codes = `200`**, dann gilt sowohl
`500` als auch `503` als **down**. Wer auch beim Bootstrap-Ausfall sauberes 503-JSON möchte,
nutzt den optionalen [`db-error.php`-Drop-in](#optional-bootstrap-db-ausfall-abfangen-db-errorphp).

(Den Code kann man per Filter `health_endpoint_status_code` überschreiben.)

### Optional: Bootstrap-DB-Ausfall abfangen (`db-error.php`)

Wenn die Datenbank schon beim Start nicht erreichbar ist, lädt WordPress **kein** Plugin –
es rendert stattdessen `wp-content/db-error.php` (falls vorhanden). Lege dafür den mitgelieferten
Drop-in ab:

```bash
cp db-error.php /var/www/<site>/wp-content/db-error.php
```

Dann liefern die Health-URLs auch im Bootstrap-Ausfall konsistentes **503-JSON**
(`{"status":"error","db":"down",...}`), während normale Besucher eine schlichte
„Service temporarily unavailable"-Seite sehen. Der Drop-in ist optional und unabhängig vom Plugin.

---

## Konfiguration (Konstanten & Filter)

**Konstanten** (in `wp-config.php`):

| Konstante | Default | Zweck |
|---|---|---|
| `HEALTH_ENDPOINT_TOKEN` | – | Secret für den Diagnose-Modus. Nicht gesetzt = Diagnose aus. |
| `HEALTH_ENDPOINT_SLUG` | `health` | Slug der Pretty-URL ändern, z. B. `status` → `/status`. |

**Filter** (für Entwickler):

| Filter | Zweck |
|---|---|
| `health_endpoint_payload` | Gesamtes Antwort-Array anpassen. |
| `health_endpoint_detail` | Eigene Diagnose-Werte/Checks ergänzen. |
| `health_endpoint_status_code` | HTTP-Status überschreiben. |
| `health_endpoint_token` | Token programmatisch liefern (statt Konstante). |

Beispiel – eigener Check im Diagnose-Block:

```php
add_filter( 'health_endpoint_detail', function ( $detail ) {
	$detail['queue_pending'] = (int) get_option( 'my_queue_pending', 0 );
	return $detail;
} );
```

---

## Sicherheit

- Der **öffentliche** Endpoint gibt nur `status`, `db` und `time` aus – keine Versionen,
  Pfade oder interne Details.
- Erweiterte Werte gibt es **ausschließlich** mit korrektem Token; der Token-Vergleich läuft
  zeitkonstant (`hash_equals`), kein Timing-Leak.
- Ein leeres oder nur aus Leerzeichen bestehendes Secret zählt als „nicht gesetzt" → Diagnose bleibt aus.
- **In Produktion den `X-Health-Token`-Header bevorzugen, nicht `?token=`:** Query-Strings landen
  im Klartext in Webserver-Access-Logs sowie in Logs von vorgelagerten Proxies/CDNs/WAFs
  (und können via `Referer` weitergegeben werden). Den `?token=`-Weg nur für schnelle manuelle Tests nutzen;
  wenn ein Token je in einer URL stand, **rotieren**.
- Nutze ein langes Zufalls-Secret, z. B.:
  ```bash
  openssl rand -hex 24
  ```

---

## Caching-Hinweise

⚠️ **Wichtig für Monitoring:** Hinter einem Full-Page-Cache (WP Super Cache, W3TC, LiteSpeed,
WP Rocket, Varnish, Cloudflare) kann die **Pretty-URL `/health` zu einem False-Positive führen** –
der Monitor bleibt **grün** (gecachte `200 {"status":"ok"}`), obwohl die DB längst weg ist.
Grund: Die gecachte Antwort wird ausgeliefert, **bevor** PHP/das Plugin überhaupt läuft
(`advanced-cache.php` läuft vor den Plugins). Die `nocache`-Header/`DONOTCACHEPAGE` verhindern nur
das *Speichern* einer frischen Antwort, nicht das *Ausliefern* einer bereits gecachten Seite.

**Empfehlung auf Seiten mit Page-Cache:**

- Monitore die **Query-Variante** `?health_check=1` oder die **REST-Route** `/wp-json/health/v1/check`
  (Requests mit Query-String bzw. `/wp-json/` werden von diesen Caches standardmäßig **nicht** gecacht).
- Trage `/health`, `/?health_check=1` und `/wp-json/health/*` zusätzlich in die **Never-Cache-/
  Ausschlussliste** des Cache-Plugins ein und lege bei Cloudflare eine **Cache-Bypass-Regel** an.
- Optional einen Cache-Buster anhängen, z. B. `?health_check=1&t=<timestamp>`.

Ohne Page-Cache ist die Pretty-URL `/health` unproblematisch.

---

## Changelog

**2.0.0**
- REST-Route `/wp-json/health/v1/check` (permalink-unabhängig)
- Token-geschützter Diagnose-Modus (DB-Latenz, Object-Cache, Disk, Load, PHP/WP, HTTPS, WooCommerce)
- HEAD-Support, `DONOTCACHEPAGE`, konfigurierbarer Slug
- Filter-API (`payload`, `detail`, `status_code`, `token`)
- DB-Check zusätzlich per `SELECT 1` verifiziert; bei DB-Verlust **im Request** `503`-JSON
  (bei DB-Ausfall **beim Bootstrap** antwortet WP core mit `500` – siehe optionaler `db-error.php`-Drop-in)
- **Fail-fast:** Core-Reconnect-Loop deaktiviert (kein ~5 s-Block bei DB-Ausfall); `suppress_errors`
  verhindert, dass WP-Fehler-HTML ins JSON leakt
- Whitespace-only-Token zählt als „nicht gesetzt" (fail-closed)
- Optionaler `db-error.php`-Drop-in für konsistentes 503-JSON beim Bootstrap-DB-Ausfall

**1.0.3**
- Erste Version: öffentliches `/health` mit DB-Connection-Check
