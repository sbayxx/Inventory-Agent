-- Additive, repeatable. Public enrollment remains disabled until explicitly enabled.
CREATE TABLE IF NOT EXISTS inv_device_keys (
 agent_id CHAR(32) PRIMARY KEY, public_key VARCHAR(512) NOT NULL,
 hostname VARCHAR(255) NOT NULL, platform VARCHAR(16) NOT NULL,
 state VARCHAR(16) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL,
 expires_at DATETIME NOT NULL, approved_by BIGINT UNSIGNED NULL,
 approved_at DATETIME NULL, revoked_at DATETIME NULL,
 KEY enrollment_queue(state,expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_device_nonces (
 agent_id CHAR(32) NOT NULL, nonce CHAR(32) NOT NULL, expires_at BIGINT NOT NULL,
 PRIMARY KEY(agent_id,nonce), KEY nonce_expiry(expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
CREATE TABLE IF NOT EXISTS inv_enrollment_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, agent_id CHAR(32) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL, customer_id BIGINT UNSIGNED NULL,
 action VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO inv_settings(name,value) VALUES('enrollment_enabled','0'),('enrollment_schema_version','1');
