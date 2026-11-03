-- Alpha Adventist Digital School — MySQL/MariaDB schema for cPanel.
-- Import once in phpMyAdmin (SQL tab) or let the app create it on first start.
-- The cPanel database may be named alphadve_alpha_school; table names stay adsp_*.
-- Keep this file in sync with src/platform/store.js (npm run db:schema).
SET NAMES utf8mb4;
SET time_zone = "+00:00";

CREATE TABLE IF NOT EXISTS adsp_users (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `username` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_users_seq (seq),
  UNIQUE KEY uk_adsp_users_username (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_sessions (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_sessions_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_login_limits (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_login_limits_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_departments (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_departments_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_staff_profiles (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `staffId` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_staff_profiles_seq (seq),
  UNIQUE KEY uk_adsp_staff_profiles_staffId (`staffId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_student_profiles (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `studentRef` VARCHAR(191) NULL,
  `userId` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_student_profiles_seq (seq),
  UNIQUE KEY uk_adsp_student_profiles_studentRef (`studentRef`),
  UNIQUE KEY uk_adsp_student_profiles_userId (`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_student_guardians (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `studentId` VARCHAR(191) NULL,
  `guardianUserId` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_student_guardians_seq (seq),
  UNIQUE KEY uk_adsp_student_guardians_studentId_guardianUserId (`studentId`, `guardianUserId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_classes (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_classes_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_class_teachers (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `classId` VARCHAR(191) NULL,
  `teacherUserId` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_class_teachers_seq (seq),
  UNIQUE KEY uk_adsp_class_teachers_classId_teacherUserId (`classId`, `teacherUserId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_attendance_records (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `studentId` VARCHAR(191) NULL,
  `date` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_attendance_records_seq (seq),
  UNIQUE KEY uk_adsp_attendance_records_studentId_date (`studentId`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_student_results (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_student_results_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_learning_materials (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_learning_materials_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_family_messages (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_family_messages_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_admission_documents (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_admission_documents_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_leave_types (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_leave_types_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_leave_requests (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_leave_requests_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_leave_balances (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_leave_balances_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_contracts (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_contracts_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_documents (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_documents_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_document_versions (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_document_versions_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_workflow_definitions (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_workflow_definitions_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_workflow_instances (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_workflow_instances_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_notices (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_notices_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_notice_acknowledgements (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_notice_acknowledgements_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_cms_content (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `kind` VARCHAR(191) NULL,
  `slug` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_cms_content_seq (seq),
  UNIQUE KEY uk_adsp_cms_content_kind_slug (`kind`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_admissions (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_admissions_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_submissions (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_submissions_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_notifications (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_notifications_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_outbox (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_outbox_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_calendar (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_calendar_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_media (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_media_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_media_consents (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_media_consents_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_privacy_requests (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_privacy_requests_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_incidents (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_incidents_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_audit_logs (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  `sequence` VARCHAR(191) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_audit_logs_seq (seq),
  UNIQUE KEY uk_adsp_audit_logs_sequence (`sequence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_system_settings (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_system_settings_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS adsp_download_grants (
  id VARCHAR(191) NOT NULL,
  revision INT UNSIGNED NOT NULL,
  seq BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payload LONGTEXT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_adsp_download_grants_seq (seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TRIGGER adsp_audit_logs_no_update BEFORE UPDATE ON adsp_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only';
CREATE TRIGGER adsp_audit_logs_no_delete BEFORE DELETE ON adsp_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit records are append-only';
