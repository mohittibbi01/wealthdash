-- ============================================================
-- GA-55A SYSTEM — FILE 005
-- Bill values — har column ka amount yahan store hoga
-- EAV pattern: naya column add karo → yahan automatically save hoga
-- Code change nahi karna padega
-- ============================================================

USE ga55_system;

CREATE TABLE IF NOT EXISTS bill_values (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bill_id    INT UNSIGNED    NOT NULL,
    col_id     INT UNSIGNED    NOT NULL,
    amount     DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

    CONSTRAINT uq_bill_col UNIQUE (bill_id, col_id),
    CONSTRAINT fk_bv_bill  FOREIGN KEY (bill_id) REFERENCES salary_bills(id) ON DELETE CASCADE,
    CONSTRAINT fk_bv_col   FOREIGN KEY (col_id)  REFERENCES salary_columns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
