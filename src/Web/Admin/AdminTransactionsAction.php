<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\BulkJobRepository;
use App\Repository\TransactionRepository;
use App\Service\BulkJobService;
use App\Service\OrderExportService;
use App\Service\StatusPresenter;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * GET|POST /admin/transactions — the operator's queue, and the one place a
 * whole page of it can be settled at once, or handed over as a file.
 *
 * POST is a bulk action, and it is here rather than on separate routes for the
 * same reason the single-order desk uses one endpoint with a `do`
 * discriminator: the bar and the list are the same view, so a separate URL
 * would only mean a second place to keep in step with the filters above.
 *
 * The two actions are not the same kind of thing, and the code says so.
 *
 * `bulk_status` used to settle inside the request and now hands the batch to
 * `BulkJobService` instead. A hundred rows, each with its own refund,
 * notification and activity log, does not reliably fit in a web request's
 * time limit — and when it does not, the operator gets a timeout and no way to
 * tell which half of the batch moved. The request writes one queue row and
 * returns; cron settles the rest in chunks. The page shows the queue, so the
 * wait is a progress bar rather than a blank form.
 *
 * `bulk_export` is the opposite case and deliberately stays inline: it only
 * reads, so there is no half-done state to be afraid of, and putting a download
 * behind a queue would mean waiting a minute for a file that takes milliseconds
 * to build.
 *
 * The selection is only ever drawn on the `kind=service` tab, because the other
 * tab mixes in top-ups — rows in the same table, with a NULL `service_id`, that
 * are already paid. Settling one of those as `failed` would refund an amount
 * this path never charged. `ServiceRequestAdminService::settleMany()` enforces
 * that too, so the tab hides the path and the service makes it unreachable.
 * The export is allowed to carry them, and labels them, because a handover file
 * that silently dropped the recharges in the selection would look like data loss.
 *
 * Both methods go through one `state()` normaliser. The bulk form posts the
 * view it was drawn in back as hidden fields, and re-deriving the query string
 * from them by hand would be a second copy of the sort whitelist and the page
 * clamping — the two things most likely to drift out of step with the GET.
 */
final readonly class AdminTransactionsAction
{
    /**
     * Rows per page, which is also the largest selection the bar can produce —
     * the settle and the export both cap the submission independently, but a
     * queue page cannot hand either of them more than this.
     */
    private const PER_PAGE = 20;

    public function __construct(
        private WebViewRenderer $view,
        private TransactionRepository $transactions,
        private BulkJobService $bulk,
        private OrderExportService $exporter,
        private BulkJobRepository $jobs,
        private SessionInterface $session,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        if ($request->getMethod() === 'POST') {
            return $this->submit((array) $request->getParsedBody(), $identity);
        }

        $state = $this->state($request->getQueryParams());

        $data = $this->transactions->all(
            $state['page'],
            self::PER_PAGE,
            $state['status'],
            $state['q'],
            $state['sort'],
            $state['dir'],
            $state['kind'] === 'service',
        );

        return $this->view->render('site/admin/transactions.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $state['page'],
            'perPage' => self::PER_PAGE,
            'status' => $state['status'],
            'q' => $state['q'],
            'kind' => $state['kind'],
            'sort' => $state['sort'],
            'dir' => $state['dir'],
            'statuses' => StatusPresenter::REQUEST_STATUSES,
            // The operator's own recent batches, newest first. Read on every
            // GET rather than fetched by polling: a queue page is reloaded to
            // look at the list, and the list is the natural moment to learn
            // whether last night's batch finished.
            'jobs' => $this->jobs->recentFor($identity->id),
        ]);
    }

    /**
     * Run whichever bulk action the bar asked for.
     *
     * `bulk_status` always redirects back to the same view — filters, sort and
     * page carried through — because the operator's next move is nearly always
     * the next batch off the same list, and the queue they now need to watch is
     * rendered on it. `bulk_export` answers with the file instead, so it is the
     * one branch that leaves without a redirect.
     *
     * @param array<string, mixed> $input
     */
    private function submit(array $input, Identity $identity): ResponseInterface
    {
        $state = $this->state([
            'page' => $input['return_page'] ?? '',
            'q' => $input['return_q'] ?? '',
            'status' => $input['return_status'] ?? '',
            'kind' => $input['return_kind'] ?? '',
            'sort' => $input['return_sort'] ?? '',
            'dir' => $input['return_dir'] ?? '',
        ]);

        $do = (string) ($input['do'] ?? '');

        if ($do === 'bulk_export') {
            return $this->export($input, $state);
        }

        // Anything that is not one of the two known actions is refused rather
        // than guessed at: this endpoint moves money, and "no `do` matched"
        // must not fall through into settling whatever `status` happened to
        // arrive.
        $result = $do === 'bulk_status'
            ? $this->bulk->enqueueSettle(
                (array) ($input['ids'] ?? []),
                (string) ($input['status'] ?? ''),
                $identity,
            )
            : ['ok' => false, 'message' => 'অজানা অ্যাকশন।'];

        $this->session->set($result['ok'] ? 'flash_success' : 'flash_error', $result['message']);

        return new Response(302, ['Location' => $this->url($state)]);
    }

    /**
     * The ticked orders as a CSV, or a redirect explaining why not.
     *
     * No flash on the way out: a download does not navigate, so a message set
     * here would not be read until the operator happened to come back to this
     * page — by which time it is stale news. The file arriving is the
     * confirmation, and the browser is already showing it.
     *
     * A selection that exports to nothing is a different case and does redirect:
     * an empty file is indistinguishable from a broken one, so the operator is
     * sent back to the list with the reason rather than handed a header row.
     *
     * @param array<string, mixed> $input
     * @param array{page: int, status: string, q: string, kind: string, sort: string, dir: string} $state
     */
    private function export(array $input, array $state): ResponseInterface
    {
        $csv = $this->exporter->csv((array) ($input['ids'] ?? []));

        if ($csv['exported'] === 0) {
            $this->session->set('flash_error', 'নির্বাচিত অর্ডারের তালিকা খালি, তাই ফাইল তৈরি হয়নি।');

            return new Response(302, ['Location' => $this->url($state)]);
        }

        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader(
                'Content-Disposition',
                // The name is built here, never taken from the request, so it
                // cannot carry a quote or a newline into the header.
                sprintf('attachment; filename="%s"', $csv['filename']),
            )
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($this->streamFactory->createStream($csv['body']));
    }

    /**
     * The queue's filter state, normalised.
     *
     * Read from the query string on GET and from the bulk form's hidden fields
     * on POST, through the same door, so a redirect can rebuild the operator's
     * view without re-implementing — and eventually contradicting — the rules
     * the list itself runs on.
     *
     * @param array<string, mixed> $params
     *
     * @return array{page: int, status: string, q: string, kind: string, sort: string, dir: string}
     */
    private function state(array $params): array
    {
        $sort = (string) ($params['sort'] ?? 'id');
        $dir = strtolower((string) ($params['dir'] ?? 'desc'));

        return [
            'page' => max(1, (int) ($params['page'] ?? '1')),
            'status' => (string) ($params['status'] ?? ''),
            'q' => trim((string) ($params['q'] ?? '')),
            // `kind=service` narrows the queue to service requests. Top-ups live in
            // the same table and have their own admin page, so they are noise here.
            'kind' => (string) ($params['kind'] ?? '') === 'service' ? 'service' : '',
            'sort' => isset(TransactionRepository::SORTABLE[$sort]) ? $sort : 'id',
            'dir' => $dir === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** The list URL for a state — where the redirect after a bulk action lands. */
    private function url(array $state): string
    {
        $query = [];
        if ($state['kind'] !== '') {
            $query[] = 'kind=' . $state['kind'];
        }
        if ($state['q'] !== '') {
            $query[] = 'q=' . urlencode($state['q']);
        }
        if ($state['status'] !== '') {
            $query[] = 'status=' . urlencode($state['status']);
        }
        if ($state['sort'] !== 'id') {
            $query[] = 'sort=' . urlencode($state['sort']);
        }
        if ($state['dir'] !== 'desc') {
            $query[] = 'dir=' . urlencode($state['dir']);
        }
        $query[] = 'page=' . $state['page'];

        return '/admin/transactions?' . implode('&', $query);
    }
}
