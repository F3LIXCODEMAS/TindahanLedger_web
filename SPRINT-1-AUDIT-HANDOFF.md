# TindahanLedger Sprint 1 Audit Handoff

**Updated:** 2026-10-15  
**Purpose:** Preserve the read-only Sprint 1 audit and verification state for a future AI or developer.  
**Approval boundary:** This work was an audit only. Do not implement the findings or otherwise modify the application unless the owner explicitly approves.

## Scope and outcome

The audit compared the repository with the Sprint 1 Jira checklist supplied by the owner: DUMS-21, DUMS-22, DUMS-23, DUMS-25, DUMS-26, DUMS-27, financial integrity, Suki Points/debt cycles, user interface, security, and automated tests.

**Overall conclusion:** Sprint 1 is substantially implemented. The financial workflows have meaningful feature tests and are coherent along the normal application paths. Before relying on the application for live financial records, address or explicitly accept the database-level payment/customer association gap and the customer credit-limit edit race, and verify locking/concurrency on the intended MySQL/MariaDB runtime. An old payment confirmation also has an inaccurate fallback after later transactions.

No Critical or High-severity vulnerability was confirmed. No application code was changed during the audit.

## Ticket status at audit time

| Ticket | Status | Evidence and outstanding work |
|---|---|---|
| DUMS-21 — Database foundation | PARTIAL | Decimal financial columns, email uniqueness, foreign keys, and indexes are present. All migrations reported applied on the active local database, and expected columns were read. Payment customer/entry ownership is not a database invariant. Production schema/data were not inspected. |
| DUMS-22 — Owner authentication | PASS | Authenticated routes, throttled login, session regeneration/invalidation, password change, and feature coverage are present. No owner self-registration or customer-facing account route was found. Password reset is not implemented; it was not treated as a Sprint 1 defect absent an explicit requirement. |
| DUMS-23 — Customer management | PARTIAL | Required-field validation, email uniqueness/edit self-exclusion, normalization, duplicate name/contact detection, and outstanding-based credit-limit checks are present and tested. Customer list has no search. Credit-limit updates can race with concurrent credit creation. |
| DUMS-25 — Customer ledger | PARTIAL | Multi-credit history, payments, running balances, overdue calculations, available credit, and empty state have implementation and feature coverage. A mismatched payment association could cause customer-wide and per-entry results to diverge. |
| DUMS-26 — Record credit | PARTIAL | Server validation, hard credit-limit blocking, transaction handling, locking, idempotency, and rollback coverage exist. MySQL/MariaDB concurrency and the credit-limit-edit race are unverified. |
| DUMS-27 — Record payment | PARTIAL | Positive amount validation, overpayment rejection, FIFO allocation, idempotency, transactional writes, and multi-credit tests exist. Old confirmation fallback can show inaccurate historical balances; real-engine concurrency and the database association invariant remain open. |

## Findings to carry forward

### F-1 — Payment/customer ownership is not enforced in the schema (Medium)

`payments` has separate `customer_id` and `ledger_entry_id` foreign keys, but nothing requires the referenced ledger entry to belong to the referenced customer. The normal `LedgerService` path supplies consistent values, but a direct or alternate write could create an inconsistent row and cause customer-wide balances to disagree with per-credit calculations.

- Evidence: `database/migrations/2026_09_23_155949_create_payments_table.php:14-22`; `app/Services/LedgerService.php:246-269`; dashboard aggregation in `app/Http/Controllers/DashboardController.php:18-30`.
- Follow-up: enforce the ownership invariant at the database boundary (or reconsider the redundant association) and add an integrity regression test.
- No mismatched payment row was reported or investigated; this is a missing schema invariant, not evidence of corrupted data.

### F-2 — Revisited payment confirmation can show incorrect historical balances (Medium)

When the flashed receipt is absent, `PaymentController::confirmation` calculates the remaining amount using the customer's current balance and derives the previous amount by adding the old batch amount. Later credits or payments can therefore change what an old confirmation displays.

- Evidence: `app/Http/Controllers/PaymentController.php:81-108`.
- Follow-up: persist a batch receipt snapshot or show only values that can be accurately derived from that payment batch. Test revisiting a batch after later activity.

### F-3 — Credit-limit update can race with credit creation (Medium)

`SaveCustomerRequest` checks outstanding debt before `CustomerController::update` writes the new limit. That read/check and update are not one locked transaction. Credit creation does lock the customer, but the customer update path does not share an atomic check/update boundary.

- Evidence: `app/Http/Requests/SaveCustomerRequest.php:107-123`; `app/Http/Controllers/CustomerController.php:67-76`; `app/Services/LedgerService.php:21-45`.
- Follow-up: check and update the limit atomically while locking the customer; test the race against MySQL/MariaDB.
- This was not reproduced. Existing tests use SQLite in-memory and do not prove production database locking.

### F-4 — Customer index lacks search (Low / scope to confirm)

The customer index is alphabetically ordered and paginated but has no search field. Credit/payment forms do have customer search.

- Evidence: `app/Http/Controllers/CustomerController.php:15-30`; `resources/views/customers/index.blade.php`.
- Follow-up: confirm whether customer-list search is in scope before treating this as a defect.

## Financial behavior verified in code and tests

- Outstanding balance is derived from ledger credit amounts minus payments; available credit is clamped to zero.
- Credit entry creation hard-blocks exceeding the customer limit.
- Customer payments exceeding total outstanding balance are rejected; entered amounts are not silently reduced.
- Customer payments allocate FIFO by transaction date, creation time, then entry ID.
- Ledger history groups payment allocations into a payment batch and calculates chronological running balances.
- An entry is overdue only if its deadline is before today and it still has an unpaid amount.
- A debt cycle increments when total customer outstanding transitions from positive to zero.
- Suki Points award one point per ₱100 actually paid; payment remainders carry forward and point transactions reference a unique payment.
- Payment operations update allocations, points, customer balance snapshot, and debt-cycle state within database transactions.
- These behaviors have feature-test coverage. Concurrent MySQL/MariaDB behavior remains unverified.

## Authentication, schema, and interface notes

- `routes/web.php` protects dashboard, customer, credit, payment, and password settings routes with `auth`; login is under `guest`.
- Login throttles failed attempts, regenerates the session on success, and logout invalidates the session and CSRF token.
- Password changes require the current password. No public owner registration or customer login route was found.
- Customer email is required/unique in the final schema and validated uniquely on create/update; edits exclude the same customer's current email.
- Financial columns use `DECIMAL(12,2)`. Customer and transaction relationships have foreign keys; financial-history deletion was changed to restricted deletion.
- Responsive CSS exists, but no real-browser or device layout test was run. Dashboard, forms, and ledger behavior were assessed through source and feature tests, not visual inspection.
- Actual schema column names were queried read-only on the active local database. This does not establish that another environment, particularly production, has the same schema or data.

## Verification actually performed

1. `php artisan test --compact` — **96 passed, 492 assertions**. `phpunit.xml` configures SQLite `:memory:` for the test suite, so this did not target the active local MySQL database.
2. `php artisan config:show database.default` — effective local driver resolved to `mysql`.
3. `php artisan migrate:status` — all listed migrations reported **Ran** against the active local database.
4. Read-only `Schema::getColumnListing(...)` checks — confirmed columns in `users`, `customers`, `ledger_entries`, `payments`, `suki_points`, and `suki_point_transactions`.
5. `php artisan route:list --except-vendor` — 21 application routes; no registration or password-reset routes appeared.
6. `php artisan db:show` — did not complete because the configured MariaDB instance lacks `performance_schema.session_status`.
7. `php artisan db:table` — metadata lookup began but output formatting failed because PHP CLI does not have the `intl` extension. Separate column-list checks above did succeed.

No migrations, seeders, resets, destructive commands, dependency changes, environment edits, or production data inspection were performed. No browser/device test or concurrent MySQL/MariaDB test was performed.

## Earlier focused Money helper note

The earlier review of `app/Support/Money.php` found integer-cent arithmetic appropriate for the ledger's ordinary inputs. Edge cases observed:

- `Money::fromCents(PHP_INT_MIN)` throws `TypeError` because `abs()` yields a float, which is then passed to `intdiv()`.
- A float input such as `1.005` is rejected by the two-decimal input regex; `0.1` converted to 10 cents in the probe. Prefer decimal strings for financial input and clarify whether float support is intended.
- Existing `tests/Unit/ExampleTest.php` checks normal negative/positive formatting, not these boundaries.

No Money helper change was made; these are low-priority robustness observations.

## Repository state and next steps

At the time this handoff was created, `git status --short` showed:

```text
 M boost.json
?? .mcp.json
```

These existing worktree changes were not modified by the audit and must be preserved. Re-check Git status before future edits, because the workspace may have changed since this snapshot.

Recommended order after the owner approves implementation:

1. Make payment/customer association impossible to persist inconsistently; add a regression test.
2. Make credit-limit validation and update atomic; add MySQL/MariaDB concurrency coverage.
3. Fix payment confirmation history semantics and test after subsequent transactions.
4. Confirm whether customer list search is in scope; verify responsive layouts in a browser if needed.
5. Consider the `Money::fromCents(PHP_INT_MIN)` edge case and add focused boundary tests.

The Sprint 1 findings above remain unactioned unless the owner explicitly approves them. The owner has since approved Sprint 2 Task 1; its implementation and verification state are recorded below.

## Sprint 2 Task 1 — Gmail SMTP and reusable email infrastructure

**Status:** Infrastructure implemented; real Gmail delivery remains pending local credentials and inbox verification.

### Implemented

- `.env.example` now shows safe Gmail SMTP placeholders, port 587, SMTP scheme, STARTTLS, and use of a Google App Password. Real credentials were not added to source.
- `config/mail.php` maps TLS settings to Symfony Mailer's required STARTTLS option. Laravel 12's SMTP scheme is `smtp`; port 587 negotiates STARTTLS, and `require_tls` prevents cleartext fallback.
- `app/Mail/ReusableEmail.php` provides a reusable configurable mailable with subject, greeting, body, optional details, and both HTML and plain-text views.
- `resources/views/emails/layouts/base.blade.php`, `resources/views/emails/reusable-message.blade.php`, and `resources/views/emails/reusable-message-text.blade.php` provide a shared, mobile-friendly, escaped template.
- `app/Services/MailDeliveryService.php` validates recipients, sends synchronously, returns failure status rather than propagating transport failures, and logs sanitized diagnostics (masked recipient, exception type, redacted/truncated message).
- `app/Console/Commands/SendTestEmail.php` adds `php artisan mail:test recipient@example.com`. It is CLI-only, refuses to report success unless Gmail SMTP is configured, and returns failure status for invalid recipients or transport errors.
- No reminder schedule, customer payment receipt workflow, route, dependency, or email call inside a financial transaction was added.

### Verification

- `php artisan test --compact tests/Feature/ReusableEmailTest.php` — **7 passed (26 assertions)**.
- `php artisan test --compact` — **103 passed (518 assertions)**.
- `vendor/bin/pint --dirty --format agent` — passed.
- PHP diagnostics — no errors in the changed PHP files.
- `php artisan list --raw` confirmed the `mail:test` command is registered.
- Redacted runtime config check with temporary dummy environment values confirmed Gmail host, port 587, SMTP scheme, and required TLS are read correctly.
- `git check-ignore -v .env` confirmed `.env` is ignored. It was not read or modified.

### Remaining manual step

The local runtime was still configured with `MAIL_MAILER=log`, localhost SMTP defaults, and no SMTP username/password. **No real email was sent and no delivery is verified.** The owner must set their Gmail address and a Google App Password in the ignored local `.env` (never send it in chat), run `php artisan config:clear` if config is cached, then run `php artisan mail:test <recipient-address>` and verify arrival in the mailbox (including Spam). The CLI success message only confirms SMTP transport acceptance, not inbox delivery.

The worktree also contains unrelated pre-existing changes such as `AGENTS.md`, `boost.json`, `.agents/`, and `.mcp.json`; preserve them and re-check Git status before further edits.

### Owner-facing email settings UI

The owner-facing follow-up has now been implemented:

- Authenticated owners can open **Email notifications** from the dashboard/account navigation, save a store name and Gmail address, and enter a Google App Password in [resources/views/settings/email-notifications.blade.php](./resources/views/settings/email-notifications.blade.php).
- Settings are stored per authenticated user in `store_email_settings`; the App Password uses Laravel's `encrypted` cast and is hidden from model serialization. Updating other fields with the password blank preserves the saved credential. Validation redirects explicitly omit the password from flashed old input.
- The page includes a three-step Google App Password guide with Google's official support link. The guide is text-based rather than containing screenshots, which avoids brittle screenshots of a changing Google interface.
- **Test my email** sends a test message to the saved Gmail address using an isolated, per-send `Mail::build()` SMTP mailer configured for `smtp.gmail.com:587` with required TLS; it does not mutate the application's shared `.env` mailer config. The endpoint is authenticated and limited to three attempts per owner/IP per minute.
- No debt reminders or payment notifications were wired to send mail; those remain separate product work. Successful test feedback indicates Gmail accepted the test message, not that it reached the inbox.

Verification after this follow-up:

- Focused mail/settings tests — **22 passed (99 assertions)**.
- Full `php artisan test --compact` — **118 passed (591 assertions)**.
- `vendor/bin/pint --dirty --format agent` — passed.
- `npm.cmd run build` — passed.
- `php artisan route:list --path=settings --except-vendor` shows the email settings routes under authenticated middleware.
- The new additive `store_email_settings` migration was applied to the local database; prior migrations were already applied.
- Automated SMTP transport tests mock network delivery. No real Gmail credentials were entered, and no real message was sent.
