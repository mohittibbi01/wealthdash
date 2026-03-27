# NPS Module — Deployment Guide
## Tasks Completed: t99, t100, t101

### Step 1: Database Migration (RUN FIRST)
```sql
-- XAMPP phpMyAdmin ya command line mein run karo:
source database/migrations/014_nps_nav_history.sql
```
Yeh create karega:
- `nps_nav_history` table (daily NAV storage)
- `nps_schemes` mein return columns: `return_1y`, `return_3y`, `return_5y`, `return_since`, `aum_cr`
- `app_settings` mein NPS keys

### Step 2: Files Copy Karo (REPLACE originals)
```
database/migrations/014_nps_nav_history.sql  → NEW
cron/nps_nav_scraper.php                      → REPLACE
api/nps/nps_list.php                          → REPLACE
api/nps/nps_screener.php                      → NEW
api/nps/nps_statement.php                     → NEW
api/admin/nps_nav_trigger.php                 → NEW
templates/pages/nps_screener.php              → NEW
templates/pages/nps.php                       → REPLACE
api/router.php                                → REPLACE
templates/sidebar.php                         → REPLACE
templates/pages/admin.php                     → REPLACE
public/js/nps.js                              → REPLACE
```

### Step 3: NAV History Backfill (PFRDA se data fetch)
Admin Panel → NAV Operations → NPS NAV Update → **Backfill 5Y History**
(background mein chalega, 2-5 min lagenge)

### Step 4: Verify
- NPS page → Statement dropdown → CSV/PDF kaam kare
- Sidebar → "🔍 Find NPS Scheme" link visible ho
- Admin panel → NPS NAV card dikhna chahiye
- NPS Screener → schemes list aaye

### API Endpoints Added
| Endpoint | Description |
|----------|-------------|
| `GET /api/?action=nps_list&type=summary` | Asset allocation + FY tax data |
| `GET /api/?action=nps_statement&format=csv\|html\|pdf` | Statement download |
| `POST /api/?action=admin_nps_nav_trigger` | Admin NAV trigger |
| `GET /api/nps/nps_screener.php` | NPS screener (standalone) |

### Cron Schedule (Optional — auto-trigger se bhi hota hai)
```
# Daily NPS NAV at 9 PM
0 21 * * * php /path/to/wealthdash/cron/nps_nav_scraper.php daily
# Weekly returns recalculation (Sunday midnight)
0 0 * * 0 php /path/to/wealthdash/cron/nps_nav_scraper.php returns
```
