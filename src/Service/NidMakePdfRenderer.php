<?php

declare(strict_types=1);

namespace App\Service;

use Mpdf\Mpdf;
use RuntimeException;
use Twig\Environment as TwigEnvironment;

/**
 * Renders the NID card PDF for a provider-backed order.
 *
 * The images are the whole point of this class and also its only sharp edge.
 * A photo and a signature arrive through `ImageUploadStorage`, which files them
 * under a hashed name *outside the document root* and deliberately has no
 * method that hands a caller a servable location — so there is no URL to put
 * in `<img src>`. They are inlined as `data:` URIs instead, read here by the
 * process that owns them. That keeps the "never reachable over HTTP" property
 * intact (the bytes go into a PDF a person is already entitled to) while giving
 * mPDF something it can actually load, and it sidesteps the other problem with
 * a bare filesystem path: a Windows path carries backslashes and a drive
 * letter, and putting one into an HTML attribute unescaped is how a template
 * ends up with a broken image on somebody else's machine.
 *
 * Values come from {@see \App\Repository\ServiceSubmissionRepository}, i.e.
 * field-keyed JSON, and every one of them is stringified before it reaches the
 * template. The JSON is not a fixed set of columns, so a hand-edited row could
 * contain an array where the template expects text; flattening here means the
 * template can rely on every variable being printable rather than each `{{ }}`
 * having to defend itself.
 */
final class NidMakePdfRenderer
{
    /**
     * Form fields the card template reads, in the order it reads them.
     *
     * A whitelist rather than "pass everything through": the stored JSON is
     * attacker-influenced in the sense that any extra key an admin added to a
     * form would arrive here too, and the template has no use for those. It
     * also bounds what a corrupted row can do to the rendered page.
     *
     * @var string[]
     */
    private const FIELDS = [
        'nid_number',
        'pin',
        'name_bangla',
        'name_english',
        'date_of_birth',
        'birth_place',
        'father_name',
        'mother_name',
        'gender',
        'blood_group',
        'issue_date',
        'full_address',
    ];

    /**
     * The template engine, not the web view renderer.
     *
     * The card is a single standalone fragment: a layout, a CSRF token and an
     * injected identity would all be wrong in it, and asking `WebViewRenderer`
     * for it means this class quietly inherits whatever every page injects.
     * Plain Twig is also what the container already has, so the renderer works
     * from a console poll with no request behind it.
     */
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly ImageUploadStorage $images,
    ) {}

    /**
     * The card as PDF bytes.
     *
     * @param array<string, mixed> $values the order's stored field-keyed answers
     * @param array<string, mixed> $extra template extras (reference, dates)
     *
     * @throws RuntimeException when the template or mPDF cannot produce a
     *                          document — a caller must never be handed an
     *                          empty "PDF", because a 200 with a broken file is
     *                          worse to debug than a 500.
     */
    public function render(array $values, array $extra = []): string
    {
        $parameters = $extra;

        foreach (self::FIELDS as $field) {
            // Printed for a human: a date goes on the card the way the customer
            // typed it (`06-10-2026`), not as the `2026-10-06` the row stores.
            // ServiceDate::display() leaves every value that is not a date
            // untouched, so names, numbers and the photo name pass through.
            $parameters[$field] = ServiceDate::display($this->flatten($values[$field] ?? ''));
        }

        $parameters['photo_url'] = $this->imageSource($values['photo'] ?? null);
        $parameters['signature_url'] = $this->imageSource($values['signature'] ?? null);

        try {
            $html = $this->twig->render('mpdf/nid-make-pdf.twig', $parameters);
            $mpdf = $this->mpdf();
            $mpdf->WriteHTML($html);

            return (string) $mpdf->Output('', 'S');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'পিডিএফ তৈরি করা যায়নি: ' . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * A `data:` URI for a stored image, or null when there is not one to show.
     *
     * Null rather than an empty string so the template's "ফটো নেই" branch is
     * the truth: a missing photo is a normal state for this service (both image
     * fields are optional), not an error worth reporting.
     *
     * @param mixed $relative a `ImageUploadStorage` relative path
     */
    private function imageSource(mixed $relative): ?string
    {
        if (!is_string($relative) || trim($relative) === '') {
            return null;
        }

        // absolutePath() re-checks the shape of the path and that the file is
        // inside the base directory, so a value that was hand-edited into the
        // database cannot point mPDF at an arbitrary file on the server.
        $absolute = $this->images->absolutePath($relative);
        if ($absolute === null || !is_file($absolute)) {
            return null;
        }

        $bytes = @file_get_contents($absolute);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $mime = (string) (getimagesize($absolute)['mime'] ?? 'image/jpeg');

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }

    /**
     * A stored value as something printable.
     *
     * Scalars pass through as strings; arrays are dropped rather than
     * `json_encode`d, because a nested value in a form field means the row is
     * not what this template was written for and an empty cell says so more
     * honestly than a wall of escaped JSON.
     */
    private function flatten(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'হ্যাঁ' : 'না';
        }

        return '';
    }

    /**
     * mPDF configured with the Bengali fonts.
     *
     * Registered from `resources/fonts/` rather than the vendor directory so
     * the .ttf files are part of the repository — mPDF cannot subset or embed a
     * font it cannot find, and a deployment that ships without them produces
     * boxes instead of Bengali text with no error anywhere.
     */
    private function mpdf(): Mpdf
    {
        $fonts = dirname(__DIR__, 2) . '/resources/fonts';
        $temp = dirname(__DIR__, 2) . '/runtime/mpdf';

        if (!is_dir($temp)) {
            @mkdir($temp, 0o750, true);
        }

        return new Mpdf([
            'fontDir' => $fonts,
            'fontdata' => [
                'solaimanlipi' => ['R' => 'SolaimanLipi.ttf'],
                'nikosh' => ['R' => 'Nikosh.ttf'],
            ],
            'tempDir' => $temp,
            // mPDF's own default is a system temp path that shared hosting
            // frequently does not let the web user write to; a failure there
            // surfaces as a blank page, so it is pinned to somewhere known.
            'mode' => 'utf-8',
        ]);
    }
}