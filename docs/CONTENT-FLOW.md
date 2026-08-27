# comiker91.de Git → WordPress Content Flow

## Sicherheitsziel

Der Flow darf keine bestehenden oder geplanten Inhalte versehentlich verändern.

- Neue Git-Inhalte werden **immer als Entwurf** angelegt.
- Ein vorhandener Git-Entwurf wird nur aktualisiert, wenn er dieselbe interne `source_id` besitzt **und weiterhin `draft` ist**.
- `publish`, `future` (geplant), `pending`, `private`, `trash`, `auto-draft` und andere Status werden vom Import **übersprungen**.
- Es gibt **kein Matching über Titel, Slug, Datum oder Autor**. Dadurch können alte manuelle Beiträge nicht zufällig getroffen werden.
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

## Commit-Verhalten

Der Workflow importiert nur vollständige Artikelordner unter `content/queue/`, die im **aktuellen Commit** verändert wurden. Ein manueller Workflow-Start importiert absichtlich keinen Content. Dadurch werden alte Queue-Einträge nicht massenhaft erneut angefasst.

## Yoast

Unterstützt werden `seo_title`, `meta_description`, `focus_keyword`, `canonical`, OpenGraph-Titel/-Beschreibung, Twitter-Titel/-Beschreibung sowie Yoast Schema Page/Article Type. Die Metadaten werden nur beim Erstellen oder Aktualisieren eines erlaubten Git-Entwurfs geschrieben.
