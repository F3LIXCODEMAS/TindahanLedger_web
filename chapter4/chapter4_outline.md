# Chapter 4 Outline

## METHODOLOGY, RESULTS AND DISCUSSION

### Evidence and drafting rules

- This outline follows the numbering and required components in `reference/chapter4outline` and the organization of `reference/ClassDex_Chapter4.docx`. The ClassDex system's requirements, diagrams, entities, results, and conclusions are not adopted.
- Treat the current repository as evidence of implemented code, not proof of production deployment, successful operation by store owners, or user acceptance. The application is Laravel 12/PHP 8.2; migrations define a relational schema, and the test suite is configured for SQLite `:memory:`.
- Existing project records include `README.md`, `SPRINT-1-AUDIT-HANDOFF.md`, migrations, application code, tests, and Git history. Jira board/export evidence, approved requirements, final deployment evidence, respondent data, and an approved project schedule were not available during inspection.
- Do not infer formal SDLC selection, dates, duration, hardware, staffing, costs, performance targets, or evaluation results from commit dates or implementation details. Use **[NEEDS VERIFICATION]** and identify the evidence needed.
- Do not treat proposed targets as achieved results. Report existing measurements only with their exact test conditions and limitations.
- The project tests were run during this documentation task: **858 passed (3,348 assertions)**. `phpunit.xml` configures the test database as SQLite `:memory:`; this result does not verify production data or MySQL/MariaDB concurrency. Other test or performance results must be tied to the source records that document them.
- Output remains documentation only. No application source, migration, database record, or behavior is to be changed for the Chapter 4 work.

## 4.1 Requirements Analysis

### 4.1.1 Performance Requirements

1. **Purpose:** Specify response and processing-time expectations for the implemented web workflows, with measurable limits and timing conditions.
2. **Content:** Provide a requirements table identifying each feature, timing relationship, proposed or approved target, workload, and rationale. Keep SMTP time separate from database transaction latency and identify client/network delays that are excluded.
3. **TindahanLedger modules:** Owner authentication; customer management; credit recording; payment recording and running-balance computation; product catalog; cash-purchase recording; QR customer portal; Gmail SMTP receipts/reminders; Suki Points; and product recommendations.
4. **Supporting evidence:** `routes/web.php`; relevant controllers, Form Requests and services under `app/Http/`; `app/Services/LedgerService.php`; `app/Services/CashPurchaseService.php`; `app/Services/ProductRecommendationService.php`; `app/Services/MailDeliveryService.php`; `config/customer_portal.php`; `README.md` performance notes; `phpunit.xml`; the focused feature tests and current full-suite run.
5. **Required presentation:** Table of performance requirements. No separate diagram is required. Add a benchmark figure or results table only if the test conditions and source data are retained and described.
6. **Needs verification:** No approved response-time Service Level Objective was found. Proposed limits require owner/adviser approval and a defined workload, hardware, network, concurrency, measurement point, and repeatable test. The documented recommendation timings are local development measurements, not production guarantees. Gmail's 15-second SMTP timeout is a transport setting, not a measured delivery-time result.
7. **Literature to research/use:** ISO/IEC 25010:2023 for specifying and evaluating product-quality characteristics; Nielsen's response-time guidance for user feedback and perceived responsiveness; DeLone and McLean (2003) for the relationship between system/information quality and information-system success. Use these as rationale, not as TindahanLedger test results.

### 4.1.2 Safety Requirements

1. **Purpose:** Identify foreseeable financial-record, customer-impact, and information-loss hazards and describe safeguards and prohibited operations.
2. **Content:** Explain risks from balance errors, repeated submissions, invalid amounts, deletion of financial history, incorrect point redemption, unauthorized financial changes, and loss of stored records. Separate controls verified in code/schema from operational controls still to be approved.
3. **TindahanLedger modules:** Credit and payment workflows; customer balance and ledger; cash purchases; Suki Points/debt-cycle tracking; late-penalty records; customer/store administration; database persistence and backup operations.
4. **Supporting evidence:** `app/Services/LedgerService.php`; `app/Support/Money.php`; `app/Services/CashPurchaseService.php`; `app/Http/Controllers/PaymentController.php`; relevant request classes and exceptions; migrations for ledger, payment, Suki Points, debt cycles, purchases, and restrictive foreign keys; `app/Policies/`; `app/Services/AdminAuditLogger.php`; `SPRINT-1-AUDIT-HANDOFF.md`; tests such as `RecordCreditTest.php`, `RecordPaymentTest.php`, `LedgerWorkflowTest.php`, `SukiPointRedemptionTest.php`, and `TenantIsolationTest.php`.
5. **Required presentation:** Safety requirements table with hazard/loss, safeguard/action, and prevented action/constraint. Add no safety diagram unless the adviser requires a separate hazard model.
6. **Needs verification:** The older payment-confirmation fallback can derive historical balances from a customer's current balance after later transactions; its accuracy must be addressed before using a revisited confirmation as a historical receipt. Also verify backup frequency, off-site retention, recovery point/time objectives, restore-test evidence, incident response, live database integrity, and operational handling of erroneous records. Do not describe a tested backup/recovery system unless evidence is supplied.
7. **Literature to research/use:** ISO/IEC 25010:2023 as a quality-model reference for reliability and risk-related product quality; research on database transaction integrity and financial-record controls if a scholarly source is needed for the final discussion. Do not imply that a software quality standard certifies this system as safe.

### 4.1.3 Security Requirements

1. **Purpose:** State the authentication, authorization, privacy, and technical-protection requirements for owners, administrators, and customers.
2. **Content:** Describe verified controls for role-based access, hashed passwords, session handling, store isolation, QR bearer credentials, customer financial information, CSRF, input validation, parameterized data access, and notification credentials/recipients. Identify the limits of QR possession-based access and operational protections that remain unverified.
3. **TindahanLedger modules:** Owner and Super Admin authentication; customer portal and credential issuance/revocation; store-scoped customer and financial routes; administrative actions; Form Requests; database access; Gmail settings, receipts, and reminders.
4. **Supporting evidence:** `routes/web.php`; `app/Http/Controllers/Auth/AuthenticatedSessionController.php`; `app/Models/User.php`; role/account/password middleware; `app/Policies/`; `app/Services/CustomerAccessService.php`; portal controllers/middleware; `app/Http/Middleware/SetPrivatePageHeaders.php`; email settings model and service; relevant migrations; `bootstrap/app.php`; `config/session.php`; tenant, authentication, portal, and email tests.
5. **Required presentation:** Security requirements table with domain, protection requirement, and policy/standard reference. No separate security diagram is required in 4.1; the trust boundaries belong in the 4.2 context and data-flow diagrams.
6. **Needs verification:** HTTPS/TLS configuration in the deployed environment; effective session-cookie flags and session encryption; production key/secrets management; database/storage encryption and access controls; retention/deletion policy; privacy notice and consent/legal basis; comprehensive security testing; repository-wide SQL injection review; and whether the Data Privacy Act of 2012 applies to the deployment and processing arrangement. The code review does not establish compliance or certification.
7. **Literature to research/use:** NIST SP 800-63B-4 (2025) for digital authentication guidance; OWASP ASVS 5.0.0 as a web-application security verification framework; Republic Act No. 10173 and National Privacy Commission guidance where applicable. These are comparison references, not claims of conformance.

**Section file:** `chapter4_requirements.md` contains the drafted 4.1 section and its citations.

## 4.2 Design of Software, Systems, Product, and/or Processes

### 4.2.1 Systems Development Life Cycle Processes

1. **Purpose:** Explain the SDLC model actually selected and how its activities map to the TindahanLedger work.
2. **Content:** Present the evidence-supported iterative and incremental model, explain its phases and feedback practices, and distinguish observed ticket/commit-based work from formally adopted Agile or Scrum.
3. **TindahanLedger modules:** Cross-cutting development of authentication, customer/ledger workflows, purchases, QR portal, email, loyalty, and recommendations.
4. **Supporting evidence:** Git commit history; project README and Sprint 1 handoff; Jira ticket IDs referenced in the README. A Jira board export, sprint records, team meeting notes, and approved methodology statement are not present in the inspected materials.
5. **Required presentation:** A labeled diagram of the chosen SDLC model and a phase/activity/evidence table, as requested by the reference paper.
6. **Needs verification:** Jira issue descriptions/status history, team roles and ceremonies, phase dates, review/approval records, requirement acceptance, and release/deployment evidence.
7. **Literature to research/use:** Larman and Basili's verified work on iterative/incremental development; the Agile Manifesto as contextual comparison only, not evidence that the project formally adopted Scrum or an Agile framework.

### 4.2.2 Test Plan

1. **Purpose:** Define how functional, integration, security, and performance behavior is assessed, without presenting planned tests as completed tests.
2. **Content:** Map each test objective to scope, method, test data, expected outcome, responsible person, environment, and evidence. Separate automated coverage, manual/browser checks, and unperformed target-database testing.
3. **TindahanLedger modules:** Authentication/authorization; tenant isolation; financial calculations; idempotency; products and cash/credit purchase; portal credentials; Suki Point earning/redemption; email settings/reminders; recommendations and analytics.
4. **Supporting evidence:** `phpunit.xml`; `tests/Feature/` and `tests/Unit/`; tests named in the handoff and README; the current full-suite result of 858 passing tests and 3,348 assertions in SQLite in-memory. README includes local MariaDB recommendation measurements and synthetic offline evaluation, with stated limitations.
5. **Required presentation:** Test-plan table, as called for by the reference. Include a results table in 4.5 only for tests actually run and recorded.
6. **Needs verification:** Formal acceptance criteria, test-case approval, target browser/device matrix, deployed-system test evidence, MySQL/MariaDB concurrency suite, accessibility/usability test evidence, and test execution records from the intended release.
7. **Literature to research/use:** ISO/IEC 25010:2023 for quality evaluation; OWASP ASVS for application-security test objectives; current Laravel/Pest documentation for test implementation details (not as academic outcome evidence).

### 4.2.3 Security Plan

1. **Purpose:** Describe security controls to retain, review, and test during implementation and operation.
2. **Content:** Address account lifecycle, least privilege, tenant isolation, customer QR credential lifecycle, sensitive email settings, privacy, vulnerability testing, secrets, audit logging, deployment hardening, and incident handling. Mark code-observed controls separately from operational actions not evidenced.
3. **TindahanLedger modules:** Auth/role middleware, store-scoped routes and policies, admin audit logging, customer portal, database and SMTP settings.
4. **Supporting evidence:** Authentication and portal files, policies, request validation, `AdminAuditLogger`, email settings/service, migrations, tests, and `README.md`. No deployment security checklist or completed independent penetration-test report was found.
5. **Required presentation:** Security-plan table of control, assessment/activity, timing or trigger, owner, and evidence; discuss implementation and remaining gaps.
6. **Needs verification:** Named security owner; incident-response procedure; dependency/security patch policy; production TLS and secret storage; access-review cadence; backup encryption; privacy handling; actual test schedule and findings.
7. **Literature to research/use:** OWASP ASVS 5.0.0; NIST SP 800-63B-4; Philippine Data Privacy Act/NPC guidance if applicable; ISO/IEC 25010:2023. Do not label the system compliant without a formal assessment.

### 4.2.4 Context Diagram

1. **Purpose:** Define TindahanLedger's system boundary and verified external actors/services.
2. **Content:** Show Store Owner, Super Admin, QR-access Customer, the application, and configured Gmail SMTP; represent only verified data exchanges. Keep the database, scheduler, framework, and browser inside the application boundary.
3. **TindahanLedger modules:** Role-based owner/admin workflows, QR customer portal, and outbound email notifications.
4. **Supporting evidence:** `routes/web.php`; authentication/portal controllers; `MailDeliveryService`; `README.md` local QR and email workflow documentation.
5. **Required presentation:** Context diagram (required).
6. **Needs verification:** Production mail configuration and inbox delivery, deployment topology, and any external backup, hosting, or monitoring services. Do not depict an unverified infrastructure provider as an actor.
7. **Literature to research/use:** Systems-analysis literature defining context diagrams and system boundaries; cite the course-prescribed Systems Analysis and Design text/edition.

### 4.2.5 Data Flow Diagram

1. **Purpose:** Explain how user-submitted transaction data is validated, processed, stored, and presented.
2. **Content:** Model authentication/roles; store, user, customer, and product management; credit/utang recording; cash payment allocation; cash purchases; QR portal access; Suki Points/redemption; recommendations and analytics; and email notifications. Name actual migration-backed logical stores and distinguish cash purchase records from debt/payment records.
3. **TindahanLedger modules:** Credit and payments, cash sales, customer/product records, recommendation rules, loyalty, reminders/receipts, and customer portal.
4. **Supporting evidence:** `routes/web.php`; corresponding controllers/Form Requests/services/models; migrations and Eloquent relationships; README operational descriptions.
5. **Required presentation:** Data Flow Diagram (required), with consistent symbols, numbered processes, named data stores, and balanced parent/child flows if levels are used.
6. **Needs verification:** Deployment-side data stores/external services, exact email data sent in production, and any unmodeled external interfaces.
7. **Literature to research/use:** Systems-analysis literature on DFD notation, decomposition, and balancing; cite the course-prescribed source rather than borrowing ClassDex's data flows.

### 4.2.6 System Flowchart

1. **Purpose:** Illustrate the decision sequence and safeguards in the principal transaction workflow.
2. **Content:** Show owner/admin authentication and authorization, password-change gate, request idempotency, input and business-rule validation, customer/store verification, atomic financial persistence, post-commit email attempts, and confirmation/error paths. Include cash purchase and points redemption as separate flows, plus the QR credential validation and read-only portal path.
3. **TindahanLedger modules:** Credit/payment recording, cash purchases, Suki Points redemption, and customer QR access.
4. **Supporting evidence:** `CreditController`, `PaymentController`, `LedgerService`, relevant Form Requests, portal controllers and `CustomerAccessService`.
5. **Required presentation:** System flowchart with decision branches and explicit distinction among debt, cash purchase, cash payment, and points redemption.
6. **Needs verification:** None for code flow beyond review against the final implementation; external operating procedures remain **[NEEDS VERIFICATION]**.
7. **Literature to research/use:** Systems-analysis literature for standard flowchart symbols and control-flow representations.

### 4.2.7 Entity Relationship Diagram

1. **Purpose:** Present the actual relational data model and its cardinalities/constraints.
2. **Content:** Derive entities, key attributes, foreign keys, optionality, unique constraints, composite store/customer consistency constraints, and deletion rules from migrations. Keep implementation details such as lookup indexes distinguishable from conceptual relations.
3. **TindahanLedger modules:** Users/stores; customers and ledger/payments; products/purchases/items; Suki Points, transactions, debt-cycle completions; penalties; email settings/reminder logs; portal access credentials; recommendation rule sets/rules; audit records.
4. **Supporting evidence:** All files in `database/migrations/`; corresponding files in `app/Models/`; test factories. The schema MCP query timed out, so migrations are the primary schema evidence; the deployed database's actual migration state is unverified.
5. **Required presentation:** Two-panel Entity Relationship Diagram with actual entity attributes, PKs, FKs, meaningful unique/composite constraints, optionality, and cardinalities. Identify framework/infrastructure tables omitted from the application ERD.
6. **Needs verification:** Compare final diagram to a read-only schema export from the intended database engine and confirm all migrations have been applied. Do not invent entities or relationships from requirements alone.
7. **Literature to research/use:** Database-design literature (e.g., Elmasri and Navathe, *Fundamentals of Database Systems*, verified edition) for ER modeling and constraints.

### 4.2.8 Database Normalization

1. **Purpose:** Explain how relational tables reduce duplication and protect transaction relationships.
2. **Content:** Work from an explicitly hypothetical UNF transaction example, then assess actual TindahanLedger relations against 1NF, 2NF, and 3NF. Explain JSON/document-valued exceptions, partial dependencies on composite candidate keys, transitive/repeated identifiers, historical purchase snapshots, and derived balance/points summaries. Discuss update, insertion, and deletion anomalies and the actual constraints/services that mitigate them; do not claim universal 3NF.
3. **TindahanLedger modules:** Customer/store, product/purchase, ledger/payment, Suki Point history, reminder logs, and versioned recommendation models.
4. **Supporting evidence:** Migration definitions, model relationships/casts, relevant services, and tests for financial invariants.
5. **Required presentation:** Normalization-analysis table with relation, key/dependency evidence, normal-form assessment, and justified exceptions; relationship to ERD.
6. **Needs verification:** A complete attribute-level dependency analysis and actual-engine constraints. No claim of 3NF until every relation and dependency is checked.
7. **Literature to research/use:** Database-design literature (e.g., Elmasri and Navathe, verified edition) for functional dependencies and normalization.

### 4.2.9 Use Case Diagram

1. **Purpose:** Summarize the system functions available to each verified actor.
2. **Content:** Distinguish Store Owner, Super Admin, QR-access Customer, and configured Gmail SMTP transport. Show the customer portal as read-only and email transport as an external service, not a human actor; avoid unimplemented customer registration or customer recommendation use cases.
3. **TindahanLedger modules:** Authentication, store/customer/product management, credit/payment/cash-purchase workflows, Suki Point redemption, penalties, analytics/recommendation, email settings, administrative management, customer ledger portal.
4. **Supporting evidence:** `routes/web.php`, route middleware, controllers, authorization policies, Blade views, feature tests.
5. **Required presentation:** Use Case Diagram where applicable; it is applicable because the system has distinct user roles and capabilities.
6. **Needs verification:** Confirm the final role/use-case scope with the owner and any additional actors external to the inspected implementation.
7. **Literature to research/use:** UML specification/current Object Management Group Use Cases guidance or course-prescribed systems-analysis text; cite the exact edition/version used.

### 4.2.10 Maintenance Plan

1. **Purpose:** Specify how the deployed application and its records will be monitored, corrected, backed up, and updated.
2. **Content:** Plan application/dependency updates, migration review, error/log review, database backups and restore tests, scheduler checks, credential rotation, account administration, and support/incident escalation. Identify which activities are documented versus proposed.
3. **TindahanLedger modules:** Laravel application, MySQL/MariaDB or chosen deployment database, reminder/penalty scheduler, SMTP credentials, recommendation model/rule sets, and QR credential lifecycle.
4. **Supporting evidence:** `routes/console.php`; `app/Console/Commands/`; migration history; `README.md` scheduler/local testing instructions; Composer/npm manifests; `storage/logs` configuration. No verified production operations or backup procedure was found.
5. **Required presentation:** Maintenance-plan table showing activity, trigger/frequency, responsible role, and retained evidence.
6. **Needs verification:** Assigned maintainer, backup/storage provider, retention period, monitoring/alerting, update window, recovery objectives, restore test, and production change approval process.
7. **Literature to research/use:** ISO/IEC 25010:2023 lifecycle quality guidance; Laravel official deployment/scheduling documentation for specific operations; scholarly software-maintenance literature if a broader maintenance rationale is needed.

### 4.2.11 Gantt Chart

1. **Purpose:** Display actual project activities and their duration over a verified calendar timeline.
2. **Content:** Map requirements, design, implementation, testing, revision, and rollout tasks to supported start/end dates and completion status. Do not use Git commit timestamps as a substitute for complete activity durations.
3. **TindahanLedger modules:** Include modules only where dated work records support them.
4. **Supporting evidence:** Jira issue/sprint history and exports, approved project schedule, team records, Git history as corroboration, and test/deployment records.
5. **Required presentation:** Gantt chart (required by the reference) and a task/date/status data table.
6. **Needs verification:** Project start/end, activity dates, duration, dependencies, milestone dates, approved scope changes, and schedule owner. **[NEEDS VERIFICATION]** No approved complete timeline was present in the inspected files.
7. **Literature to research/use:** A project-management source defining schedule planning/Gantt use; use the institution's required project-management textbook or PMI source, citing its actual edition.

### 4.2.12 PERT Chart

1. **Purpose:** Show project-task dependencies and calculate early/late schedule values.
2. **Content:** Define activities and durations, predecessor links, and the critical path. Include Early Start, Early Finish, Late Start, and Late Finish using a consistent time unit and documented assumptions.
3. **TindahanLedger modules:** Activities should correspond to verified development/testing/deployment work, not assumed modules or invented dates.
4. **Supporting evidence:** Jira dependency/issue history, approved schedule, task estimates, team records, completion evidence.
5. **Required presentation:** PERT network and activity table with ES, EF, LS, LF, duration, and slack, as explicitly required by the reference.
6. **Needs verification:** All task durations, precedence relationships, calendar conventions, date baseline, and critical-path review. Do not calculate results until these inputs are supplied.
7. **Literature to research/use:** A verifiable project-management source on PERT and critical-path calculations, using the required academic citation style.

## 4.3 Description of the Prototype, where applicable

1. **Purpose:** Describe the current working prototype and its user-facing components without implying production readiness.
2. **Content:** Explain the web-based architecture, role-specific screens, responsive interface, main transaction flows, portal QR experience, email settings, and recommendation interface. Identify the prototype's verified environment and limitations. Describe hardware only when inventoried.
3. **TindahanLedger modules:** Owner dashboard; customer ledger; credit/payment/cash purchase; product catalog; QR portal; Suki Points; email notifications; analytics/recommendation; Super Admin tools.
4. **Supporting evidence:** `resources/views/`, `resources/css/`, `resources/js/`, routes/controllers/services, README, test suite, build output if available. The project docs contain a local phone/hotspot QR testing procedure, not proof of a completed user/device trial.
5. **Required presentation:** Prototype screenshots or annotated figures for representative screens; optional architecture figure; hardware/software environment table. Reference calls for hardware details where applicable.
6. **Needs verification:** Approved screenshot capture, browser/device/version matrix, actual hardware inventory, accessibility evaluation, stakeholder demonstration/acceptance, and whether prototype state corresponds to a named release.
7. **Literature to research/use:** ISO/IEC 25010:2023 for product quality; verified HCI/usability literature for responsive/mobile access; cite only sources tied to the measured or described qualities.

## 4.4 Implementation Plan (Infrastructure/Deployment), where needed

1. **Purpose:** Present the planned or completed deployment arrangements and implementation activities.
2. **Content:** Define project title, description, objectives, duration, deployment architecture, software/hardware prerequisites, rollout/monitoring/evaluation approach, risks and mitigation. Clearly mark plans versus completed deployments.
3. **TindahanLedger modules:** Laravel/PHP web application, relational database, web server/runtime, SMTP provider, scheduled reminder/penalty commands, persistent storage/logs, and browser/mobile access.
4. **Supporting evidence:** `composer.json`, `package.json`, `vite.config.js`, `config/`, `routes/console.php`, migrations, `.env.example` (never use secrets), README scheduler and local-network guidance, GitHub/project records if supplied.
5. **Required presentation:** Infrastructure/deployment diagram if an actual topology is confirmed; implementation-plan table with **Module, Specific Objective/s, Hardware Requirements, Software Requirements, Duration, Person Involved, Estimated Budget, Risk Assessment and Mitigation Strategies, and Evaluation and Monitoring**. Preserve the reference's fields; use **[NEEDS VERIFICATION]** instead of invented values.
6. **Needs verification:** Actual host/cloud provider, operating environment, database engine/version, domain/HTTPS, persistent storage, environment configuration, backup/restore, deployment and scheduler evidence, named people, dates/duration, approved budget, risk owner, and acceptance criteria.
7. **Literature to research/use:** Official Laravel deployment/scheduling documentation for technical procedures; a verified project-management/deployment source for implementation planning. Do not cite Laravel Cloud as the actual host absent deployment evidence.

## 4.5 Implementation Results, where applicable

1. **Purpose:** Report what the implementation achieved against verified objectives and distinguish source-level implementation from real-world evaluation.
2. **Content:** Present implemented workflows and test outcomes, limitations, and measured results with method/environment/sample size. Relate each result to a specific approved objective; avoid unsupported conclusions about productivity, profit, usability, customer behavior, or recommendation quality.
3. **TindahanLedger modules:** Report only those implemented and evidenced: authentication, store/customer/product, ledger, cash purchase, portal, email infrastructure, Suki Points/penalties, recommendation and analytics.
4. **Supporting evidence:** Current tests and `phpunit.xml`; README benchmarks/evaluation notes; migrations, routes and services; email manual-verification record; future deployment logs, user evaluation, Jira acceptance records, or device test records if provided.
5. **Required presentation:** Results/objective matrix and relevant tables/figures. Reproduce performance tables only with stated test setup. Include survey/respondent tables only if actual consented data and methodology exist.
6. **Needs verification:** User/respondent counts and evaluation instruments; actual acceptance; production deployment and email delivery; real database-engine tests; performance under target load; usability/accessibility results; recommendation evaluation on a suitable, licensed, representative sari-sari dataset. Current README explicitly says the external dataset is not suitable/approved for customer-facing recommendation use.
7. **Literature to research/use:** DeLone and McLean (2003) as a framework for interpreting information-system quality/success dimensions; ISO/IEC 25010:2023 for product-quality criteria; domain-specific recommendation-evaluation literature only if a suitable real-store evaluation is actually performed.

## Sources to verify and use when drafting

- DeLone, W. H., & McLean, E. R. (2003). The DeLone and McLean model of information systems success: A ten-year update. *Journal of Management Information Systems, 19*(4), 9–30. https://doi.org/10.1080/07421222.2003.11045748
- International Organization for Standardization. (2023). *ISO/IEC 25010:2023 Systems and software engineering—Systems and software quality requirements and evaluation (SQuaRE)—Product quality model*. https://www.iso.org/standard/78176.html
- National Institute of Standards and Technology. (2025). *Digital identity guidelines: Authentication and authenticator management* (NIST SP 800-63B-4). https://csrc.nist.gov/pubs/sp/800/63/b/4/final
- Nielsen, J. (1993). Response times: The three important limits. In *Usability engineering* (Chapter 5). Morgan Kaufmann. Online excerpt: https://www.nngroup.com/articles/response-times-3-important-limits/
- OWASP Foundation. (n.d.). *Application Security Verification Standard (ASVS), version 5.0.0*. https://asvs.owasp.org/
- Republic of the Philippines. (2012). *Republic Act No. 10173: Data Privacy Act of 2012*. National Privacy Commission: https://privacy.gov.ph/data-privacy-act/

Confirm citation style and the editions required by the institution before finalizing the complete Chapter 4.
