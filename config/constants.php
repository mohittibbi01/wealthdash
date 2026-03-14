<?php
/**
 * WealthDash — Application Constants
 * Tax rates, LTCG rules, AMFI, financial year helpers
 */
declare(strict_types=1);

// -------------------------------------------------------
// TAX RATES (India FY 2024-25 onwards)
// -------------------------------------------------------

// Equity LTCG (held > 365 days)
define('EQUITY_LTCG_RATE',         12.5);   // % (Budget 2024)
define('EQUITY_LTCG_DAYS',         365);
define('EQUITY_LTCG_EXEMPTION',    125000); // ₹1.25 lakh per FY
define('LTCG_EXEMPTION_LIMIT',     125000); // Alias

// Equity STCG (held <= 365 days)
define('EQUITY_STCG_RATE',         20.0);   // % (Budget 2024)

// Debt LTCG (held > 1095 days = 3 years, pre Apr 2023 funds only)
// Post Apr 2023: Debt MF gains taxed as per slab, no indexation
define('DEBT_LTCG_RATE',           20.0);   // % with indexation
define('DEBT_LTCG_DAYS',           1095);

// FD TDS
define('FD_TDS_RATE',              10.0);   // % on interest
define('FD_TDS_SENIOR_RATE',       10.0);   // same as normal for now
define('FD_TDS_THRESHOLD',         40000);  // ₹40,000 per year
define('FD_TDS_THRESHOLD_SENIOR',  50000);  // ₹50,000 for senior citizens

// Savings Account — 80TTA / 80TTB
define('SAVINGS_80TTA_LIMIT',      10000);  // Non-senior
define('SAVINGS_80TTB_LIMIT',      50000);  // Senior citizens

// ELSS lock-in
define('ELSS_LOCKIN_DAYS',         1095);   // 3 years

// -------------------------------------------------------
// FINANCIAL YEAR HELPERS
// -------------------------------------------------------
define('FY_START_MONTH',           4);      // April

// -------------------------------------------------------
// AMFI NAV SOURCE
// -------------------------------------------------------
define('AMFI_NAV_URL',             'https://www.amfiindia.com/spages/NAVAll.txt');
// Fix: use per-fund historical NAV endpoint (MFApi.in mirrors AMFI historical data)
define('AMFI_NAV_HISTORY_URL',     'https://api.mfapi.in/mf');

// -------------------------------------------------------
// PLATFORM OPTIONS
// -------------------------------------------------------
define('MF_PLATFORMS', [
    'direct'    => 'Direct (AMC)',
    'zerodha'   => 'Zerodha Coin',
    'groww'     => 'Groww',
    'mfcentral' => 'MF Central',
    'paytm'     => 'Paytm Money',
    'kfintech'  => 'KFintech',
    'cams'      => 'CAMS',
    'others'    => 'Others',
]);

define('STOCK_PLATFORMS', [
    'zerodha'  => 'Zerodha',
    'groww'    => 'Groww',
    'icici'    => 'ICICI Direct',
    'hdfc'     => 'HDFC Securities',
    'sbi'      => 'SBI Securities',
    'upstox'   => 'Upstox',
    'angelone' => 'Angel One',
    'others'   => 'Others',
]);

// -------------------------------------------------------
// DATE FORMAT
// -------------------------------------------------------
define('DATE_DISPLAY',  'd-m-Y');       // DD-MM-YYYY for display
define('DATE_DB',       'Y-m-d');       // YYYY-MM-DD for MySQL
define('CURRENCY',      '₹');
define('CURRENCY_CODE', 'INR');

// -------------------------------------------------------
// PAGINATION
// -------------------------------------------------------
define('PAGE_SIZE', 25);

// -------------------------------------------------------
// ROLES
// -------------------------------------------------------
define('ROLE_ADMIN',  'admin');
define('ROLE_MEMBER', 'member');

// -------------------------------------------------------
// TRANSACTION TYPES
// -------------------------------------------------------
define('MF_TXN_TYPES', [
    'BUY'          => 'Buy / Purchase',
    'SELL'         => 'Sell / Redeem',
    'DIV_REINVEST' => 'Dividend Reinvest',
    'DIV_PAYOUT'   => 'Dividend Payout',
    'SWITCH_IN'    => 'Switch In',
    'SWITCH_OUT'   => 'Switch Out',
    'STP_IN'       => 'STP In',
    'STP_OUT'      => 'STP Out',
    'SWP'          => 'SWP',
]);

define('STOCK_TXN_TYPES', [
    'BUY'   => 'Buy',
    'SELL'  => 'Sell',
    'BONUS' => 'Bonus',
    'SPLIT' => 'Split',
    'DIV'   => 'Dividend',
]);

define('ASSET_VERSION', '1.0');