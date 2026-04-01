# Telepage — Installazione

## Requisiti
- PHP 8.1+
- Estensione PDO SQLite
- Estensione cURL
- Server web con HTTPS (richiesto da Telegram per il webhook)

## Installazione

1. **Carica la cartella** `telepage/` sul tuo hosting tramite FTP/cPanel
2. **Assicurati che `config.json` NON sia presente** nella cartella (il wizard lo creerà)
3. **Apri il wizard** nel browser: `https://tuodominio.com/telepage/install/`
4. **Segui i 5 step** del wizard

## Note importanti

- **Non includere `config.json`** nei backup pubblici o repository Git — contiene il token Telegram
- Per installare più siti (un sito per canale), copia la cartella con un nome diverso: `telepage-cucina/`, `telepage-tech/`, ecc.
- Per resettare un'installazione: crea un file `reset.txt` nella cartella root, poi accedi a `install/index.php`

## Dopo l'installazione

1. Imposta il webhook dalla dashboard admin
2. Usa **History Scanner** per importare i messaggi storici del canale
3. I nuovi messaggi arrivano automaticamente via webhook in tempo reale
