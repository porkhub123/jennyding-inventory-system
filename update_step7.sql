-- Step 7 update: run this ONCE if you already imported database.sql before Step 7.
-- It adds user status and last login without deleting your products or history.
USE jennyding_db;

ALTER TABLE users
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN last_login DATETIME NULL AFTER is_active;
