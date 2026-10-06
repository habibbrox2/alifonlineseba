<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Codeception\Test\Unit;

use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

/**
 * Alpine must boot *after* every script that defines a component provider.
 *
 * The failure this guards is silent, total, and easy to misread. Alpine's cdn
 * build ends with `queueMicrotask(() => Alpine.start())`, and the HTML spec
 * runs a microtask checkpoint after *each* deferred script. So a
 * `<script defer src="alpine.min.js">` sitting in <head> does not merely finish
 * before the body's deferred scripts — `start()` has already walked the DOM by
 * the time the second one runs.
 *
 * Anything whose `x-data` calls a global that a page-rendered script defines is
 * then evaluated a microtask too early and throws
 * `ReferenceError: <fn> is not defined`. That is what the icon picker on
 * /admin/services was doing: `iconPicker` was still undefined when Alpine
 * reached `x-data='iconPicker(...)'`, so the component got no data object and
 * all twenty-odd bindings inside it fell through to same-named window globals.
 * `x-show="open"` then read `window.open` and `@click.outside="close()"` called
 * `window.close()` — the browser answered "Scripts may close only the windows
 * that were opened by them".
 *
 * No PHP test can catch that by rendering a page, because the ordering is a
 * property of script tag *position*, so this reads the templates as text. The
 * thing worth pinning is the invariant, not the current spelling of the tag:
 * Alpine's script must come after `</head>`, after `{% block body %}`, and
 * after every other script in the layout. Move it back into <head> to "tidy
 * the markup up" and this fails with the reason printed.
 */
final class AlpineBootOrderTest extends Unit
{
    /** The tag itself, matched verbatim so a refactor has to be a deliberate one. */
    private const ALPINE_TAG = '<script defer src="/assets/js/alpine.min.js"></script>';

    private string $layout;

    protected function _before(): void
    {
        $this->layout = self::readTemplate(self::templatePath('layouts/base.twig'));
    }

    private static function templatePath(string $relativePath): string
    {
        return codecept_root_dir() . 'resources/views/' . $relativePath;
    }

    public function testAlpineBootsAfterHeadSoBodyScriptsHaveAlreadyRun(): void
    {
        $alpine = strpos($this->layout, self::ALPINE_TAG);
        assertNotFalse($alpine, 'base.twig must load Alpine exactly once — the tag below is the app-wide boot point.');

        $headEnd = strpos($this->layout, '</head>');
        assertNotFalse($headEnd, 'base.twig must still close <head>.');

        assertLessThan(
            $alpine,
            $headEnd,
            'Alpine boots from a microtask, so a <head> tag makes start() run before '
            . 'the body\'s deferred scripts. Any component whose x-data calls a global '
            . 'defined by a page script then throws "is not defined", and its bindings '
            . 'silently resolve against window instead — x-show="open" reads '
            . 'window.open and @click.outside="close()" calls window.close(). '
            . 'Move the tag to the end of <body>; see the comment above it.',
        );
    }

    public function testAlpineBootsAfterPageContent(): void
    {
        $alpine = strpos($this->layout, self::ALPINE_TAG);
        assertNotFalse($alpine, 'base.twig must load Alpine exactly once.');

        $body = strpos($this->layout, '{% block body %}');
        assertNotFalse($body, 'base.twig must keep a {% block body %}: every page script is rendered inside it.');

        assertLessThan(
            $alpine,
            $body,
            'Alpine must boot after {% block body %}. That block is where every page '
            . 'renders its own scripts (the icon picker, the dashboard provider), and '
            . 'deferred scripts only execute in document order.',
        );
    }

    public function testAlpineIsTheLastScriptInTheLayout(): void
    {
        $alpine = strpos($this->layout, self::ALPINE_TAG);
        assertNotFalse($alpine, 'base.twig must load Alpine exactly once.');

        $after = substr($this->layout, $alpine + strlen(self::ALPINE_TAG));

        assertStringNotContainsString(
            '<script',
            $after,
            'Nothing may follow Alpine\'s boot tag. A script emitted after it — including '
            . 'one added to {% block scripts %} — runs after start() has already walked '
            . 'the DOM, which is the whole failure this test exists for.',
        );
    }

    public function testExactlyOneLayoutBootsAlpine(): void
    {
        $boots = [];
        foreach (glob(self::templatePath('layouts') . '/*.twig') ?: [] as $file) {
            if (str_contains(self::readTemplate($file), 'alpine.min.js')) {
                $boots[] = pathinfo($file, PATHINFO_FILENAME) . '.twig';
            }
        }

        sort($boots);
        assertSame(
            ['base.twig'],
            $boots,
            'Alpine must be booted by base.twig and nothing else. A layout that loads it '
            . 'again boots a second, independent Alpine over the same DOM.',
        );
    }

    /**
     * The picker's provider is a global called from `x-data`, so how its script
     * tag is written is load-bearing too. `async` would drop it out of the
     * ordered deferred list entirely, and this is the invariant that keeps a
     * future edit to `icon-picker.js` from re-breaking the picker alone.
     */
    public function testIconPickerProviderLoadsAsAnOrderedDeferredScript(): void
    {
        $picker = self::readTemplate(self::templatePath('components/icon-picker.twig'));

        assertSame(
            1,
            preg_match('/<script\s+defer\s+src="[^"]*icon-picker\.js[^"]*"\s*><\/script>/', $picker),
            'components/icon-picker.twig must load its provider as '
            . '<script defer src="…icon-picker.js…"></script>. `async` takes it out of '
            . 'the ordered deferred list, and type="module" re-orders it against Alpine; '
            . 'either way the provider can land after start().',
        );

        assertSame(
            1,
            preg_match("/x-data='iconPicker\\(/", $picker),
            "The provider call must stay inside a single-quoted x-data attribute. Twig "
            . 'writes the URLs with json_encode, which emits double quotes — a '
            . "double-quoted x-data=\"iconPicker(\"/assets/…\")\" ends the HTML attribute "
            . 'at the first inner quote, so Alpine receives a truncated expression and '
            . 'the picker silently never initialises.',
        );
    }

    /** Template source with Twig comments removed, so prose cannot satisfy a match. */
    private static function readTemplate(string $absolutePath): string
    {
        $raw = file_get_contents($absolutePath);
        assertNotFalse($raw, "Template not found or unreadable: {$absolutePath}");

        // The comment above Alpine's tag explains this exact ordering in prose,
        // including the words "defer" and "</head>" — strip comments first so the
        // test reads tags, not documentation.
        assertStringContainsString('{%', $raw, "Sanity check: {$absolutePath} looks like a Twig template.");

        $stripped = preg_replace('/\{#.*?#\}/s', '', $raw);

        return $stripped === null ? $raw : $stripped;
    }
}