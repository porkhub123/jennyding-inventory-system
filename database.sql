-- Jenny & Ding Sari-Sari Store Inventory Management System
-- Import this in phpMyAdmin (Import tab).
-- It creates a fresh database with all tables and the store's product list.

DROP DATABASE IF EXISTS jennyding_db;
CREATE DATABASE jennyding_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE jennyding_db;

-- Users who can log in (admin = owner, staff = store helper)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
    is_active TINYINT(1) NOT NULL DEFAULT 1,   -- 0 = deactivated or waiting for approval
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Product categories
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
);

-- Products in the store (image = picture file name in uploads/products/)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT,
    name VARCHAR(150) NOT NULL,
    unit VARCHAR(30) DEFAULT 'pc',
    cost_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    selling_price DECIMAL(10,2) NOT NULL DEFAULT 0,
    stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 5,
    image VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Every stock movement:
--   in     = delivery / restock (stock goes up)
--   out    = stock leaving the store, with a reason (Sold, Expired, Damaged, ...)
--   adjust = manual correction from the Edit Product page
-- unit_price = cost price for 'in', selling price for 'out' (saved at that moment)
CREATE TABLE stock_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    user_id INT,
    type ENUM('in','out','adjust') NOT NULL,
    reason VARCHAR(30) NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NULL,
    remarks VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX (type, created_at)
);

-- Customers who buy on credit (utang)
CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NULL,
    address VARCHAR(150) NULL,
    credit_limit DECIMAL(10,2) NULL,             -- optional maximum utang
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Every utang record: charge = bought on credit (balance goes up), payment = paid (balance goes down)
CREATE TABLE credit_transactions (
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

-- Default admin account  ->  username: admin   password: admin123
INSERT INTO users (full_name, username, password, role) VALUES
('Store Owner', 'admin', '$2y$10$lHTFkOzrjUsRRWuf16OBb.yj5ThKngwX0mNfx0xLgxY2KWF.QDfli', 'admin');

-- Categories (from the store's inventory list)
INSERT INTO categories (id, name) VALUES
(1, 'Instant Noodles & Pasta'),
(2, 'Canned Goods (De-Lata)'),
(3, 'Coffee, Milk & Beverages'),
(4, 'Condiments & Cooking Essentials'),
(5, 'Snacks & Sweets (Chichirya)'),
(6, 'Personal Care & Toiletries'),
(7, 'Laundry & Cleaning Supplies'),
(8, 'Miscellaneous & Retail');

-- Products. Prices are estimates only: edit them in the Products page.
INSERT INTO products (category_id, name, unit, cost_price, selling_price, stock, reorder_level) VALUES
-- Instant Noodles & Pasta
(1, 'Lucky Me! Instant Noodles Chicken', 'pack', 9.00, 11.00, 36, 10),
(1, 'Lucky Me! Instant Noodles Beef', 'pack', 9.00, 11.00, 36, 10),
(1, 'Payless Instant Noodles', 'pack', 7.00, 9.00, 30, 10),
(1, 'Homi Instant Noodles', 'pack', 7.00, 9.00, 24, 8),
(1, 'Lucky Me! Pancit Canton Calamansi', 'pack', 14.00, 17.00, 36, 10),
(1, 'Lucky Me! Pancit Canton Chilimansi', 'pack', 14.00, 17.00, 30, 10),
(1, 'Lucky Me! Pancit Canton Sweet & Spicy', 'pack', 14.00, 17.00, 24, 8),
(1, 'Lucky Me! Pancit Canton Hot & Spicy', 'pack', 14.00, 17.00, 4, 8),
(1, 'Nissin Cup Noodles', 'cup', 24.00, 30.00, 12, 4),
(1, 'Lucky Me! Mini Me', 'cup', 15.00, 18.00, 12, 4),
(1, 'Bihon (1/4 kilo)', 'pack', 18.00, 22.00, 10, 3),
(1, 'Misua', 'pack', 12.00, 15.00, 10, 3),
(1, 'Sotanghon', 'pack', 14.00, 18.00, 10, 3),
(1, 'Dried Miki', 'pack', 15.00, 20.00, 8, 3),
-- Canned Goods (De-Lata)
(2, 'Mega Sardines Red 155g', 'can', 22.00, 26.00, 24, 6),
(2, 'Mega Sardines Green 155g', 'can', 22.00, 26.00, 18, 6),
(2, 'Ligo Sardines 155g', 'can', 23.00, 27.00, 18, 6),
(2, '555 Sardines 155g', 'can', 20.00, 24.00, 18, 6),
(2, 'Young''s Town Sardines 155g', 'can', 18.00, 22.00, 12, 4),
(2, 'Argentina Corned Beef 150g', 'can', 35.00, 42.00, 15, 5),
(2, 'CDO Corned Beef 150g', 'can', 33.00, 40.00, 12, 4),
(2, 'Highlands Corned Beef 150g', 'can', 45.00, 55.00, 8, 3),
(2, 'Purefoods Corned Beef 150g', 'can', 50.00, 60.00, 6, 3),
(2, 'Century Tuna 155g', 'can', 30.00, 36.00, 12, 4),
(2, '555 Tuna 155g', 'can', 22.00, 27.00, 12, 4),
(2, 'San Marino Corned Tuna 150g', 'can', 26.00, 32.00, 10, 4),
(2, 'CDO Meat Loaf 150g', 'can', 22.00, 27.00, 12, 4),
(2, 'Argentina Meat Loaf 150g', 'can', 24.00, 29.00, 3, 4),
(2, 'Purefoods Luncheon Meat 165g', 'can', 45.00, 55.00, 6, 3),
-- Coffee, Milk & Beverages
(3, 'Kopiko Blanca', 'sachet', 9.00, 11.00, 40, 10),
(3, 'Kopiko Brown', 'sachet', 9.00, 11.00, 40, 10),
(3, 'Kopiko Astig 3-in-1', 'sachet', 8.00, 10.00, 30, 10),
(3, 'Nescafe Original 3-in-1', 'sachet', 9.00, 11.00, 40, 10),
(3, 'Nescafe Creamy White', 'sachet', 9.00, 11.00, 30, 10),
(3, 'Great Taste White', 'sachet', 9.00, 11.00, 40, 10),
(3, 'Milo', 'sachet', 10.00, 12.00, 30, 10),
(3, 'Bear Brand', 'sachet', 11.00, 13.00, 30, 10),
(3, 'Birch Tree', 'sachet', 11.00, 13.00, 20, 6),
(3, 'Alaska Condensada', 'can', 38.00, 45.00, 8, 3),
(3, 'Milkmaid Condensed Milk', 'can', 45.00, 52.00, 6, 3),
(3, 'Carnation Evaporated Milk 370ml', 'can', 35.00, 42.00, 8, 3),
(3, 'Kremdensada', 'can', 30.00, 36.00, 6, 3),
(3, 'Tang Powdered Juice', 'sachet', 12.00, 15.00, 24, 6),
(3, 'Eight O''Clock Powdered Juice', 'sachet', 10.00, 13.00, 24, 6),
(3, 'Coca-Cola Mismo 290ml', 'bottle', 15.00, 20.00, 24, 6),
(3, 'Sprite Mismo 290ml', 'bottle', 15.00, 20.00, 12, 6),
(3, 'Royal Mismo 290ml', 'bottle', 15.00, 20.00, 2, 6),
(3, 'C2 Green Tea 230ml', 'bottle', 20.00, 25.00, 12, 4),
(3, 'Chuckie 110ml', 'pc', 12.00, 15.00, 12, 4),
(3, 'Yakult', 'bottle', 11.00, 14.00, 10, 5),
-- Condiments & Cooking Essentials
(4, 'Silver Swan Soy Sauce', 'pouch', 12.00, 15.00, 12, 4),
(4, 'Datu Puti Soy Sauce', 'pouch', 12.00, 15.00, 12, 4),
(4, 'Datu Puti Vinegar', 'pouch', 11.00, 14.00, 12, 4),
(4, 'Datu Puti Patis', 'bottle', 15.00, 19.00, 8, 3),
(4, 'Tenten Patis', 'bottle', 14.00, 18.00, 6, 3),
(4, 'Lorins Patis', 'bottle', 18.00, 22.00, 6, 3),
(4, 'UFC Banana Catsup 320g', 'pouch', 28.00, 34.00, 8, 3),
(4, 'Papa Ketchup 320g', 'pouch', 22.00, 28.00, 8, 3),
(4, 'Mang Tomas Lechon Sauce 330g', 'bottle', 45.00, 55.00, 5, 2),
(4, 'Magic Sarap', 'sachet', 4.00, 5.00, 48, 12),
(4, 'Knorr Pork Cubes', 'pc', 6.00, 8.00, 30, 10),
(4, 'Knorr Beef Cubes', 'pc', 6.00, 8.00, 30, 10),
(4, 'Knorr Chicken Cubes', 'pc', 6.00, 8.00, 30, 10),
(4, 'Ajinomoto Vetsin', 'sachet', 4.00, 5.00, 30, 10),
(4, 'Cooking Oil / Mantika 250ml', 'bottle', 28.00, 35.00, 10, 3),
(4, 'White Sugar 1/4 kilo', 'pack', 22.00, 25.00, 20, 5),
(4, 'Brown Sugar 1/4 kilo', 'pack', 20.00, 23.00, 20, 5),
(4, 'Iodized Salt', 'pack', 8.00, 10.00, 15, 5),
-- Snacks & Sweets (Chichirya)
(5, 'Piattos', 'pack', 16.00, 20.00, 24, 6),
(5, 'Nova', 'pack', 16.00, 20.00, 18, 6),
(5, 'Chippy', 'pack', 9.00, 12.00, 24, 6),
(5, 'Clover Chips', 'pack', 9.00, 12.00, 18, 6),
(5, 'Mr. Chips', 'pack', 9.00, 12.00, 18, 6),
(5, 'Oishi Prawn Crackers', 'pack', 8.00, 10.00, 24, 6),
(5, 'Oishi Cracklings', 'pack', 9.00, 12.00, 18, 6),
(5, 'Marty''s Cracklin''', 'pack', 9.00, 12.00, 18, 6),
(5, 'Ding Dong', 'pack', 12.00, 15.00, 18, 6),
(5, 'SkyFlakes', 'pack', 7.00, 9.00, 40, 10),
(5, 'Rebisco Crackers', 'pack', 7.00, 9.00, 30, 10),
(5, 'Fita', 'pack', 7.00, 9.00, 30, 10),
(5, 'Magic Flakes', 'pack', 7.00, 9.00, 30, 10),
(5, 'Fudgee Barr', 'pc', 8.00, 10.00, 24, 6),
(5, 'Lemon Square Cheese Cupcake', 'pc', 8.00, 10.00, 24, 6),
(5, 'Regent Cakes', 'pc', 8.00, 10.00, 18, 6),
(5, 'Maxx Candy', 'pc', 0.80, 1.00, 100, 20),
(5, 'Snow Bear Candy', 'pc', 0.80, 1.00, 100, 20),
(5, 'V-Fresh Candy', 'pc', 0.80, 1.00, 100, 20),
(5, 'Mentos Roll', 'roll', 20.00, 25.00, 10, 3),
(5, 'X.O. Candy', 'pc', 0.80, 1.00, 100, 20),
(5, 'Nips', 'pack', 8.00, 10.00, 20, 6),
-- Personal Care & Toiletries
(6, 'Sunsilk Shampoo', 'sachet', 6.00, 8.00, 36, 10),
(6, 'Palmolive Shampoo', 'sachet', 6.00, 8.00, 36, 10),
(6, 'Cream Silk Conditioner', 'sachet', 6.00, 8.00, 36, 10),
(6, 'Head & Shoulders Shampoo', 'sachet', 7.00, 9.00, 24, 8),
(6, 'Pantene Shampoo', 'sachet', 7.00, 9.00, 24, 8),
(6, 'Keratin Plus Shampoo', 'sachet', 6.00, 8.00, 24, 8),
(6, 'Safeguard Bar Soap', 'pc', 25.00, 30.00, 8, 3),
(6, 'Bioderm Bar Soap', 'pc', 20.00, 25.00, 8, 3),
(6, 'Silka Papaya Soap', 'pc', 22.00, 27.00, 8, 3),
(6, 'Palmolive Bar Soap', 'pc', 22.00, 27.00, 8, 3),
(6, 'Colgate Toothpaste', 'sachet', 8.00, 10.00, 24, 6),
(6, 'CloseUp Toothpaste', 'sachet', 8.00, 10.00, 24, 6),
(6, 'Hapee Toothpaste', 'sachet', 6.00, 8.00, 24, 6),
(6, 'Modess Napkin', 'pc', 6.00, 8.00, 20, 6),
(6, 'Whisper Napkin', 'pc', 6.00, 8.00, 20, 6),
(6, 'Green Cross Alcohol 250ml', 'bottle', 55.00, 65.00, 5, 2),
(6, 'Medi-Plast Band-Aid', 'pc', 2.00, 3.00, 50, 10),
-- Laundry & Cleaning Supplies
(7, 'Surf Powder', 'sachet', 7.00, 9.00, 40, 10),
(7, 'Tide Powder', 'sachet', 9.00, 11.00, 30, 10),
(7, 'Ariel Powder', 'sachet', 10.00, 12.00, 30, 10),
(7, 'Breeze Powder', 'sachet', 9.00, 11.00, 30, 10),
(7, 'Champion Detergent Bar', 'pc', 15.00, 18.00, 12, 4),
(7, 'Downy Fabric Conditioner', 'sachet', 7.00, 9.00, 30, 10),
(7, 'Surf Fabcon', 'sachet', 6.00, 8.00, 30, 10),
(7, 'Del Fabric Conditioner', 'sachet', 5.00, 7.00, 30, 10),
(7, 'Joy Dishwashing Liquid', 'sachet', 6.00, 8.00, 30, 10),
(7, 'Zonrox Bleach 250ml', 'bottle', 20.00, 25.00, 10, 3),
(7, 'Clorox Bleach 250ml', 'bottle', 30.00, 38.00, 6, 3),
(7, 'Tiger Katol', 'box', 15.00, 20.00, 12, 4),
(7, 'Baygon Katol', 'box', 25.00, 30.00, 8, 3),
(7, 'Elephant Katol', 'box', 15.00, 20.00, 8, 3),
-- Miscellaneous & Retail
(8, 'Marlboro Red (per stick)', 'stick', 8.00, 10.00, 100, 20),
(8, 'Marlboro Gold (per stick)', 'stick', 8.00, 10.00, 100, 20),
(8, 'Fortune (per stick)', 'stick', 6.00, 8.00, 100, 20),
(8, 'Winston (per stick)', 'stick', 7.00, 9.00, 100, 20),
(8, 'Mighty (per stick)', 'stick', 5.00, 7.00, 100, 20),
(8, 'Camel (per stick)', 'stick', 8.00, 10.00, 60, 20),
(8, 'Red Horse Beer 500ml', 'bottle', 60.00, 70.00, 24, 6),
(8, 'San Miguel Pale Pilsen 320ml', 'bottle', 50.00, 60.00, 24, 6),
(8, 'San Miguel Light 320ml', 'bottle', 52.00, 62.00, 12, 6),
(8, 'Emperador Light 350ml', 'bottle', 85.00, 100.00, 6, 2),
(8, 'GSM Blue 350ml', 'bottle', 75.00, 90.00, 6, 2),
(8, 'Tanduay Rhum 375ml', 'bottle', 75.00, 90.00, 6, 2),
(8, 'Fresh Eggs (Itlog)', 'pc', 7.00, 9.00, 60, 12),
(8, 'Posporo (Matchsticks)', 'box', 1.50, 2.00, 30, 10),
(8, 'Lighter', 'pc', 15.00, 20.00, 12, 4),
(8, 'Ice Candy', 'pc', 4.00, 5.00, 20, 6);

-- Record the starting stock of each product in the history
INSERT INTO stock_logs (product_id, user_id, type, quantity, unit_price, remarks)
SELECT id, 1, 'in', stock, cost_price, 'Initial stock' FROM products;
