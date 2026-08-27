<?php
if ($argc < 2) { fwrite(STDERR, "Usage: php scripts/validate-content-package.php <article-dir> [...]\n"); exit(2); }
$failed = false;
foreach (array_slice($argv, 1) as $dir) {
    $dir = rtrim($dir, '/');
    $mf = $dir . '/manifest.json'; $af = $dir . '/article.html';
    if (!is_file($mf) || !is_file($af)) { fwrite(STDERR, "FAIL $dir: manifest.json and article.html are required\n"); $failed=true; continue; }
    $m = json_decode(file_get_contents($mf), true);
    if (!is_array($m)) { fwrite(STDERR, "FAIL $dir: invalid manifest JSON\n"); $failed=true; continue; }
    foreach (['source_id','title'] as $key) if (empty($m[$key]) || !is_string($m[$key])) { fwrite(STDERR, "FAIL $dir: $key is required\n"); $failed=true; }
    if (($m['state'] ?? 'draft') !== 'draft') { fwrite(STDERR, "FAIL $dir: state must be draft\n"); $failed=true; }
    if (isset($m['post_status']) && $m['post_status'] !== 'draft') { fwrite(STDERR, "FAIL $dir: post_status may only be draft\n"); $failed=true; }
    if (!preg_match('/^[a-z0-9_-]+$/', (string)($m['source_id'] ?? ''))) { fwrite(STDERR, "FAIL $dir: source_id must match [a-z0-9_-]+\n"); $failed=true; }
    $article = file_get_contents($af);
    preg_match_all('/\{\{image:([^}]+)\}\}/', $article, $matches);
    $placeholders = array_values(array_unique($matches[1] ?? []));
    $declared = [];
    foreach (($m['images'] ?? []) as $img) {
        $fn = $img['file'] ?? '';
        if (!is_string($fn) || $fn === '' || basename($fn) !== $fn || preg_match('~[\\\\/]~', $fn)) { fwrite(STDERR, "FAIL $dir: invalid image filename\n"); $failed=true; continue; }
        if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $fn)) { fwrite(STDERR, "FAIL $dir: unsupported image extension $fn\n"); $failed=true; }
        if (isset($declared[$fn])) { fwrite(STDERR, "FAIL $dir: duplicate image declaration $fn\n"); $failed=true; }
        $declared[$fn] = true;
    }
    foreach ($placeholders as $fn) if (!isset($declared[$fn])) { fwrite(STDERR, "FAIL $dir: placeholder $fn is not declared in images[]\n"); $failed=true; }
    foreach (array_keys($declared) as $fn) {
        $featured = false;
        foreach (($m['images'] ?? []) as $img) if (($img['file'] ?? '') === $fn && !empty($img['featured'])) $featured = true;
        if (strpos($article, '{{image:' . $fn . '}}') === false && !$featured) { fwrite(STDERR, "FAIL $dir: declared image $fn is neither a placeholder nor featured\n"); $failed=true; }
    }
    if (isset($m['meta_description']) && strlen($m['meta_description']) > 180) fwrite(STDERR, "WARN $dir: meta_description is over 180 characters\n");
    if (isset($m['seo_title']) && strlen($m['seo_title']) > 70) fwrite(STDERR, "WARN $dir: seo_title is over 70 characters\n");
    if (!$failed) echo "OK $dir\n";
}
exit($failed ? 1 : 0);
