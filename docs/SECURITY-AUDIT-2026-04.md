# Telepage — Security & Quality Audit 2026-04

Audit prodotto dopo 16 fix chiusi della review post-v1.0.4 (da #1 a #27
nel tracking originale) + follow-up migration. Ricostruisce lo stato
attuale del codice ignorando completamente la lista originale: cattura
cosa c'è ancora da fare **oggi**, non cosa era aperto 3 settimane fa.

Ordine = priorità stimata. Numeri tra parentesi = effort grossolano:
**S** = 30 min, **M** = 1–2 h, **L** = 3+ h con design.

---

## Alta priorità (security con superficie reale)

### A1. Session cookie senza flag Secure/SameSite (S)

`api/admin.php`, `admin/_auth.php`, `admin/login.php` chiamano
`session_name(...)` + `session_start()` ma NON settano
`session_set_cookie_params()`. Su installazioni HTTPS il cookie
`tp_...` viene emesso senza `Secure`, quindi è inviato anche su
HTTP (es. mixed content su CDN) — un MitM HTTP può rubare la
sessione admin.

**Fix**: `session_set_cookie_params(['secure' => $isHttps,
'httponly' => true, 'samesite' => 'Lax'])` **prima** di
session_start, in tutti i punti.

### A2. Input pubblici senza length cap (M)

`api/contents.php` accetta `q`, `tag`, `type`, `from`, `to` senza
validazione di lunghezza o pattern. Un attaccante può mandare
`?q=<100MB di A>` con 60 req/min, ciascuna genera un `LIKE
'%<100MB>%'` che scansiona tutta la tabella contents. Non è SQL
injection (query parametrizzate) ma amplification DoS.

**Fix**: length cap su `q` (256 char), validazione enum stretta
su `type` (solo link|photo|video|youtube|tiktok), regex
`yyyy-mm-dd` su `from`/`to`, rifiuto 400 se non matcha.

### A3. Content-Security-Policy (M)

Zero CSP headers. I fix XSS fatti (#8 theme_color, #27 AI) sono
difese in profondità valide, ma CSP fornisce il safety net
strutturale mancante.

**Fix**: `.htaccess` `Header always set Content-Security-Policy
"default-src 'self'; script-src 'self'; style-src 'self'
'unsafe-inline'; ..."`. Bisogna enumerare tutti gli inline-script
attuali (admin pages ne hanno) e decidere se rimuoverli o
permetterli con hash/nonce. Lavoro di design, non solo di header.

### A4. Cron endpoint key in query string (S)

`api/cron.php` accetta la chiave via `?key=...`. Query strings
finiscono in Apache access_log, history browser, bookmark.
**Fix**: accetta anche `X-Cron-Key` header; log warning se key
arriva in query string su request HTTP. CLI resta invariato.

### A5. Cron reusa webhook_secret (S)

`api/cron.php` usa lo stesso `webhook_secret` del webhook
Telegram. Secret compromesso = cron DoS gratis (loop).

**Fix**: secret dedicato `cron_secret` in config.json, generato
in install. Backward-compat: se `cron_secret` manca, fallback a
webhook_secret + deprecation warning in log.

---

## Media priorità (tech debt con impatto reale)

### B1. JSON upload OOM (M — "fix #24")

`api/admin.php::actionImportJson` fa `file_get_contents($file
['tmp_name'])` senza limite sulla dimensione. Un export Telegram
da 500 MB (canali grandi, molti anni) viene caricato in RAM
intero, poi json_decode raddoppia. Worker crash.

**Fix**: limite esplicito (es. 50 MB) su `$file['size']` prima
di leggere. Per file più grossi: streaming JSON parser
(complesso, forse libreria; alternativa pragmatica: upload in
chunks via `tus-protocol` o simile, più grosso come lavoro).
Iniziare col cap + errore parlante "file troppo grande, splittate
l'export per anno".

### B2. Schema FTS5 + CHECK + indici compositi (L — "fix #14")

`api/contents.php` fa `LIKE '%q%'` su `c.title`, `c.description`,
`c.ai_summary` — full scan garantito su tabelle grandi. Installs
con 10k+ contenuti iniziano a rallentare percepibilmente.

**Fix**: virtual table FTS5 che indicizza quei 3 campi +
trigger per mantenere sincronizzazione dopo INSERT/UPDATE/DELETE.
Migration script per popolare FTS da contents esistenti.
Aggiunta CHECK constraints su colonne enum (content_type,
source) per integrità. Indici compositi: `(is_deleted,
created_at)`, `(content_id, tag_id)` già c'è.

### B3. Output error leak — `jsonError(500, 'Save error: ' . $e->getMessage())` (S)

`api/admin.php` riga 767 espone il messaggio d'eccezione al
client. Se `$e` contiene path filesystem o dettagli SQL, leak.

**Fix**: messaggio generico al client ('Save failed, see admin
log'), messaggio completo a `Logger::admin`. Pattern già
stabilito da fix #3 error handler.

### B4. Upload logo fallback senza GD (S)

`api/admin.php::actionUploadLogo` su host senza GD fa
`move_uploaded_file` diretto — l'unica barriera è
`mime_content_type`, bypassabile con polyglot. Richiede admin
compromesso (basso rischio reale) ma è un coltello in più in
mano all'attaccante.

**Fix**: rifiutare upload se GD non è disponibile (piuttosto
che copiare senza sanitizzazione); messaggio chiaro all'admin.

### B5. Strict-Transport-Security + Permissions-Policy (S)

HSTS manca; Permissions-Policy manca. Per installazioni HTTPS,
HSTS è baseline.

**Fix**: `.htaccess` condizionale `SetEnvIf ... HTTPS=on` →
`Header always set Strict-Transport-Security "max-age=31536000;
includeSubDomains"`. Permissions-Policy: disabilitare
`camera/microphone/geolocation/payment` che Telepage non usa.

---

## Bassa priorità (nice-to-have, non security)

### C1. Composer + PSR-4 autoload (L)

Attualmente ogni entry-point fa N `require_once` espliciti.
Errore-prone (ricordarsi ordine); fix #21 slugify ha aggiunto
require di Str.php a 5 punti. Composer + autoload elimina il
problema per sempre e apre la porta a dipendenze esterne
(Guzzle per Scraper, phpunit, monolog).

### C2. PHPUnit migration dei test esistenti (M)

Tests attuali (`StrTest`, `CsrfGuardTest`, `UrlValidatorTest`,
ecc.) sono dependency-free PHP scripts. Funzionano ma non si
integrano con CI, no coverage, no assertion framework.

**Fix**: composer require --dev phpunit/phpunit; riscrivere i
5 test file in TestCase; `phpunit.xml` bootstrapping. Test
logic identica, solo il framework cambia.

### C3. GitHub Actions CI (M)

Dopo C2: workflow che gira `php -l` su tutti i .php, esegue
phpunit, eventualmente phpstan (vedi C4). Copre regressioni
meccaniche. Non previene bug logici.

### C4. PHPStan level 5 (M)

Type safety a regime statico. Stanerà chiamate a metodi su
variabili null, argomenti con tipo sbagliato, array access
senza isset.

### C5. Healthcheck endpoint (S)

Endpoint `api/health.php` pubblico che ritorna 200 se DB
raggiungibile, config completo, webhook registrato. Utile per
uptime monitoring esterno. Non tocca alcuna feature esistente.

### C6. Admin monolith split (L)

`api/admin.php` è ~1100 righe con 30 action. Split in file per
dominio (`admin_contents.php`, `admin_tags.php`, `admin_scanner.php`,
`admin_system.php`) migliora leggibilità e testabilità. Nessun
bug noto ma debito tecnico crescente.

### C7. Settings into DB (M) — **WON'T FIX (re-evaluated 2026-04)**

Attualmente molti settings sono in `config.json`. `theme_color`,
`items_per_page`, booleani AI potrebbero stare in una tabella
`settings(key, value)`. Vantaggio: concorrency (ora mitigata con
flock ma DB è più pulito), eventi di cambio, audit log per chi
ha cambiato cosa. Svantaggio: migration + cambio di pattern
consolidato.

**Re-valutazione (post C1-C5)**: l'item è stato chiuso come
*won't fix* dopo aver mappato i call site reali di `Config`.
Le motivazioni dell'audit originale non si sono rivelate
abbastanza forti per giustificare il refactor:

1. **Concurrency già risolta**. `Config::update()` usa un
   `flock(LOCK_EX)` su `config.json.lock` con read-modify-write
   atomico. `tests/ConfigConcurrencyTest.php` stressa 120 worker
   concorrenti e tutti gli update sopravvivono. Spostare a DB
   non aggiunge nulla qui — anzi sposterebbe il problema dal
   filesystem (dove flock è ben capito) a SQLite (dove richiede
   transazioni esplicite per risultati equivalenti).

2. **Audit log non incluso nello scope**. L'audit originale
   nominava "audit log per chi ha cambiato cosa" come motivazione,
   ma il refactor proposto aggiungeva solo un campo `updated_at`
   alla tabella `settings`, non un vero audit trail (che
   richiederebbe una tabella `settings_history` separata). Senza
   il log vero, il "vantaggio" si riduce a una colonna timestamp.

3. **Schema bifurcato peggiora il modello**. Lasciare segreti
   (`webhook_secret`, `cron_secret`, `gemini_api_key`) in
   `config.json` per le ragioni che già conosciamo (mai esposti
   via DB dump, separati dal data-plane) e spostare il resto in
   DB introduce due API di lettura per due categorie diverse di
   settings. 17 file fanno `Config::get()` oggi e leggono
   liberamente l'array merged: cambiare quel contratto è una
   superficie di rottura non banale per zero beneficio.

4. **Costo/beneficio**. Stima 2-3 ore reali di refactor + test
   + migration script. Lo stesso tempo investito in **B2 (FTS5)**
   o **C6 (admin split)** chiude item con valore concreto
   (search performance, code maintainability). C7 è puro
   churning.

**Conclusione**: l'item resta documentato qui per memoria
storica. Se in futuro emerge un caso d'uso reale (es. multi-
admin con audit trail richiesto, o settings frequentemente
cambiati a runtime), riapriamo con scope più chiaro. Per ora
`config.json` rimane il single source of truth per tutta la
configurazione applicativa.

---

## Fuori audit (ma osservato)

- Documentazione: `docs/` ha 2 file (CONTENT-SYNC, HISTORY-SCANNER).
  Non c'è una `SECURITY.md` con policy di disclosure, né una
  `CONTRIBUTING.md`. Non urgente.
- `config.example.json` esiste: è il template per install.
  Scorrendo: OK.
- `lang/`: non guardato. Multi-lingua già supportato.
- Scraper.php: SSRF mitigato (fix #2). Altri vettori non ovvi.
- Migration `bin/migrate-tag-slugs.php`: one-shot, fa il suo.
  Non ci sono altri script di migration pendenti che abbia trovato.

---

## Suggerimento di ordine operativo

1. **A1 (Secure cookie)** — 30 min, impatto immediato su HTTPS
2. **A4 + A5 (cron secret/transport)** — piccoli, chiudono together
3. **B3 (error leak)** — piccolo, buona igiene
4. **B4 (upload GD required)** — piccolo
5. **B5 (HSTS/Permissions-Policy)** — piccolo
6. **A2 (input cap contents.php)** — medio
7. **A3 (CSP)** — medio-grande, richiede mappa inline scripts
8. **B1 (OOM upload)** — medio
9. **B2 (FTS5)** — grande, sessione dedicata
10. Dopo: passare a Phase B (C1-C4) oppure lasciare come roadmap futura

Alta priorità A totalizza ~3-4h di lavoro spalmabile.
Media priorità B (escluso #14 FTS5) ~3h.
