# Architecture, Database, Routes, Components & Phases

Stack: **PHP 8.2+ / Yii 3 (yiisoft/app 1.4) / Twig 3 (yiisoft/view-twig) / MySQL 8 (yiisoft/db-mysql v2)
/ Tailwind CSS (build-time) / Alpine.js + vanilla JS (no build) / Lucide icons (sprite, no build)**.
Target: shared hosting — compiled static assets, file cache, file session, no Redis/Node/worker in production.

## 1. Directory layout (within the Yii app)

```
th-tools/
├── composer.json
├── .env / .env.example           # never commit .env
├── config/
│   ├── common/
│   │   ├── params.php            # app name, locale, pagination, tokens
│   │   ├── di/db.php             # ConnectionInterface (MySQL via env)
│   │   ├── di/session.php        # SessionInterface (file), cookie params
│   │   ├── di/cache.php          # PSR-16 FileCache (runtime/cache)
│   │   ├── di/twig.php           # Twig Environment + loader + extensions
│   │   ├── di/services.php       # AuthService, ServiceManager, etc.
│   │   └── di/csrf.php
│   ├── web/routes.php            # all routes (public, auth, admin groups)
│   ├── web/middleware.php        # pipeline: ErrorCatcher, Session, Csrf, Auth, Router
│   ├── environments/prod/params.php
│   └── console/…                 # migration params, seed command
├── migrations/                   # plain PHP migrations (namespace-less)
├── public_html/                  # ONLY web-accessible dir on shared hosting
│   ├── index.php                 # front controller
│   ├── .htaccess                 # rewrite + hardening + caching
│   └── assets/ css/js/img fonts  # compiled Tailwind + JS libs
├── resources/
│   ├── views/                    # Twig templates (layouts/, components/, pages)
│   └── js/                       # app.js, alpine plugins (source; copied to assets)
├── src/
│   ├── Console/SeedCommand.php
│   ├── Service/                  # AuthService, AuthThrottle, DashboardService,
│   │                             # ServiceManager, TransactionService, NotificationService, Api
│   ├── Repository/               # UserRepository, ServiceRepository, TransactionRepository…
│   ├── ServiceProvider/          # ServiceProviderInterface + mocks
│   ├── Auth/Identity.php, IdentityRepository.php, AuthMiddleware.php, AdminMiddleware.php
│   ├── Http/ApiResponse.php, ValidationError.php
│   └── Web/                      # action classes per page (Controllers)
├── runtime/                      # cache, twig cache, logs (web-writable)
├── docs/
└── tests/                        # codeception functional+unit
```

Note: shared hosting maps the docroot to `public_html/`. For local XAMPP dev the app lives at
`C:\xampp\htdocs\th-tools` with `public/` used as docroot by `php yii serve`; deployment uses
`public_html/` (we build in `public/` and rename on deploy, or symlink; see deployment doc).

## 2. Database plan

Core tables (all InnoDB, utf8mb4_unicode_ci):

- **users**: id PK, username (unique), phone (unique), email nullable, password_hash, status enum(active,disabled), role enum(user,staff,admin), avatar nullable, last_login_at null, timestamps.
- **password_reset_tokens**: email PK, token, created_at.
- **service_categories**: id, name, slug unique, icon, description, sort_order, status, timestamps.
- **services**: id, category_id FK→categories, name, slug unique, description, icon, route, service_type enum(mock,manual), price decimal(10,2), badge, status, sort_order, timestamps.
- **transactions**: id, user_id FK, service_id FK, reference unique, amount, status enum(pending,processing,completed,failed,cancelled), metadata JSON null, timestamps. Indexes: (user_id, created_at), service_id.
- **activity_logs**: id, user_id null FK, action, description, ip_address 45, user_agent 512 null, metadata JSON null, created_at. Index user_id.
- **notifications**: id, user_id FK, title, message, type enum(info,success,warning,danger), read_at null, created_at. Index (user_id, read_at).

No sensitive identity data is stored by design; service inputs are passed to mock providers and
only non-sensitive references are kept in `metadata`.

## 3. Route map

Public: `GET /` (landing) · `GET /login` · `POST /login` · `GET|POST /register` · `POST /logout`
· `GET /about|/privacy|/terms` · `GET /services` (public catalog).
Authed (user): `GET /dashboard` · `GET /services/category/{slug}` · `GET /services/view/{slug}`
· `POST /services/view/{slug}` · `GET /transactions` · `GET /notifications` · `POST /notifications/read/{id}`
· `GET|POST /profile` · `POST /profile/password`.
API (authed, JSON, `/api` prefix): `GET /api/dashboard` · `GET /api/services` · `GET /api/notifications`
· `GET /api/transactions` · `PATCH /api/notifications/{id}/read` · `GET /api/profile`.
Admin (role admin/staff): `GET /admin` · CRUD `/admin/users|/admin/categories|/admin/services`
· `GET /admin/transactions` · `GET /admin/activity-logs` · `GET /admin/notifications(broadcast)`.
Errors: dedicated views 400/401/403/404/419/429/500/503 via error renderer mapping.

## 4. UI component map (Twig)

`resources/views/`:
- layouts: `base.twig` (doctype, meta/OG, css/js, lucide sprite), `public.twig` (marketing pages),
  `auth.twig` (split hero + card), `dashboard.twig` (navbar + sidebar + content).
- components: `service-card.twig`, `stat-card.twig`, `table.twig` (thead/tbody + empty/loading),
  `input.twig`, `select.twig`, `textarea.twig`, `button.twig`, `form-error.twig`, `alert.twig`,
  `badge.twig`, `modal.twig`, `pagination.twig`, `breadcrumb.twig`, `category-chip.twig`,
  `empty-state.twig`, `notification-dropdown.twig`, `user-menu.twig`, `toast.twig`.
- macros: `ui.twig` (icon(), money(), statusBadge(), formatDate()).

Alpine.js drives: sidebar drawer, user/notification dropdowns, category filter, search debounce,
modals, toasts, form loading states, collapsible sidebar groups. Vanilla JS module `app.js`:
fetch wrapper with CSRF, debounce, money/date helpers. Lucide via inline SVG sprite (`lucide-sprite.svg`, `x-data`/`data-lucide` init) — no runtime icon JS.

## 5. Implementation phases

- **P1** Foundation: env config, DB DI, session/CSRF pipeline, Twig, Tailwind build, migrations,
  seed data, auth (login/register/logout/throttle/roles), layouts, error pages.
- **P2** Dashboard: navbar, sidebar, welcome banner, stats, category chips, service card grid,
  responsive pass.
- **P3** Service pages + mock providers, transactions, notifications, profile, tables/search/forms,
  JSON API.
- **P4** Admin panel: stats, users CRUD, categories CRUD, services CRUD, transactions, activity logs.
- **P5** Hardening: rate limiting, headers, prod envs, shared-hosting `public_html` + `.htaccess`,
  caching, tests, docs.

## 6. Testing plan

Codeception functional tests via PHP browser against the running app: auth flows (register/login/
logout/bad credentials/throttle), auth-guards (guest→login redirect, non-admin→403), service pages
(list/detail/mock execute/validation), API format (`success/message/data/errors` envelope), admin
CRUD happy paths. Unit tests for throttle logic and money helpers.
