<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\BulkJobRepository;
use App\Repository\ServiceOrderRepository;
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
 * GET|POST /admin/orders — the operator's order queue, and the one place a
 * whole page of it can be settled at once or handed over as a file.
 *
 * **There is more than one operator, and the queue is built around that.**
 * Three filters at the top are not decoration:
 *
 * - "সব" (everything) is the shared pool of work nobody has opened.
 * - "আমার কাজ" (my work) is what *this* admin has claimed — the queue after
 *   they have worked a few, which is the view they actually want most of the
 *   day.
 * - "অন্যের কাজ" (someone else's) is the claimed-by-other-admins view, so a
 *   collision is visible as "taken, by name" rather than as a row that silently
 *   refuses when you press approve.
 *
 * Bulk settling is offered only on unclaimed rows and only for the settle
 * status — see {@see \App\Service\ServiceRequestAdminService::settleMany()},
 * which enforces it again so a crafted POST cannot get past the UI.
 *
 * POST is a bulk action here rather than on separate routes for the same
 * reason the single-order desk uses one endpoint with a `do` discriminator: the
 * bar and the list are the same view, so a separate URL would only mean a
 * second place to keep in step with the filters above.
 *
 * `bulk_status` hands the batch to {@see BulkJobService} rather than settling
 * inline. A hundred rows, each with its own claim check, credit and activity
 * log, does not reliably fit in a web request's time limit — and when it does
 * not, the operator gets a timeout and no way to tell which half moved. The
 * request writes one queue row and returns; cron settles the rest in chunks.
 *
 * `bulk_export` is the opposite case and deliberately stays inline: it only
 * reads, so there is no half-done state to be afraid of.
 *
 * Both methods go through one `state()` normaliser, because the bulk form posts
 * the view it was drawn in back as hidden fields and re-deriving the query
 * string by hand would be a second copy of the sort whitelist.
 */
final readonly class AdminOrdersAction
{
    /**
     * Rows per page, which is also the largest selection the bar can produce —
     * the settle and the export both cap the submission independently, but a
     * queue page cannot hand either of them more than this.
     */
    private const PER_PAGE = 20;

    /** Which slice of the queue this page is showing. */
    public const SCOPE_ALL = 'all';
    public const SCOPE_MINE = 'mine';
    public const SCOPE_TAKEN = 'taken';

    public function __construct(
        private WebViewRenderer $view,
        private ServiceOrderRepository $orders,
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

        $data = $this->orders->adminList(
            $state['page'],
            self::PER_PAGE,
            $state['status'],
            $state['q'],
            $state['sort'],
            $state['dir'],
            $state['scope'] === self::SCOPE_MINE ? $identity->id : null,
            $state['scope'] === self::SCOPE_ALL,
        );

        return $this->view->render('site/admin/orders.twig', [
            'rows' => $data['rows'],
            'total' => $data['total'],
            'page' => $state['page'],
            'perPage' => self::PER_PAGE,
            'status' => $state['status'],
            'q' => $state['q'],
            'scope' => $state['scope'],
            'sort' => $state['sort'],
            'dir' => $state['dir'],
            'statuses' => StatusPresenter::REQUEST_STATUSES,
            // The current view as a query string, for the sortable headers and
            // the pager. Built from the same normalised state the list was
            // rendered from, so the two cannot disagree about what is filtered.
            'ordersQuery' => $this->query($state),
            // How much of the open queue this operator has already taken. Shown
            // as a badge on "আমার কাজ" so the count is answerable without
            // switching tabs — the question an operator asks most often is
            // "what have I already got?"
            'mine' => $this->orders->claimedBy($identity->id),
            'open' => $this->orders->openOrders(),
            // The operator's own recent batches, newest first. Read on every
            // GET rather than fetched by polling: a queue page is reloaded to
            // look at the list, and the list is the natural moment to learn
            // whether last night's batch finished.
            'jobs' => $this->jobs->recentFor($identity->id),
            'identity' => $identity,
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
            'scope' => $input['return_scope'] ?? '',
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
     * page — by which time it is stale news.
     *
     * A selection that exports to nothing is a different case and does
     * redirect: an empty file is indistinguishable from a broken one.
     *
     * @param array<string, mixed> $input
     * @param array{page: int, status: string, q: string, scope: string, sort: string, dir: string} $state
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
     * @return array{page: int, status: string, q: string, scope: string, sort: string, dir: string}
     */
    private function state(array $params): array
    {
        $sort = (string) ($params['sort'] ?? 'id');
        $dir = strtolower((string) ($params['dir'] ?? 'desc'));
        $scope = (string) ($params['scope'] ?? self::SCOPE_ALL);

        return [
            'page' => max(1, (int) ($params['page'] ?? '1')),
            'status' => (string) ($params['status'] ?? ''),
            'q' => trim((string) ($params['q'] ?? '')),
            'scope' => in_array($scope, [self::SCOPE_ALL, self::SCOPE_MINE, self::SCOPE_TAKEN], true)
                ? $scope
                : self::SCOPE_ALL,
            'sort' => isset(ServiceOrderRepository::SORTABLE[$sort]) ? $sort : 'id',
            'dir' => $dir === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** The list URL for a state — where the redirect after a bulk action lands. */
    private function url(array $state): string
    {
        return '/admin/orders?' . $this->query($state) . 'page=' . $state['page'];
    }

    /**
     * The filter part of the URL, without the page number.
     *
     * Shared by the redirect target, the sortable headers and the pager: all
     * three need "the current view minus the page", and three hand-built
     * copies of that are three chances for the list to come back filtered one
     * way and its links to point another.
     *
     * @param array{page: int, status: string, q: string, scope: string, sort: string, dir: string} $state
     */
    private function query(array $state): string
    {
        $query = [];
        if ($state['scope'] !== self::SCOPE_ALL) {
            $query[] = 'scope=' . $state['scope'];
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

        return $query === [] ? '' : implode('&', $query) . '&';
    }
}
