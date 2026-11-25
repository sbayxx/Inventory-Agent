-- Independent, additive V3. Existing inventory and ITSM tables are preserved.
CREATE TABLE IF NOT EXISTS inv_v3_devices (
 agent_id CHAR(32) PRIMARY KEY,
 updates_enabled TINYINT NOT NULL DEFAULT 0,commands_enabled TINYINT NOT NULL DEFAULT 1,
 teamviewer_id VARCHAR(16) NOT NULL DEFAULT '',vnc_host VARCHAR(255) NOT NULL DEFAULT '',vnc_port INT UNSIGNED NOT NULL DEFAULT 5900,
 last_control_at DATETIME NULL,reported_version VARCHAR(64) NULL,
 FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v3_jobs (
 id CHAR(32) PRIMARY KEY,agent_id CHAR(32) NOT NULL,
 request_key CHAR(64) NOT NULL UNIQUE,kind VARCHAR(32) NOT NULL,state VARCHAR(24) NOT NULL,
 created_by BIGINT UNSIGNED NOT NULL,created_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,
 lease_hash CHAR(64) NULL,lease_until DATETIME NULL,attempts INT NOT NULL DEFAULT 0,
 completed_at DATETIME NULL,result JSON NULL,
 KEY v3_job_agent(agent_id,state,created_at),FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v3_tokens (
 id CHAR(32) PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,label VARCHAR(120) NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,created_by BIGINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,expires_at DATETIME NOT NULL,revoked_at DATETIME NULL,last_used_at DATETIME NULL,
 rate_count INT UNSIGNED NOT NULL DEFAULT 0,rate_window DATETIME NULL,
 KEY v3_token_customer(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v3_releases (
 id CHAR(32) PRIMARY KEY,version VARCHAR(32) NOT NULL,platform VARCHAR(16) NOT NULL,architecture VARCHAR(16) NOT NULL,
 manifest MEDIUMTEXT NOT NULL,signature VARCHAR(128) NOT NULL,artifact VARCHAR(100) NOT NULL,
 enabled TINYINT NOT NULL DEFAULT 0,created_at DATETIME NOT NULL,
 UNIQUE KEY v3_release_version(version,platform,architecture)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v3_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NULL,agent_id CHAR(32) NULL,
 action VARCHAR(48) NOT NULL,details JSON NOT NULL,created_at DATETIME NOT NULL,
 KEY v3_audit_customer(customer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO inv_settings(name,value) VALUES
 ('v3_schema_version','1'),('v3_enabled','0'),('v3_updates','1'),('v3_commands','1'),('v3_integrations','1'),('v3_remote','1');
