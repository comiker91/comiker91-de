# comiker91.de Git → WordPress Content Flow

## Sicherheitsziel

Der Flow darf keine bestehenden oder geplanten Inhalte versehentlich verändern.

- Neue Git-Inhalte werden **immer als Entwurf** angelegt.
- Ein vorhandener Git-Entwurf wird nur aktualisiert, wenn er dieselbe interne `source_id` besitzt **und weiterhin `draft` ist**.
- `publish`, `future` (geplant), `pending`, `private`, `trash`, `auto-draft` und andere Status werden vom Import **übersprungen**.
- Es gibt **kein Matching über Titel, Slug, Datum oder Autor**.
- Doppelte `source_id` in WordPress blockiert den Import statt irgendeinen Treffer auszuwählen.
- Bild-Uploads sind nur für CM91-Git-Beiträge im Status `draft` erlaubt.
- Content-ZIPs und Bilder-ZIPs werden auf Path Traversal geprüft.

## Paketstruktur

`content/queue/<artikel>/manifest.json` und `content/queue/<artikel>/article.html`.

Beispielmanifest:

```json
{
  "source_id": "gaming-news-beispiel-2026-08",
  "state": "draft",
  "post_type": "post",
  "title": "Beispieltitel",
  "slug": "beispieltitel",
  "excerpt": "Kurzer Teaser.",
  "author": "comiker91",
  "categories": ["Gaming"],
  "tags": ["Twitch"],
  "seo_title": "Beispieltitel | comiker91",
  "meta_description": "Beschreibung für Suchmaschinen.",
  "focus_keyword": "Beispiel Keyword",
  "canonical": "",
  "og_title": "Beispieltitel",
  "og_description": "Social-Media-Beschreibung",
  "twitter_title": "Beispieltitel",
  "twitter_description": "Social-Media-Beschreibung",
  "schema_page_type": "WebPage",
  "schema_article_type": "Article",
  "images": [
    {"file": "hero.webp", "alt": "Beschreibung des Bildes", "title": "Hero", "featured": true},
    {"file": "detail.webp", "alt": "Detailbild", "title": "Detail", "featured": false}
  ]
}
```

Im `article.html` werden Bilder als `{{image:detail.webp}}` platziert. Nach dem Git-Import erscheint im WordPress-Editor die Box **CM91 Bilder importieren**. Dort wird ein ZIP mit den in `images[]` genannten Dateien hochgeladen. Nur erwartete Bilddateien werden verarbeitet; MIME und Dateiendung müssen zusammenpassen. Das `featured`-Bild wird als Beitragsbild gesetzt, Platzhalter werden durch Gutenberg-Image-Blöcke ersetzt und Alt-Texte werden gesetzt.

## Content vor dem Import prüfen

Ein Paket kann mit folgendem Befehl validiert werden:

`php scripts/validate-content-package.php content/queue/<artikel>`

`state` muss `draft` sein. Falls `post_status` vorhanden ist, darf auch dieser ausschließlich `draft` sein.

## Manuellen GitHub-Import starten

Der Workflow **Content Dispatch** wird ausschließlich über **Actions → Content Dispatch → Run workflow** gestartet. Ein normaler Push importiert keinen Content.

Als Input `package` wird nur der Verzeichnisname unter `content/queue/` angegeben, beispielsweise:

`obs-stream-einstellungen-2026`

Der Workflow validiert das Paket, prüft den Draft-only-Health-Contract der bestehenden CM91 Content Bridge, baut das ZIP, signiert es mit der vorhandenen Secret-Konfiguration und sendet es an die bestehende Bridge. Es gibt keinen Workflow-Input für einen WordPress-Status und keinen Publish-Pfad.

## Ergebnis

Die Bridge meldet pro Beitrag:

- `created_draft` – neuer WordPress-Entwurf wurde angelegt.
- `updated_draft` – bestehender Entwurf mit derselben `source_id` wurde aktualisiert.
- `skipped_protected_status` – die `source_id` gehört bereits zu einem Nicht-Draft-Beitrag; dieser bleibt unverändert.

Fehlerhafte oder mehrdeutige `source_id`, ungültige Manifeste oder ein unsicherer Health-Contract führen zum Fehler statt zu einem Schreibversuch.

## Yoast

Unterstützt werden `seo_title`, `meta_description`, `focus_keyword`, `canonical`, OpenGraph-Titel/-Beschreibung, Twitter-Titel/-Beschreibung sowie Yoast Schema Page/Article Type. Die Metadaten werden nur beim Erstellen oder Aktualisieren eines erlaubten Git-Entwurfs geschrieben.
