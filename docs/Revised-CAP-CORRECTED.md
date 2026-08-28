# Revised CAP — Corrected Excerpts

Companion to `Revised-CAP.docx`. Apply these replacements in the Word document (see also [CAP_CORRECTIONS.md](CAP_CORRECTIONS.md)).

## Locale (consistent naming)

> …at **Lagman Qualicare Multispecialty and Diagnostic Center**…

## §2.4 Development Tools and Technologies (corrected)

- Programming Languages: PHP, JavaScript, HTML, CSS, **Python**
- Database Management System: MySQL
- Development Environment: XAMPP
- AI / ML: Python, scikit-learn (Isolation Forest), Flask local API
- Front-end: HTML, CSS, JavaScript

## Proposed Features — add Approve / Release

**11. Result Approval and Release Control**

Features:
- Explicit Medical Technologist approval after validation
- Report generation only after approval
- Controlled result release with audit logging

Functions:
- Prevents premature release of unverified or AI-flagged results
- Enforces status flow: pending → encoded → validated → approved → reported → released

## Objective 4 / Sprint language (workload claim removed)

Replace “Develop predictive workload analysis” with:

> Integrate AI-based abnormal result detection, operational dashboard monitoring (open requests, specimen delays, pending reviews), and role-based access control.

## Training manual topic (corrected)

Replace “Dashboard interpretation and workload monitoring” with:

> Dashboard interpretation and operational monitoring (specimen delay alerts, pending MT reviews, AI warnings)

## AI integration paragraph (add under Methodology)

> The AI module is implemented in Python using scikit-learn’s Isolation Forest algorithm and exposed through a local Flask API. The PHP Laboratory Information System sends encoded numeric result panels to this service after rule-based validation; returned anomaly flags are advisory and require Medical Technologist review before approval and release.

## References cleanup

- Use Sayed et al. (**2021**) consistently in Rationale and RRL.
- Remove duplicate Jarmakovica (2025) entry.
- Replace sci-hub/sci-net links with publisher DOI URLs for Chen et al. (2024), Sayed et al. (2021), and Topol (2019).
