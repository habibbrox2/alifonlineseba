# AGENTS.md — Digital Sheba / Alif Tools

## 1. Project identity and source of truth

- This repository is the Digital Sheba / Alif Tools digital-services platform. Before changing behavior, inspect the relevant implementation and the current documentation; do not rely on assumptions or stale plans.
- Main stack: PHP 8.2+ / Yii 3 (Yiisoft), PSR-15 middleware and FastRoute, Twig 3, MySQL/MariaDB, Tailwind CSS (build-time), Alpine.js and vanilla JavaScript, Lucide SVG sprite.
- Additional clients: native Android admin app in `android-admin/` (Kotlin, Jetpack Compose, MVVM) and user-facing Trusted Web Activity in `twa/`. Keep their purposes distinct.
- Production target is shared hosting/cPanel with `public/` as the web root, compiled assets, file-backed cache/session, and no resident Node.js service, Redis, or always-on worker. Scheduled tasks may invoke documented PHP console commands where configured.
- Read relevant files under `docs/` before changing a documented subsystem. In particular:
  - `README.md` — local setup and project overview.
  - `docs/architecture-plan.md` — application architecture.
  - `docs/deployment.md` — shared-hosting deployment.
  - `docs/ADMIN_ANDROID_API.md`, `docs/ADMIN_ANDROID_ARCHITECTURE.md`, `docs/ADMIN_ANDROID_SECURITY.md` — admin API and Android client contracts.
  - `docs/notification-architecture-audit.md`, `docs/push-notifications.md`, `docs/webpush-runbook.md` — notification design and operations.
  - `docs/referral-qualifying-recharge-rule.md` — referral qualification and payout invariants.

## 2. Path and File Location Rules (CRITICAL – NEVER VIOLATE)

### 2.1 Project Root
- Canonical project root on this machine: `D:\xampp-server\digital-sheba`
- Always treat this as the single source of truth for paths.
- Never invent, assume, or fall back to other roots (e.g. `D:\upal-server\...`, `C:\...`, `/home/...`, or any other drive/folder name).

### 2.2 Source vs Served Assets (Very Important)

| Category                        | Correct Location                          | Never use / Never assume                  |
|--------------------------------|-------------------------------------------|-------------------------------------------|
| Source JavaScript              | `resources/js/`                           | Do not treat as browser-ready             |
| Source CSS                     | `resources/css/`                          | Do not treat as browser-ready             |
| Twig templates                 | `resources/views/`                        | —                                         |
| Compiled / publicly served JS  | `public/assets/js/`                       | Never put source-only files here          |
| Compiled / publicly served CSS | `public/assets/css/`                      | Never hand-edit generated files           |
| Other public static assets     | `public/assets/` or `public/`             | —                                         |
| Controllers / Actions          | `src/Web/`                                | —                                         |
| Services                       | `src/Service/`                            | —                                         |
| Repositories                   | `src/Repository/`                         | —                                         |
| Migrations                     | `migrations/`                             | —                                         |
| Android Admin                  | `android-admin/`                          | —                                         |
| TWA                            | `twa/`                                    | —                                         |

### 2.3 Absolute Rules for AI about paths

1. **Never guess a file path.**
   If you are not 100% certain where a file lives, first list the directory or ask the user.

2. **Prefer full absolute paths** when referring to any file in replies or commands:
   Example: `D:\xampp-server\digital-sheba\public\assets\js\firebase-social-auth.js`

3. **Client-side JavaScript that is loaded directly by the browser** lives under `public/assets/js/`.
   Source files that go through the build process live under `resources/js/`.
   Do not mix them up.

4. Before creating or editing any file:
   - Confirm the exact folder exists.
   - Use the table above.
   - If the file is a browser-loaded script (e.g. Firebase auth, third-party SDKs, standalone utilities), put it in `public/assets/js/`.

5. When the user mentions a relative path, always expand it against the canonical root `D:\xampp-server\digital-sheba`.

6. If you are about to use a path that does not match the table above, **stop and ask the user for confirmation**.

### 2.4 Common real examples
- Firebase Social Auth (browser): `D:\xampp-server\digital-sheba\public\assets\js\firebase-social-auth.js`
- Source JS that needs building: `D:\xampp-server\digital-sheba\resources\js\...`
- Twig layout: `D:\xampp-server\digital-sheba\resources\views\...`

## 3. Architecture and code placement

- Web routes and middleware: `config/common/routes.php` and the relevant configuration under `config/`.
- HTTP actions are normally invokable classes under `src/Web/`; use constructor dependency injection and keep request parsing/response handling in the action.
- Business rules belong in focused classes under `src/Service/` or the existing domain-specific service area. Do not duplicate those rules in Twig, JavaScript, or Android.
- Database access belongs in `src/Repository/`; follow the repository's existing `ConnectionInterface`, parameterized SQL, table/column quoting and transaction patterns.
- Twig templates live in `resources/views/`; CSS source is in `resources/css/`; source JavaScript is in `resources/js/`; public entry point and served assets live under `public/`.
- Migrations live in `migrations/`. Follow the installed Yii DB migration conventions and inspect adjacent migrations before creating one.
- Android admin source is under `android-admin/app/src/main/java/com/aliftools/admin/`. Its API/data models, repository, ViewModels and Compose screens should follow the existing structure.
- `twa/` is the user-facing Android TWA, not the native admin app. Do not mix their signing, identity, navigation, or release configuration.
- Prefer established project patterns and dependencies. Do not add another framework, ORM, CSS framework, JavaScript framework, or dependency without a clear need and explicit approval.

## 4. Financial and business invariants — treat as critical

- Balance, ledger, top-up/recharge, withdrawal, service-order and referral changes are financial operations. Trace the complete flow before editing it, including transaction boundaries, audit logging, notifications and all clients.
- Never change a user's balance with a bare SQL update or a separate write that bypasses the established ledger service. Use the existing `LedgerService` and repository/service methods so balance, ledger references and before/after values remain consistent.
- Protect every credit, debit, approval, rejection, cancellation, refund and settlement against retries and concurrent requests. Use database transactions, unique/deterministic references and guarded state transitions following the existing implementation.
- A recharge approval must not credit twice, including when two admins act concurrently or a request is replayed. Preserve the transaction-ID duplicate checks and the existing top-up review/claim states.
- Preserve referral qualification semantics: count approved qualifying recharges using the configured threshold and minimum amount; preserve the referral's snapshotted requirements and ensure bonuses are paid at most once. Read the referral rule document before changing it.
- Do not let a client decide prices, balances, roles, approval eligibility, ownership or permission outcomes. Validate and authorize on the server.
- Keep audit/activity-log records for privileged actions and money-moving operations. Do not remove audit events as a side effect of refactoring.

## 5. Authentication, authorization and API contracts

- Server-side authorization is authoritative. Enforce the appropriate user/staff/admin/superadmin permissions in middleware and/or the action/service itself; hiding a button or screen is not security.
- Preserve session-cookie and CSRF protections for browser requests. Follow the established bearer-token flow for machine clients; do not weaken CSRF checks globally to make an API request work.
- For Android admin API changes, update the Yii backend and `android-admin/` client together when request/response fields, status codes, roles, settings, pagination, or error handling change.
- Keep the documented JSON envelope and error semantics compatible. Update `docs/ADMIN_ANDROID_API.md` when the contract changes and add focused tests for both success and failure cases.
- Do not expose password hashes, remember tokens, access/refresh tokens, secrets, internal stack traces or private customer information in responses, activity logs, console output or client logs.
- Treat uploaded identity documents, receipts, images and personal information as sensitive. Validate file size and actual type, generate safe server-side names, prevent path traversal, and store private files outside the publicly served directory unless the existing design explicitly requires otherwise.
- Keep external-provider integrations (FCM, Web Push, Telegram, payment/support providers) behind the established service/channel boundaries. Network failure must not accidentally repeat or reverse a financial action.

## 6. Notifications and scheduled work

- Reuse the existing notification event/channel matrix, manager, queue, channel implementations and console worker. Do not create a second parallel notification pipeline.
- Preserve user push preferences, multi-device fan-out, deduplication keys, retry/dead-letter behavior, and admin fan-out for events that require it.
- Do not make slow third-party HTTP calls inside the critical balance/ledger transaction path. Enqueue work and use the existing console/cron mechanism.
- Keep the shared-hosting constraint in mind: production cannot depend on a continuously running Node process, Redis, or an unconfigured daemon. Document any required cron command and cadence in the operational runbook.

## 7. PHP, database and migration style

- Use `declare(strict_types=1);` and follow the neighboring PHP style, namespaces, imports and return types. Prefer focused `final` action/service/repository classes where consistent with existing code.
- Keep actions thin; put reusable business rules in services and SQL/data mapping in repositories. Inject collaborators through the constructor rather than creating service dependencies ad hoc.
- Use parameterized queries for all untrusted values. Never concatenate user input into SQL identifiers or SQL strings. Whitelist dynamic sort columns and directions.
- Use decimal-safe database values and existing money/ledger conventions for amounts. Avoid float arithmetic for new financial calculations where the established decimal/string or database transaction pattern can be used.
- Migrations must preserve existing data and be safe to reason about during deployment. Inspect the current schema and related migrations first; avoid destructive column/table changes without explicit approval, a verified backup/rollback plan and a compatible rollout.
- Keep migrations repeat-safe where the project convention requires it. Never run a production migration, destructive database command, seed/reset, or rollback without explicit authorization.
- Do not add secrets, customer data, database dumps, production receipts, private keys, or generated runtime artifacts to source control.

## 8. Web UI, assets and accessibility

- Use Twig for server-rendered pages, Tailwind utility classes for styling, Alpine.js for small declarative interactions, and vanilla JavaScript for established browser utilities. Do not introduce Bootstrap or a competing UI stack.
- Preserve the shared layouts, Twig macros/components, CSRF-aware fetch helpers, responsive behavior, keyboard access, visible focus, labels, loading/error/empty states and Bengali user-facing copy.
- Use the project's Lucide sprite and established icon conventions; do not add inline icon libraries or new runtime dependencies without need.
- Edit source files under `resources/` and regenerate compiled assets with the documented build. Do not hand-edit generated `public/assets/css/app.css`, copied JavaScript bundles or generated sprite output unless the task specifically concerns the build output.
- Files that are loaded directly by the browser without going through the build pipeline (e.g. third-party SDKs, Firebase auth helpers, standalone utilities) must live in `public/assets/js/`.
- Keep public pages, customer dashboard and admin interfaces consistent with existing design tokens and permission boundaries. Never rely on client-side validation alone.

## 9. Android admin app

- Follow Kotlin, Jetpack Compose, Material 3, MVVM, StateFlow/coroutines, Retrofit/OkHttp, repository and existing dependency-injection patterns already in `android-admin/`.
- The server remains the single source of truth. Do not add a local database or offline write queue unless the backend and project design explicitly support it.
- Store tokens only through the established secure storage path; never store passwords or log tokens/PII. Preserve HTTPS-only networking, refresh-token rotation, one refresh/retry on 401, role-aware navigation and server-side authorization.
- Sensitive actions must continue to require the appropriate confirmation/re-authentication UX, while the backend independently validates every action.
- Keep signing keystores and signing properties out of version control. Do not weaken cleartext/TLS settings, certificate checks, release hardening or minimum-version handling to work around a development issue.
- For API changes, check `AdminApiService.kt`, `ApiModels.kt`, `AdminRepository.kt`, affected ViewModels/screens and the API contract document.

## 10. Code style and maintainability

- Keep comments minimal and explain why a non-obvious decision or constraint exists—not what the code already says.
- Do not add change-history comments such as `// newly added`, `// updated logic` or `// fixed issue`. Put change summaries in commit/PR descriptions.
- Use clear names, explicit types and small functions with one responsibility. Avoid unnecessary abstractions, duplicated business logic, premature optimization and unrelated refactoring.
- Handle errors and asynchronous operations deliberately. Do not silently swallow failures or show raw internal errors to users.
- Preserve public interfaces, API payloads, database formats, route behavior, notification payloads, Android IPC/navigation contracts and existing configuration unless the task requires a compatible change.
- Make the smallest coherent change that solves the request. Avoid file-wide rewrites and unrelated formatting or dependency changes.
- Add/update focused tests for behavior changes. Report the checks actually run and their real outcomes; never claim unrun checks passed.

## 11. Build, test and verification

Run only checks relevant to the changed area, using the project's installed tools and current environment:

- PHP syntax: `php -l path/to/changed-file.php`.
- PHP test suites: `composer test` or the focused Codeception command documented by the project; test suites may require a configured test database.
- Front-end production build: `npm run build` (runs palette generation, Tailwind compilation, JS sync and Lucide sprite generation).
- Palette validation: `npm run palette:check` / the Composer script `composer palette:check`, if relevant.
- Android admin unit tests: from `android-admin/`, run `.\gradlew.bat testDebugUnitTest` on Windows with a supported JDK (the project documentation specifies JDK 17+).
- Inspect `git diff --check`, the full diff and `git status --short` before finishing. If a test cannot run, state why; do not replace a real test with an unsupported assumption.
- Do not automatically run every expensive test, build, migration, release or deployment command. Choose checks proportional to the change and its risk.

## 12. Git, environment and repository hygiene

- Start by checking `git status --short --branch` and reviewing the relevant diff. The working tree may contain the user's in-progress work.
- Never reset, checkout, clean, stash, rebase, amend, force-push, delete, or overwrite unrelated user changes. Never use `git checkout -- .`, `git reset --hard`, `git clean -fd`, or equivalent destructive shortcuts.
- Stage only files intentionally changed for the task. Do not commit, push, publish a release, deploy, normalize the entire repository, or alter branches unless explicitly authorized.
- Keep `.env`, local properties, database dumps, logs, caches, generated files, receipts, uploads, keystores, certificates, private keys and service-account credentials out of Git. Preserve safe example/config templates such as `.env.example`.
- Ignore rules must target local/generated/secret artifacts only. Before ignoring or untracking a file, inspect whether it is source, a required asset, a fixture, or a user-owned artifact. Never delete a file merely because Git ignores it.
- Use LF line endings for text files where compatible with the existing repository configuration; keep binary assets binary and avoid repository-wide renormalization without explicit approval.
- Do not copy real secrets into examples, docs, test output, screenshots or prompts. If a credential appears exposed, avoid repeating it and report the exposure without rotating it unless authorized.

## 13. AI-assisted changes and contribution quality

- Understand every generated or suggested change before keeping it. Do not introduce code you cannot explain or verify.
- Follow the repository's and target project's contribution policies. If AI disclosure is required, disclose it accurately; do not invent human review or test results.
- Respond to review feedback by addressing the underlying issue, not by making superficial repetitive edits.
- Before finishing, review the final diff for security regressions, financial correctness, redundant comments, unclear names, duplicated logic, accidental secrets and unrelated changes.

## 14. Documentation maintenance

- For every substantial project change, assess whether the root `README.md` must be updated. Major feature additions/removals, architecture or workflow changes, setup/deployment changes, new integrations, changed environment variables, new commands, and meaningful changes to project capabilities must be reflected in the README when relevant.
- Update the README as part of the same task when it is needed; do not leave documentation for a later step if the implementation has already changed.
- Keep the README aligned with the actual implementation. Verify commands, paths, prerequisites, configuration names and feature descriptions against the repository; never document an unimplemented feature or claim an unverified result.
- Update the more specific document under `docs/` as well when a change affects a documented subsystem, API contract, security rule, operational runbook or architecture detail. The README should provide the appropriate overview and link to deeper documentation rather than duplicating long technical specifications.
- Avoid unnecessary README churn for trivial internal fixes or changes that do not affect user-facing behavior, setup, operation, architecture or project capabilities. If no documentation update is needed, keep the README unchanged.
- Include README and other documentation changes in the final changed-files summary, and report documentation checks actually performed.

## 15. Completion report

When reporting work, summarize:
1. What changed and why.
2. Files changed.
3. Tests/checks run and actual results.
4. Any checks not run, known risks, required environment setup or follow-up work.

Do not claim that code is deployed, a notification was delivered, a migration succeeded, a test passed or an Android package was signed unless that result was directly verified.


AGENTS ALWAYS TRY USE MCP CPMMECTOPM RETRY AND USE.