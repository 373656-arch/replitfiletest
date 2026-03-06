CREATE TABLE IF NOT EXISTS conversions (
    conversion_id INT AUTO_INCREMENT PRIMARY KEY,
    click_id INT NOT NULL,
    part_id INT NOT NULL,
    commission_amount DECIMAL(10, 2) NOT NULL,
    conversion_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
