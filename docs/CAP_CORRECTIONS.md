# CAP Corrections Checklist

Apply these edits to `Revised-CAP.docx` for consistency with the implemented system and defense readiness.

## Naming

- Use **Lagman Qualicare Multispecialty and Diagnostic Center** consistently (remove stray “At Lagman”).

## Tools & technologies (§2.4)

Add explicitly:

- Programming Languages: **PHP, JavaScript, HTML, CSS, Python**
- Database: MySQL
- Environment: XAMPP
- AI library: scikit-learn (Isolation Forest), Flask for local AI API

## Workload prediction claim

Isolation Forest is for **anomaly detection**, not workload prediction.

- In Sprint/Objective 4 and training materials, replace “predictive workload analysis / workload prediction” with **dashboard operational monitoring** (open requests, active specimens, delay alerts, pending MT reviews).
- Keep future “workload prediction” only under Future Expansion if desired.

## Approve / Release formalization

Add to Proposed Features:

- **Result Approval Gate** — Medical Technologist explicitly approves validated results (including AI-flagged cases) before reporting.
- **Result Release** — Only reported results may be released; action is audited.

Result states: `pending → encoded → validated → approved → reported → released`.

## Methodology polish

- Remove duplicate “Methodology” heading.
- Complete Rule-Based Data Validation feature bullets (reference-range checks, critical flags, encoding hard-blocks).

## Citations

- Align Sayed et al. year in Rationale with References (use **2021** as in refs, or update refs if 2018 is intended).
- Replace sci-hub / sci-net URLs with publisher DOI links (Chen 2024, Sayed 2021, Topol 2019).
- Remove duplicate Jarmakovica (2025) reference entry.

## Python integration sentence (suggested)

> The AI module is implemented in Python using scikit-learn’s Isolation Forest algorithm and exposed through a local Flask API. The PHP Laboratory Information System sends encoded numeric result panels to this service after rule-based validation; returned anomaly flags are advisory and require Medical Technologist review before approval and release.
