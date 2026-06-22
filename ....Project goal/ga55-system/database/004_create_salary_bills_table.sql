-- ============================================================
-- GA-55A SYSTEM — FILE 004
-- Salary bills — main entry table
-- UNIQUE: ek user ka ek hi bill_no + tv_no combination
-- Doosre user ka same bill_no + tv_no allowed hai
-- ============================================================

USE ga55_system;

CREATE TABLE IF NOT EXISTS salary_bills (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED    NOT NULL,
    bill_no          VARCHAR(30)     NOT NULL,
    bill_date        DATE            NOT NULL,
    tv_no            VARCHAR(30)     NOT NULL,
    tv_date          DATE            NOT NULL,
    fy               VARCHAR(10)     NOT NULL,   -- e.g. '2025-26'
    month_no         CHAR(2)         NOT NULL,   -- '01' to '12'
    gross_amount     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    total_deduction  DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    net_payable      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    remark           TEXT                    DEFAULT NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- KEY RULE: user_id + bill_no + tv_no must be unique
    CONSTRAINT uq_user_bill_tv UNIQUE (user_id, bill_no, tv_no),

    CONSTRAINT fk_bill_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
