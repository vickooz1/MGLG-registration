CREATE TABLE IF NOT EXISTS event_registrations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  institution VARCHAR(180) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(254) NOT NULL,
  participant_type ENUM('student', 'non_student') NOT NULL,
  course VARCHAR(180) NULL,
  student_year VARCHAR(40) NULL,
  attachment_seeking ENUM('yes', 'no') NULL,
  employment_status ENUM('seeking_job', 'employed', 'not_seeking') NULL,
  area_of_specification VARCHAR(180) NULL,
  area_of_residence VARCHAR(160) NOT NULL,
  occupation VARCHAR(180) NOT NULL,
  hometown VARCHAR(160) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event_registrations_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
