-- Request budgets are separate from inventories and historical scans. No existing rows are changed.
CREATE TABLE IF NOT EXISTS inv_request_limits (
 bucket_key CHAR(64) PRIMARY KEY,window_start BIGINT UNSIGNED NOT NULL,
 request_count BIGINT UNSIGNED NOT NULL,expires_at BIGINT UNSIGNED NOT NULL,
 KEY request_limit_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
INSERT IGNORE INTO inv_settings(name,value) VALUES('security_schema_version','1');
