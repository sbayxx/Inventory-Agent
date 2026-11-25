-- Additive V2, separate from the existing hardware/browser inventory. Idempotent.
CREATE TABLE IF NOT EXISTS inv_v2_events (
 event_key CHAR(64) PRIMARY KEY,agent_id CHAR(32) NOT NULL,scan_id CHAR(32) NOT NULL,
 category VARCHAR(32) NOT NULL,operation VARCHAR(24) NOT NULL,item_key CHAR(64) NOT NULL,
 label VARCHAR(255) NOT NULL,before_value JSON NULL,after_value JSON NULL,occurred_at DATETIME(6) NOT NULL,
 KEY v2_event_agent(agent_id,occurred_at),KEY v2_event_time(occurred_at),
 FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_security (
 agent_id CHAR(32) NOT NULL,check_name VARCHAR(32) NOT NULL,state VARCHAR(24) NOT NULL,
 source VARCHAR(80) NOT NULL,detail VARCHAR(255) NOT NULL,observed_at DATETIME(6) NOT NULL,
 PRIMARY KEY(agent_id,check_name),FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_license_observations (
 agent_id CHAR(32) NOT NULL,product_id VARCHAR(64) NOT NULL,product VARCHAR(255) NOT NULL,
 state VARCHAR(32) NOT NULL,source VARCHAR(80) NOT NULL,observed_at DATETIME(6) NOT NULL,
 PRIMARY KEY(agent_id,product_id),FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_installations (
 agent_id CHAR(32) NOT NULL,product_key CHAR(64) NOT NULL,name VARCHAR(255) NOT NULL,
 publisher VARCHAR(255) NOT NULL,name_hash CHAR(64) NOT NULL,publisher_hash CHAR(64) NOT NULL,
 versions JSON NOT NULL,observed_at DATETIME(6) NOT NULL,PRIMARY KEY(agent_id,product_key),
 KEY v2_install_name(name_hash,publisher_hash),FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(255) NOT NULL,publisher VARCHAR(255) NOT NULL,name_hash CHAR(64) NOT NULL,
 publisher_hash CHAR(64) NOT NULL,created_at DATETIME(6) NOT NULL,
 UNIQUE KEY v2_product_match(customer_id,name_hash,publisher_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_entitlements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,metric VARCHAR(16) NOT NULL,seats INT UNSIGNED NOT NULL,
 reference VARCHAR(255) NOT NULL,starts_on DATE NULL,expires_on DATE NULL,archived TINYINT NOT NULL DEFAULT 0,
 created_at DATETIME(6) NOT NULL,updated_at DATETIME(6) NOT NULL,
 KEY v2_entitlement_customer(customer_id),FOREIGN KEY(product_id) REFERENCES inv_v2_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,
 entitlement_id BIGINT UNSIGNED NOT NULL,agent_id CHAR(32) NULL,subject VARCHAR(255) NOT NULL,
 subject_key CHAR(64) NOT NULL,active TINYINT NOT NULL DEFAULT 1,created_at DATETIME(6) NOT NULL,
 UNIQUE KEY v2_assignment_identity(entitlement_id,subject_key),KEY v2_assignment_customer(customer_id),
 FOREIGN KEY(entitlement_id) REFERENCES inv_v2_entitlements(id),FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_notices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,
 notice_key CHAR(64) NOT NULL,agent_id CHAR(32) NULL,kind VARCHAR(32) NOT NULL,severity VARCHAR(16) NOT NULL,
 title VARCHAR(255) NOT NULL,message TEXT NOT NULL,active TINYINT NOT NULL DEFAULT 1,
 first_seen DATETIME(6) NOT NULL,last_seen DATETIME(6) NOT NULL,
 acknowledged_by BIGINT UNSIGNED NULL,acknowledged_at DATETIME(6) NULL,
 UNIQUE KEY v2_notice_key(customer_id,notice_key),KEY v2_notice_scope(customer_id,active,last_seen),
 FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_v2_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,customer_id BIGINT UNSIGNED NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,action VARCHAR(48) NOT NULL,object_id BIGINT UNSIGNED NOT NULL,
 created_at DATETIME(6) NOT NULL,KEY v2_audit_scope(customer_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO inv_settings(name,value) VALUES
 ('v2_rules_last_run','0'),('v2_schema_version','1'),('v2_enabled','0'),('v2_changes','1'),('v2_licenses','1'),
 ('v2_security','1'),('v2_reports','1'),('v2_notifications','1');
