# CM91 Git Deployer

Sichere Einweg-Deployment-Bridge zwischen `comiker91/comiker91-de` und der WordPress-Installation auf `comiker91.de`.

## Ablauf

1. Ein Commit landet auf `main` und verändert `plugins/` oder `themes/`.
2. GitHub Actions validiert PHP-Syntax und erstellt ein ZIP-Paket.
3. Das Paket wird per HMAC-SHA256 mit `CM91_DEPLOY_SECRET` signiert.
4. WordPress empfängt es über `/wp-json/cm91-deploy/v1/push`.
5. Das Plugin prüft Signatur und Paketpfade, erstellt Backups, installiert die verwalteten Ordner und rollt bei Fehlern zurück.

## Einmalige Einrichtung

1. Plugin ZIP in WordPress installieren und aktivieren.
2. **Werkzeuge → CM91 Git Deploy** öffnen.
3. Secret kopieren.
4. GitHub → `comiker91/comiker91-de` → Settings → Secrets and variables → Actions.
5. Repository Secret `CM91_DEPLOY_SECRET` anlegen.
6. Workflow `Deploy comiker91.de` manuell starten.

## Sicherheit

- Nur signierte Pakete werden akzeptiert.
- Nur `plugins/` und `themes/` dürfen im Paket vorkommen.
- Path Traversal wird vor dem Entpacken abgewiesen.
- Vor jedem Austausch vorhandener Ordner wird ein Backup erstellt.
- Die letzten fünf Deployments werden behalten.
- WordPress Core, Uploads, Datenbank, Credentials und Server-Konfiguration werden nicht verändert.
