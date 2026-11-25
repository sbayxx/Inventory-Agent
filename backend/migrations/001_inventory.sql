-- Additive migration. Run once against the dedicated inventory database.
CREATE TABLE inv_settings (name VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO inv_settings VALUES ('schema_version','1'),('enabled','0');
CREATE TABLE inv_agents (
 id CHAR(32) PRIMARY KEY, token_hash CHAR(64) NOT NULL, customer_id BIGINT UNSIGNED NOT NULL,
 label VARCHAR(255) NOT NULL, enabled TINYINT NOT NULL DEFAULT 1,
 created_at DATETIME(6) NOT NULL, last_seen DATETIME(6) NULL,
 rate_window DATETIME(6) NULL, rate_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_computers (
 agent_id CHAR(32) PRIMARY KEY, hostname VARCHAR(255) NOT NULL,
 os VARCHAR(32) NOT NULL, agent_version VARCHAR(64) NOT NULL,
 scanned_at DATETIME(6) NOT NULL, snapshot JSON NOT NULL,
 FOREIGN KEY (agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_scan_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, agent_id CHAR(32) NOT NULL,
 scan_id CHAR(32) NOT NULL, scanned_at DATETIME(6) NOT NULL,
 received_at DATETIME(6) NOT NULL, payload_hash CHAR(64) NOT NULL, snapshot JSON NOT NULL,
 UNIQUE KEY agent_scan (agent_id,scan_id), KEY agent_time (agent_id,scanned_at),
 FOREIGN KEY (agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_hardware (agent_id CHAR(32) PRIMARY KEY, data JSON NOT NULL, FOREIGN KEY (agent_id) REFERENCES inv_agents(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_disks (agent_id CHAR(32) NOT NULL, ordinal INT NOT NULL, data JSON NOT NULL, PRIMARY KEY(agent_id,ordinal), FOREIGN KEY (agent_id) REFERENCES inv_agents(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_software (agent_id CHAR(32) NOT NULL, ordinal INT NOT NULL, data JSON NOT NULL, PRIMARY KEY(agent_id,ordinal), FOREIGN KEY (agent_id) REFERENCES inv_agents(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_network (agent_id CHAR(32) NOT NULL, ordinal INT NOT NULL, data JSON NOT NULL, PRIMARY KEY(agent_id,ordinal), FOREIGN KEY (agent_id) REFERENCES inv_agents(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE inv_changes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, agent_id CHAR(32) NOT NULL, scan_id CHAR(32) NOT NULL, category VARCHAR(32) NOT NULL, before_value JSON NOT NULL, after_value JSON NOT NULL, created_at DATETIME(6) NOT NULL, KEY agent_changes(agent_id,id), FOREIGN KEY(agent_id) REFERENCES inv_agents(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
