# Software correction checklist

The supplied review image is the requirements reference. The pediatric clinic project was consulted for its booking, arrival, no-show and patient-progress concepts; this LIS keeps its PHP/MySQL implementation.

| Review request | Current implementation |
| --- | --- |
| Patient registration and account | `portal/register.php`, patient role and linked patient record |
| Request checkup and laboratory tests | `portal/book.php` with preferred time, reason and test panels |
| Approved schedule and ten-minute no-show expiry | `appointments/view.php`, `includes/appointments.php`; expiry is evaluated when appointment and portal pages load, and the arrival update checks the deadline atomically |
| View own results | Portal dashboard, result list and printable result; patient ownership and released status are checked on the server |
| Separate manager/doctor and MedTech | Retained per user clarification; manager administers users, both clinical roles can approve results |
| Patient and staff dashboards | `portal/dashboard.php`, `dashboard.php` with role-specific navigation |
| Patient search, filters and historical records | Patient pages, appointment search/status/date filters, report search/status/date filters; previous records remain accessible |
| Printable reports by date | `reports/index.php` date filters and print list, individual report printing; matching lists are no longer silently truncated |
| User management | `admin/users.php`, `admin/user_edit.php`, manager permissions |
| Show each patient's process | Booking approval, arrival, laboratory work and released-result progress on the portal dashboard |
| Revised AI assistance and doctor's advice note | Existing anomaly validation and AI disclaimer helper displayed in result and AI interfaces |

## Verification

Run `php scripts/check_appointment_rules.php` for date-validation and grace-period checks. PHP source files can be syntax checked with `php -l`.

Database acceptance still requires running MySQL. Verify: patient registration and login; booking; clinic approval; arrival before the ten-minute cutoff; expiry at/after the cutoff; laboratory request and result processing; generated reports remaining hidden until release; a patient being unable to open another patient's result; filtered report printing. Exercise staff, MedTech and manager logins to verify their separate permissions.

Expiry currently runs on page access rather than through a background scheduler. No SMS, QR check-in or live queue display was requested in the correction image.
