# Results + Render database guide

This LIS stores **laboratory results** in Postgres on Render (or MySQL on local XAMPP). Dashboard **Quick actions → Results** opens the encoding/review queue. Managers also have **Database** to inspect the live instance without exposing passwords.

## What “Results” writes to the database

| Table | What it holds |
|-------|----------------|
| `lab_results` | One row per panel (CBC, CHEMISTRY, URINE, …) and workflow status |
| `result_values` | Values per test (numeric and qualitative text) |
| `ai_flags` | Isolation Forest score / warning text |
| `specimens` | Sample status that should be processed before encoding |
| `lab_requests` / `request_tests` | The order that **creates** pending result rows |

**Status pipeline**

`pending` → `encoded` → `validated` → `approved` → `reported` → `released`

A result row is created automatically when you **create a lab request**. Encoding does not create the row; it fills `result_values` and moves status forward.

Fresh installs and empty databases also load **four demo results** (pending, AI warning, approved, released). On Results, use **Load demo results** if the list is empty. Redeploying `ailab-web` runs this seed on boot when `lab_results` has no rows.

## Quick actions (MedTech / Manager)

| Button | Goes to |
|--------|---------|
| **Results** | All result rows |
| Review results / Awaiting review | `status=validated` (ready to approve) |
| Encode pending | `status=pending` |
| AI warnings | Flagged rows still in review |
| **Database** (Manager) | Live table counts + recent results |

## 1. Create or reuse Postgres on Render

1. Open [Render Dashboard](https://dashboard.render.com/).
2. Either:
   - **Blueprint** (recommended): push this repo, then [New Blueprint](https://dashboard.render.com/blueprint/new?repo=https://github.com/Leo2121Scorp/AI-Assisted_Laborator_Information_System). Apply. That creates `ailab-db` and sets `DATABASE_URL` on `ailab-web`.
   - **Manual:** New → PostgreSQL. Name it `ailab-db`. Same **region** as `ailab-web`.
3. Free workspaces allow **one** Postgres. If create fails, open the existing instance instead of making a second.

Immutable after create: database name, user, region, Postgres major version.

## 2. Connect `ailab-web` (control the URL)

Render gives **two** URLs for the same database:

| URL | Use when |
|-----|----------|
| **Internal Database URL** | `ailab-web` on Render, **same region** (preferred) |
| **External Database URL** | Your PC, DBeaver, `psql`, CI. **TLS required** |

On `ailab-web` → **Environment**:

- Blueprint path: `DATABASE_URL` is filled from `ailab-db` (`fromDatabase` in `render.yaml`).
- Manual path: paste the **Internal** URL, Save, then **Manual Deploy**.

Do not paste a truncated URL (ellipsis `…`). Host should look like `dpg-xxxxx-a` (internal) or `dpg-xxxxx-a.REGION-postgres.render.com` (external).

After deploy, the container runs `scripts/auto_install.php`, which creates schema + demo users if the database is empty.

## 3. Put a result in the database (end-to-end)

Sign in as `medtech` or `manager` (`password123` on a fresh install).

1. **Patients** → Register (or pick an existing patient).
2. **Requests → New request** → choose tests (CBC / Chemistry / Urine). Save.
3. **Specimens** → mark collected → processing → completed (MedTech).
4. Dashboard **Results** (or **Encode pending**) → open the panel → enter values → **Save, validate & run AI**.
5. If AI warns, review, then **Approve**.
6. **Generate report** → **Release**.

Confirm on **Database** (Manager): `lab_results` row count increases; recent table shows the new `result_code`.

## 4. Inspect and control data

**In the app (Manager)**

- Nav **Database** — engine, host, table row counts, last 25 results.
- **Backup** — dump to `backups/` (on Render the disk is ephemeral; download/export if you need a lasting copy).

**On Render**

- Postgres instance → **Connect** → `psql` or copy External URL.
- **Metrics** — disk, connections.
- **Recovery** — snapshots / restore from the Dashboard (plan-dependent). Deleting the instance **destroys backups**.

**From your PC (read/write with psql)**

```bash
psql "YOUR_EXTERNAL_DATABASE_URL"
```

Useful checks:

```sql
SELECT status, COUNT(*) FROM lab_results GROUP BY status;
SELECT result_code, panel_code, status, ai_flagged FROM lab_results ORDER BY id DESC LIMIT 20;
SELECT rv.numeric_value, lt.test_code
FROM result_values rv
JOIN lab_tests lt ON lt.id = rv.lab_test_id
JOIN lab_results r ON r.id = rv.lab_result_id
WHERE r.result_code = 'RES…';
```

`query_render_postgres` in Cursor is **read-only**. App encoding still happens through the Results screens.

## 5. Local XAMPP vs Render

| | Local | Render |
|---|---------|--------|
| Engine | MySQL (`config/database.php` defaults) | Postgres via `DATABASE_URL` |
| Schema | `database/schema.sql` | `database/schema.postgres.sql` |
| Install | `/install.php` | auto on boot + `/install.php` |
| Data | Stays on your PC | Stays on Render Postgres (not the web container disk) |

Never point production `DATABASE_URL` at localhost. The web service filesystem is ephemeral; **results must live in Postgres**.

## Troubleshooting

| Symptom | What to do |
|---------|------------|
| Results page empty | Create a **lab request**; pending results are not seeded by default |
| `DATABASE_URL is not reaching this service` | Set Internal URL on `ailab-web`, Save + rebuild |
| SSL / `sslmode` errors from a laptop | Use **External** URL with TLS |
| Missing tables | Open `/install.php` or check auto_install logs on the web service |
| Free Postgres create failed | Reuse the existing instance; one free DB per workspace |
| First load ~30–60s | Free web services sleep when idle |

Demo logins after install: `manager` / `medtech` / `staff` — password `password123`. Change these in **Users** before real clinic use.
