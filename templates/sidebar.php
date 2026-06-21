<?php
/**
 * WealthDash — Sidebar Navigation
 * Updated: all new modules added (Crypto, Alt Investments, RE, Cashflow, Govt)
 */
if (!defined('WEALTHDASH')) die();

// ── SVG icon helper ───────────────────────────────────────────────────────────
function _ic(string $d, int $s = 18): string {
    return "<svg width=\"{$s}\" height=\"{$s}\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"2\" viewBox=\"0 0 24 24\">{$d}</svg>";
}

$navItems = [

    'dashboard' => [
        'label' => 'Dashboard',
        'href'  => APP_URL . '/index.php',
        'icon'  => _ic('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'),
    ],

    'unified_dashboard' => [
        'label' => 'All Assets',
        'href'  => APP_URL . '/templates/pages/unified_dashboard.php',
        'icon'  => _ic('<circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/><path d="M3.05 11a9 9 0 1 0 .5-2.69"/>'),
        'badge' => 'NEW',
    ],

    'mf' => [
        'label'    => 'Mutual Funds',
        'icon'     => _ic('<path d="M3 3v18h18"/><path d="m7 16 4-8 4 6 4-4"/>'),
        'children' => [
            'mf_holdings'     => ['label' => 'Holdings',       'href' => APP_URL . '/templates/pages/mf_holdings.php',     'icon' => _ic('<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>', 14)],
            'mf_transactions' => ['label' => 'Transactions',   'href' => APP_URL . '/templates/pages/mf_transactions.php', 'icon' => _ic('<path d="M7 16V4m0 0L3 8m4-4 4 4"/><path d="M17 8v12m0 0 4-4m-4 4-4-4"/>', 14)],
            'mf_screener'     => ['label' => 'Find Funds',     'href' => APP_URL . '/templates/pages/mf_screener.php',     'icon' => _ic('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>', 14)],
            'mf_report'       => ['label' => 'Report & Tools', 'href' => APP_URL . '/templates/pages/mf_report.php',      'icon' => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>', 14)],
        ],
    ],

    'nps' => [
        'label'    => 'NPS',
        'icon'     => _ic('<path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/>'),
        'children' => [
            'nps'          => ['label' => 'Holdings',        'href' => APP_URL . '/templates/pages/nps.php',          'icon' => _ic('<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>', 14)],
            'nps_screener' => ['label' => 'Find NPS Scheme', 'href' => APP_URL . '/templates/pages/nps_screener.php', 'icon' => _ic('<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>', 14)],
        ],
    ],

    'stocks' => [
        'label'    => 'Stocks & ETF',
        'icon'     => _ic('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'),
        'children' => [
            'stocks' => ['label' => 'Stocks',      'href' => APP_URL . '/templates/pages/stocks.php', 'icon' => _ic('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>', 14)],
            'etf'    => ['label' => 'ETF Holdings', 'href' => APP_URL . '/templates/pages/etf.php',    'icon' => _ic('<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2z"/>', 14)],
            'nfo'    => ['label' => 'NFO Tracker',  'href' => APP_URL . '/templates/pages/nfo.php',    'icon' => _ic('<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/>', 14)],
            'crypto' => ['label' => 'Crypto',       'href' => APP_URL . '/templates/pages/crypto.php', 'icon' => _ic('<path d="M11.767 19.089c4.924.868 6.14-6.025 1.216-6.894m-1.216 6.894L5.86 18.047m5.908 1.042-.347 1.97m1.563-8.864c4.924.869 6.14-6.025 1.215-6.893m-1.215 6.893-3.94-.694m5.155-6.2L8.29 4.26m5.908 1.042.348-1.97M7.48 20.364l3.126-17.727"/>', 14)],
        ],
    ],

    'market_indexes' => [
        'label' => 'Market Indexes',
        'href'  => APP_URL . '/templates/pages/market_indexes.php',
        'icon'  => _ic('<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10"/>'),
    ],

    'fd' => [
        'label' => 'Fixed Deposits',
        'href'  => APP_URL . '/templates/pages/fd.php',
        'icon'  => _ic('<rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>'),
    ],

    'savings' => [
        'label' => 'Savings',
        'href'  => APP_URL . '/templates/pages/savings.php',
        'icon'  => _ic('<path d="M19 5H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2z"/><path d="M16 10h-1a2 2 0 0 0 0 4h1"/><path d="M2 10h20"/>'),
    ],

    'post_office' => [
        'label' => 'Post Office',
        'href'  => APP_URL . '/templates/pages/post_office.php',
        'icon'  => _ic('<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>'),
    ],

    'alt_investments' => [
        'label'    => 'Alt Investments',
        'icon'     => _ic('<circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/>'),
        'children' => [
            'sgb'           => ['label' => 'Sov. Gold Bonds',  'href' => APP_URL . '/templates/pages/sgb.php',           'icon' => _ic('<circle cx="12" cy="12" r="8"/><path d="M8 12h8m-4-4v8"/>', 14)],
            'gold'          => ['label' => 'Gold Tracker',     'href' => APP_URL . '/templates/pages/gold.php',          'icon' => _ic('<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>', 14)],
            'reits'         => ['label' => 'REITs & InvITs',   'href' => APP_URL . '/templates/pages/reits.php',         'icon' => _ic('<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>', 14)],
            'bonds'         => ['label' => 'Corp. Bonds',      'href' => APP_URL . '/templates/pages/bonds.php',         'icon' => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="9" y1="13" x2="15" y2="13"/>', 14)],
            'bonds_54ec'    => ['label' => '54EC Bonds',       'href' => APP_URL . '/templates/pages/bonds_54ec.php',    'icon' => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M9 10h6M9 14h4"/>', 14)],
            'esop'          => ['label' => 'ESOP / RSU',       'href' => APP_URL . '/templates/pages/esop.php',          'icon' => _ic('<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8l-2 4h12l-2-4z"/>', 14)],
            'international' => ['label' => 'International',    'href' => APP_URL . '/templates/pages/international.php', 'icon' => _ic('<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>', 14)],
            'pms'           => ['label' => 'PMS / AIF',        'href' => APP_URL . '/templates/pages/pms.php',           'icon' => _ic('<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/>', 14)],
        ],
    ],

    'goals' => [
        'label'    => 'Goals',
        'icon'     => _ic('<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>'),
        'children' => [
            'goals'        => ['label' => 'Goal Planning',  'href' => APP_URL . '/templates/pages/goals.php',        'icon' => _ic('<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/>', 14)],
            'goal_buckets' => ['label' => 'Goal Buckets',   'href' => APP_URL . '/templates/pages/goal_buckets.php', 'icon' => _ic('<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14l-4-4 1.41-1.41L10 13.17l6.59-6.59L18 8l-8 8z"/>', 14)], // t139
        ],
    ],

    'cashflow' => [
        'label' => 'Cashflow',
        'href'  => APP_URL . '/templates/pages/cashflow.php',
        'icon'  => _ic('<path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>'),
    ],

    'banking' => [
        'label'    => 'Banking & Loans',
        'icon'     => _ic('<line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="5 10 5 3 19 3 19 10"/>'),
        'children' => [
            'banks'      => ['label' => 'Bank Accounts', 'href' => APP_URL . '/templates/banks.php',            'icon' => _ic('<line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="5 10 5 3 19 3 19 10"/>', 14)],
            'loans'      => ['label' => 'Loans / EMI',   'href' => APP_URL . '/templates/loans.php',            'icon' => _ic('<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>', 14)],
            'realestate' => ['label' => 'Real Estate',   'href' => APP_URL . '/templates/pages/realestate.php', 'icon' => _ic('<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>', 14)],
        ],
    ],

    'protection' => [
        'label'    => 'Insurance & EPF',
        'icon'     => _ic('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>'),
        'children' => [
            'insurance'    => ['label' => 'Insurance',    'href' => APP_URL . '/templates/insurance.php',          'icon' => _ic('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>', 14)],
            'epf'          => ['label' => 'EPF / PF',     'href' => APP_URL . '/templates/epf.php',                'icon' => _ic('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>', 14)],
            'govt_schemes' => ['label' => 'Govt Schemes', 'href' => APP_URL . '/templates/pages/govt_schemes.php', 'icon' => _ic('<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><line x1="9" y1="22" x2="9" y2="12"/><line x1="15" y1="22" x2="15" y2="12"/>', 14)],
        ],
    ],

    'reports' => [
        'label'    => 'Reports',
        'icon'     => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>'),
        'children' => [
            'report_fy'        => ['label' => 'FY Gains',       'href' => APP_URL . '/templates/pages/report_fy.php',           'icon' => _ic('<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>', 14)],
            'report_tax'       => ['label' => 'Tax Planning',   'href' => APP_URL . '/templates/pages/report_tax.php',          'icon' => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="9" y1="14" x2="15" y2="14"/>', 14)],
            'ai_tax_optimizer' => ['label' => '🤖 AI Tax Optimizer', 'href' => APP_URL . '/templates/pages/ai_tax_optimizer.php', 'icon' => _ic('<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>', 14)],
            'tax_calculator'   => ['label' => 'Tax Calculator',  'href' => APP_URL . '/templates/pages/tax_calculator.php',      'icon' => _ic('<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="10" y1="14" x2="14" y2="14"/>', 14)],
            'tax_regime'       => ['label' => '⚖️ Regime Picker',  'href' => APP_URL . '/templates/pages/tax_regime.php',    'icon' => _ic('<line x1="3" y1="12" x2="21" y2="12"/><polyline points="8 7 3 12 8 17"/><polyline points="16 7 21 12 16 17"/>', 14)],
            'fire_calculator'  => ['label' => 'FIRE Calculator', 'href' => APP_URL . '/templates/pages/fire_calculator.php',     'icon' => _ic('<path d="M12 2c0 0-4 4-4 8a4 4 0 0 0 8 0c0-4-4-8-4-8z"/>', 14)],
            'report_networth'  => ['label' => 'Net Worth',      'href' => APP_URL . '/templates/pages/report_networth.php',     'icon' => _ic('<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>', 14)],
            'portfolio_heatmap'=> ['label' => '🌡️ Heatmap',       'href' => APP_URL . '/templates/pages/portfolio_heatmap.php',    'icon' => _ic('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>', 14)],
            'report_rebalance' => ['label' => 'Rebalancing',    'href' => APP_URL . '/templates/pages/report_rebalancing.php',  'icon' => _ic('<path d="M18 15l-6-6-6 6"/>', 14)],
            'report_sip'       => ['label' => 'MF SIP/SWP',     'href' => APP_URL . '/templates/pages/report_sip.php',          'icon' => _ic('<path d="M3 3v18h18"/><path d="m7 12 3-3 3 3 5-5"/>', 14)],
            'cap_gains'        => ['label' => 'Capital Gains',  'href' => APP_URL . '/templates/pages/capital_gains_summary.php','icon' => _ic('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>', 14)],
            'ltcg_harvest'     => ['label' => 'Tax Harvesting', 'href' => APP_URL . '/templates/pages/ltcg_harvesting.php',     'icon' => _ic('<path d="M12 22V12M12 12L8 16M12 12l4 4"/><circle cx="12" cy="6" r="4"/>', 14)],
            'wealth_statement' => ['label' => 'Wealth Statement','href' => APP_URL . '/templates/pages/wealth_statement.php',   'icon' => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>', 14)],
        ],
    ],


    // ════════════════════════════════════════════════════════
    // BELOW: 20 June 2026 router-merge addendum.
    // 52 of the 80 newly-merged tasks had a clear matching page
    // file and are added below, grouped by category. The other
    // 28 (admin/backend-only or no dedicated page found) are
    // listed in router_merge_README.md instead — add manually
    // if/when you build a page for them.
    // ════════════════════════════════════════════════════════

    'admin_infra_id_m' => [
        'label'    => 'Admin / Infra',
        'icon'     => _ic('<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/>'),
        'children' => [
            't43_bank_accounts_tracker' => ['label' => 'Bank Accounts Tracker', 'href' => APP_URL . '/templates/pages/banks.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't51_system_health_dashboard' => ['label' => 'System Health Dashboard', 'href' => APP_URL . '/pages/admin/system_health.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't53_db_manager' => ['label' => 'DB Manager', 'href' => APP_URL . '/pages/admin/db_manager.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'ai_features' => [
        'label'    => 'AI Features',
        'icon'     => _ic('<circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/>'),
        'children' => [
            't58_ai_portfolio_advisor' => ['label' => 'AI Portfolio Advisor', 'href' => APP_URL . '/pages/ai/portfolio_advisor.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't59_ai_auto_categorization' => ['label' => 'AI Auto Categorization', 'href' => APP_URL . '/pages/ai/auto_categorize.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't61_ai_goal_based_planning' => ['label' => 'AI Goal-based Planning', 'href' => APP_URL . '/pages/ai/goal_planning.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't243_ai_fund_recommendation' => ['label' => 'AI Fund Recommendation', 'href' => APP_URL . '/pages/ai/fund_recommend.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't244_ai_portfolio_narrative' => ['label' => 'AI Portfolio Narrative', 'href' => APP_URL . '/pages/ai/portfolio_narrative.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't246_ai_anomaly_detector' => ['label' => 'AI Anomaly Detector', 'href' => APP_URL . '/pages/ai/anomaly_detector.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't329_ai_weekly_digest' => ['label' => 'AI Weekly Digest', 'href' => APP_URL . '/pages/ai/weekly_digest.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't330_ai_chatbot' => ['label' => 'AI Chatbot', 'href' => APP_URL . '/pages/ai/chatbot.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't331_ai_sip_optimizer' => ['label' => 'AI SIP Optimizer', 'href' => APP_URL . '/pages/ai/sip_optimizer.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't332_ai_goal_advisor' => ['label' => 'AI Goal Advisor', 'href' => APP_URL . '/pages/ai/goal_advisor.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't333_ai_portfolio_report_card' => ['label' => 'AI Portfolio Report Card', 'href' => APP_URL . '/pages/ai/report_card.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't382_ai_fund_research' => ['label' => 'AI Fund Research', 'href' => APP_URL . '/pages/ai/fund_research.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't384_ai_anomaly_detector_v2' => ['label' => 'AI Anomaly Detector v2', 'href' => APP_URL . '/pages/ai/anomaly_detector_v2.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't385_ai_goal_coach' => ['label' => 'AI Goal Coach', 'href' => APP_URL . '/pages/ai/goal_coach.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'crypto_tools' => [
        'label'    => 'Crypto Tools',
        'icon'     => _ic('<path d="M11.767 19.089c4.924.868 6.14-6.025 1.216-6.894"/>'),
        'children' => [
            't42_crypto_tax_30_flat_calcul' => ['label' => 'Crypto Tax 30% Flat Calculator', 'href' => APP_URL . '/pages/crypto/tax_calculator.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            'tc006_cold_wallet_tracker' => ['label' => 'Cold Wallet Tracker', 'href' => APP_URL . '/pages/crypto/cold_wallet.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'tax_retirement_goals' => [
        'label'    => 'Tax / Retirement / Goals',
        'icon'     => _ic('<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>'),
        'children' => [
            'tg003_retirement_calculator' => ['label' => 'Retirement Calculator', 'href' => APP_URL . '/pages/goal/retirement_calculator.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            'tg005_goals_vs_actual' => ['label' => 'Goals vs Actual', 'href' => APP_URL . '/pages/goal/goals_tracker.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't360_life_events_calendar' => ['label' => 'Life Events Calendar', 'href' => APP_URL . '/pages/life_events.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'epf_insurance_property_loans' => [
        'label'    => 'EPF / Insurance / Property / Loans',
        'icon'     => _ic('<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'),
        'children' => [
            't122_insurance_portfolio' => ['label' => 'Insurance Portfolio', 'href' => APP_URL . '/pages/insurance/insurance.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't123_loan_tracker' => ['label' => 'Loan Tracker', 'href' => APP_URL . '/pages/loans/loans.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't323_ulip_tracker' => ['label' => 'ULIP Tracker', 'href' => APP_URL . '/pages/insurance/ulip.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't462_premium_calendar' => ['label' => 'Premium Calendar', 'href' => APP_URL . '/pages/insurance/premium_calendar.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't463_property_portfolio' => ['label' => 'Property Portfolio', 'href' => APP_URL . '/pages/property/property.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't150_digilocker_documents' => ['label' => 'DigiLocker / Documents', 'href' => APP_URL . '/pages/documents/digilocker.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't124_real_estate_rental_yield_' => ['label' => 'Real Estate / Rental Yield Calculator', 'href' => APP_URL . '/pages/property/rental_yield_calculator.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'dashboard_ux_personalization' => [
        'label'    => 'Dashboard / UX / Personalization',
        'icon'     => _ic('<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>'),
        'children' => [
            't55_dashboard_widget_customiz' => ['label' => 'Dashboard Widget Customizer', 'href' => APP_URL . '/pages/dashboard/widget_customizer.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't373_widget_mode' => ['label' => 'Widget Mode', 'href' => APP_URL . '/pages/dashboard/widget_mode.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't445_customizable_overview_car' => ['label' => 'Customizable Overview Cards', 'href' => APP_URL . '/pages/dashboard/overview_cards.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't446_portfolio_health_heatmap' => ['label' => 'Portfolio Health Heatmap', 'href' => APP_URL . '/pages/portfolio/health_heatmap.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't242_investor_streak_milestone' => ['label' => 'Investor Streak & Milestones', 'href' => APP_URL . '/pages/gamification/streaks.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'security_onboarding' => [
        'label'    => 'Security & Onboarding',
        'icon'     => _ic('<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'),
        'children' => [
            't371_biometric_login' => ['label' => 'Biometric Login', 'href' => APP_URL . '/pages/webauthn.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't387_session_security' => ['label' => 'Session Security', 'href' => APP_URL . '/pages/security/sessions.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't389_gdpr_data_controls' => ['label' => 'GDPR Data Controls', 'href' => APP_URL . '/pages/security/data_controls.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't240_onboarding_flow' => ['label' => 'Onboarding Flow', 'href' => APP_URL . '/pages/onboarding/onboarding.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't454_onboarding_setup_wizard' => ['label' => 'Onboarding Setup Wizard', 'href' => APP_URL . '/pages/setup_wizard_modal.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'budget_reports_sharing' => [
        'label'    => 'Budget & Reports & Sharing',
        'icon'     => _ic('<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>'),
        'children' => [
            't471_monthly_budget_tracker' => ['label' => 'Monthly Budget Tracker', 'href' => APP_URL . '/pages/budget/budget.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't485_portfolio_treemap' => ['label' => 'Portfolio Treemap', 'href' => APP_URL . '/pages/portfolio/treemap.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't488_yield_curve' => ['label' => 'Yield Curve', 'href' => APP_URL . '/pages/portfolio/yield_curve.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't406_anonymous_benchmarking' => ['label' => 'Anonymous Benchmarking', 'href' => APP_URL . '/pages/benchmarking/peer_compare.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't503_credit_card_optimizer' => ['label' => 'Credit Card Optimizer', 'href' => APP_URL . '/pages/budget/credit_card_optimizer.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            'th001_daily_financial_journal' => ['label' => 'Daily Financial Journal', 'href' => APP_URL . '/pages/journal/journal.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't443_morning_briefing' => ['label' => 'Morning Briefing', 'href' => APP_URL . '/pages/dashboard/morning_briefing.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't155_child_education_planner' => ['label' => 'Child Education Planner', 'href' => APP_URL . '/pages/goal/education_planner.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't404_smart_alerts_v2' => ['label' => 'Smart Alerts v2', 'href' => APP_URL . '/pages/alerts/smart_alerts.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'tools_profile' => [
        'label'    => 'Tools & Profile',
        'icon'     => _ic('<circle cx="12" cy="7" r="4"/><path d="M5.5 21a6.5 6.5 0 0 1 13 0"/>'),
        'children' => [
            'th002_sip_discipline_score' => ['label' => 'SIP Discipline Score', 'href' => APP_URL . '/pages/tools/sip_discipline.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            'ti001_personal_finance_profile' => ['label' => 'Personal Finance Profile', 'href' => APP_URL . '/pages/profile/finance_profile.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            'ti004_personal_finance_score' => ['label' => 'Personal Finance Score', 'href' => APP_URL . '/pages/profile/finance_score.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            'tj005_annual_financial_review' => ['label' => 'Annual Financial Review', 'href' => APP_URL . '/pages/tools/annual_review.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'extended_investments' => [
        'label'    => 'More Investments',
        'icon'     => _ic('<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>'),
        'children' => [
            't39_ltcg_stcg_tax_report' => ['label' => 'LTCG/STCG Tax Report', 'href' => APP_URL . '/templates/pages/tax_report.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't114_gold_tracker' => ['label' => 'Gold Tracker', 'href' => APP_URL . '/templates/pages/gold.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't116_corporate_bonds_ncds' => ['label' => 'Corporate Bonds / NCDs', 'href' => APP_URL . '/templates/pages/bonds.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't121_international_stocks_lrs' => ['label' => 'International Stocks / LRS', 'href' => APP_URL . '/templates/pages/international.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't145_reality_check' => ['label' => 'Reality Check', 'href' => APP_URL . '/templates/pages/reality_check.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't432_portfolio_p_e' => ['label' => 'Portfolio P/E', 'href' => APP_URL . '/templates/pages/portfolio_pe.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't435_watchlist' => ['label' => 'Watchlist', 'href' => APP_URL . '/templates/pages/watchlist.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't436_stock_sip' => ['label' => 'Stock SIP', 'href' => APP_URL . '/templates/pages/stock_sip.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't38_stocks_screener' => ['label' => 'Stocks Screener', 'href' => APP_URL . '/templates/pages/screener.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't118_rbi_g_secs_t_bills' => ['label' => 'RBI / G-Secs / T-Bills', 'href' => APP_URL . '/templates/pages/rbi_securities.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],

    'data_import_tools' => [
        'label'    => 'Import Tools',
        'icon'     => _ic('<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>'),
        'children' => [
            't302_groww_csv_import' => ['label' => 'Groww CSV / API Import', 'href' => APP_URL . '/templates/pages/groww_import.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't334_bulk_excel_import' => ['label' => 'Bulk Excel Import', 'href' => APP_URL . '/templates/pages/bulk_import.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
            't490_csv_importer_v3' => ['label' => 'CSV Importer v3', 'href' => APP_URL . '/templates/pages/csv_import_v3.php', 'icon' => _ic('<circle cx="12" cy="12" r="3"/>', 14)],
        ],
    ],
];

if (function_exists('is_admin') && is_admin()) {
    $navItems['admin'] = [
        'label' => 'Admin',
        'href'  => APP_URL . '/templates/pages/admin.php',
        'icon'  => _ic('<circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/>'),
    ];
    $navItems['godmode'] = [
        'label' => 'NAV Pipeline',
        'href'  => APP_URL . '/godmode_unified.php',
        'icon'  => _ic('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
    ];
}

$navItems['settings'] = [
    'label' => 'Settings',
    'href'  => APP_URL . '/templates/pages/settings.php',
    'icon'  => _ic('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>'),
];
?>

<div class="sidebar-header">
  <a href="<?= APP_URL ?>/index.php" class="sidebar-brand">
    <svg width="32" height="32" viewBox="0 0 40 40" fill="none">
      <rect width="40" height="40" rx="10" fill="#4f46e5"/>
      <path d="M10 28L18 16L24 22L30 12" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
      <circle cx="30" cy="12" r="2" fill="#a5b4fc"/>
    </svg>
    <span class="sidebar-brand-text"><?= e(APP_NAME) ?></span>
  </a>
  <button class="sidebar-close" onclick="closeSidebar()" aria-label="Close sidebar">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>
</div>

<nav class="sidebar-nav">
  <ul class="nav-list">
    <?php foreach ($navItems as $key => $item): ?>

      <?php if (!empty($item['children'])): ?>
        <?php
          $isGroupActive = ($activeSection === $key)
                        || array_key_exists($activePage ?? '', $item['children']);
        ?>
        <li class="nav-item has-children <?= $isGroupActive ? 'open' : '' ?>">
          <button class="nav-link nav-group-toggle"
                  onclick="toggleNavGroup(this)"
                  data-label="<?= e($item['label']) ?>"
                  aria-expanded="<?= $isGroupActive ? 'true' : 'false' ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <svg class="nav-chevron" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </button>
          <ul class="nav-children">
            <?php foreach ($item['children'] as $childKey => $child): ?>
              <li class="nav-item">
                <a href="<?= e($child['href']) ?>"
                   class="nav-link nav-child-link <?= ($activePage ?? '') === $childKey ? 'active' : '' ?>">
                  <?php if (!empty($child['icon'])): ?>
                    <span class="nav-icon nav-child-icon"><?= $child['icon'] ?></span>
                  <?php endif; ?>
                  <span class="nav-label"><?= e($child['label']) ?></span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>

      <?php else: ?>
        <li class="nav-item">
          <a href="<?= e($item['href']) ?>"
             class="nav-link <?= ($activePage ?? '') === $key ? 'active' : '' ?>"
             data-label="<?= e($item['label']) ?>">
            <span class="nav-icon"><?= $item['icon'] ?></span>
            <span class="nav-label"><?= e($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?>
              <span style="font-size:9px;font-weight:700;background:var(--accent);color:#fff;padding:1px 5px;border-radius:8px;margin-left:4px;letter-spacing:.04em;"><?= e($item['badge']) ?></span>
            <?php endif; ?>
          </a>
        </li>
      <?php endif; ?>

    <?php endforeach; ?>
  </ul>
</nav>

<div class="sidebar-footer">
  <?php
  $navDate = DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key = 'nav_last_updated'");
  ?>
  <div class="nav-status">
    <span class="nav-status-dot <?= $navDate ? 'dot-green' : 'dot-red' ?>"></span>
    <span class="nav-status-text">
      MF NAV: <?= $navDate ? date(DATE_DISPLAY, strtotime($navDate)) : 'Not updated' ?>
    </span>
  </div>
</div>
