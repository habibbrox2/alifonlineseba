# mPDF Templates

This folder holds Twig templates that are rendered to PDF via mPDF.

When adding a new template here:
1. Use `{{ variable|default('') }}` for every field — mPDF receives the
   service data array directly (no layout wrapper).
2. Set `font-family="solaimanlipi, nikosh, dejavusans"` on the `<html>` tag.
   The `solaimanlipi` and `nikosh` fonts are the preferred Bengali fonts;
   register them with mPDF (see step 5). `dejavusans` is the built-in fallback.
3. Avoid Bootstrap classes, flexbox and `<marquee>` — mPDF 8 supports only a
   subset of CSS. Use `<table>` for layout and inline `@page` / `body` CSS.
4. Name the file after the service key and path /service, e.g. `nid-make-pdf.twig` for the
   `mock-nid-make` service.
5. Register custom fonts once in mPDF config so the template can reference them:
    ```php
    $mpdf = new \Mpdf\Mpdf([
        'fontDir' => [__DIR__ . '/../../resources/fonts'],
        'fontdata' => [
            'solaimanlipi' => [
                'R' => 'SolaimanLipi.ttf',
            ],
            'nikosh' => [
                'R' => 'Nikosh.ttf',
            ],
        ],
    ]);
    ```
    Font files live in `resources/fonts/`, not inside the vendor directory.
    Both are downloaded and committed there.

### QR codes & barcodes in templates

Use the Twig `BarcodeExtension` functions in mPDF templates — no external API needed:

| Function | Source | Output |
|---|---|---|
| `{{ qr_code(data, border, scale, fg, bg) }}` | `App\Service\QrEncoder` | inline SVG |
| `{{ pdf417(data, width, height, color) }}` | `tecnickcom/tc-lib-barcode` | inline SVG |
| `{{ barcode(type, code, width, height, color) }}` | `tecnickcom/tc-lib-barcode` | inline SVG |

Example:
```twig
{{ qr_code('NID: ' ~ nid_number, 4, 3) }}
```
