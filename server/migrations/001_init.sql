CREATE TABLE IF NOT EXISTS tasks (
  id              CHAR(26) PRIMARY KEY,
  type            VARCHAR(128) NOT NULL,
  payload_json    JSON NOT NULL,
  status          ENUM('queued','running','succeeded','failed','canceled') NOT NULL DEFAULT 'queued',
  attempts        INT NOT NULL DEFAULT 0,
  max_attempts    INT NOT NULL DEFAULT 3,
  idempotency_key VARCHAR(128) NULL,
  scheduled       TINYINT(1) NOT NULL DEFAULT 0,
  schedule_ref    VARCHAR(255) NULL,
  cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_error      TEXT NULL,
  INDEX idx_tasks_status (status),
  UNIQUE KEY uk_tasks_idem (type, idempotency_key)
);

CREATE TABLE IF NOT EXISTS task_runs (
  id           BIGINT PRIMARY KEY AUTO_INCREMENT,
  task_id      CHAR(26) NOT NULL,
  run_started  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  run_finished TIMESTAMP NULL,
  result       ENUM('succeeded','failed') NULL,
  error        TEXT NULL,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
  INDEX idx_task_runs_task (task_id)
);
