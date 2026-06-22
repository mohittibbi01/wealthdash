-- ============================================================
-- GA-55A SYSTEM — FILE 006
-- Temp import table — CSV upload ke baad validation yahan hogi
-- Error wali rows yahan rahegi jab tak user fix na kare
-- Fix hone ke baad main tables me jaayegi, yahan se delete hogi
-- ============================================================

USE ga55_system;

CREATE TABLE IF NOT EXISTS temp_import (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key     VARCHAR(64)     NOT NULL,   -- ek upload session ka unique key
    user_id         INT UNSIGNED    NOT NULL,
    row_number      INT UNSIGNED    NOT NULL,   -- CSV ki kaunsi row hai
    raw_data        JSON            NOT NULL,   -- original row ka data JSON me
    bill_no         VARCHAR(30)             DEFAULT NULL,
    bill_date       VARCHAR(20)             DEFAULT NULL,
    tv_no           VARCHAR(30)             DEFAULT NULL,
    tv_date         VARCHAR(20)             DEFAULT NULL,
    fy              VARCHAR(10)             DEFAULT NULL,
    month_no        CHAR(2)                 DEFAULT NULL,
    remark          TEXT                    DEFAULT NULL,
    has_error       TINYINT(1)      NOT NULL DEFAULT 0,
    error_details   JSON                    DEFAULT NULL,  -- kaunsi field me kya error
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_session (session_key),
    INDEX idx_user    (user_id),
    CONSTRAINT fk_temp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Temp column values — dynamic columns ke amounts
CREATE TABLE IF NOT EXISTS temp_import_values (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    temp_id     INT UNSIGNED    NOT NULL,
    col_key     VARCHAR(50)     NOT NULL,
    raw_value   VARCHAR(50)             DEFAULT NULL,   -- user ne jo likha
    clean_value DECIMAL(12,2)           DEFAULT NULL,   -- parsed amount
    has_error   TINYINT(1)      NOT NULL DEFAULT 0,
    error_msg   VARCHAR(200)            DEFAULT NULL,

    CONSTRAINT fk_tiv_temp FOREIGN KEY (temp_id) REFERENCES temp_import(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
