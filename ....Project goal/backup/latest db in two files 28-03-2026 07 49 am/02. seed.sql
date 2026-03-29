-- ============================================================
-- WealthDash — Seed Data
-- Version: 2.0 (March 2026)
-- ============================================================
-- INSTALL KAISE KARO:
--   schema_complete.sql ke BAAD ye file import karo
-- ============================================================
-- Ye file include karti hai:
--   • fund_houses    — 36 major Indian AMCs
--   • nps_schemes    — 34 PFM schemes (SBI, LIC, UTI, HDFC, ICICI, Kotak, ABSL, Axis, DSP, Tata)
--   • stock_master   — 25 NSE + 4 BSE blue-chip stocks
--   • app_settings   — Default settings (NAV, NPS, Peak NAV, app config)
-- ============================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- ============================================================
-- FUND HOUSES (36 major AMCs)
-- ============================================================
INSERT IGNORE INTO `fund_houses` (`name`, `short_name`) VALUES
('Aditya Birla Sun Life Mutual Fund',   'ABSL MF'),
('Axis Mutual Fund',                    'Axis MF'),
('Bandhan Mutual Fund',                 'Bandhan MF'),
('Baroda BNP Paribas Mutual Fund',      'Baroda BNP MF'),
('Canara Robeco Mutual Fund',           'Canara Robeco MF'),
('DSP Mutual Fund',                     'DSP MF'),
('Edelweiss Mutual Fund',               'Edelweiss MF'),
('Franklin Templeton Mutual Fund',      'Franklin MF'),
('HDFC Mutual Fund',                    'HDFC MF'),
('HSBC Mutual Fund',                    'HSBC MF'),
('ICICI Prudential Mutual Fund',        'ICICI Pru MF'),
('IDFC Mutual Fund',                    'IDFC MF'),
('IL&FS Mutual Fund',                   'ILFS MF'),
('Invesco Mutual Fund',                 'Invesco MF'),
('ITI Mutual Fund',                     'ITI MF'),
('Kotak Mahindra Mutual Fund',          'Kotak MF'),
('L&T Mutual Fund',                     'L&T MF'),
('LIC Mutual Fund',                     'LIC MF'),
('Mahindra Manulife Mutual Fund',       'Mahindra MF'),
('Mirae Asset Mutual Fund',             'Mirae MF'),
('Motilal Oswal Mutual Fund',           'Motilal MF'),
('Navi Mutual Fund',                    'Navi MF'),
('Nippon India Mutual Fund',            'Nippon MF'),
('PGIM India Mutual Fund',              'PGIM MF'),
('PPFAS Mutual Fund',                   'PPFAS MF'),
('Quantum Mutual Fund',                 'Quantum MF'),
('Quant Mutual Fund',                   'Quant MF'),
('SBI Mutual Fund',                     'SBI MF'),
('Shriram Mutual Fund',                 'Shriram MF'),
('Sundaram Mutual Fund',                'Sundaram MF'),
('Tata Mutual Fund',                    'Tata MF'),
('Taurus Mutual Fund',                  'Taurus MF'),
('Union Mutual Fund',                   'Union MF'),
('UTI Mutual Fund',                     'UTI MF'),
('WhiteOak Capital Mutual Fund',        'WhiteOak MF'),
('Zerodha Mutual Fund',                 'Zerodha MF');

-- ============================================================
-- NPS SCHEMES (34 schemes across 10 PFMs)
-- ============================================================
INSERT IGNORE INTO `nps_schemes` (`pfm_name`, `scheme_name`, `scheme_code`, `tier`, `asset_class`) VALUES
-- SBI Pension Funds
('SBI Pension Funds', 'SBI Pension Fund - Scheme E - Tier I',  'SBI_E_T1',  'tier1', 'E'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme C - Tier I',  'SBI_C_T1',  'tier1', 'C'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme G - Tier I',  'SBI_G_T1',  'tier1', 'G'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme A - Tier I',  'SBI_A_T1',  'tier1', 'A'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme E - Tier II', 'SBI_E_T2',  'tier2', 'E'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme C - Tier II', 'SBI_C_T2',  'tier2', 'C'),
('SBI Pension Funds', 'SBI Pension Fund - Scheme G - Tier II', 'SBI_G_T2',  'tier2', 'G'),
-- LIC Pension Fund
('LIC Pension Fund', 'LIC Pension Fund - Scheme E - Tier I',   'LIC_E_T1',  'tier1', 'E'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme C - Tier I',   'LIC_C_T1',  'tier1', 'C'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme G - Tier I',   'LIC_G_T1',  'tier1', 'G'),
('LIC Pension Fund', 'LIC Pension Fund - Scheme E - Tier II',  'LIC_E_T2',  'tier2', 'E'),
-- UTI Retirement Solutions
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme E - Tier I',  'UTI_E_T1', 'tier1', 'E'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme C - Tier I',  'UTI_C_T1', 'tier1', 'C'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme G - Tier I',  'UTI_G_T1', 'tier1', 'G'),
('UTI Retirement Solutions', 'UTI Retirement Solutions - Scheme E - Tier II', 'UTI_E_T2', 'tier2', 'E'),
-- HDFC Pension Fund
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme E - Tier I', 'HDFC_E_T1', 'tier1', 'E'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme C - Tier I', 'HDFC_C_T1', 'tier1', 'C'),
('HDFC Pension Fund', 'HDFC Pension Fund - Scheme G - Tier I', 'HDFC_G_T1', 'tier1', 'G'),
-- ICICI Prudential Pension Fund
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme E - Tier I', 'ICICI_E_T1', 'tier1', 'E'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme C - Tier I', 'ICICI_C_T1', 'tier1', 'C'),
('ICICI Prudential Pension Fund', 'ICICI Pru Pension Fund - Scheme G - Tier I', 'ICICI_G_T1', 'tier1', 'G'),
-- Kotak Pension Fund
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme E - Tier I', 'KOTAK_E_T1', 'tier1', 'E'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme C - Tier I', 'KOTAK_C_T1', 'tier1', 'C'),
('Kotak Pension Fund', 'Kotak Pension Fund - Scheme G - Tier I', 'KOTAK_G_T1', 'tier1', 'G'),
-- Aditya Birla Sun Life Pension Fund
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme E - Tier I', 'ABSL_E_T1', 'tier1', 'E'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme C - Tier I', 'ABSL_C_T1', 'tier1', 'C'),
('Aditya Birla Sun Life Pension Fund', 'ABSL Pension Fund - Scheme G - Tier I', 'ABSL_G_T1', 'tier1', 'G'),
-- Axis Pension Fund
('Axis Pension Fund', 'Axis Pension Fund - Scheme E - Tier I', 'AXIS_E_T1', 'tier1', 'E'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme C - Tier I', 'AXIS_C_T1', 'tier1', 'C'),
('Axis Pension Fund', 'Axis Pension Fund - Scheme G - Tier I', 'AXIS_G_T1', 'tier1', 'G'),
-- DSP Pension Fund
('DSP Pension Fund', 'DSP Pension Fund - Scheme E - Tier I', 'DSP_E_T1', 'tier1', 'E'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme C - Tier I', 'DSP_C_T1', 'tier1', 'C'),
('DSP Pension Fund', 'DSP Pension Fund - Scheme G - Tier I', 'DSP_G_T1', 'tier1', 'G'),
-- Tata Pension Fund
('Tata Pension Fund', 'Tata Pension Fund - Scheme E - Tier I', 'TATA_E_T1', 'tier1', 'E'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme C - Tier I', 'TATA_C_T1', 'tier1', 'C'),
('Tata Pension Fund', 'Tata Pension Fund - Scheme G - Tier I', 'TATA_G_T1', 'tier1', 'G');

-- ============================================================
-- STOCK MASTER — NSE Blue Chips (25 stocks)
-- ============================================================
INSERT IGNORE INTO `stock_master` (`exchange`, `symbol`, `company_name`, `isin`, `sector`) VALUES
('NSE', 'RELIANCE',    'Reliance Industries Ltd',             'INE002A01018', 'Energy'),
('NSE', 'TCS',         'Tata Consultancy Services Ltd',       'INE467B01029', 'IT'),
('NSE', 'HDFCBANK',    'HDFC Bank Ltd',                       'INE040A01034', 'Banking'),
('NSE', 'INFY',        'Infosys Ltd',                         'INE009A01021', 'IT'),
('NSE', 'ICICIBANK',   'ICICI Bank Ltd',                      'INE090A01021', 'Banking'),
('NSE', 'HINDUNILVR',  'Hindustan Unilever Ltd',              'INE030A01027', 'FMCG'),
('NSE', 'SBIN',        'State Bank of India',                 'INE062A01020', 'Banking'),
('NSE', 'BHARTIARTL',  'Bharti Airtel Ltd',                   'INE397D01024', 'Telecom'),
('NSE', 'ITC',         'ITC Ltd',                             'INE154A01025', 'FMCG'),
('NSE', 'KOTAKBANK',   'Kotak Mahindra Bank Ltd',             'INE237A01028', 'Banking'),
('NSE', 'LT',          'Larsen & Toubro Ltd',                 'INE018A01030', 'Infrastructure'),
('NSE', 'AXISBANK',    'Axis Bank Ltd',                       'INE238A01034', 'Banking'),
('NSE', 'WIPRO',       'Wipro Ltd',                           'INE075A01022', 'IT'),
('NSE', 'BAJFINANCE',  'Bajaj Finance Ltd',                   'INE296A01024', 'NBFC'),
('NSE', 'SUNPHARMA',   'Sun Pharmaceutical Industries',       'INE044A01036', 'Pharma'),
('NSE', 'TITAN',       'Titan Company Ltd',                   'INE280A01028', 'Consumer'),
('NSE', 'HCLTECH',     'HCL Technologies Ltd',                'INE860A01027', 'IT'),
('NSE', 'ASIANPAINT',  'Asian Paints Ltd',                    'INE021A01026', 'Consumer'),
('NSE', 'MARUTI',      'Maruti Suzuki India Ltd',             'INE585B01010', 'Auto'),
('NSE', 'NESTLEIND',   'Nestle India Ltd',                    'INE239A01016', 'FMCG'),
('NSE', 'ULTRACEMCO',  'UltraTech Cement Ltd',                'INE481G01011', 'Cement'),
('NSE', 'POWERGRID',   'Power Grid Corporation of India',     'INE752E01010', 'Utilities'),
('NSE', 'NTPC',        'NTPC Ltd',                            'INE733E01010', 'Utilities'),
('NSE', 'ONGC',        'Oil and Natural Gas Corporation',     'INE213A01029', 'Energy'),
('NSE', 'BAJAJFINSV',  'Bajaj Finserv Ltd',                   'INE918I01026', 'Financial Services');

-- BSE equivalents (4 most traded)
INSERT IGNORE INTO `stock_master` (`exchange`, `symbol`, `company_name`, `isin`, `sector`) VALUES
('BSE', 'RELIANCE', 'Reliance Industries Ltd',         'INE002A01018', 'Energy'),
('BSE', 'TCS',      'Tata Consultancy Services Ltd',   'INE467B01029', 'IT'),
('BSE', 'HDFCBANK', 'HDFC Bank Ltd',                   'INE040A01034', 'Banking'),
('BSE', 'INFY',     'Infosys Ltd',                     'INE009A01021', 'IT');

-- ============================================================
-- APP SETTINGS — Default values
-- ============================================================
INSERT IGNORE INTO `app_settings` (`setting_key`, `setting_val`) VALUES
-- App config
('app_name',                   'WealthDash'),
('app_version',                '2.0.0'),
-- NAV settings
('nav_last_updated',            NULL),
('nav_update_source',          'amfi'),
-- NPS NAV settings (Migration 014)
('nps_nav_auto_update',        '1'),
('nps_nav_source',             'pfrda_api'),
('nps_historical_years',       '5'),
('nps_nav_last_run',            NULL),
('nps_nav_last_status',        'never_run'),
-- Peak NAV tracker
('peak_nav_batch_date',        '2001-01-01'),
('peak_nav_status',            'idle'),
('peak_nav_stop',              '0'),
-- NAV history downloader
('nav_history_status',         'idle'),
('nav_dl_stop',                '0'),
('nav_history_last_run',        NULL),
('nav_history_from_date',      '2000-01-01'),
('nav_history_current_batch',   NULL),
-- SIP reminders
('sip_reminder_enabled',       '1'),
('sip_reminder_days_before',   '2'),
-- Goal planner
('goal_default_return_pct',    '12'),
-- Stock prices
('stocks_last_updated',         NULL),
-- Data import tracking
('exit_load_last_updated',      NULL),
('ter_last_updated',            NULL),
('last_recalc_holdings',        NULL);

COMMIT;
