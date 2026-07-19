# ONLYOFFICE Read-only Preview Implementation Plan

> **For the implementing agent:** Follow the test-driven-development skill and execute each task RED → GREEN. Do not commit, reset, or reformat unrelated files.

**Goal:** Keep Jin's lightweight pre-generated WebP on the file detail page while opening supported Office files through an authenticated, JWT-signed, strictly read-only ONLYOFFICE Docs 9.4 viewer from the explicit Preview button.

**Architecture:** Authenticated project-scoped config requests produce a fresh ONLYOFFICE config and short-lived relative-signed internal download URL. The unauthenticated download endpoint validates both Laravel's signature and the immutable stored-file version key before streaming the private original. Svelte loads the DocumentServer API only when the preview dialog opens, destroys the editor on close, and offers the existing preview/download path on failure. PDF and images remain on the existing viewer.

**Tech Stack:** Laravel 13.19, Fortify session auth, Inertia 3, Svelte 5, Pest 4, `firebase/php-jwt` 7.1, ONLYOFFICE Docs 9.4.0.1.

---

### Task 1: Resolve supported formats and immutable document versions

**Files:**
- Create: `app/Support/OnlyOfficeDocumentTypeResolver.php`
- Create: `app/Support/OnlyOfficeDocumentVersion.php`
- Test: `tests/Unit/OnlyOfficeDocumentTypeResolverTest.php`
- Test: `tests/Unit/OnlyOfficeDocumentVersionTest.php`

1. Write failing tests for `xlsx/xls/xlsm/ods => cell`, `docx/doc/odt => word`, `pptx/ppt/odp => slide`, lowercase normalized `fileType`, obvious MIME mismatch rejection, and unsupported PDF/image rejection.
2. Verify RED with the two Unit test files.
3. Implement only the maps and version key. The key is a 64-character SHA-256 over an application namespace plus stored-file ID, immutable random storage path, and size; rename must not change it, replacement path/size must.
4. Verify GREEN.

### Task 2: Build signed, read-only ONLYOFFICE configuration

**Files:**
- Create: `config/onlyoffice.php`
- Create: `app/Support/OnlyOfficeTokenService.php`
- Create: `app/Support/OnlyOfficeConfigService.php`
- Modify: `.env.example`
- Modify: `composer.json`, `composer.lock` (dependency already added as `firebase/php-jwt:^7.1`)
- Test: `tests/Feature/OnlyOfficePreviewTest.php`

1. Write a failing authenticated config-API test asserting `type=embedded`, document type, version key, fixed-base signed URL, `mode=view`, all edit/comment/review/form/chat/protect permissions disabled, env-controlled download/print/copy, macros/plugins disabled, no callback URL, HS256 token, secret absence, and `Cache-Control: no-store`.
2. Verify RED.
3. Add config values only through `config/onlyoffice.php`: enabled, internal DocumentServer URL, browser public URL, DocumentServer-to-Laravel internal app URL, secret, TTL, download/print/copy flags.
4. Build the config in a service; sign the exact config object (before adding root `token`) with `firebase/php-jwt` HS256. Do not log tokens, secrets, or signed URLs.
5. Verify GREEN; add tests for disabled/misconfigured/unsupported cases and key changes after replacement.

### Task 3: Add scoped config and signed streaming download routes

**Files:**
- Create: `app/Http/Controllers/OnlyOfficePreviewController.php`
- Create: `app/Http/Controllers/OnlyOfficeFileController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/OnlyOfficePreviewTest.php`

1. Write failing tests for authorized 200, project outsider 403, wrong-project/unknown file 404, unsupported 422, expired relative signature 403, version mismatch 410, and unauthenticated valid signed stream success.
2. Verify RED.
3. Put config route inside existing team/project scoped auth/tool middleware. Put download route outside session auth with `signed:relative`; accept only bound StoredFile ID and signed `version`, never a storage path or arbitrary URL.
4. Stream with `Storage::readStream`, `Content-Type`, safe inline disposition, no full-file PHP read, `nosniff`, and private/no-store headers.
5. Verify GREEN.

### Task 4: Extend accepted Office formats and WebP detail generation

**Files:**
- Modify: `app/Http/Requests/Phase4/StoreProjectFileRequest.php`
- Modify: `app/Http/Requests/Phase4/ReplaceProjectFileRequest.php`
- Modify: `app/Support/StoredFilePreviewGenerator.php`
- Modify: `resources/js/components/StoredFilePreviewDialog.svelte` or its format helper only as needed
- Test: `tests/Feature/StoredFileTest.php`
- Test: `tests/Feature/StoredFilePreviewTest.php`

1. Write failing tests proving `xlsm/ods/odt/odp` upload validation and queued WebP support.
2. Verify RED.
3. Add the four formats without broadening unrelated uploads. Preserve same-extension replacement validation and source-version guards.
4. Verify GREEN.

### Task 5: Add lazy Svelte ONLYOFFICE dialog with fallback

**Files:**
- Create: `resources/js/components/OnlyOfficePreviewDialog.svelte`
- Modify: `resources/js/pages/projects/files/Show.svelte`
- Keep: `resources/js/components/StoredFilePreviewDialog.svelte` as PDF/image/CSV and explicit fallback
- Test: `tests/Feature/OnlyOfficePreviewTest.php` (source contract where no component harness exists)

1. Write a failing source contract for no list-page script load, config fetch only after open, one shared API script promise, non-zero responsive container, `DocsAPI.DocEditor`, `destroyEditor()`, loading/error UI, existing-preview fallback, and download fallback.
2. Verify RED.
3. Add an Office-only dialog. Select it only when backend marks the format supported and ONLYOFFICE enabled. Load `${publicUrl}/web-apps/apps/api/documents/api.js` exactly once after config success; never on list/detail load. Destroy on close/unmount and before reinitialize.
4. On config/script/init errors, reveal buttons for existing preview and download without exposing internal details.
5. Keep Excel source as original Office file so sheets and two-axis scrolling remain available.
6. Verify Svelte typecheck/lint/build GREEN.

### Task 6: Document and verify deployment

**Files:**
- Modify: `README.md`

1. Document ONLYOFFICE Docs 9.4.0.1 (verified current 2026-05 stable and amd64/arm64 multi-arch), environment variables, HS256 shared secret, three URL directions, reverse proxy/TLS, no direct public exposure, supported formats, read-only settings, and troubleshooting requested in the specification.
2. Because the repository has no Compose file, do not invent a project network; provide a pinned `docker run`/Compose example and production network requirements.
3. Run focused PHP tests, Unit tests, full PHP suite, Pint check, Larastan, Svelte check, ESLint, Prettier check, production build, migration up/down, and Playwright fallback behavior.
4. If a local DocumentServer can be started safely, verify config JWT and original-file fetch; otherwise report actual viewer interaction (including Excel sheet switching) as a production-environment verification item rather than fabricating it.
