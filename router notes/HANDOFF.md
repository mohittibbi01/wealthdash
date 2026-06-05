# WealthDash — Handoff Summary
Worker: ID-W4 | Task: t106

---

## t106 — NPS Contribution Auto-detect (Bank Statement Import) ✅

**Files:**

| File | Action |
|------|--------|
| `api/nps/nps_import.php` | OVERWRITE (was stub TODO) |
| `database/migrations/t106_migration.sql` | NEW |

---

## Router routes to add (api/router.php):

```php
// ── t106: NPS Bank Statement Import ───────────────────────────
case 'nps_import_parse':
case 'nps_import_staging_list':
case 'nps_import_staging_update':
case 'nps_import_staging_accept':
case 'nps_import_staging_reject':
case 'nps_import_confirm':
case 'nps_import_sessions_list':
case 'nps_import_schemes':
case 'nps_import_session_delete':
    require APP_ROOT . '/api/nps/nps_import.php'; exit;
```

---

## New DB Tables

| Table | Purpose |
|-------|---------|
| `nps_import_sessions` | One row per upload session |
| `nps_import_staging`  | One row per detected NPS transaction |

`nps_transactions` altered — ADD COLUMN IF NOT EXISTS:
- `tier`, `contribution_type`, `investment_fy` (from t99 — safe idempotent)
- `import_source` — `manual` / `bank_import` / `csv_upload`
- `staging_id` — FK back to staging row

---

## Flow

```
1. nps_import_parse      → Upload CSV → detect NPS rows → create session + staging
2. nps_import_staging_list → Review detected rows
3. nps_import_staging_update → Assign scheme_id + tier + units + nav per row
4. nps_import_staging_accept / _reject → Mark each row
5. nps_import_confirm    → Import accepted rows → nps_transactions
```

---

## Supported Banks (auto-detect from CSV headers)
SBI · HDFC · ICICI · Axis · Kotak · DEFAULT (generic)

## NPS Detection Keywords (24 patterns, confidence 80–100)
`NPS CONTRIBUTION`, `NSDL CRA`, `NPS PFRDA`, `NPSTRUST`, `NPS TIER 2`,
`HDFC PENSION`, `SBI PENSION`, `ICICI PRU PENSION`, `KOTAK PENSION`,
`UTI RETIREMENT`, `LIC PENSION`, `ADITYA BIRLA PENSION`, `AXIS PENSION`,
`DSP PENSION`, `TATA PENSION`, `MAX LIFE PENSION`, `EMPLOYER NPS`, `PFRDA` etc.

---

## Overwrite Policy

| File | Overwrite? |
|------|-----------|
| `api/nps/nps_import.php` | YES — was a TODO stub |
| `api/router.php` | ❌ NOT TOUCHED |
