# ContentBridge v1 migration: comiker91

Plugin version: 0.3.0. Generated from the shared Comitement component; provenance records its checksum.

Legacy routes and importers are preserved. Existing placeholders remain unchanged. Native media upload/assignment is preferred; explicit placeholder_fallback keeps the current editorial pipeline usable. No article is published during deployment.

Authentication reuses the existing content credential: constants ['CM91_CONTENT_SECRET', 'CM91_DEPLOY_SECRET'], options ['cm91_git_deployer_secret']. Connect expects Vault key `contentbridge_comiker91_secret`. Existing observer tokens are not interchangeable unless explicitly audited as the same content credential. Keep credentials out of responses and logs.

Run existing repository tests plus ContentBridge v1 contracts, merge through normal GitHub process, and use the existing production code deployment workflow. Verify authenticated `/contentbridge/v1/capabilities` through Connect after deployment. The shared Streamtechnik pilot passed real draft/media/update/SEO/taxonomy/alt/idempotency/fallback and automatic cleanup on 2026-10-01 (Comitement workflow 36872757756).

Only post content is exposed by v1. Legacy content types, routes and scheduled articles continue through their existing workflows. v1 uses explicit publish/schedule transitions and refuses unresolved image placeholders.
