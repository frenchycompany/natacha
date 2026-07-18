-- Site settings (key/value pairs)
CREATE TABLE IF NOT EXISTS site_settings (
    setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default start date
INSERT INTO site_settings (setting_key, setting_value) VALUES ('love_start_date', '2024-07-14')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
