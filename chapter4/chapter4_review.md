# Chapter 4 Final Review Report

**Review date:** 28 October 2026  
**Project:** TindahanLedger: Digital Utang Tracking System with Gmail SMTP Notifications and Suki Points for Sari-Sari Stores  
**Review scope:** `chapter4_outline.md`, `chapter4_requirements.md`, `chapter4_design.md`, and `chapter4_implementation.md`, checked against the supplied ClassDex reference and available repository evidence.  
**Reference location:** The requested ClassDex paper was found at `reference/ClassDex_Chapter4.docx` (the repository folder is named `reference`, singular, rather than `references`).

## Overall assessment

The Chapter 4 materials are substantially evidence-conscious: they distinguish code and automated-test evidence from production deployment, user acceptance, and real-store effectiveness; mark proposed performance targets; and leave unsupported Gantt/PERT calculations pending rather than inventing dates or durations. The SDLC characterization as iterative and incremental is appropriately qualified, and the major TindahanLedger workflows are grounded in the Laravel routes, services, migrations, tests, and README.

**The draft is not yet ready for final submission.** The current files are separate section documents rather than one assembled chapter; table numbering repeats across files; and the ordering/numbering of Section 4.2 differs from the reference sequence and the original prescribed outline. The Gantt and PERT charts, approved study objectives, final deployment evidence, and several user/device evaluation materials are not available. These omissions are identified in the draft and must remain pending until supported evidence is supplied.

## Issues found and corrections required

| ID | Priority | Issue | Required correction |
|---|---|---|---|
| R-01 | High | **Section ordering and numbering need a final approved decision.** The ClassDex reference places Gantt and PERT before the context/DFD/flowchart/ERD sequence. The TindahanLedger design file places context through use cases at 4.2.4–4.2.9 and moves Maintenance, Gantt, and PERT to 4.2.10–4.2.12. This matches the earlier specific request for diagram numbering but not the reference order or the initial master outline. | Confirm which prescribed order has priority with the adviser/institution, then apply one sequence consistently in the outline and chapter. Do not renumber the individually requested diagram sections without that decision. |
| R-02 | High | **The chapter is split across separate Markdown files and repeats chapter headings.** Each content file starts with “CHAPTER 4 / METHODOLOGY, RESULTS AND DISCUSSION.” Separate files are useful while drafting, but concatenating them as-is duplicates the chapter title and can disrupt flow. | Assemble a final reading copy with one chapter heading, section transitions, and one consolidated reference list. Retain the individual source files only if they are still needed as working documents. |
| R-03 | High | **Table numbering is duplicated across files.** For example, Table 4.1 is used for both performance requirements and the SDLC evidence table; Tables 4.2 and 4.3 are likewise reused for different content. | Renumber all tables globally after assembling the chapter and update every in-text table reference and caption. Check figure numbering and citations at the same time. |
| R-04 | High | **The official research objectives are absent.** Section 4.5 transparently uses six “working objective” groupings derived from scope/code. They are not verified as the proposal’s approved objectives. | Obtain the approved general and specific objectives and map each results subsection to their exact wording and order. Do not present the working headings as the approved objectives. |
| R-05 | High | **Gantt and PERT are not supplied as charts.** The design document correctly explains that there is no approved activity schedule, duration, predecessor list, or project baseline. It includes a PERT input-evidence table but no Gantt bars, PERT network, ES/EF/LS/LF/slack values, or critical path. | Obtain approved schedule and dependency evidence. Then prepare the Gantt and calculate the PERT/CPM forward and backward passes using a stated time unit and calendar convention. Until then, retain the explicit pending notice and do not insert estimated values. |
| R-06 | Medium | **The reference includes additional ERD/use-case presentation artifacts.** ClassDex includes a relationship summary/data dictionary and a use-case summary table, while the TindahanLedger draft presents a two-panel ERD and use-case diagram with explanatory prose but no equivalent summary tables. | Check the institution’s rubric. If those reference elements are required, add TindahanLedger-specific summaries derived from the actual schema/routes, or explicitly mark them pending. Do not copy ClassDex entities or use cases. |
| R-07 | Medium | **The prototype is described from source rather than demonstrated visually.** No screenshots or approved interface walkthrough were found in the inspected image locations. The source-view inventory supports screen/module claims but not the appearance, layout, or usability of the running prototype. | Capture dated, approved screenshots or record a walkthrough from the named release and browser/device. Add figure captions and explain each view. If visuals are not required, state that the prototype description is source-based rather than visually verified. |
| R-08 | Medium | **Several deployment and evaluation items remain unverified.** The actual production host, public domain/TLS, production database and migration state, scheduler installation, backups, operating owner, budget, module duration, and real-device/Gmail results are not evidenced. The implementation plan marks many corresponding cells `[NEEDS VERIFICATION]`, appropriately. | Keep these values pending until deployment records, approvals, or test evidence are available. Do not use the documented local-development instructions as proof of deployment. |
| R-09 | Medium | **One known historical payment-confirmation limitation remains unresolved.** The safety requirements and test plan correctly note that an old payment confirmation can derive balances from the customer’s current state when the original session receipt is absent. | Before describing old confirmations as reliable historical receipts, obtain a corrective implementation and regression-test record, or state the limitation in the final results and recommendations. Do not imply this is fixed. |
| R-10 | Medium | **Some technical verification is limited to migrations and SQLite tests.** The ERD and normalization analysis are carefully described as migration-derived, while the full automated suite uses SQLite `:memory:`. This does not establish that all constraints, locks, migrations, or concurrent behavior work on the intended MySQL/MariaDB deployment engine. | Record the approved target engine/version and migration status; run schema, financial-integrity, and concurrency checks against it. Preserve the current qualification until those checks exist. |
| R-11 | Low | **No complete data dictionary is presented.** The ERD lists selected business-significant fields but intentionally omits many attributes, framework columns, and secondary indexes. | If the capstone format requires a data dictionary, provide a separate table listing each in-scope domain field, type, nullability, key/constraint, and meaning from migrations. Clearly distinguish optional infrastructure tables from domain tables. |
| R-12 | Low | **Final document style and reference-list consolidation remain.** Section documents repeat title blocks and maintain separate reference lists; the outline also contains planning instructions and literature to research, rather than final chapter prose. | Treat the outline as a working aid, not chapter body. Consolidate citations and references in the final chapter, apply the institution’s required citation/heading/table style, and remove drafting-only language only after every evidence gap has been dispositioned. |

## Review against the requested criteria

| Criterion | Finding |
|---|---|
| Reference structure and formatting | The draft covers the principal requirements, SDLC, test/security/maintenance plans, diagrams, normalization, prototype, implementation plan, and results. Its Section 4.2 ordering differs from the reference, and its split-file table numbering is not final-ready. ClassDex’s data dictionary and use-case summary-table presentation are not currently mirrored. Resolve R-01 through R-03 and R-06/R-11. |
| Academic writing | The prose is generally formal, explanatory, and appropriately cautious. It often distinguishes “implemented,” “planned,” and “not verified.” Some sections are dense with implementation detail; final editing should reduce repeated caveats while retaining their evidentiary meaning. |
| Requirements vs. implementation | The performance section labels unsupported numeric thresholds as `[PROPOSED TARGET — FOR APPROVAL]`. Safety/security sections distinguish code-observed controls from required operational procedures. Continue to keep proposed targets out of the results as achieved outcomes. |
| SDLC model | Iterative and incremental development is supported by ticketed/feature-sized Git work and later refinement. The text correctly avoids claiming formal Scrum, ceremonies, user approval, or a formally selected Agile framework without evidence. Jira exports and review records remain missing. |
| Gantt schedule | No dates or durations are asserted without evidence. The chart and activity schedule are explicitly pending. There are no Gantt dates or durations available to check for arithmetic consistency. |
| PERT calculation | No numeric ES, EF, LS, LF, slack, or critical path is reported. This is the correct choice while durations and predecessor links are unavailable; there is no calculation to validate. A network chart and computed table remain outstanding. |
| Context Diagram and DFD | Their external entities are consistent: Store Owner, Super Admin, QR-access Customer, and configured Gmail SMTP. The database is modeled as internal logical stores in the DFD, not an external context actor. Both diagrams distinguish the customer’s read-only portal and outbound mail transport. The DFD includes more detail than the context diagram by design. |
| ERD vs. schema | The two panels are based on migrations, show important relationships and composite/unique constraints, and disclose that they are not a production schema export. The ERD notes the polymorphic audit subject and un-constrained `source_cutoff_purchase_id`; that qualification is useful. A target-engine schema comparison and a complete data dictionary are still needed for a final physical-schema claim. |
| Normalization | The section avoids claiming that the whole schema is in 3NF. It identifies JSON/document-valued columns, composite-key partial dependencies, repeated customer identifiers, historical snapshots, and cached balances/points. The conclusion is appropriately qualified. A formal table-by-table candidate-key/dependency analysis is still outstanding, and a database-normalization textbook/source should be added if required by the adviser. |
| Test, security, and maintenance plans | The plans distinguish automated coverage from unperformed browser, target-engine, production SMTP, and operational procedures. The current full-suite outcome is consistently reported as 858 tests and 3,348 assertions, with SQLite `:memory:` limitations. Security standards are comparison frameworks rather than compliance claims. The historical payment-confirmation concern remains open. |
| Implementation results | The six results subsections map to working objective groupings and cite actual routes, services, migrations, tests, and README evidence. The full test result is supported by an execution during this review task. Recommendation timings and exploratory hit-rate figures are clearly labelled as local/synthetic and not real-world effectiveness. Approved objectives, actual-user/device evidence, live Gmail delivery, and deployment results remain unavailable. |
| Citations | The cited core bibliographic records checked during review are verifiable: Codd’s DOI and pages 377–387; Larman and Basili’s DOI, title, journal, volume/issue, and pages 47–56; DeLone and McLean’s DOI and journal metadata; ISO/IEC 25010:2023’s official ISO page; NIST SP 800-63B-4’s July 2025 publication record; NIST SP 800-53 Rev. 5 Update 1; OWASP ASVS 5.0.0; the Philippine Data Privacy Act page; and Nielsen’s response-time excerpt. Maintain consistent APA/institution style and one final reference list. |
| Fabricated content | No invented Gantt/PERT values, budget, staffing, respondents, deployment results, acceptance results, or performance targets are presented as verified. The implementation file explicitly marks the six objectives as working headings. Retain these safeguards. |
| Cross-section consistency | Core descriptions of the financial, portal, SMTP, Suki Points, and recommendation flows are substantially consistent across the reviewed drafts. The most important document-level inconsistency is ordering/numbering. Historical test outcomes in the Sprint 1 handoff are older audit snapshots; the current Chapter 4’s 858-test result should be identified as the later run, not conflated with those earlier counts. |
| Required figures/tables | Requirements, SDLC, test/security/maintenance plans, Context, DFD, System Flowchart, ERD, normalization, and Use Case materials are present in draft form. Gantt and PERT charts are explicitly pending. A prototype screenshot/architecture figure, data dictionary, relationship summary, and use-case summary table are absent or need rubric confirmation. Mermaid diagrams are present as source; verify that the final submission/export renders them legibly. |

## Missing evidence

1. Approved proposal containing the final general and specific objectives.
2. Jira issue/sprint history and approved project schedule with dates, durations, dependencies, milestone status, and responsible roles.
3. Deployment record identifying host, release, domain/TLS, server/runtime, production database/version, applied migrations, scheduler, and operational owner.
4. Hardware and browser/device inventory, if the research format requires these details.
5. Real-device QR portal test and browser/device compatibility record.
6. Authorized Gmail test record distinguishing SMTP acceptance from confirmed inbox delivery.
7. Target-engine schema, migration, transaction-integrity, and concurrency results.
8. Backup/restore procedure and successful restore evidence.
9. User/respondent evaluation method, consent, participant count, instruments, observations, and analysis, if acceptance/usability outcomes are required.
10. Licensed, representative sari-sari-store data and a predeclared evaluation protocol before claiming real-world recommendation quality.
11. Approved project budget, named participants/roles, durations, and module-level evaluation/monitoring owners for the implementation plan.
12. Approved screenshots or a recorded prototype walkthrough.

## Missing diagrams and tables

- **Gantt chart:** Not present; explicitly pending approved schedule evidence.
- **PERT network and computed ES/EF/LS/LF/slack/critical path table:** Not present; explicitly pending approved durations and dependencies.
- **Prototype interface screenshots or architecture figure:** Not present; source descriptions are provided instead.
- **Full data dictionary and ERD relationship-summary table:** Not present; confirm whether required by the institutional template/reference.
- **Use-case summary table:** Not present; confirm whether to add a TindahanLedger-specific table alongside the use-case diagram.
- **Final render of Mermaid diagrams:** Mermaid source is present, but rendered output in the submission document has not been inspected. Validate legibility after export.

## Technical and academic editing improvements

- Resolve the competing Section 4.2 ordering directions before final renumbering.
- Assign one global sequence to all chapter tables and figures after combining the files.
- Use the approved objective wording as the organizing structure for implementation results; keep source/test outcomes separate from user/system-effectiveness conclusions.
- Date and identify the test run and environment in the final results table or paragraph. The recorded full suite used SQLite `:memory:` and does not establish production database behavior.
- Keep “SMTP transport accepted” distinct from “email delivered to inbox.”
- Keep synthetic recommendation evaluation distinct from real-store recommendation performance.
- Verify every cross-reference (table, figure, subsection) after export, and make sure Mermaid diagrams are rendered as readable figures rather than left as code blocks if the submission format does not render Mermaid.
- Use one consolidated reference list and the required institutional citation style. Add an authoritative normalization reference if the adviser requires literature support beyond Codd’s relational-model paper.
- Maintain explicit labels for all pending facts; do not remove caveats merely to make tables appear complete.

## Final readiness assessment

**Status: Evidence-based working draft; not yet submission-ready.**

The draft is suitable for adviser review of its technical substance and evidence gaps. It is not ready to be represented as a complete Chapter 4 until the approved objectives are mapped, the Section 4.2 order and global numbering are settled, and the missing schedule/deployment/evaluation evidence is either supplied or explicitly accepted as pending under the institution’s rules. No Gantt/PERT calculation, user acceptance result, production deployment result, or real-world effectiveness claim should be added without its supporting records.
