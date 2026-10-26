CREATE DATABASE IF NOT EXISTS practicum4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE practicum4;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    sku VARCHAR(20) NOT NULL UNIQUE,
    stock INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO products (name, price, sku, stock) VALUES
    ('Ceremonial Matcha Kyoto', 899.00, 'MAT-003', 14),
    ('Matcha Latte Blend 150g', 489.00, 'MAT-004', 0),
    ('Whey Protein Vanilla 900g', 1299.00, 'SUP-045', 0),
    ('Collagen Peptides Unflavored', 1099.00, 'SUP-046', 3),
    ('Seamless Leggings Flow', 1799.00, 'APP-021', 9),
    ('Resistance Bands Set', 699.00, 'GEAR-014', 18);

-- Практикум №7, крок 4.
-- Індекс на price — для фільтра за діапазоном ціни (GET /api.php?resource=products&price_min=..&price_max=..).
-- Індекс на sku НЕ створюємо: sku уже має UNIQUE, а UNIQUE в MySQL/MariaDB сам створює індекс,
-- тож окремий CREATE INDEX дублював би його і не змінював план EXPLAIN.
CREATE INDEX idx_products_price ON products (price);
