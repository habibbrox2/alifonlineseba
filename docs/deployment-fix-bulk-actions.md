# Production Deployment Runbook: Admin Bulk-Action Fix

This document outlines the safe deployment steps for the administrative interface fixes. These changes are primarily server-side (PHP/Twig) and do not require frontend asset rebuilding.

## ⚠️ Pre-Deployment Check
- [ ] Ensure a database backup has been performed.
- [ ] Verify that the current production branch is in sync with `main`.
- [ ] Confirm that the `runtime/` directory is writable on the production server.

## 🚀 Deployment Steps

### 1. Code Upload
- [ ] Upload the modified files via Git or SFTP.
  - `src/Web/Admin/AdminStaffAction.php`
  - `resources/views/site/admin/users.twig`
  - `resources/views/site/admin/staff.twig`
  - `tests/Web/AdminBulkStatusCest.php`

### 2. Database Migrations
- [ ] Run any pending migrations (though no schema changes were part of this specific fix, this is standard procedure).
  ```bash
  php yii migrate:up
  ```

### 3. Cache & Runtime Maintenance
- [ ] **CRITICAL:** Clear the Twig template cache to ensure the new markup (the updated `do` verbs) is rendered immediately.
  ```bash
  rm -rf runtime/twig/*
  ```
- [ ] (Optional) Clear the application cache if unexpected behavior persists.
  ```bash
  # If using FileCache as per docs
  rm -rf runtime/cache/*
  ```

### 4. Verification
- [ ] Log in to the Admin panel.
- [ ] Navigate to **User Management** and perform a **Bulk Status** change on multiple users.
- [ ] Navigate to **Staff Roster** and perform a **Bulk Role** change.
- [ ] Verify that "Restore All Staff" works correctly.

## 🛠 Troubleshooting
- **Issue:** Bulk buttons still show old behavior or no reaction.
  - **Cause:** Twig cache is still serving the old templates.
  - **Fix:** Re-run `rm -rf runtime/twig/*`.
- **Issue:** "Action not found" or "Unknown action" error.
  - **Cause:** Mismatch between the Twig `<option value="...">` and the PHP `match($do)` branch.
  - **Fix:** Check that the Twig values now include the `bulk_` prefix (e.g., `bulk_status`).
