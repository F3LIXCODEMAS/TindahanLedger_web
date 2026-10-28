# Chapter 4 Visual Asset and Table Manifest

This manifest maps the current Chapter 4 Markdown drafts to the assets prepared for Microsoft Word conversion. SVG files are vector graphics and can be scaled without raster loss. The Word-friendly table compilation is an HTML file that can be opened in Word or copied table-by-table into the chapter.

## Figures and charts

| Chapter section | Figure | Caption | File path | Insert location / instruction | Source or note |
|---|---|---|---|---|---|
| 4.2.1 | Figure 4.1 | Iterative and incremental development cycle applied to TindahanLedger | `chapter4/assets/figures/figure-4-1-sdlc-cycle.svg` | Replace the Mermaid code block under 4.2.1; retain the existing explanation. | Retrospective synthesis of ticket-named branches, commits, application code, and tests. Formal approval gates are not evidenced. |
| 4.2.4 | Figure 4.2 | Context diagram of the TindahanLedger system | `chapter4/assets/figures/figure-4-2-context-diagram.svg` | Replace the Mermaid code block under 4.2.4. | Verified external entities: Store Owner, Super Admin, QR-access Customer, and configured Gmail SMTP. Gmail inbox delivery is unverified. |
| 4.2.5 | Figure 4.3 | Level 1 data flow diagram for TindahanLedger | `chapter4/assets/figures/figure-4-3-level-1-dfd.svg` | Replace the Mermaid code block under 4.2.5. | Processes summarize verified routes/services; data stores name migration-defined tables. |
| 4.2.6 | Figure 4.4 | Main TindahanLedger application workflow | `chapter4/assets/figures/figure-4-4-system-flowchart.svg` | Replace the Mermaid code block under 4.2.6. | High-level owner, Super Admin, financial, and QR-portal flows; not a screenshot or screen mockup. |
| 4.2.7 | Figure 4.5(a) | Core store, customer, ledger, catalog, and purchase entities | `chapter4/assets/figures/figure-4-5a-core-erd.svg` | Replace the Figure 4.5(a) Mermaid code block. | Based on migration-defined entities and constraints. Framework tables, timestamps, and secondary indexes are omitted. |
| 4.2.7 | Figure 4.5(b) | Loyalty, portal, notification, audit, and recommendation entities | `chapter4/assets/figures/figure-4-5b-supporting-erd.svg` | Replace the Figure 4.5(b) Mermaid code block. | Supporting entities and selected cardinalities are migration-based; explanatory relationship notes supplement lines omitted to avoid crossings. |
| 4.2.9 | Figure 4.6 | TindahanLedger use-case diagram | `chapter4/assets/figures/figure-4-6-use-case.svg` | Replace the Mermaid/flowchart code block under 4.2.9. | Only verified actors and functions are shown; customer access is read-only and Gmail SMTP is an external service. |
| 4.2.11 | Figure 4.7 | Dated GitHub branch-head commit milestones identified for TindahanLedger | `chapter4/assets/figures/figure-4-7-github-commit-milestones.svg` | Insert after the Gantt section's introductory limitation paragraph, before Figure 4.8. | Selected GitHub commit timestamps displayed as UTC dates. These are not branch-creation dates, Jira task-creation dates, task durations, or dependency evidence. Some branch names and head commit subjects do not align. |
| 4.2.11 | Figure 4.8 | Gantt chart pending verified project schedule | `chapter4/assets/figures/figure-4-8-gantt-pending.svg` | Insert after the pending-Gantt note in 4.2.11. | Placeholder only. No duration bars or task dates are fabricated. |
| 4.2.12 | Figure 4.9 | PERT network and critical-path calculations pending verified inputs | `chapter4/assets/figures/figure-4-9-pert-pending.svg` | Insert after the PERT limitation paragraph in 4.2.12 and before Table 4.10. | Placeholder only. ES, EF, LS, LF, slack, and critical path are not calculated. |

## Tables

All tables are compiled in [`chapter4/assets/chapter4_tables.html`](assets/chapter4_tables.html). The source Markdown tables remain available in the chapter section files. The HTML captions below use unique chapter-wide numbering in document order.

| Chapter section | Table | Caption | File path | Insert location / instruction | Source or note |
|---|---|---|---|---|---|
| 4.1.1 | Table 4.1 | Proposed performance requirements for TindahanLedger | `chapter4/assets/chapter4_tables.html` | Retain in 4.1.1. | Targets remain marked proposed; they are not achieved results. |
| 4.1.2 | Table 4.2 | Safety requirements for TindahanLedger | `chapter4/assets/chapter4_tables.html` | Retain in 4.1.2. | Safeguards are limited to inspected implementation and identified operational gaps. |
| 4.1.3 | Table 4.3 | Security requirements for TindahanLedger | `chapter4/assets/chapter4_tables.html` | Retain in 4.1.3. | Do not imply controls beyond the verified source implementation. |
| 4.2.1 | Table 4.4 | SDLC phases and TindahanLedger evidence | `chapter4/assets/chapter4_tables.html` | Retain after Figure 4.1 discussion. | Repository evidence supports iterative/incremental work, not formal Scrum adoption. |
| 4.2.2 | Table 4.5 | TindahanLedger test plan | `chapter4/assets/chapter4_tables.html` | Retain in 4.2.2. | Planned assessments and expected outcomes are distinguished from completed automated tests. |
| 4.2.3 | Table 4.6 | TindahanLedger security plan | `chapter4/assets/chapter4_tables.html` | Retain in 4.2.3. | Testing/operational steps not evidenced as completed remain plans. |
| 4.2.7 | Table 4.7 | TindahanLedger domain data dictionary | `chapter4/assets/chapter4_tables.html` | Insert after ERD relationship discussion and before 4.2.8. | Concise dictionary of migration-defined domain entities and selected key attributes; not a full DDL export. |
| 4.2.8 | Table 4.8 | Functional dependencies and normalization implications in TindahanLedger | `chapter4/assets/chapter4_tables.html` | Retain in 4.2.8. | Describes dependencies and deliberate redundancies; does not claim whole-schema 3NF. |
| 4.2.10 | Table 4.9 | TindahanLedger maintenance plan | `chapter4/assets/chapter4_tables.html` | Retain in 4.2.10. | Implemented schedules are distinguished from proposed operating procedures. |
| 4.2.12 | Table 4.10 | PERT input evidence required before calculation | `chapter4/assets/chapter4_tables.html` | Retain after Figure 4.9. | Inputs are marked pending; no numeric PERT calculations are supplied. |
| 4.3 | Table 4.11 | TindahanLedger software requirements and development environment | `chapter4/assets/chapter4_tables.html` | Retain in 4.3 under software requirements. | Local development/test environment is distinguished from unknown production deployment. |
| 4.3 | Table 4.12 | Implemented TindahanLedger application modules | `chapter4/assets/chapter4_tables.html` | Retain in 4.3 under application modules. | Module status is based on inspected source and tests. |
| 4.4 | Table 4.13 | TindahanLedger infrastructure and deployment implementation plan | `chapter4/assets/chapter4_tables.html` | Retain in 4.4. | Unknown duration, budget, staffing, and deployment infrastructure remain marked for verification. |
| 4.5 | Table 4.14 | Local synthetic recommendation benchmark measurements | `chapter4/assets/chapter4_tables.html` | Retain under the objective on analytics and recommendations. | Local synthetic measurements only; not production benchmarks or evidence of real-store effectiveness. |

## Assembly notes

- Section headings retain the existing 4.1–4.5 numbering.
- Table captions in the section drafts were renumbered to avoid the previous duplicate Table 4.1–4.6 numbering across separate Markdown files. Table numbers are now unique from 4.1 through 4.14.
- Preserve the `[PROPOSED TARGET — FOR APPROVAL]`, `[NEEDS VERIFICATION]`, and `[PENDING]` labels during Word conversion.
- Use the SVGs as vector images. Do not convert them to screenshots or add system screenshots; none were supplied for this task.
- Figure 4.7 is a commit-date evidence timeline, not a Gantt chart. The actual Gantt and PERT visuals remain placeholders until approved schedule durations and dependencies are available.
- The data dictionary summarizes domain fields from the existing migration-based ERD. For a submission requiring every schema column, validate against a target-database export and expand it before final submission.
