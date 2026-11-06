<?php

/*
|--------------------------------------------------------------------------
| Rechain adapter
|--------------------------------------------------------------------------
|
| Table and column names for the post-rewrite migration generator. The shell
| scripts read .claude/rechain.json directly; this file reads the same JSON so
| the artisan command and the scripts cannot disagree. Keys missing from the
| JSON fall back to the defaults below. `php artisan config:cache` inlines the
| result, so edit the JSON and re-cache.
|
| From the rechain skill: github.com/Justproof/rechain (adapters/laravel).
*/

$file = base_path('.claude/rechain.json');
$json = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];

return array_merge([
    'table' => 'posts',
    'slug_column' => 'slug',
    'content_column' => 'content',
    'status_column' => null,
    'published_value' => null,
    'meta_title_column' => null,
    'meta_description_column' => null,
    'meta_keyword_column' => null,
    'image_alt_column' => null,
    'updated_at_column' => 'updated_at',
    'stub_path' => 'scripts/lib/post-rewrite-migration.stub',
    'migration_prefix' => 'rewrite',
], is_array($json) ? array_filter($json, fn ($key) => ! str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY) : []);
