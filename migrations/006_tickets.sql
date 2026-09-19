-- ============================================================
-- 工单系统 - MySQL 5.7+/MariaDB 10.3+
-- 用法：mysql -u townsite -p townsite < migrations/006_tickets.sql
-- 可重复执行，不会删除已有数据。
-- ============================================================

CREATE TABLE IF NOT EXISTS `tickets` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author`     VARCHAR(32)   NOT NULL,
  `category`   ENUM('bug','appeal','suggestion','other') NOT NULL,
  `subject`    VARCHAR(100)  NOT NULL,
  `content`    TEXT          NOT NULL,
  `status`     ENUM('open','replied','closed') NOT NULL DEFAULT 'open',
  `reply`      TEXT          NULL,
  `created`    DATETIME      NOT NULL,
  `replied_at` DATETIME      NULL,
  `replied_by` VARCHAR(32)   NULL,
  `ip`         VARCHAR(45)   NULL,
  PRIMARY KEY (`id`),
  KEY `idx_author_created` (`author`, `created`),
  KEY `idx_status_created` (`status`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
