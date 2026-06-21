# WealthDash Patch v2 — 05-Apr-2026

## Files Changed
| File | Tasks |
|---|---|
| `templates/pages/stocks.php` | t216, t217 |
| `templates/pages/report_tax.php` | t132, t133, t135, t137 |
| `templates/pages/post_office.php` | t201, t204 |
| `templates/pages/dashboard.php` | t205 |
| `public/js/stocks.js` | t216, t217 |
| `public/js/post_office.js` | t201, t204 |

## Tasks Completed

### t216 — Stocks Sector-wise Analytics
- Bar chart of sector allocation by current value
- Auto-populates from `stock_master.sector` column

### t217 — Stocks Dividend Tracker
- Summary: Total dividends, This FY, Count
- Table: Stock, Date, Amount, FY
- Reads from `stock_dividends` via DIV transactions

### t132 — Grandfathering Calculator (Jan 31, 2018 FMV Rule)
- Inputs: Purchase price, Jan 31 2018 FMV, Sale price, Units
- Side-by-side: With vs Without grandfathering tax comparison
- Tax saving calculation

### t133 — HIFO Lot Selector
- Add multiple purchase lots with date, cost, units
- FIFO vs HIFO side-by-side gain comparison
- Tax saving shown if HIFO is better

### t135 — Capital Loss Set-Off & 8-Year Carry Forward
- Current year LTCG/STCG + brought-forward losses
- Step-by-step set-off: STCL → STCG → LTCG, LTCL → LTCG only
- Net taxable LTCG/STCG + total tax + carry-forward amount

### t137 — Advance Tax Calculator
- Income, LTCG, STCG, regime, deductions, paid inputs
- Total tax liability with cess
- 4-quarter schedule (15%, 30%, 30%, 25% of balance due)

### t201 — RD Monthly Installment Tracker
- Select RD scheme, see month grid
- Click to toggle: Unpaid → Paid → Missed
- localStorage persistence, shows paid/missed count + total deposited

### t204 — SSY Complete Tracker
- DOB, opening date, yearly deposit, balance inputs
- Maturity projection (age 21), partial withdrawal (age 18, 50%)
- Deposit period progress bar

### t205 — NSC Interest Deemed Reinvestment (Section 80C)
- Year-wise interest table (5 years)
- Shows 80C deduction available for Years 2–5 (deemed reinvestment)
- Current year highlighted

## Also marked ✅ in tracker (already had code):
t102, t103, t104, t105, t107, t100, t101, t140, t141, t143, t17, t196

## Task Tracker (v14)
✅ Done: 117 | ⏳ Pending: 98

## [2026-04-05] Tasks t21 + t82 + t87 + t157 + t203 + t211

### t21 — Market Indexes (already complete — marked done)
All indexes (Nifty 50, Sensex, Bank Nifty, Midcap 150, Smallcap 250, Sectoral, World, Commodities), 30-sec auto-refresh, live countdown, last-updated timestamp — fully implemented in `indexes.js` + `indexes_fetch.php`.

### t82 — 80C Dashboard (already complete — marked done)
Progress bar (₹0→₹1.5L), ELSS + PPF + NPS breakdown, 80CCD(1B) extra ₹50K NPS, remaining amount — fully implemented in `report_tax.php` + `tax_planning.php`.

### t87 — Mobile Responsive (already complete — marked done)
Holdings table: Fund+Value+Gain% only on mobile, horizontal swipe (`overflow-x: auto; -webkit-overflow-scrolling: touch`), sidebar auto-collapse at 768px, touch targets 44px+, screener responsive, drawer full-screen — fully implemented in `app.css`.

### t157 — Emergency Fund Tracker
- **`templates/pages/dashboard.php`**: New card after stat-grid
  - PHP: queries savings_accounts + liquid/overnight MF holdings → `$emergencyFund`
  - Progress bar: 0 → 6 months coverage (green/amber/red)
  - Status messages: "Adequate", "Borderline — add ₹X", "Low! Add ₹X"
  - Monthly expense input: saves to `app_settings.monthly_expense_estimate`
  - `efSaveExpense()` JS function → `save_setting` API action

### t203 — PPF Annual Deposit Tracker
- **`templates/pages/post_office.php`**: New card below SSY calculator
  - PHP: fetches active PPF accounts from `po_schemes` table
  - FY deposit progress bar (₹0 → ₹1,50,000) with % label
  - Manual deposit entry → saves to `app_settings.ppf_fy_deposit_{id}_{fy}`
  - Est. interest, days to FY-end, partial withdrawal eligibility, lock-in countdown
  - March deadline alert (🚨 if <30 days left and limit not reached)
  - `savePpfDeposit()` JS function

### t211 — Admin DB Backup & Restore UI
- **`api/admin/db_manage.php`**: 4 new actions
  - `admin_db_backup`: mysqldump → .sql.gz in `database/backups/`, records to `app_settings.db_backup_list`
  - `admin_db_backup_list`: returns list with file existence check
  - `admin_db_backup_download`: streams .sql.gz file for download
  - `admin_db_backup_delete`: removes file + list entry
- **`templates/pages/admin.php`**: Backup card in Setup tab right sidebar
  - "Backup Now" button with progress indicator
  - Backup list: file name, size, date, ⬇ download + 🗑 delete per entry
  - `runDbBackup()`, `loadBackupList()`, `deleteBackup()` JS functions
