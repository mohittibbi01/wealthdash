-- ============================================================
-- GA-55A SYSTEM — FILE 003
-- Salary columns — DYNAMIC
-- Admin yahan se naye earning/deduction columns add kar sakta hai
-- Code change nahi karna padega
-- ============================================================

USE ga55_system;

CREATE TABLE IF NOT EXISTS salary_columns (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    col_key     VARCHAR(50)               NOT NULL UNIQUE,  -- e.g. 'basic_pay', 'nps'
    label       VARCHAR(100)              NOT NULL,          -- e.g. 'Basic Pay', 'NPS'
    col_type    ENUM('earning','deduction') NOT NULL,
    is_active   TINYINT(1)                NOT NULL DEFAULT 1,
    sort_order  INT UNSIGNED              NOT NULL DEFAULT 0,
    created_at  TIMESTAMP                 NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default earnings columns (aapke screenshot ke hisaab se)
INSERT INTO salary_columns (col_key, label, col_type, sort_order) VALUES
('basic_pay',  'Basic Pay',    'earning',   1),
('da',         'DA',           'earning',   2),
('hra',        'HRA',          'earning',   3),
('rop',        'ROP',          'earning',   4),
('bonus',      'Bonus',        'earning',   5),
('pl_arrear',  'PL Arrear',    'earning',   6),
('si_loan',    'SI Loan',      'earning',   7),
('cca',        'CCA',          'earning',   8);

-- Default deduction columns
INSERT INTO salary_columns (col_key, label, col_type, sort_order) VALUES
('si',         'SI',           'deduction', 1),
('si_loan_emi','SI Loan EMI',  'deduction', 2),
('nps',        'NPS',          'deduction', 3),
('gis',        'GIS',          'deduction', 4),
('gis_tax',    'GIS Tax',      'deduction', 5),
('hfrf',       'H.F.R.F.',     'deduction', 6),
('cm_fund',    'C M Fund',     'deduction', 7),
('gpf_2004',   'GPF 2004',     'deduction', 8),
('rghs',       'RGHS',         'deduction', 9),
('itax',       'Itax',         'deduction', 10);
