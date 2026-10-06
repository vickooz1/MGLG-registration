USE mglg_registration;

ALTER TABLE event_registrations
  ADD COLUMN student_year VARCHAR(40) NULL AFTER course,
  ADD COLUMN attachment_seeking ENUM('yes', 'no') NULL AFTER student_year,
  ADD COLUMN employment_status ENUM('seeking_job', 'employed', 'not_seeking') NULL AFTER attachment_seeking;
