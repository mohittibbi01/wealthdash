-- ============================================================
-- GA-55A SYSTEM — FILE 002
-- Employees table
-- Har user apna ek record yahan hoga
-- ============================================================

USE ga55_system;

CREATE TABLE IF NOT EXISTS employees (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED        NOT NULL UNIQUE,   -- ek user = ek employee
    emp_code        VARCHAR(30)                 DEFAULT NULL,
    name            VARCHAR(150)        NOT NULL,
    designation     VARCHAR(100)                DEFAULT NULL,
    department      VARCHAR(100)                DEFAULT NULL,
    ddo_name        VARCHAR(150)                DEFAULT NULL,
    ddo_code        VARCHAR(30)                 DEFAULT NULL,
    gpf_no          VARCHAR(50)                 DEFAULT NULL,
    pan_no          VARCHAR(10)                 DEFAULT NULL,
    rghs_no         VARCHAR(50)                 DEFAULT NULL,
    bank_name       VARCHAR(100)                DEFAULT NULL,
    bank_account    VARCHAR(30)                 DEFAULT NULL,
    bank_ifsc       VARCHAR(15)                 DEFAULT NULL,
    mobile          VARCHAR(15)                 DEFAULT NULL,
    email           VARCHAR(100)                DEFAULT NULL,
    is_active       TINYINT(1)          NOT NULL DEFAULT 1,
    created_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_emp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
