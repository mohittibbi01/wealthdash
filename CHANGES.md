# WealthDash — MF Fixes Patch

## Files Changed (5 total)

| File | Task | Kya badla |
|------|------|-----------|
| `public/js/mf.js` | t31 | Transaction table header sort — click karo to sort ▲▼ |
| `api/mutual_funds/mf_list.php` | t31 | Dynamic ORDER BY — sort_col + sort_dir params support |
| `public/js/reports.js` | t32 | FY Gains mein fund name search filter logic |
| `templates/pages/report_fy.php` | t32 | FY Gains table ke upar search input UI |
| `templates/pages/mf_holdings.php` | t03 | Export/Import button icons fix |

## Feature Details

### t31 — Transaction Table Sort
- Date, Fund, Type, Units, NAV, Amount columns pe click karo
- Active column **blue + bold** hoga, arrow ▲/▼ direction dikhayega
- Default: Date DESC (latest pehle)
- Dusra column click = ascending; same column click = toggle
- Sort backend pe hota hai (DB query) → pagination ke saath sahi kaam karta hai

### t32 — FY Gains Fund Name Search
- MF Gains table ke upar search box hai
- Type karo → instantly filter hota hai (client-side, page reload nahi)
- "5 of 23 results" count dikhata hai
- ✕ Clear button se search reset hota hai
- Naya FY select karne pe search auto-clear ho jaata hai

### t03 — Button Icons Fix
- Import CSV → ↑ upload arrow (tray mein upar jaata hai)
- Export CSV → ↓ download arrow (tray se neeche aata hai)
- Download Excel → file icon (sahi tha, raha)
