<?php
declare(strict_types=1);

/**
 * Role-specific onboarding guides for turnover / training.
 *
 * @return array{id:string,title:string,subtitle:string,steps:list<array{title:string,body:string,demo:string,cta?:array{label:string,href:string}}>}
 */
function guide_for_role(?string $role = null): array
{
    $role = $role ?? user_role() ?? ROLE_STAFF;
    $guides = [
        ROLE_STAFF => [
            'id' => 'staff',
            'title' => 'Staff quick-start guide',
            'subtitle' => 'Register patients, create requests, collect specimens, and find reports — step by step.',
            'steps' => [
                [
                    'title' => 'Your daily path',
                    'body' => 'As Administrative Staff you handle front-desk lab intake: patients → requests → specimen collection → looking up released reports. You do not encode or approve results — that is MedTech work.',
                    'demo' => 'staff-path',
                ],
                [
                    'title' => 'Register a patient',
                    'body' => 'Open Patients → Register patient. Enter name, birth date, sex, and contact. Search by code or name anytime to reopen a record.',
                    'demo' => 'patient-register',
                    'cta' => ['label' => 'Go to Patients', 'href' => 'patients/index.php'],
                ],
                [
                    'title' => 'Create a lab request',
                    'body' => 'From the patient record (or Requests → New request), pick the patient, physician, specimen type, and ordered tests. The system creates a specimen and pending result slots automatically.',
                    'demo' => 'request-create',
                    'cta' => ['label' => 'New request', 'href' => 'requests/create.php'],
                ],
                [
                    'title' => 'Log specimen collection',
                    'body' => 'Open Specimens, find the pending sample, and mark it collected when the sample is drawn. MedTech will move it through processing afterward.',
                    'demo' => 'specimen-collect',
                    'cta' => ['label' => 'Open Specimens', 'href' => 'specimens/index.php'],
                ],
                [
                    'title' => 'Find a report',
                    'body' => 'When MedTech releases a report, open Reports to view or print it for the clinic or patient. Use search to find by patient or request code.',
                    'demo' => 'report-view',
                    'cta' => ['label' => 'Open Reports', 'href' => 'reports/index.php'],
                ],
            ],
        ],
        ROLE_MED_TECH => [
            'id' => 'medtech',
            'title' => 'MedTech quick-start guide',
            'subtitle' => 'Process specimens, encode results, review AI warnings, approve, and release reports.',
            'steps' => [
                [
                    'title' => 'Your laboratory path',
                    'body' => 'Medical Technologists own the analytic workflow: process specimens → encode → review rule + AI flags → approve → generate → release. Staff can collect; you complete the rest.',
                    'demo' => 'medtech-path',
                ],
                [
                    'title' => 'Advance specimen status',
                    'body' => 'In Specimens, move samples from collected → processing → completed (or flag delayed/missing). Watch the dashboard delay alerts for samples stuck ≥ SLA hours.',
                    'demo' => 'specimen-process',
                    'cta' => ['label' => 'Open Specimens', 'href' => 'specimens/index.php'],
                ],
                [
                    'title' => 'Encode results',
                    'body' => 'Open Results, pick a pending/encoded panel, enter values, and save. Rule-based checks run immediately; out-of-range values show soft warnings. Impossible values are blocked.',
                    'demo' => 'result-encode',
                    'cta' => ['label' => 'Open Results', 'href' => 'results/index.php'],
                ],
                [
                    'title' => 'Review AI & approve',
                    'body' => 'After validation, Isolation Forest may mark ai_flagged. This is a soft warning only — never auto-approve. Review, then Approve or Reject back for re-entry.',
                    'demo' => 'ai-review',
                ],
                [
                    'title' => 'Generate & release',
                    'body' => 'Approved → Generate report → Release. Only released reports are meant for clinic/patient handoff. Audit logs record each critical action.',
                    'demo' => 'report-release',
                    'cta' => ['label' => 'Open Reports', 'href' => 'reports/index.php'],
                ],
            ],
        ],
        ROLE_MANAGER => [
            'id' => 'manager',
            'title' => 'Manager quick-start guide',
            'subtitle' => 'Oversee operations, users, ranges, backups, and the full lab pipeline.',
            'steps' => [
                [
                    'title' => 'Full oversight',
                    'body' => 'Managers can do everything MedTech and Staff can, plus Users, Reference Ranges, Backup, and Audit. Use the dashboard for delays, pending MT review, and AI warnings.',
                    'demo' => 'manager-path',
                ],
                [
                    'title' => 'Watch the dashboard',
                    'body' => 'Start each shift here: open requests, active specimens, awaiting review, AI flags, and delay alerts. Click any stat card to jump straight to the filtered list.',
                    'demo' => 'dashboard-watch',
                    'cta' => ['label' => 'Open Dashboard', 'href' => 'dashboard.php'],
                ],
                [
                    'title' => 'Manage users & access',
                    'body' => 'Users → create or deactivate accounts and assign Staff / MedTech / Manager roles. Only active users can sign in.',
                    'demo' => 'manage-users',
                    'cta' => ['label' => 'Manage Users', 'href' => 'admin/users.php'],
                ],
                [
                    'title' => 'Reference ranges',
                    'body' => 'Ranges drive rule-based validation by age, sex, and test. Keep them current so soft warnings and hard blocks stay clinically meaningful.',
                    'demo' => 'manage-ranges',
                    'cta' => ['label' => 'Edit Ranges', 'href' => 'admin/ranges.php'],
                ],
                [
                    'title' => 'Backup & audit',
                    'body' => 'Run Backup regularly and review Audit for logins, encoding, approvals, releases, and backup events. This supports turnover accountability.',
                    'demo' => 'backup-audit',
                    'cta' => ['label' => 'Run Backup', 'href' => 'backup/index.php'],
                ],
            ],
        ],
    ];

    return $guides[$role] ?? $guides[ROLE_STAFF];
}
