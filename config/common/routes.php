<?php

declare(strict_types=1);

use App\Auth\AdminMiddleware;
use App\Auth\ApiAuthMiddleware;
use App\Auth\AuthMiddleware;
use App\Auth\OptionalAuthMiddleware;
use App\Auth\SuperAdminMiddleware;
use App\Web\Account\DeliverableAction;
use App\Web\Account\NotificationsAction;
use App\Web\Account\ProfileAction;
use App\Web\Account\ReceiptAction;
use App\Web\Account\RechargeAction;
use App\Web\Account\RechargeCancelAction;
use App\Web\Account\ReferralsAction;
use App\Web\Account\ServiceHistoryAction;
use App\Web\Account\TransactionsAction;
use App\Web\Admin\AdminCategoriesAction;
use App\Web\Admin\AdminDashboardAction;
use App\Web\Admin\AdminLogsAction;
use App\Web\Admin\AdminNotificationsAction;
use App\Web\Admin\AdminRechargeAction;
use App\Web\Admin\AdminReferralsAction;
use App\Web\Admin\AdminLedgerAction;
use App\Web\Admin\AdminOrderAction;
use App\Web\Admin\AdminOrdersAction;
use App\Web\Admin\AdminServicesAction;
use App\Web\Admin\AdminSettingsAction;
use App\Web\Admin\AdminStaffAction;
use App\Web\Admin\AdminTopupsAction;
use App\Web\Admin\AdminTransactionAction;
use App\Web\Admin\AdminTransactionsAction;
use App\Web\Admin\AdminUserEditAction;
use App\Web\Admin\AdminUsersAction;
use App\Web\Admin\AdminWithdrawsAction;
use App\Web\Api\AppVersionApiAction;
use App\Web\Api\AuthApiAction;
use App\Web\Api\DashboardApiAction;
use App\Web\Api\DeviceApiAction;
use App\Web\Api\NotificationsApiAction;
use App\Web\Api\ProfileApiAction;
use App\Web\Api\PushApiAction;
use App\Web\Api\ServiceRequestApiAction;
use App\Web\Api\ServiceRequestDetailApiAction;
use App\Web\Api\ServiceRequestsWatchApiAction;
use App\Web\Api\ServicesApiAction;
use App\Web\Api\Admin\AdminDashboardApiAction;
use App\Web\Api\Admin\AdminLogsApiAction;
use App\Web\Api\Admin\AdminNotificationsApiAction;
use App\Web\Api\Admin\AdminOrdersApiAction;
use App\Web\Api\Admin\AdminRechargesApiAction;
use App\Web\Api\Admin\AdminSettingsApiAction;
use App\Web\Api\Admin\AdminStaffApiAction;
use App\Web\Api\Admin\AdminTopupsApiAction;
use App\Web\Api\Admin\AdminUsersApiAction;
use App\Web\Api\Admin\AdminWithdrawsApiAction;
use App\Web\Api\TransactionsApiAction;
use App\Web\Auth\ForgotPasswordAction;
use App\Web\Auth\LoginAction;
use App\Web\Auth\LogoutAction;
use App\Web\Auth\RegisterAction;
use App\Web\Auth\ResetPasswordAction;
use App\Web\Dashboard\DashboardAction;
use App\Web\Services\CategoryAction;
use App\Web\Services\ServiceDetailAction;
use App\Web\Site\ApkDownloadAction;
use App\Web\Site\AppPageAction;
use App\Web\Site\AssetLinksAction;
use App\Web\Site\HomeAction;
use App\Web\Site\RobotsAction;
use App\Web\Site\SitemapAction;
use App\Web\Site\StaticPageAction;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

return [
    // Public site
    Route::get('/')->action(HomeAction::class)->name('home'),
    Route::get('/sitemap.xml')->action(SitemapAction::class)->name('sitemap'),
    // Routed, not a file in public/, so the Sitemap line names the host this
    // copy is actually served from instead of a hardcoded production domain.
    Route::get('/robots.txt')->action(RobotsAction::class)->name('robots'),
    Route::get('/about')
        ->action(static fn (StaticPageAction $page) => $page('about'))
        ->name('about'),
    Route::get('/privacy')
        ->action(static fn (StaticPageAction $page) => $page('privacy'))
        ->name('privacy'),
    Route::get('/terms')
        ->action(static fn (StaticPageAction $page) => $page('terms'))
        ->name('terms'),

    // The Android client. Public because the person who has to install the app
    // is, by definition, the one who has no account yet.
    Route::get('/app')->action(AppPageAction::class)->name('app'),
    // The bytes themselves. Outside the web root, so this route is the only
    // way to reach them — see ApkDownloadAction.
    Route::get('/app/apk')->action(ApkDownloadAction::class)->name('app-apk'),

    // The origin's side of the TWA trust handshake. The Android verifier
    // fetches this exact path over HTTPS, from a device that has never signed
    // in, so it is public and outside the auth group. Until it is configured
    // it 404s — which is the correct, diagnosable answer. See AssetLinksAction.
    Route::get('/.well-known/assetlinks.json')
        ->action(AssetLinksAction::class)
        ->name('assetlinks'),

    // Auth
    Route::methods(['GET', 'POST'], '/login')->action(LoginAction::class)->name('login'),
    Route::methods(['GET', 'POST'], '/register')->action(RegisterAction::class)->name('register'),
    Route::post('/logout')->action(LogoutAction::class)->name('logout'),
    // Recovery. Public by definition — the visitor is by definition signed
    // out — and CSRF-protected by the global middleware, so a third party
    // cannot fire resets from a page of theirs. The throttling that keeps
    // this from being an account-enumeration oracle lives inside
    // PasswordResetService, not in a middleware: the rate limit is per
    // identifier, not per route.
    Route::methods(['GET', 'POST'], '/forgot-password')->action(ForgotPasswordAction::class)->name('forgot-password'),
    Route::methods(['GET', 'POST'], '/reset-password')->action(ResetPasswordAction::class)->name('reset-password'),

    // Authenticated app
    Group::create()->middleware(AuthMiddleware::class)->routes(
        Route::get('/dashboard')->action(DashboardAction::class)->name('dashboard'),
        Route::get('/services')->action(CategoryAction::class)->name('services'),
        Route::get('/services/category/{slug}')->action(CategoryAction::class)->name('service-category'),
        Route::methods(['GET', 'POST'], '/services/view/{slug}')->action(ServiceDetailAction::class)->name('service-detail'),
        Route::methods(['GET', 'POST'], '/recharge')->action(RechargeAction::class)->name('recharge'),
        Route::get('/recharge/receipt/{id}')->action(ReceiptAction::class)->name('recharge-receipt'),
        Route::post('/recharge/cancel')->action(RechargeCancelAction::class)->name('recharge-cancel'),
        Route::get('/transactions')->action(TransactionsAction::class)->name('transactions'),
        Route::get('/service-history')->action(ServiceHistoryAction::class)->name('service-history'),
        // Streams the deliverable an admin attached. Deliberately a plain GET
        // link rather than an API call: a <a download> is the only way to hand
        // bytes to the browser without a fetch-then-blob dance, and the action
        // re-checks ownership on every hit.
        Route::get('/service-requests/{id}/file')->action(DeliverableAction::class)->name('service-request-file'),
        Route::get('/referrals')->action(ReferralsAction::class)->name('referrals'),
        Route::get('/notifications')->action(NotificationsAction::class)->name('notifications'),
        Route::post('/notifications/read-all')->action(NotificationsAction::class)->name('notifications-read-all'),
        Route::methods(['GET', 'POST'], '/profile')->action(ProfileAction::class)->name('profile'),
    ),

    // Machine auth (Phase 1.5) — public, no session needed; the throttle for
    // credential abuse lives inside the login flow.
    Route::methods(['POST'], '/api/auth/{action}')->action(AuthApiAction::class)->name('api-auth'),

    // Web Push, deliberately outside the authenticated group: a signed-out
    // visitor on /app can grant notification permission, and that is the whole
    // point of offering browser push at all. The POSTs are CSRF-protected by
    // the middleware stack. See PushApiAction.
    Route::get('/api/push/key')->action(PushApiAction::class)->name('api-push-key'),

    // These two resolve the session identity without requiring one. Being
    // outside AuthMiddleware is what lets a guest subscribe; being inside
    // OptionalAuthMiddleware is what stops that same request from storing
    // `user_id = NULL` when the visitor happens to be signed in. See
    // OptionalAuthMiddleware for what the missing attribute cost.
    Group::create()->middleware(OptionalAuthMiddleware::class)->routes(
        Route::post('/api/push/subscribe')->action(PushApiAction::class)->name('api-push-subscribe'),
        Route::post('/api/push/unsubscribe')->action(PushApiAction::class)->name('api-push-unsubscribe'),
    ),

    // The installed app's update check. Unauthenticated on purpose: an app
    // with an expired token must still be able to find out it needs to update.
    Route::get('/api/app/version')->action(AppVersionApiAction::class)->name('api-app-version'),

    // JSON API (auth required — bearer token OR session)
    Group::create('/api')->middleware(ApiAuthMiddleware::class)->routes(
        Route::get('/dashboard')->action(DashboardApiAction::class)->name('api-dashboard'),
        Route::get('/services')->action(ServicesApiAction::class)->name('api-services'),
        Route::get('/services/{slug}')->action(ServicesApiAction::class)->name('api-service'),
        Route::get('/transactions')->action(TransactionsApiAction::class)->name('api-transactions'),
        Route::get('/service-requests/{id}')->action(ServiceRequestDetailApiAction::class)->name('api-service-request-detail'),
        // The history page polls this for the rows it is currently showing.
        Route::post('/service-requests/watch')->action(ServiceRequestsWatchApiAction::class)->name('api-service-requests-watch'),
        Route::post('/service-requests/{id}/{action}')->action(ServiceRequestApiAction::class)->name('api-service-request'),
        Route::post('/devices')->action(DeviceApiAction::class)->name('api-devices'),
        Route::get('/devices')->action(DeviceApiAction::class)->name('api-devices-list'),
        Route::delete('/devices/{id}')->action(DeviceApiAction::class)->name('api-device-delete'),
        Route::get('/notifications')->action(NotificationsApiAction::class)->name('api-notifications'),
        Route::patch('/notifications/{id}/read')->action(NotificationsApiAction::class)->name('api-notification-read'),
        Route::post('/notifications/read-all')->action(NotificationsApiAction::class)->name('api-notifications-read-all'),
        Route::get('/profile')->action(ProfileApiAction::class)->name('api-profile'),
    ),

    // Admin JSON API (auth required — bearer token OR session)
    Group::create('/api/admin')->middleware(AdminApiMiddleware::class)->routes(
        Route::get('/dashboard')->action(AdminDashboardApiAction::class)->name('api-admin-dashboard'),
        Route::methods(['GET', 'POST'], '/users')->action(AdminUsersApiAction::class)->name('api-admin-users'),
        Route::methods(['GET', 'POST'], '/orders')->action(AdminOrdersApiAction::class)->name('api-admin-orders'),
        Route::post('/orders/bulk')->action(AdminOrdersApiAction::class)->name('api-admin-orders-bulk'),
        Route::get('/topups')->action(AdminTopupsApiAction::class)->name('api-admin-topups'),
        Route::methods(['GET', 'POST'], '/recharges/{id}')->action(AdminRechargesApiAction::class)->name('api-admin-recharges'),
        Route::get('/withdraws')->action(AdminWithdrawsApiAction::class)->name('api-admin-withdraws'),
        // The decision desk. Same action, same AdminWithdrawService as
        // the web /admin/withdraws/{id} desk — the Android super-admin
        // gets exactly the same approve/reject rules as the browser.
        Route::methods(['POST'], '/withdraws/{id}')->action(AdminWithdrawsApiAction::class)->name('api-admin-withdraw'),
        Route::get('/staff')->action(AdminStaffApiAction::class)->name('api-admin-staff'),
        Route::methods(['GET', 'POST'], '/settings')->action(AdminSettingsApiAction::class)->name('api-admin-settings'),
        Route::get('/logs')->action(AdminLogsApiAction::class)->name('api-admin-logs'),
        Route::get('/notifications')->action(AdminNotificationsApiAction::class)->name('api-admin-notifications'),
        Route::post('/notifications/{id}/retry')->action(AdminNotificationsApiAction::class)->name('api-admin-notification-retry'),
    ),

    // Admin (admin/staff/superadmin)
    Group::create('/admin')
        ->middleware(AdminMiddleware::class)
        ->routes(
            // Empty pattern, not "/": the group prefix alone must match "/admin",
            // otherwise FastRoute only accepts "/admin/" and the sidebar link 404s.
            Route::get('')->action(AdminDashboardAction::class)->name('admin'),
            Route::get('/')->action(AdminDashboardAction::class)->name('admin-slash'),
            Route::get('/users')->action(AdminUsersAction::class)->name('admin-users'),
            Route::post('/users')->action(AdminUsersAction::class)->name('admin-users-post'),
            // The per-user editor. Everything a row cannot hold — the name,
            // the handles, the birth date — which is why role and status stay
            // on the list as one-click controls instead of being repeated here.
            Route::methods(['GET', 'POST'], '/users/{id}')->action(AdminUserEditAction::class)->name('admin-user-edit'),
            Route::get('/categories')->action(AdminCategoriesAction::class)->name('admin-categories'),
            Route::post('/categories')->action(AdminCategoriesAction::class)->name('admin-categories-post'),
            Route::get('/services')->action(AdminServicesAction::class)->name('admin-services'),
            Route::post('/services')->action(AdminServicesAction::class)->name('admin-services-post'),
            // The order queue and its per-order desk. Money moves on approve, so
            // this is where an operator spends their day.
            Route::methods(['GET', 'POST'], '/orders')->action(AdminOrdersAction::class)->name('admin-orders'),
            Route::methods(['GET', 'POST'], '/orders/{id}')->action(AdminOrderAction::class)->name('admin-order'),
            // The old combined page. Kept as a redirect rather than deleted so
            // a bookmark, a stored notification link or a chat message from
            // before this change still lands somewhere real.
            Route::methods(['GET', 'POST'], '/transactions')->action(AdminTransactionsAction::class)->name('admin-transactions'),
            Route::methods(['GET', 'POST'], '/transactions/{id}')->action(AdminTransactionAction::class)->name('admin-transaction'),
            // An operator's own earnings, and their withdrawal request.
            Route::methods(['GET', 'POST'], '/ledger')->action(AdminLedgerAction::class)->name('admin-ledger'),
            Route::methods(['GET', 'POST'], '/topups')->action(AdminTopupsAction::class)->name('admin-topups'),
            Route::methods(['GET', 'POST'], '/recharges/{id}')->action(AdminRechargeAction::class)->name('admin-recharge'),
            Route::methods(['GET', 'POST'], '/referrals')->action(AdminReferralsAction::class)->name('admin-referrals'),
            Route::get('/notifications')->action(AdminNotificationsAction::class)->name('admin-notifications'),
            Route::post('/notifications/{id}/retry')->action(AdminNotificationsAction::class)->name('admin-notification-retry'),
            Route::get('/activity-logs')->action(AdminLogsAction::class)->name('admin-logs'),
        ),

    // Super-admin only. A separate group rather than a per-route middleware,
    // so the whole set is guarded by one declaration and a route added later
    // cannot accidentally ship without it.
    //
    // These are the pages where one person's decision moves another person's
    // money or authority: payouts, and who is allowed to do that at all.
    //
    // `Group::middleware()` is variadic, so the two guards are separate
    // arguments. Handing it a single array instead makes it read the array as
    // one definition and every route in the group 500s on a
    // InvalidMiddlewareDefinitionException.
    Group::create('/admin')
        ->middleware(AdminMiddleware::class, SuperAdminMiddleware::class)
        ->routes(
            Route::methods(['GET', 'POST'], '/withdraws')->action(AdminWithdrawsAction::class)->name('admin-withdraws'),
            Route::methods(['GET', 'POST'], '/withdraws/{id}')->action(AdminWithdrawsAction::class)->name('admin-withdraw-desk'),
            Route::methods(['GET', 'POST'], '/staff')->action(AdminStaffAction::class)->name('admin-staff'),
            // Site settings *and* the recharge rules inside them (min/max,
            // receipt, wallet numbers) are owner decisions: they change what
            // every user is asked to pay and where the money goes. The admin's
            // half of that workflow — reviewing and approving a recharge
            // request at /topups and /recharges/{id} — stays in the group
            // above; only the *rules* move here.
            Route::methods(['GET', 'POST'], '/settings')->action(AdminSettingsAction::class)->name('admin-settings'),
        ),
];
