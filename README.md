# comiker91.de

GitHub-verwalteter WordPress-Code für comiker91.de.

## Struktur

- `themes/comiker91-streamer-theme/` – aktuelles produktives Theme, initial übernommen aus Version 2.4.1
- `plugins/cm91-git-deployer/` – sichere Deployment-Bridge für GitHub Actions
- `.github/workflows/` – Runner-, Deploy- und Smoke-Test-Workflows
- `docs/DEPLOYMENT.md` – Bootstrap- und Deployment-Dokumentation

## Deployment

Der Self-Hosted Runner validiert PHP-Dateien, baut ein Paket aus `plugins/` und `themes/`, signiert es mit `CM91_DEPLOY_SECRET` und überträgt es an WordPress. WordPress erstellt vor Änderungen Backups und rollt bei Fehlern zurück.

**Wichtig:** Solange das WordPress-Deployer-Plugin nicht installiert und `CM91_DEPLOY_SECRET` nicht in GitHub gesetzt ist, ist der Bootstrap noch nicht vollständig.
