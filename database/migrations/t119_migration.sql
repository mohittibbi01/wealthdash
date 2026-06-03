-- ═══════════════════════════════════════════════════════════════
-- WealthDash — t119: PMS / AIF Tracker
-- Migration: t119_migration.sql
-- ═══════════════════════════════════════════════════════════════

-- ── PMS Holdings ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pms_holdings (
    id                  INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED    NOT NULL,
    portfolio_id        INT UNSIGNED    NOT NULL,
    pms_name            VARCHAR(200)    NOT NULL               COMMENT 'e.g. Marcellus CCP, ASK IEP',
    manager_name        VARCHAR(150)    DEFAULT NULL           COMMENT 'PMS provider / AMC name',
    strategy_name       VARCHAR(200)    DEFAULT NULL           COMMENT 'Portfolio strategy name',
    asset_class         ENUM('PMS','AIF_CAT1','AIF_CAT2','AIF_CAT3') NOT NULL DEFAULT 'PMS',
    aif_category        TINYINT(1)      DEFAULT NULL           COMMENT '1, 2 or 3 for AIF',
    investment_date     DATE            NOT NULL,
    invested_amount     DECIMAL(16,2)   NOT NULL DEFAULT 0.00,
    current_value       DECIMAL(16,2)   DEFAULT NULL,
    gain_loss           DECIMAL(16,2)   GENERATED ALWAYS AS (current_value - invested_amount) STORED,
    gain_loss_pct       DECIMAL(8,4)    GENERATED ALWAYS AS (
                            CASE WHEN invested_amount > 0
                                 THEN ((current_value - invested_amount) / invested_amount) * 100
                                 ELSE NULL END
                        ) STORED,
    xirr                DECIMAL(8,4)    DEFAULT NULL           COMMENT 'Stored XIRR %',
    nav_current         DECIMAL(14,4)   DEFAULT NULL,
    nav_date            DATE            DEFAULT NULL,
    units               DECIMAL(14,4)   DEFAULT NULL,
    folio_number        VARCHAR(60)     DEFAULT NULL,
    lock_in_months      INT UNSIGNED    DEFAULT NULL,
    lock_in_end_date    DATE            DEFAULT NULL,
    management_fee_pct  DECIMAL(5,3)    DEFAULT NULL           COMMENT 'Annual mgmt fee %',
    performance_fee_pct DECIMAL(5,3)    DEFAULT NULL           COMMENT 'Performance fee % (hurdle)',
    hurdle_rate_pct     DECIMAL(5,3)    DEFAULT NULL,
    benchmark           VARCHAR(150)    DEFAULT NULL,
    platform            VARCHAR(100)    DEFAULT NULL           COMMENT 'Zerodha, IIFL, Motilal, etc.',
    notes               TEXT            DEFAULT NULL,
    is_active           TINYINT(1)      NOT NULL DEFAULT 1,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_user          (user_id),
    INDEX idx_portfolio     (portfolio_id),
    INDEX idx_asset_class   (asset_class),
    INDEX idx_active        (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PMS/AIF Transactions ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS pms_transactions (
    id              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    holding_id      INT UNSIGNED    NOT NULL,
    user_id         INT UNSIGNED    NOT NULL,
    portfolio_id    INT UNSIGNED    NOT NULL,
    txn_type        ENUM('investment','withdrawal','dividend','management_fee','performance_fee','nav_update','switch') NOT NULL,
    txn_date        DATE            NOT NULL,
    amount          DECIMAL(16,2)   NOT NULL DEFAULT 0.00,
    nav             DECIMAL(14,4)   DEFAULT NULL,
    units           DECIMAL(14,4)   DEFAULT NULL,
    notes           TEXT            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_holding    (holding_id),
    INDEX idx_user       (user_id),
    INDEX idx_date       (txn_date),
    INDEX idx_type       (txn_type),
    CONSTRAINT fk_pmst_holding FOREIGN KEY (holding_id) REFERENCES pms_holdings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── PMS NAV History ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS pms_nav_history (
    id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    holding_id  INT UNSIGNED    NOT NULL,
    nav_date    DATE            NOT NULL,
    nav         DECIMAL(14,4)   NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_nav (holding_id, nav_date),
    INDEX idx_holding (holding_id),
    INDEX idx_date    (nav_date),
    CONSTRAINT fk_pmsnav_holding FOREIGN KEY (holding_id) REFERENCES pms_holdings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- ROUTER NOTES (Master merge karega — touch mat karo router.php)
-- ═══════════════════════════════════════════════════════════════
-- READ-ONLY actions to add in $csrfExempt array:
--   'pms_list',           // t119 - list all PMS/AIF holdings
--   'pms_detail',         // t119 - single holding detail + txns
--   'pms_summary',        // t119 - portfolio summary stats
--   'pms_txns',           // t119 - transactions list
--   'pms_nav_history',    // t119 - NAV history for chart
-- WRITE actions (NOT in csrfExempt — need CSRF token):
--   'pms_add', 'pms_edit', 'pms_delete',
--   'pms_txn_add', 'pms_txn_delete',
--   'pms_nav_add', 'pms_update_value'
-- Route case block (in switch($action)):
/*
case 'pms_list':
case 'pms_detail':
case 'pms_summary':
case 'pms_txns':
case 'pms_nav_history':
case 'pms_add':
case 'pms_edit':
case 'pms_delete':
case 'pms_txn_add':
case 'pms_txn_delete':
case 'pms_nav_add':
case 'pms_update_value':
    require APP_ROOT . '/api/pms_aif/pms_tracker.php';
    break;
*/
