-- Additive, repeatable. Observation dates are NOT original activation dates.
CREATE TABLE IF NOT EXISTS inv_v2_activation_metadata (
 agent_id CHAR(32) NOT NULL,
 product_id VARCHAR(64) NOT NULL,
 first_observed_at DATETIME(6) NOT NULL,
 first_observed_licensed_at DATETIME(6) NULL,
 evaluation_end_at DATETIME NULL,
 grace_period_remaining_minutes INT UNSIGNED NULL,
 license_status_reason INT UNSIGNED NULL,
 license_family VARCHAR(255) NULL,
 observed_at DATETIME(6) NOT NULL,
 PRIMARY KEY(agent_id,product_id),FOREIGN KEY(agent_id) REFERENCES inv_agents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO inv_v2_activation_metadata(agent_id,product_id,first_observed_at,first_observed_licensed_at,observed_at)
 SELECT agent_id,product_id,observed_at,IF(state='licensed',observed_at,NULL),observed_at FROM inv_v2_license_observations;
INSERT IGNORE INTO inv_settings(name,value) VALUES('activation_schema_version','1');
