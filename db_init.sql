-- UniSubmit schema (runs once, when the MySQL volume is first created).
-- lib.php's ensure_schema() also upgrades older databases in place.

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reg_number VARCHAR(100) NOT NULL,
    student_name VARCHAR(150) NOT NULL,
    score INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'Pending',   -- Pending (queued) | Graded (auto-released) | Flagged (held for lecturer) | Approved
    strength TEXT,
    gap TEXT,
    filename VARCHAR(255),                   -- stored name in uploads/
    original_name VARCHAR(255),
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    token VARCHAR(32) NULL,                  -- private link to the student's result (/?s=token)
    summary TEXT NULL,                       -- overall comments to the student
    criteria TEXT NULL,                      -- JSON: [{name, weight, score, comment}]
    lecturer_note TEXT NULL,
    members TEXT NULL,                       -- JSON: group members [{name, reg}]
    flags TEXT NULL,                         -- JSON: why the result is held for review
    attempts INT NOT NULL DEFAULT 0,         -- marking attempts (the worker retries queued work)
    graded_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    token VARCHAR(32) NOT NULL,
    is_brief TINYINT(1) NOT NULL DEFAULT 0,  -- the assignment brief students see and the marker reads
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS settings (
    name VARCHAR(50) PRIMARY KEY,
    value TEXT                               -- e.g. the marking rubric
);
