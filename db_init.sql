-- UniSubmit database (runs once, when the MySQL volume is first created).

CREATE TABLE IF NOT EXISTS submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(150) NOT NULL,
    reg_number VARCHAR(100) NOT NULL,
    filename VARCHAR(255),                  -- stored name in uploads/
    original_name VARCHAR(255),             -- name the student uploaded
    token VARCHAR(32),                      -- private link to the student's receipt
    status VARCHAR(50) DEFAULT 'Submitted', -- Submitted | Marked
    score INT NULL,                         -- mark out of 100, set by the lecturer
    lecturer_note TEXT NULL,                -- lecturer's comment
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    token VARCHAR(32) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
