<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Blade compile guard for the Filament page views.
 *
 * Why this exists: on 2026-06-13 the /agency/performance page 500'd with
 * "Undefined variable $gp" — NOT a logic bug, a *compiler* bug. A fragile
 * single-line `@php(...)` directive nested deep in this large Livewire/Filament
 * view failed to receive its closing `?>` during compilation. From that point
 * the rest of the template fell out of PHP context: every downstream directive
 * (`@foreach`, `@if`, `@php`) was emitted as LITERAL TEXT, the goal-progress
 * loop never iterated, and `$gp` was undefined when its `{{ }}` echoes ran.
 *
 * The blast radius reached production precisely because nothing rendered or even
 * COMPILED these views in CI. This test closes that gap cheaply: it compiles
 * each Filament page view with the real Blade compiler (Filament's directive
 * extensions included) and asserts two things hold for the compiled output:
 *
 *   1. No leftover Blade control directives — a compiled view must never still
 *      contain a line beginning with `@php`/`@if`/`@foreach`/`@endphp` etc.
 *      Their presence means the compiler lost PHP context partway through
 *      (the exact failure signature above). CSS at-rules (`@media`,
 *      `@keyframes`) and Alpine attribute directives (`@keydown`) are excluded —
 *      they are legitimately literal and are NOT control directives.
 *   2. The compiled PHP is syntactically valid (`php -l`), so a broken compile
 *      can never reach a browser as a 500 again.
 *
 * DB-free by construction: this only exercises the Blade *compiler*, never
 * mounts a Livewire component, never opens a connection — safe under the
 * local-.env-points-at-prod caveat.
 *
 * Guard rule for future edits: prefer the block form `@php $x = …; @endphp`
 * over the single-line `@php(…)` form inside deeply-nested Filament/Livewire
 * views — the block form compiles through a robust raw-block path that is not
 * subject to the directive-offset drift that broke Performance.
 */
class FilamentViewCompilesTest extends TestCase
{
    /**
     * Control directives that must NEVER survive into compiled output. A
     * compiled `.php` view that still starts a line with one of these means the
     * compiler dropped out of PHP context. Deliberately excludes CSS at-rules
     * (@media, @keyframes, @supports, @font-face, @import, @charset) and Alpine
     * (@click, @keydown, @submit, …) which are legitimately literal in markup.
     *
     * @var list<string>
     */
    private const CONTROL_DIRECTIVES = [
        'php', 'endphp', 'if', 'elseif', 'else', 'endif',
        'foreach', 'endforeach', 'for', 'endfor', 'forelse', 'empty', 'endforelse',
        'while', 'endwhile', 'switch', 'case', 'break', 'default', 'endswitch',
        'isset', 'endisset', 'unless', 'endunless', 'js', 'json',
    ];

    /**
     * Static — runs before the Laravel app boots, so it must NOT use base_path()
     * or any container helper. Derive the project root from this file's location:
     * tests/Unit/ → project root is two levels up.
     *
     * @return array<string, array{0:string}>
     */
    public static function filamentPageViews(): array
    {
        $root = dirname(__DIR__, 2);
        $dir = $root . '/resources/views/filament';
        $cases = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $cases[$rel] = [$file->getPathname()];
            }
        }

        return $cases;
    }

    #[DataProvider('filamentPageViews')]
    public function test_filament_view_compiles_without_losing_php_context(string $path): void
    {
        $compiled = Blade::compileString(file_get_contents($path));

        // (1) No leftover control directive at the start of any compiled line.
        $pattern = '/^\s*@(' . implode('|', self::CONTROL_DIRECTIVES) . ')\b/m';
        $leftover = [];
        if (preg_match_all($pattern, $compiled, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$frag, $off]) {
                $line = substr_count(substr($compiled, 0, $off), "\n") + 1;
                $leftover[] = "compiled line {$line}: " . trim($frag);
            }
        }
        $this->assertSame(
            [],
            $leftover,
            "Blade compiler lost PHP context in {$path} — these control directives "
            . "were emitted as literal text instead of compiled PHP. This is the "
            . "/agency/performance \"Undefined variable\" 500 class. Convert any nearby "
            . "single-line `@php(...)` to the block form `@php ...; @endphp`.\n"
            . implode("\n", $leftover)
        );

        // (2) Compiled PHP must be syntactically valid.
        $tmp = tempnam(sys_get_temp_dir(), 'bladephp_') . '.php';
        file_put_contents($tmp, $compiled);
        try {
            $out = [];
            $code = 0;
            exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
            $this->assertSame(
                0,
                $code,
                "Compiled view {$path} is not valid PHP:\n" . implode("\n", $out)
            );
        } finally {
            @unlink($tmp);
        }
    }
}
