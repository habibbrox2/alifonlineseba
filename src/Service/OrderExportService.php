<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TransactionRepository;

/**
 * CSV export of the orders an operator has ticked.
 *
 * The second thing the bar can do, and deliberately the dull one: exporting
 * changes nothing, so it needs no job, no queue and no second thought about
 * what happens if the request dies halfway. It is here because "settle these
 * forty to processing" and "hand these forty to the next shift as a file" are
 * the same grip on the same list, and splitting them into two pages means the
 * operator ticks the orders twice.
 *
 * Rows come back in the order they were ticked, not in id order, because a
 * selection is a hand-made list and a spreadsheet that reorders it reads as
 * the export being wrong.
 */
final class OrderExportService
{
    /**
     * Byte order mark.
     *
     * Excel on Windows reads a UTF-8 CSV without one as the system codepage,
     * which turns every Bengali service name into mojibake — and this file is
     * destined for exactly that program. It is invisible in a text editor and
     * costs three bytes.
     */
    private const BOM = "\xEF\xBB\xBF";

    /** @var array<string, string> */
    private const HEADERS = [
        'reference' => 'রেফারেন্স',
        'created_at' => 'তারিখ',
        'username' => 'ইউজার',
        'phone' => 'ফোন',
        'service_name' => 'সার্ভিস',
        'amount' => 'পরিমাণ',
        'status' => 'অবস্থা',
        'type' => 'ধরন',
    ];

    public function __construct(private readonly TransactionRepository $transactions) {}

    /**
     * Build the download for a selection.
     *
     * Ids are normalised exactly as the settle normalises them — strip, drop
     * non-positives, de-duplicate, cap — so a crafted id cannot ask for a
     * longer list than the bar can produce, and the two actions disagree about
     * nothing.
     *
     * @param array<int|string, mixed> $ids
     *
     * @return array{filename: string, body: string, exported: int}
     */
    public function csv(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0);
        $ids = array_slice(array_values(array_unique($ids)), 0, ServiceRequestAdminService::BULK_LIMIT);

        $rows = $ids === [] ? [] : $this->transactions->findManyForExport($ids);

        $lines = [self::BOM . $this->line(array_values(self::HEADERS))];
        $exported = 0;

        foreach ($ids as $id) {
            // A ticked id that has since been deleted is not an error, it is
            // just absent from the file — the selection was a moment in time.
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }

            $lines[] = $this->line([
                (string) ($row['reference'] ?? ''),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['username'] ?? ''),
                (string) ($row['phone'] ?? ''),
                (string) ($row['service_name'] ?? ''),
                number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
                // The operator's label, not the stored key: the file goes to a
                // spreadsheet, and "সম্পন্ন" is what that reader can act on.
                StatusPresenter::label((string) ($row['status'] ?? '')),
                $this->typeLabel($row['service_id'] ?? null),
            ]);
            $exported++;
        }

        return [
            'filename' => 'service-orders-' . date('Ymd-His') . '.csv',
            'body' => implode("\r\n", $lines) . "\r\n",
            'exported' => $exported,
        ];
    }

    /**
     * One RFC 4180 record.
     *
     * @param array<int, string> $fields
     */
    private function line(array $fields): string
    {
        return implode(',', array_map(
            static function (string $value): string {
                // Quote on anything that could break the record — a comma in a
                // Bengali service name is ordinary, a newline in a note is not,
                // and Excel treats both as the end of a cell.
                if (preg_match('/[",\r\n]/', $value) === 1) {
                    return '"' . str_replace('"', '""', $value) . '"';
                }

                return $value;
            },
            $fields,
        ));
    }

    /**
     * Top-ups share the table; a file that calls them "service" is misleading.
     *
     * Read off `service_id` rather than a `type` column, because there is not
     * one — a null `service_id` *is* the top-up, the same fact the bulk settle
     * uses to decide a row is not an order it may settle.
     */
    private function typeLabel(mixed $serviceId): string
    {
        return $serviceId === null ? 'রিচার্জ' : 'সার্ভিস';
    }
}
