-- Solanace schema. Run database_bootstrap.sql first when installing on a new MySQL/MariaDB server.
USE solanace;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_root_name (root_id, name),
  INDEX idx_categories_root (root_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_cards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_path TEXT NOT NULL,
  file_hash CHAR(40) NOT NULL UNIQUE,
  custom_title VARCHAR(255) NULL,
  note MEDIUMTEXT NULL,
  category_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FULLTEXT KEY ft_card_search (custom_title, note, file_path),
  INDEX idx_category_id (category_id),
  CONSTRAINT fk_file_cards_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  card_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_card_id (card_id),
  CONSTRAINT fk_file_images_card FOREIGN KEY (card_id) REFERENCES file_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Single local application account. Default credentials: admin / admin.
-- Passwords are stored with Argon2id. Legacy password_md5 exists only for upgrades.
CREATE TABLE IF NOT EXISTS app_auth (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  username VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NULL,
  password_md5 CHAR(32) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default credentials: admin / admin. Change them immediately after installation.
INSERT IGNORE INTO app_auth (id, username, password_hash, password_md5)
VALUES (1, 'admin', '$argon2id$v=19$m=65536,t=4,p=2$dXNNRnlqU0d3Q1ZpZFBJSA$Wg1WN75p2hrjY6nHrt5jr9PqjeFxKiBtBr35OqTm8+8', NULL);

-- Root folders whose contents are cached. A root is inserted automatically when first selected.
CREATE TABLE IF NOT EXISTS library_roots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_path TEXT NOT NULL,
  root_key CHAR(40) NOT NULL UNIQUE,
  library_uid CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_refresh_at DATETIME NULL,
  INDEX idx_last_refresh_at (last_refresh_at),
  UNIQUE KEY uq_library_roots_uid (library_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cached video paths and content fingerprints. This table powers tree rendering and search.
CREATE TABLE IF NOT EXISTS library_files (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  relative_path TEXT NOT NULL,
  file_path TEXT NOT NULL,
  path_key CHAR(40) NOT NULL,
  file_name VARCHAR(1024) NOT NULL,
  file_hash CHAR(40) NOT NULL,
  file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  file_mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  last_scan_token CHAR(32) NOT NULL DEFAULT '',
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_library_file_path (root_id, path_key),
  INDEX idx_library_files_root (root_id),
  INDEX idx_library_files_hash (file_hash),
  INDEX idx_library_files_scan (root_id, last_scan_token),
  CONSTRAINT fk_library_files_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Category assignment belongs to a concrete cached file, not to the hash-based card.
-- This allows the same video hash to have different categories in different libraries.
CREATE TABLE IF NOT EXISTS library_file_categories (
  library_file_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_library_file_categories_category (category_id),
  CONSTRAINT fk_library_file_categories_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_library_file_categories_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cached subfolders, including empty folders. Used by folder creation, deletion and moving.
CREATE TABLE IF NOT EXISTS library_dirs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  relative_path TEXT NOT NULL,
  dir_path TEXT NOT NULL,
  path_key CHAR(40) NOT NULL,
  dir_name VARCHAR(1024) NOT NULL,
  last_scan_token CHAR(32) NOT NULL DEFAULT '',
  first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_library_dir_path (root_id, path_key),
  INDEX idx_library_dirs_root (root_id),
  INDEX idx_library_dirs_scan (root_id, last_scan_token),
  CONSTRAINT fk_library_dirs_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- State of automatic video screenshot generation for each cached root folder.
CREATE TABLE IF NOT EXISTS root_video_screenshot_sets (
  root_id INT UNSIGNED NOT NULL,
  file_hash CHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  expected_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
  source_file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  source_file_mtime BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  thumbnail_sort_order TINYINT UNSIGNED NULL,
  duration_seconds DECIMAL(12,3) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (root_id, file_hash),
  INDEX idx_root_video_screenshot_sets_status (root_id, status),
  CONSTRAINT fk_root_video_screenshot_sets_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Generated JPEG frames. Files are stored inside each root folder:
-- <root>/.video_catalog_screenshots/<file_hash>/frame_01.jpg
CREATE TABLE IF NOT EXISTS root_video_screenshots (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  file_hash CHAR(40) NOT NULL,
  relative_path VARCHAR(1400) NOT NULL,
  position_seconds DECIMAL(12,3) NOT NULL DEFAULT 0,
  sort_order TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_root_video_screenshot_frame (root_id, file_hash, sort_order),
  INDEX idx_root_video_screenshots_hash (root_id, file_hash),
  CONSTRAINT fk_root_video_screenshots_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- State and progress of the detached FFmpeg worker.
CREATE TABLE IF NOT EXISTS screenshot_worker_state (
  root_id INT UNSIGNED NOT NULL PRIMARY KEY,
  status VARCHAR(20) NOT NULL DEFAULT 'idle',
  total_jobs INT UNSIGNED NOT NULL DEFAULT 0,
  completed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
  failed_jobs INT UNSIGNED NOT NULL DEFAULT 0,
  current_file_name VARCHAR(1024) NULL,
  current_frame TINYINT UNSIGNED NOT NULL DEFAULT 0,
  current_frame_total TINYINT UNSIGNED NOT NULL DEFAULT 10,
  message TEXT NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_screenshot_worker_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Derived audio/video files and background file-tool jobs.

CREATE TABLE IF NOT EXISTS file_derivatives (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  library_file_id BIGINT UNSIGNED NOT NULL,
  root_id INT UNSIGNED NOT NULL,
  source_hash CHAR(40) NOT NULL,
  kind VARCHAR(20) NOT NULL,
  relative_path VARCHAR(1600) NOT NULL,
  download_name VARCHAR(1024) NOT NULL,
  start_seconds DECIMAL(12,3) NULL,
  end_seconds DECIMAL(12,3) NULL,
  original_extension VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_file_derivatives_file (library_file_id),
  INDEX idx_file_derivatives_source (root_id, source_hash, kind),
  CONSTRAINT fk_file_derivatives_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_derivatives_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_tool_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  library_file_id BIGINT UNSIGNED NOT NULL,
  root_id INT UNSIGNED NOT NULL,
  source_hash CHAR(40) NOT NULL,
  action_type VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  params_json TEXT NULL,
  derivative_id BIGINT UNSIGNED NULL,
  message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_file_tool_jobs_file (library_file_id, status),
  INDEX idx_file_tool_jobs_root (root_id, status),
  CONSTRAINT fk_file_tool_jobs_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_tool_jobs_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_tool_jobs_derivative FOREIGN KEY (derivative_id) REFERENCES file_derivatives(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transcription provider settings and timestamped transcript storage.
CREATE TABLE IF NOT EXISTS app_transcription_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  provider VARCHAR(50) NOT NULL DEFAULT 'groq',
  model VARCHAR(100) NOT NULL DEFAULT 'whisper-large-v3',
  api_key TEXT NULL,
  python_path VARCHAR(1024) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_transcription_settings (id, provider, model, api_key)
VALUES (1, 'groq', 'whisper-large-v3', NULL);

CREATE TABLE IF NOT EXISTS file_transcripts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  library_file_id BIGINT UNSIGNED NOT NULL,
  root_id INT UNSIGNED NOT NULL,
  source_hash CHAR(40) NOT NULL,
  audio_derivative_id BIGINT UNSIGNED NULL,
  text_derivative_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(50) NOT NULL,
  model VARCHAR(100) NULL,
  language VARCHAR(16) NULL,
  start_seconds DECIMAL(12,3) NULL,
  end_seconds DECIMAL(12,3) NULL,
  full_text MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_file_transcripts_file (library_file_id),
  INDEX idx_file_transcripts_source (root_id, source_hash),
  CONSTRAINT fk_file_transcripts_file FOREIGN KEY (library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_transcripts_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_transcripts_audio FOREIGN KEY (audio_derivative_id) REFERENCES file_derivatives(id) ON DELETE SET NULL,
  CONSTRAINT fk_file_transcripts_text FOREIGN KEY (text_derivative_id) REFERENCES file_derivatives(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_transcript_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transcript_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  start_seconds DECIMAL(12,3) NOT NULL,
  end_seconds DECIMAL(12,3) NOT NULL,
  segment_text TEXT NOT NULL,
  UNIQUE KEY uq_transcript_segment_order (transcript_id, sort_order),
  INDEX idx_transcript_segments_transcript (transcript_id),
  CONSTRAINT fk_transcript_segments_transcript FOREIGN KEY (transcript_id) REFERENCES file_transcripts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Translation settings and one-to-one translations of transcript segments.
CREATE TABLE IF NOT EXISTS app_translation_settings (
  id TINYINT UNSIGNED PRIMARY KEY,
  provider VARCHAR(50) NOT NULL DEFAULT 'groq',
  model VARCHAR(100) NOT NULL DEFAULT 'openai/gpt-oss-20b',
  api_key TEXT NULL,
  python_path VARCHAR(1024) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO app_translation_settings (id, provider, model, api_key)
VALUES (1, 'groq', 'openai/gpt-oss-20b', NULL);

CREATE TABLE IF NOT EXISTS file_transcript_translations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transcript_id BIGINT UNSIGNED NOT NULL,
  provider VARCHAR(50) NOT NULL,
  model VARCHAR(100) NULL,
  source_language VARCHAR(16) NULL,
  target_language VARCHAR(16) NOT NULL,
  translation_type VARCHAR(20) NOT NULL DEFAULT 'machine',
  custom_name VARCHAR(190) NULL,
  variant_key VARCHAR(220) NOT NULL,
  full_text MEDIUMTEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transcript_translation_variant (transcript_id, variant_key),
  INDEX idx_transcript_translation_transcript (transcript_id),
  CONSTRAINT fk_transcript_translation_transcript FOREIGN KEY (transcript_id) REFERENCES file_transcripts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS file_transcript_translation_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  translation_id BIGINT UNSIGNED NOT NULL,
  sort_order INT UNSIGNED NOT NULL,
  start_seconds DECIMAL(12,3) NULL,
  end_seconds DECIMAL(12,3) NULL,
  segment_text TEXT NOT NULL,
  UNIQUE KEY uq_translation_segment_order (translation_id, sort_order),
  INDEX idx_translation_segments_translation (translation_id),
  CONSTRAINT fk_translation_segments_translation FOREIGN KEY (translation_id) REFERENCES file_transcript_translations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transcript_translation_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transcript_id BIGINT UNSIGNED NOT NULL,
  target_language VARCHAR(16) NOT NULL,
  provider VARCHAR(50) NOT NULL,
  model VARCHAR(100) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  message TEXT NULL,
  translation_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  INDEX idx_translation_jobs_transcript (transcript_id, status),
  CONSTRAINT fk_translation_job_transcript FOREIGN KEY (transcript_id) REFERENCES file_transcripts(id) ON DELETE CASCADE,
  CONSTRAINT fk_translation_job_translation FOREIGN KEY (translation_id) REFERENCES file_transcript_translations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multi-video merge jobs and provenance.
CREATE TABLE IF NOT EXISTS video_merge_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  output_name VARCHAR(1024) NOT NULL,
  params_json MEDIUMTEXT NOT NULL,
  output_library_file_id BIGINT UNSIGNED NULL,
  message TEXT NULL,
  progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
  progress_stage VARCHAR(80) NULL,
  progress_seconds DOUBLE NOT NULL DEFAULT 0,
  progress_total_seconds DOUBLE NOT NULL DEFAULT 0,
  heartbeat_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_video_merge_jobs_root (root_id, status),
  CONSTRAINT fk_video_merge_jobs_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
  CONSTRAINT fk_video_merge_jobs_output FOREIGN KEY (output_library_file_id) REFERENCES library_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_merges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  output_library_file_id BIGINT UNSIGNED NOT NULL,
  output_file_hash CHAR(40) NOT NULL,
  output_name VARCHAR(1024) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_video_merges_output (output_library_file_id),
  INDEX idx_video_merges_root_hash (root_id, output_file_hash),
  CONSTRAINT fk_video_merges_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
  CONSTRAINT fk_video_merges_output FOREIGN KEY (output_library_file_id) REFERENCES library_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS video_merge_sources (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  merge_id BIGINT UNSIGNED NOT NULL,
  source_order INT UNSIGNED NOT NULL,
  source_library_file_id BIGINT UNSIGNED NULL,
  source_file_hash CHAR(40) NOT NULL,
  source_file_name VARCHAR(1024) NOT NULL,
  source_relative_path TEXT NOT NULL,
  UNIQUE KEY uq_video_merge_source_order (merge_id, source_order),
  INDEX idx_video_merge_source_file (source_library_file_id),
  INDEX idx_video_merge_source_hash (source_file_hash),
  CONSTRAINT fk_video_merge_sources_merge FOREIGN KEY (merge_id) REFERENCES video_merges(id) ON DELETE CASCADE,
  CONSTRAINT fk_video_merge_sources_file FOREIGN KEY (source_library_file_id) REFERENCES library_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Links promoted video fragments back to their source videos.
CREATE TABLE IF NOT EXISTS promoted_clips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  root_id INT UNSIGNED NOT NULL,
  source_library_file_id BIGINT UNSIGNED NOT NULL,
  promoted_library_file_id BIGINT UNSIGNED NOT NULL,
  source_hash CHAR(40) NOT NULL,
  promoted_hash CHAR(40) NOT NULL,
  original_clip_name VARCHAR(1024) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_promoted_clip_file (promoted_library_file_id),
  INDEX idx_promoted_clip_source (source_library_file_id),
  CONSTRAINT fk_promoted_clip_root FOREIGN KEY (root_id) REFERENCES library_roots(id) ON DELETE CASCADE,
  CONSTRAINT fk_promoted_clip_source FOREIGN KEY (source_library_file_id) REFERENCES library_files(id) ON DELETE CASCADE,
  CONSTRAINT fk_promoted_clip_file FOREIGN KEY (promoted_library_file_id) REFERENCES library_files(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
