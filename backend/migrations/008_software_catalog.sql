-- Additive and repeatable. Original scan data and version/edition observations are retained.
CREATE TABLE IF NOT EXISTS inv_software_metadata (
 product_id VARCHAR(128) CHARACTER SET ascii PRIMARY KEY,
 metadata JSON NOT NULL, source_url VARCHAR(512) NOT NULL,
 source_sha256 CHAR(64) CHARACTER SET ascii NOT NULL,
 fetched_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_software_overrides (
 customer_id BIGINT UNSIGNED NOT NULL,
 fingerprint CHAR(64) CHARACTER SET ascii NOT NULL,
 canonical_name VARCHAR(255) NOT NULL, publisher VARCHAR(255) NOT NULL,
 category VARCHAR(32) CHARACTER SET ascii NOT NULL,
 subcategory VARCHAR(64) CHARACTER SET ascii NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL,
 PRIMARY KEY(customer_id,fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_software_catalog_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 customer_id BIGINT UNSIGNED NULL, fingerprint CHAR(64) CHARACTER SET ascii NULL,
 product_id VARCHAR(128) CHARACTER SET ascii NULL, actor_id BIGINT UNSIGNED NOT NULL,
 action VARCHAR(32) CHARACTER SET ascii NOT NULL,
 before_value JSON NULL, after_value JSON NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO inv_settings(name,value) VALUES('software_catalog_schema_version','1');
