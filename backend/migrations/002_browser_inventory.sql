-- Additive: browser credentials/data are isolated from hardware inventory.
CREATE TABLE IF NOT EXISTS inv_browser_profiles (
 id CHAR(32) PRIMARY KEY, agent_id CHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL,
 label VARCHAR(120) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, last_seen DATETIME(6) NULL,
 last_revision BIGINT UNSIGNED NOT NULL DEFAULT 0, payload_hash CHAR(64) NULL,
 rate_window DATETIME(6) NULL, rate_count INT NOT NULL DEFAULT 0,
 KEY browser_agent (agent_id), FOREIGN KEY (agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_browser_daily (
 profile_id CHAR(32) NOT NULL, day DATE NOT NULL, domain VARCHAR(253) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 category VARCHAR(32) NOT NULL, visits INT UNSIGNED NOT NULL,
 PRIMARY KEY(profile_id,day,domain), KEY browser_day(day),
 FOREIGN KEY(profile_id) REFERENCES inv_browser_profiles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO inv_settings(name,value) VALUES ('web_enabled','0'),('web_schema_version','1');
