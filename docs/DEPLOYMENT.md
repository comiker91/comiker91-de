# Deployment comiker91.de

## Ziel

GitHub ist die Quelle für die verwalteten Theme- und Plugin-Dateien. Änderungen an `main` werden über den Self-Hosted Runner `livingbots-comiker91` validiert und anschließend signiert an WordPress übertragen.

## Verwaltete Pfade

- `themes/comiker91-streamer-theme/`
- `plugins/cm91-git-deployer/`
- weitere künftig bewusst in `plugins/` oder `themes/` aufgenommene Projekte

Nicht verwaltet werden WordPress Core, Uploads, Datenbank und Server-Konfiguration.

## Bootstrap

Der erste Live-Deploy darf erst nach diesen Schritten erfolgen:

1. `CM91 Git Deployer` manuell in WordPress installieren und aktivieren.
2. Unter **Werkzeuge → CM91 Git Deploy** das erzeugte Secret kopieren.
3. GitHub Repository Secret `CM91_DEPLOY_SECRET` anlegen.
4. `Self-hosted runner smoke test` erfolgreich ausführen.
5. `Deploy comiker91.de` manuell ausführen.
6. Seite und WordPress-Backend prüfen.

## Rollback

Vorhandene verwaltete Ordner werden vor jedem Austausch unter `wp-content/.cm91-deploy-backups/` gesichert. Bei einem Installationsfehler stellt der Deployer den vorherigen Stand automatisch wieder her. Die letzten fünf Backup-Stände bleiben erhalten.
