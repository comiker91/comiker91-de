<?php
$root = dirname(__DIR__);
$workflow = file_get_contents($root . '/.github/workflows/content-dispatch.yml');
$bridge = file_get_contents($root . '/plugins/cm91-content-bridge/content-bridge-legacy.php');
$validator = file_get_contents($root . '/scripts/validate-content-package.php');
$fail = false;
function check($ok, $message) { global $fail; if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); $fail = true; } else { echo "OK: $message\n"; } }
check(strpos($workflow, 'workflow_dispatch:') !== false, 'workflow is manually dispatched');
check(strpos($workflow, "push:") === false, 'workflow has no push trigger');
check(strpos($workflow, 'post_status') === false || strpos($workflow, 'post_status may only be draft') !== false, 'workflow exposes no publish status input');
check(strpos($workflow, 'CM91_CONTENT_SECRET') !== false, 'workflow uses repository secret for signing');
check(strpos($workflow, 'X-CM91-Deploy-Signature') !== false, 'workflow uses existing bridge signature header');
check(strpos($workflow, 'cm91-content/v1/import') !== false, 'workflow targets existing bridge import route');
check(strpos($workflow, 'cm91-content/v1/health') !== false, 'workflow checks existing bridge health route');
check(strpos($workflow, 'created_draft') !== false && strpos($workflow, 'updated_draft') !== false && strpos($workflow, 'skipped_protected_status') !== false, 'workflow handles bridge actions explicitly');
check(strpos($bridge, "post_status']='draft'") !== false || strpos($bridge, "post_status']='draft'") !== false || strpos($bridge, "post_status']='draft'") !== false, 'bridge creates drafts');
check(strpos($bridge, "skipped_protected_status") !== false, 'bridge protects non-draft source matches');
check(strpos($bridge, "Duplicate source_id in WordPress; blocked.") !== false, 'bridge blocks ambiguous source_id');
check(strpos($bridge, "Only state=draft is accepted.") !== false, 'bridge rejects non-draft state');
check(strpos($bridge, "post_status may only be draft.") !== false, 'bridge rejects non-draft post_status');
check(strpos($bridge, "Unsafe path in package.") !== false && strpos($bridge, "Unsicherer ZIP-Pfad.") !== false, 'bridge retains ZIP path protection');
check(strpos($bridge, "Bildinhalt passt nicht zur Dateiendung.") !== false, 'bridge retains image MIME protection');
check(strpos($validator, "state must be draft") !== false && strpos($validator, "post_status may only be draft") !== false, 'repository validator enforces draft-only');
exit($fail ? 1 : 0);
