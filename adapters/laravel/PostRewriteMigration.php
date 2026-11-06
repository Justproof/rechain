<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Write one hash-guarded, reversible rewrite migration for a post: the after
 * copy and meta come from the edited local row, the before copy and meta from
 * the production row captured at triage. Writes the file, runs nothing.
 *
 * Table and column names come from config/rechain.php, which reads
 * .claude/rechain.json; the generated migration embeds them as literals so it
 * runs with no config dependency of its own.
 *
 * From the rechain skill: github.com/Justproof/rechain (adapters/laravel).
 */
class PostRewriteMigration extends Command
{
    /** Config keys of the meta columns a rewrite may carry, mapped to the migration property that holds the new value. */
    private const META = [
        'meta_title_column' => 'metaTitle',
        'meta_description_column' => 'metaDescription',
        'meta_keyword_column' => 'metaKeyword',
        'image_alt_column' => 'imageAlt',
    ];

    protected $signature = 'app:post-rewrite-migration
                            {slug : The slug of the post to rewrite}
                            {--before= : JSON file of the production row (content plus meta columns) captured at triage}
                            {--expect-hash= : SHA-256 of the production content recorded at triage; refuses on mismatch}
                            {--name=refresh : Suffix for the migration file name}
                            {--summary= : First paragraph of the migration docblock}
                            {--path= : Directory to write into; defaults to database/migrations}';

    protected $description = 'Generate a hash-guarded rewrite migration from the edited local post row and the captured production row';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $beforeFile = (string) $this->option('before');
        $table = (string) config('rechain.table');
        $slugColumn = (string) config('rechain.slug_column');
        $contentColumn = (string) config('rechain.content_column');

        foreach (['table' => $table, 'slug_column' => $slugColumn, 'content_column' => $contentColumn] as $key => $value) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
                $this->error("config rechain.{$key} must be a bare table or column name (got \"{$value}\"); check .claude/rechain.json.");

                return self::FAILURE;
            }
        }

        if ($beforeFile === '' || ! File::exists($beforeFile)) {
            $this->error('Pass --before=FILE, the production row captured at triage.');

            return self::FAILURE;
        }

        $before = json_decode(File::get($beforeFile), true);

        if (! is_array($before) || ! is_string($before[$contentColumn] ?? null)) {
            $this->error("{$beforeFile} is not a JSON object with a {$contentColumn} string.");

            return self::FAILURE;
        }

        /** @var array<string, string> $metaColumns column name => migration property, for the meta columns the config names */
        $metaColumns = [];

        foreach (self::META as $key => $property) {
            $column = config("rechain.{$key}");

            if (is_string($column) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                $metaColumns[$column] = $property;
            }
        }

        $local = DB::table($table)->where($slugColumn, $slug)->orderBy('id')->first([$contentColumn, ...array_keys($metaColumns)]);

        if ($local === null || ! is_string($local->{$contentColumn})) {
            $this->error("No local {$table} row with content for {$slug}.");

            return self::FAILURE;
        }

        $beforeBody = $before[$contentColumn];
        $afterBody = $local->{$contentColumn};
        $beforeHash = hash('sha256', $beforeBody);

        if (is_string($expected = $this->option('expect-hash')) && $expected !== '' && $expected !== $beforeHash) {
            $this->error("The before body hashes to {$beforeHash}, not the production hash {$expected}. Re-capture production before generating.");

            return self::FAILURE;
        }

        if ($beforeBody === $afterBody) {
            $this->error("The local {$slug} body matches production; there is nothing to migrate.");

            return self::FAILURE;
        }

        foreach (['before' => $beforeBody, 'after' => $afterBody] as $label => $body) {
            if (preg_match('/^[ \t]*HTML(?![A-Za-z0-9_\x80-\xff])/m', $body)) {
                $this->error("The {$label} body has a line starting with HTML, which would end the nowdoc early.");

                return self::FAILURE;
            }
        }

        $changed = array_filter(
            array_keys($metaColumns),
            fn (string $column): bool => array_key_exists($column, $before) && $before[$column] !== $local->{$column},
        );

        $updatedAt = config('rechain.updated_at_column');
        $updatedAtLiteral = is_string($updatedAt) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $updatedAt)
            ? ", '{$updatedAt}' => now()"
            : '';

        $directory = (string) ($this->option('path') ?: database_path('migrations'));
        $prefix = (string) (config('rechain.migration_prefix') ?: 'rewrite');
        $file = $directory.'/'.date('Y_m_d_His')."_{$prefix}_".str_replace('-', '_', $slug).'_'.$this->option('name').'.php';
        $stub = base_path((string) (config('rechain.stub_path') ?: 'scripts/lib/post-rewrite-migration.stub'));

        if (! File::exists($stub)) {
            $this->error("No migration stub at {$stub}; copy adapters/laravel/post-rewrite-migration.stub there or set rechain.stub_path.");

            return self::FAILURE;
        }

        File::ensureDirectoryExists($directory);
        File::put($file, strtr(File::get($stub), [
            '{{SUMMARY}}' => wordwrap((string) ($this->option('summary') ?: "Refresh the {$slug} post."), 74, "\n * "),
            '{{SLUG}}' => var_export($slug, true),
            '{{TABLE}}' => $table,
            '{{SLUG_COLUMN}}' => $slugColumn,
            '{{CONTENT_COLUMN}}' => $contentColumn,
            '{{UPDATED_AT}}' => $updatedAtLiteral,
            '{{BEFORE_HASH}}' => $beforeHash,
            '{{AFTER_HASH}}' => hash('sha256', $afterBody),
            '{{META_PROPERTIES}}' => $this->metaProperties($changed, $metaColumns, $before, $local),
            '{{META_AFTER}}' => $changed === []
                ? '[]'
                : '['.implode(', ', array_map(fn (string $column): string => "'{$column}' => \$this->".$metaColumns[$column], $changed)).']',
            '{{AFTER}}' => $afterBody,
            '{{BEFORE}}' => $beforeBody,
        ]));

        $this->info("Wrote {$file}");
        $this->line('Meta columns swapped: '.($changed === [] ? 'none' : implode(', ', $changed)));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $changed
     * @param  array<string, string>  $metaColumns
     * @param  array<string, mixed>  $before
     */
    private function metaProperties(array $changed, array $metaColumns, array $before, object $local): string
    {
        $lines = [];

        foreach ($changed as $column) {
            $lines[] = "\n    private ?string \$".$metaColumns[$column].' = '.$this->export($local->{$column}).';';
        }

        $restore = array_map(fn (string $column): string => "        '{$column}' => ".$this->export($before[$column]).',', $changed);

        $lines[] = "\n    /** @var array<string, string|null> Meta columns as they were before the rewrite, restored by down(). */";
        $lines[] = $restore === []
            ? '    private array $metaBefore = [];'
            : "    private array \$metaBefore = [\n".implode("\n", $restore)."\n    ];";

        return implode("\n", $lines)."\n";
    }

    private function export(mixed $value): string
    {
        return $value === null ? 'null' : var_export((string) $value, true);
    }
}
