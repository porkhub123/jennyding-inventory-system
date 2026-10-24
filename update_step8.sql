-- Step 8 update: run this ONCE on your existing database (after update_step7.sql).
-- Adds Customer Credit (utang) without deleting products, history or users.
USE jennyding_db;

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(150) NULL,
    credit_limit DECIMAL(10,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS credit_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    user_id INT,
    type ENUM('charge','payment') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
