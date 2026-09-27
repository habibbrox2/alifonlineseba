<?php

declare(strict_types=1);

use App\Auth\AdminMiddleware;
use App\Auth\ApiAuthMiddleware;
use App\Auth\AuthMiddleware;
use App\Web\Account\NotificationsAction;
use App\Web\Account\ProfileAction;
use App\Web\Account\ServiceHistoryAction;
use App\Web\Account\TransactionsAction;
use App\Web\Admin\AdminCategoriesAction;
use App\Web\Admin\AdminDashboardAction;
use App\Web\Admin\AdminLogsAction;
use App\Web\Admin\AdminServicesAction;
use App\Web\Admin\AdminSettingsAction;
use App\Web\Admin\AdminTopupsAction;
use App\Web\Admin\AdminTransactionsAction;
use App\Web\Admin\AdminUsersAction;
use App\Web\Api\DashboardApiAction;
use App\Web\Api\NotificationsApiAction;
use App\Web\Api\ProfileApiAction;
use App\Web\Api\ServiceRequestApiAction;
use App\Web\Api\ServicesApiAction;
use App\Web\Api\TransactionsApiAction;
use App\Web\Auth\LoginAction;
use App\Web\Auth\LogoutAction;
use App\Web\Auth\RegisterAction;
use App\Web\Dashboard\DashboardAction;
use App\Web\Services\CategoryAction;
use App\Web\Services\ServiceDetailAction;
use App\Web\Site\HomeAction;
use App\Web\Site\SitemapAction;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

return [
    // Public site
    Route::get('/')->action(HomeAction::class)->name('home'),
    Route::get('/sitemap.xml')->action(SitemapAction::class)->name('sitemap'),
    Route::get('/about')
        ->action(static fn (WebViewRenderer $view) => $view->render('@views/site/about.twig'))
        ->name('about'),
    Route::get('/privacy')
        ->action(static fn (WebViewRenderer $view) => $view->render('@views/site/privacy.twig'))
        ->name('privacy'),
    Route::get('/terms')
        ->action(static fn (WebViewRenderer $view) => $view->render('@views/site/terms.twig'))
        ->name('terms'),

    // Auth
    Route::methods(['GET', 'POST'], '/login')->action(LoginAction::class)->name('login'),
    Route::methods(['GET', 'POST'], '/register')->action(RegisterAction::class)->name('register'),
    Route::post('/logout')->action(LogoutAction::class)->name('logout'),

    // Authenticated app
    Group::create()->middleware(AuthMiddleware::class)->routes(
        Route::get('/dashboard')->action(DashboardAction::class)->name('dashboard'),
        Route::get('/services')->action(CategoryAction::class)->name('services'),
        Route::get('/services/category/{slug}')->action(CategoryAction::class)->name('service-category'),
        Route::methods(['GET', 'POST'], '/services/view/{slug}')->action(ServiceDetailAction::class)->name('service-detail'),
        Route::post('/profile/topup')->action(ProfileAction::class)->name('profile-topup'),
        Route::get('/transactions')->action(TransactionsAction::class)->name('transactions'),
        Route::get('/service-history')->action(ServiceHistoryAction::class)->name('service-history'),
        Route::get('/notifications')->action(NotificationsAction::class)->name('notifications'),
        Route::post('/notifications/read-all')->action(NotificationsAction::class)->name('notifications-read-all'),
        Route::methods(['GET', 'POST'], '/profile')->action(ProfileAction::class)->name('profile'),
    ),

    // JSON API (auth required)
    Group::create('/api')->middleware(ApiAuthMiddleware::class)->routes(
        Route::get('/dashboard')->action(DashboardApiAction::class)->name('api-dashboard'),
        Route::get('/services')->action(ServicesApiAction::class)->name('api-services'),
        Route::get('/services/{slug}')->action(ServicesApiAction::class)->name('api-service'),
        Route::get('/transactions')->action(TransactionsApiAction::class)->name('api-transactions'),
        Route::post('/service-requests/{id}/{action}')->action(ServiceRequestApiAction::class)->name('api-service-request'),
        Route::get('/notifications')->action(NotificationsApiAction::class)->name('api-notifications'),
        Route::patch('/notifications/{id}/read')->action(NotificationsApiAction::class)->name('api-notification-read'),
        Route::get('/profile')->action(ProfileApiAction::class)->name('api-profile'),
    ),

    // Admin (admin/staff only)
    Group::create('/admin')
        ->middleware(AdminMiddleware::class)
        ->routes(
            // Empty pattern, not "/": the group prefix alone must match "/admin",
            // otherwise FastRoute only accepts "/admin/" and the sidebar link 404s.
            Route::get('')->action(AdminDashboardAction::class)->name('admin'),
            Route::get('/')->action(AdminDashboardAction::class)->name('admin-slash'),
            Route::get('/users')->action(AdminUsersAction::class)->name('admin-users'),
            Route::post('/users')->action(AdminUsersAction::class)->name('admin-users-post'),
            Route::get('/categories')->action(AdminCategoriesAction::class)->name('admin-categories'),
            Route::post('/categories')->action(AdminCategoriesAction::class)->name('admin-categories-post'),
            Route::get('/services')->action(AdminServicesAction::class)->name('admin-services'),
            Route::post('/services')->action(AdminServicesAction::class)->name('admin-services-post'),
            Route::get('/transactions')->action(AdminTransactionsAction::class)->name('admin-transactions'),
            Route::methods(['GET', 'POST'], '/topups')->action(AdminTopupsAction::class)->name('admin-topups'),
            Route::methods(['GET', 'POST'], '/settings')->action(AdminSettingsAction::class)->name('admin-settings'),
            Route::get('/activity-logs')->action(AdminLogsAction::class)->name('admin-logs'),
        ),
];
