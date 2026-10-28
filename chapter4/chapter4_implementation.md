# CHAPTER 4
# METHODOLOGY, RESULTS AND DISCUSSION

## 4.3 Description of the Prototype

TindahanLedger is implemented as a browser-based Laravel application for sari-sari store operations. The checked-in prototype includes role-specific owner and Super Admin interfaces, a separate read-only customer portal accessed through an issued QR credential, relational transaction records, and integrations for configured email delivery. The repository and tests establish that these features have implementation evidence; they do not establish that the prototype has been released to a public production server or accepted by store users.

### System architecture

The application follows a server-side Laravel web architecture. A browser submits requests to routes in `routes/web.php`; middleware and policies apply authentication, role, password-change, store-status, and record-access controls. Controllers coordinate requests and responses, Form Requests validate user input, and services implement business rules. Eloquent models and migration-defined tables persist application data. Blade templates render the owner, administrator, and customer-facing pages, with JavaScript and compiled CSS supporting interactive and responsive interfaces.

The principal financial operations are implemented as database transactions in services such as `LedgerService` and `CashPurchaseService`. Credit and payment records are kept separate from cash-purchase records. Payment allocation, Suki Points, and associated balance updates are coordinated by the applicable services. The recommendation and analytics functions read store-scoped purchase and product data. Email delivery and scheduled reminder/penalty commands are application operations; they are not separate deployed microservices in the inspected source.

### Hardware used and hardware requirements

No specialized point-of-sale terminal, dedicated scanner, or other proprietary hardware is required by the inspected application. The repository documents local QR access testing using a developer computer and a phone connected to the same Wi-Fi network or hotspot. This documents a supported local testing arrangement, not proof that a device test was completed.

The prototype can be accessed using ordinary browser-capable computing devices and network connectivity. A phone camera or QR-scanning capability is needed only when a customer opens the portal by scanning the issued QR code; the URL can otherwise be opened as an ordinary web link. The exact developer computer model, processor, memory, storage, customer device models, and any production-server hardware are **[NEEDS VERIFICATION]**. No public deployment host or deployment hardware inventory was found.

### Software requirements and development environment

The project manifests and local development notes identify the following software components:

**Table 4.11. TindahanLedger software requirements and development environment**

| Component | Evidence-based description |
|---|---|
| Server-side runtime and framework | PHP `^8.2` and Laravel `^12.0` are declared in `composer.json`. The installed package report identifies Laravel Framework 12.69.2. The local PHP CLI used for this documentation run reported PHP 8.2.12. |
| Dependency and asset tools | Composer manages PHP packages. `package.json` declares Vite 7 and Tailwind CSS 4 toolchain packages; the inspected local environment reports Vite 7.3.6, Tailwind CSS 4.3.3, and Node.js 24.21.0. |
| Database | Laravel migrations and Eloquent define the relational schema. The audit handoff records that the active local database driver resolved to MySQL and that the listed migrations had run at the time of that audit. The README documents development measurements on MariaDB 10.4.32 under XAMPP on Windows. The final target database engine/version and production schema state are **[NEEDS VERIFICATION]**. |
| Automated-test database | `phpunit.xml` configures the test suite to use SQLite `:memory:`, an array cache/session, synchronous queue, and array mailer. This test arrangement is distinct from the local MariaDB development measurements and any future production database. |
| Email transport | `.env.example` contains placeholder settings for Gmail SMTP using port 587 and TLS. Real credentials are not included. Gmail inbox delivery is not verified. |
| Browser and network | The application is accessed through a web browser. The README documents local Wi-Fi/hotspot QR testing instructions. The supported browser/device matrix and public network configuration are **[NEEDS VERIFICATION]**. |

The documented local development arrangement is Windows with XAMPP/MariaDB for selected development checks, plus PHP, Composer, Node.js, and npm-based asset tooling. The repository does not establish a single, fully specified developer workstation image or a production environment. Hardware specifications and an approved browser/version matrix are **[NEEDS VERIFICATION]**.

### Application modules

The implemented prototype contains the following modules, based on the routes, controllers, services, views, migrations, tests, and project documentation:

**Table 4.12. Implemented TindahanLedger application modules**

| Module | Prototype functionality |
|---|---|
| Authentication and role-specific access | Store Owner and Super Admin sign-in, role-restricted routes, account status checks, and required temporary-password change handling. |
| Store and customer management | Store and user administration; owner management of store customers and customer records. |
| Credit ledger and payments | Recording customer credit, viewing ledger history and balances, and recording cash payments allocated to outstanding entries. |
| Product catalog and purchase records | Store-scoped product management; structured item rows for credit-linked purchases; separate cash purchases with item and price snapshots. |
| Customer QR self-service portal | Owner-issued, replaceable, and revocable bearer credentials; customer read-only ledger and balance access. QR images are generated locally as SVG. |
| Suki Points and late-payment controls | Points accrual and redemption, debt-cycle records, store penalty settings, due-penalty processing, and supported waiver workflow. |
| Email settings and reminders | Owner-configured Gmail settings, test-message interface, credit/payment receipts, and scheduled repayment reminders. The underlying SMTP infrastructure is implemented, but real delivery is unverified. |
| Analytics and recommendations | Owner purchase analytics, transaction-entry suggestions based on store-scoped single-item association rules, and optional offline evaluation. Suggestions are advisory and do not modify a transaction automatically. |
| Super Admin monitoring | Store and user administration, transaction monitoring, and platform analytics. |

### System interfaces

The Store Owner interface includes the dashboard and pages for customers, products, credit entries, payments, cash purchases, QR credentials, settings, overdue accounts, purchase analytics, and recommendation suggestions. The Super Admin interface provides administrative store/user management and platform monitoring. The QR-access customer interface is separate from owner and administrator authentication and presents read-only ledger information. Gmail SMTP is an external delivery interface for configured messages; its actual production connection and inbox delivery have not been demonstrated.

The repository includes Blade source views for these screens but no prototype screenshots were found in the inspected `public` or `storage` image locations. Consequently, this description is based on source and automated test evidence rather than a verified visual walkthrough.

### Actual deployment environment

No production host, public domain, deployment pipeline run, live database instance, production monitoring configuration, or deployment acceptance record was found. The README explains how a developer may run the application locally and how to configure Laravel's scheduler; instructions are not proof those steps were completed on a production server. The prototype's actual production deployment status is therefore **[NEEDS VERIFICATION]**. The intended hosting provider, server operating system, database version, TLS/domain configuration, backup target, operational owner, and production rollout date require deployment evidence.

## 4.4 Implementation Plan (Infrastructure/Deployment)

### Project Title

**TindahanLedger: Digital Utang Tracking System with Gmail SMTP Notifications and Suki Points for Sari-Sari Stores**

### Project Description

TindahanLedger is a Laravel web application intended to support store owners in recording and reviewing customer credit, payment allocations, balances, products, and purchases. The prototype also provides QR-based read-only customer access, Suki Points, configured Gmail SMTP messaging, store-level purchase analytics, and advisory product suggestions. This section distinguishes the implemented software from the infrastructure and operational arrangements that must still be verified before an actual deployment.

### Objectives

The repository does not contain an approved research proposal listing the final general and specific objectives. The following are **working objective groupings derived from the supplied project title and implemented scope**; their wording and order must be checked against the institution-approved proposal before submission:

1. Provide store owners with authenticated, store-scoped customer and financial record management for credit, payments, and outstanding balances.
2. Support product catalog management and the recording of itemized credit and cash purchases.
3. Provide customers with QR-based, read-only access to their ledger and balance.
4. Support configured email notifications and scheduled repayment reminders through Gmail SMTP.
5. Implement Suki Points earning, debt-cycle tracking, and points redemption within the supported ledger workflow.
6. Provide store-level purchase analytics and advisory product suggestions from recorded purchase data.

### Duration

The total project duration, approved start and end dates, deployment window, and module-level activity estimates are **[NEEDS VERIFICATION]**. No complete approved schedule was found. Git timestamps or ticket identifiers alone are not treated as an approved duration baseline.

### Implementation plan table

The table below is a deployment and operational plan, not a claim that the listed deployment activities have already occurred. No dedicated hardware specification is evidenced. “Ordinary computing device” refers to browser access; production server resources must be sized and approved for the selected hosting environment.

**Table 4.13. TindahanLedger infrastructure and deployment implementation plan**

| Module | Specific Objectives | Hardware Requirements | Software Requirements | Duration | Person Involved | Estimated Budget | Risk Assessment and Mitigation Strategies | Evaluation and Monitoring |
|---|---|---|---|---|---|---|---|---|
| Application hosting and access control | Prepare the Laravel application for authorized Store Owner and Super Admin access and select a hosting arrangement. | Browser-capable ordinary computer for administration; server/hosting capacity and redundancy **[NEEDS VERIFICATION]**. No dedicated POS hardware is evidenced. | PHP 8.2-compatible runtime, Laravel 12 application, Composer-installed dependencies, web server, HTTPS/TLS, environment-specific secrets, and asset build. Hosting provider and versions **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Store Owner and Super Admin as operational roles; deployment implementer and approver **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Misconfiguration, exposed credentials, or unauthorized role access. Use protected secret configuration, HTTPS, least-privilege accounts, reviewed environment settings, and authentication/role regression checks before release. | Record deployment version and configuration review; test owner/admin access, denied-role cases, TLS, and sanitized logs. Assign monitoring ownership and service targets **[NEEDS VERIFICATION]**. |
| Relational database and migration rollout | Install the migration-defined schema and preserve store, customer, ledger, payment, product, purchase, and loyalty relationships. | Persistent database storage, backup storage, and server resources **[NEEDS VERIFICATION]**. | MySQL/MariaDB-compatible database and supported version **[NEEDS VERIFICATION]**; Laravel migrations. SQLite `:memory:` is the configured automated-test database, not evidence of production readiness. | **[NEEDS VERIFICATION]** | Database/deployment administrator and reviewer **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Migration incompatibility, data loss, lock/concurrency differences, or failed restore. Review migrations, back up before rollout, validate constraints on the target engine, and conduct a documented restore test before accepting production records. | Check migration status, foreign keys/unique constraints, balance reconciliation, backup completion, and restore-test evidence. Recovery objectives and monitoring intervals **[NEEDS VERIFICATION]**. |
| Customer and credit/payment ledger | Configure and verify customer management, credit limits, credit entries, payment allocation, and balance summaries. | Ordinary browser-capable owner device and network; server hardware **[NEEDS VERIFICATION]**. | Laravel web application, relational database, browser; automated tests currently use SQLite in memory. | **[NEEDS VERIFICATION]** | Store Owner as operational user; implementer and financial-rule approver **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Duplicate submissions, incorrect balances, or cross-store record access. Retain idempotency and transaction safeguards; verify ledger/payment invariants, tenant isolation, and database concurrency on the approved target engine. | Execute focused financial and tenant-isolation tests; reconcile representative ledger totals; retain test output and approval records. Test datasets and acceptance limits **[NEEDS VERIFICATION]**. |
| Product catalog, credit items, and cash purchases | Maintain store-scoped product records and record purchase items without conflating cash sales with debt. | Ordinary browser-capable owner device and network; server hardware **[NEEDS VERIFICATION]**. | Laravel, database, Blade/JavaScript interface, and compiled Vite assets. | **[NEEDS VERIFICATION]** | Store Owner as operational user; implementer and acceptance reviewer **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Invalid or stale price/quantity data and duplicate sales. Validate catalog items and totals server-side, persist price/name snapshots, use idempotency keys, and test that cash purchases do not alter debt or points. | Review product/purchase records and expected-total checks; run product catalog, structured-credit-item, and cash-purchase regression tests. Release acceptance criteria **[NEEDS VERIFICATION]**. |
| Customer QR self-service portal | Enable an owner to issue/revoke QR credentials and a customer to view only their corresponding read-only ledger. | Ordinary browser-capable device; phone camera/QR reader when opening by scan; internet or trusted local network. Exact device requirements **[NEEDS VERIFICATION]**. | Laravel portal routes and customer session, locally generated SVG QR, browser supporting the portal interface, configured `APP_URL`. Public domain/TLS **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Store Owner for credential administration; Customer for portal use; deployment implementer **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Credential disclosure could expose financial information. Use the existing credential issue/revoke lifecycle, private response headers, session controls, and read-only authorization; test on a trusted network before any public access. | Record credential lifecycle and access-control test results; conduct an approved real-device/browser scan and verify revocation. Device matrix and privacy acceptance **[NEEDS VERIFICATION]**. |
| Gmail SMTP, receipts, and repayment reminders | Configure mail delivery and the scheduler for supported receipts and due reminders. | No dedicated mail hardware; application host must have network access to the configured SMTP service. Host/network policy **[NEEDS VERIFICATION]**. | Gmail SMTP using a Google App Password and TLS on the documented port; Laravel mail configuration; scheduler and cron or equivalent. Real credentials must remain outside source control. | **[NEEDS VERIFICATION]** | Store Owner configures store email; mail/deployment administrator **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Invalid credentials, rejected/duplicate messages, or false assumptions that SMTP acceptance means inbox delivery. Use the test-message flow, inspect sanitized failure status, validate reminder deduplication/retry behavior, and verify recipient inbox delivery manually. | Record test transport outcome separately from inbox receipt; monitor scheduled-command outcomes and reminder history. Mail provider, inbox verification, and schedule operations **[NEEDS VERIFICATION]**. |
| Suki Points and late-payment processing | Verify point accrual/redemption and the configured due-penalty workflow with transaction records. | Ordinary browser-capable owner device; server and persistent database requirements **[NEEDS VERIFICATION]**. | Laravel services, relational database, scheduler for due penalties, and supported browser. | **[NEEDS VERIFICATION]** | Store Owner as operational user; implementation and business-rule approver **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Incorrect points or penalty totals can affect customer balances. Preserve atomic transaction processing, test earning/remainder/redemption and penalty rules, and require authorized policy approval before live activation. | Reconcile point history, payment allocations, penalty eligibility, and balance changes; retain focused test results and policy approvals. Operational thresholds **[NEEDS VERIFICATION]**. |
| Analytics and product recommendations | Deploy read-only purchase analytics and advisory suggestions using store-scoped records. | Ordinary browser-capable owner device and network; server capacity **[NEEDS VERIFICATION]**. | Laravel analytics/recommendation services, relational purchase/product data, and Vite-built browser assets. No approved external basket dataset is required or established for live use. | **[NEEDS VERIFICATION]** | Store Owner as user; data/model reviewer and implementer **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Sparse, synthetic, or nonrepresentative purchases may produce misleading suggestions. Label suggestions as advisory, monitor eligible basket counts and model status, and evaluate against an approved representative dataset before making effectiveness claims. | Review eligibility counts, rule checks, and offline evaluation with dataset provenance and sample size. Real-store quality evaluation and acceptance thresholds **[NEEDS VERIFICATION]**. |
| Backup, monitoring, and operational handover | Establish ongoing ownership, recovery, log monitoring, security updates, and release procedures. | Backup and monitoring infrastructure **[NEEDS VERIFICATION]**. | Approved backup tool/storage, log/alerting service, scheduler, and dependency-update process **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Operational maintainer, incident contact, and data custodian **[NEEDS VERIFICATION]**. | **[NEEDS VERIFICATION]** | Unnoticed failures or unrecoverable data loss. Define access control, retention, recovery objectives, incident escalation, and restore testing before production operation. | Review backup/restore records, scheduler and application errors, dependency advisories, access reviews, and incident records at an approved cadence **[NEEDS VERIFICATION]**. |

## 4.5 Implementation Results

### Basis and limitation of the results

The approved proposal's exact specific-objective wording was not present in the inspected repository. To organize the available evidence without inventing formal research objectives, the subsections below use the six **working objective groupings** listed in Section 4.4, derived from the supplied project scope and implemented modules. The research team must replace or map these headings to the approved objective wording before submission. Results below describe source-level implementation, automated tests, and explicitly documented local measurements; they do not establish production deployment, user acceptance, or effectiveness in actual sari-sari store operations.

During preparation of this section, `php artisan test --compact` completed with **858 tests passed and 3,348 assertions in 60.55 seconds**. The test configuration in `phpunit.xml` uses SQLite `:memory:`, array cache/session, synchronous queue, and array mailer. This is evidence of the current automated test run in that configured test environment only. It is not a MySQL/MariaDB concurrency assessment, live Gmail test, browser/device evaluation, or user acceptance result.

### Working Objective 1: Authenticated management of customers, credit, payments, and balances

The prototype implements separate Store Owner and Super Admin access. Owner workflows include customer records, credit entry, payment entry, customer ledger history, and balance views. `LedgerService` records credit and customer payments within database transactions; the payment workflow allocates amounts oldest-first, while request/service logic prevents a credit from exceeding the configured customer credit limit and rejects an overpayment. Idempotency keys are used to prevent repeated submissions from creating duplicate financial records.

Evidence includes `routes/web.php`, `app/Services/LedgerService.php`, the authentication and customer/ledger controllers, `OwnerAuthenticationTest.php`, `RoleAccessTest.php`, `TenantIsolationTest.php`, `CustomerLedgerTest.php`, `RecordCreditTest.php`, `RecordPaymentTest.php`, and related financial workflow tests. All tests included in the full test run reported above passed in the configured SQLite environment.

These features provide the core recordkeeping and balance workflows described by the working objective. The observed result is implementation and automated coverage of normal and selected invalid paths, not proof that users entered real transactions accurately or that balances were accepted by store owners. The audit handoff identifies database-engine concurrency and certain historical payment-confirmation behavior as requiring further review; MySQL/MariaDB concurrency and a real operational reconciliation remain **[NEEDS VERIFICATION]**. No user acceptance or productivity measurement was located.

### Working Objective 2: Product catalog and itemized credit and cash purchases

The prototype includes store-scoped product management and structured purchase items. Credit purchases may create a ledger entry and linked `utang` purchase with item snapshots. Cash purchases are recorded separately and may be associated with a customer or remain a walk-in transaction; they do not create debt or award points. `CashPurchaseService` validates active-store/catalog constraints, calculates totals on the server, compares them with the reviewed expected total, writes purchase/item records transactionally, and returns an existing purchase for a repeated idempotency key.

Evidence includes `ProductController.php`, `CashPurchaseController.php`, `CreditController.php`, `app/Services/CashPurchaseService.php`, `app/Services/LedgerService.php`, the `products`, `purchases`, and `purchase_items` migrations, and `ProductCatalogTest.php`, `StructuredCreditItemsTest.php`, and `CashPurchaseTest.php`. The automated suite's successful result supports the tested application paths in SQLite; it does not demonstrate retail use or target-engine performance.

The implementation addresses the working objective by separating catalog, purchase header, and item detail records and by maintaining a distinction between credit and cash sales. The README and migration-backed source provide evidence of these records and safeguards. Actual inventory operations, receipt printing, physical POS integration, live sales volume, and stock deduction were not established and must not be inferred. No cashier hardware or deployment budget has been verified.

### Working Objective 3: QR-based customer self-service access

The application provides an owner-managed customer access credential and a separate read-only portal. `CustomerAccessService` generates a random bearer token, stores its hash and protected token representation, generates QR content locally as SVG, and supports credential replacement and revocation. The customer portal resolves an active token and displays the corresponding customer's ledger information without owner write functions. A QR token is a bearer credential: possession grants access but does not independently verify the holder's identity.

Evidence includes `CustomerAccessService.php`, the portal and QR controllers, `routes/web.php`, the customer-access credential migration, `CustomerAccessCodeTest.php`, and `CustomerPortalTest.php`. The README documents how a developer could test a phone on the same local Wi-Fi network or hotspot. That instruction is not evidence that an actual phone scan was completed. Automated tests passed as part of the full suite, but browser rendering, accessibility, device compatibility, session behavior in a real browser, and real-device scanning remain **[NEEDS VERIFICATION]**.

The implementation addresses the working objective by exposing a distinct read-only path for a customer who has an issued credential. No respondent feedback, customer identity verification, or evidence of portal use by real customers was found. A controlled device test and privacy review should be recorded before claiming practical customer usability.

### Working Objective 4: Gmail SMTP messaging and repayment reminders

The prototype includes owner email settings, a test-message flow, reusable email views, delivery handling, credit/payment receipts, and scheduled repayment reminders. The source and project documentation describe Gmail SMTP configuration using TLS and an App Password. Scheduled commands are defined in `routes/console.php`, and the README explains that a server-level scheduler must be configured for recurring execution.

Evidence includes `StoreEmailSettingsController.php`, `MailDeliveryService.php`, email templates, `PaymentReminderService.php`, `SendDueReminders.php`, `routes/console.php`, and tests such as `EmailSettingsTest.php`, `ReusableEmailTest.php`, `PaymentReminderServiceTest.php`, and `TransactionReceiptTest.php`. The audit handoff records successful automated tests using mocked or configured test transport, but explicitly states that no real Gmail credentials were entered and no real message or inbox delivery was verified.

The implemented interfaces and automated tests demonstrate a messaging pathway and reminder logic; they do not show that customers received messages. SMTP transport acceptance is distinct from inbox delivery. Actual Gmail configuration, message delivery, spam handling, production scheduler installation, and reminder operation in a deployed environment remain **[NEEDS VERIFICATION]**. No notification delivery rate or response-time result is claimed.

### Working Objective 5: Suki Points and debt-cycle processing

The system implements Suki Points balances and transaction history, eligible point earning from cash repayment allocations, carry-forward of payment remainders, debt-cycle completion records, and points redemption as a non-cash payment allocation. The redemption and financial updates are coordinated transactionally. A cash purchase, by contrast, does not alter outstanding debt or award points. The system also contains configurable late-payment policies and penalty processing; these are related financial controls but are distinct from points accrual.

Evidence includes `LedgerService.php`, `SukiPointService.php`, `SukiPointRedemptionController.php`, `LatePenaltyService.php`, the Suki Points/debt-cycle/penalty migrations, and `SukiPointEarningTest.php`, `SukiCycleRewardTest.php`, `SukiPointRedemptionTest.php`, and late-penalty tests. The full automated suite reported passing tests in SQLite `:memory:`.

The implementation addresses the working objective by recording point changes and linking supported redemptions to financial allocations rather than treating points as cash outside the ledger. Test coverage supports the coded rules under the test configuration; no real-store redemption, respondent assessment, incentive effectiveness, or customer-retention effect was measured. Business-rule approval, reconciliation against live records, and target-database concurrency remain **[NEEDS VERIFICATION]**.

### Working Objective 6: Store-level analytics and advisory product suggestions

The prototype includes purchase analytics and store-scoped recommendations derived from recorded purchase baskets. The implemented recommendation service reads active store rule sets and produces single-product-to-single-product suggestions; the transaction-entry interface can display up to three advisory suggestions. A suggestion does not add an item or submit a transaction automatically. The analytics page and offline evaluation are read-only and do not train or activate a live model.

Evidence includes `ProductRecommendationService.php`, `PurchaseAnalyticsController.php`, `PurchasePatternAnalyticsService.php`, recommendation training/evaluation services and commands, `ProductRecommendationServiceTest.php`, `ProductRecommendationEndpointTest.php`, `PurchaseAnalyticsTest.php`, and `RecommendationEvaluationTest.php`. The README documents internal measurements on development MariaDB 10.4.32 under XAMPP on Windows. Those measurements used synthetic data on one development machine and ran through the service and application kernel without a web server or network; they are not production benchmarks:

**Table 4.14. Local synthetic recommendation benchmark measurements**

| Basket products | Query count | Service median / p95 | In-process HTTP median / p95 |
|---:|---:|---:|---:|
| 1 | 5 | 4.9 / 6.2 ms | 9.4 / 15.6 ms |
| 5 | 5 | 9.1 / 10.7 ms | 14.4 / 17.3 ms |
| 20 | 5 | 12.7 / 14.4 ms | 20.9 / 26.1 ms |
| 50 | 5 | 33.5 / 40.3 ms | 46.0 / 52.4 ms |

The project README also records an exploratory synthetic offline evaluation using 75 held-out baskets: the popularity baseline achieved a 9.3% hit rate@3, while the association-rule suggestions achieved 5.3%. The report itself labels this evaluation exploratory because of its small sample and synthetic data. It does not measure customer behavior, sales uplift, profit, or performance on representative Philippine sari-sari store records. The external basket file's source and license were not established, so it is not evidence of real-market recommendation quality. No claim that recommendations are effective or beneficial to store owners is supported.

### Evidence checklist and pending results

The following items are needed before finalizing implementation results as a complete research evaluation:

- The approved general and specific objectives, so each working objective above can be replaced with the proposal's exact wording.
- Dated deployment records identifying the actual host, domain, server/runtime, production database, migration state, scheduler setup, and release version.
- Screenshots or a recorded, approved walkthrough of the owner, Super Admin, and customer portal interfaces; no screenshots were located in the inspected image folders.
- A documented browser/device matrix and observed QR portal test on a real phone or other intended customer device.
- A real Gmail test performed with owner-controlled credentials kept out of source control, with SMTP acceptance and inbox arrival recorded separately.
- Target-engine migration, financial-integrity, and concurrency test results for the approved MySQL/MariaDB version.
- Approved functional acceptance criteria and test execution records tied to the final release.
- Respondent/user evaluation protocol, consented participant counts, instruments, raw evidence, and analysis, if user acceptance or usability claims are required.
- A valid, licensed, representative store dataset and a predefined recommendation-evaluation protocol, if real-world recommendation quality is to be claimed.
- Approved hardware/software inventory, hosting and backup plan, project duration, responsible personnel, and budget for Section 4.4.

Until these materials are collected, deployment outcomes, user acceptance, real-world effectiveness, production reliability, and customer response are **[PENDING IMPLEMENTATION EVIDENCE]**.
