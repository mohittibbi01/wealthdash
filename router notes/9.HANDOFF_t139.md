# HANDOFF SUMMARY — t139: Goal-Based Buckets
## Task: Retirement · Education · Emergency goal buckets
## Status: COMPLETE ✅

---

## Files Delivered

### NEW
| File | Description |
|------|-------------|
| `templates/pages/goal_buckets.php` | Full UI — Goal Buckets page (Retirement/Education/Emergency/Custom) |

### UNCHANGED (already complete — included for deployment)
| File | Description |
|------|-------------|
| `api/goals/goal_buckets.php` | Full CRUD API (bucket_list, bucket_add, bucket_edit, bucket_delete, bucket_contribute, bucket_progress, bucket_summary, bucket_link_asset, bucket_unlink_asset, bucket_mark_achieved) |
| `database/migrations/t139_migration.sql` | DB migration — goal_buckets columns + goal_fund_links + goal_bucket_contributions tables |

### MODIFIED
| File | Change |
|------|--------|
| `templates/sidebar.php` | `goals` nav item converted to dropdown — added `goal_buckets` sub-link |

---

## Router Routes to Add (api/router.php — TOUCH NOT DONE)

Add these to `$csrfExempt` array:
```php
'bucket_list', 'bucket_summary', 'bucket_progress',
```

Add these cases to the router switch:
```php
// ── t139: Goal-Based Buckets ──────────────────────────────
case 'bucket_list':
case 'bucket_summary':
case 'bucket_progress':
case 'bucket_add':
case 'bucket_edit':
case 'bucket_delete':
case 'bucket_contribute':
case 'bucket_link_asset':
case 'bucket_unlink_asset':
case 'bucket_mark_achieved':
    require APP_ROOT . '/api/goals/goal_buckets.php'; exit;
```

---

## DB Migration
Run: `database/migrations/t139_migration.sql`

Creates / alters:
- `goal_buckets` — adds `bucket_type`, `monthly_target`, `current_amount`, `risk_profile`, `priority` columns
- `goal_fund_links` — fund/SIP links to buckets
- `goal_bucket_contributions` — contribution log per bucket

---

## Page URL
`/templates/pages/goal_buckets.php`

## Features Delivered
- Quick-add buttons for Retirement / Education / Emergency / Custom
- Summary strip: total buckets, target, saved, overall progress %
- Filter tabs by bucket type + achieved
- Bucket cards with animated progress ring, projection strip, priority/risk badges
- Add/Edit modal with type selector, risk/priority/color pickers
- Contribute modal with history view
- Projection modal (FV calculation, shortfall/surplus, required SIP)
- Delete confirmation modal
- Mark as Achieved / Reopen toggle
- Mobile responsive grid
