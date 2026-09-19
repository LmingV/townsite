-- ============================================================
-- 游戏数据绑定 - MySQL 5.7+/MariaDB 10.3+
-- 用法：mysql -u townsite -p townsite < migrations/005_game_sync.sql
-- 可重复执行，不会删除已有数据。
-- ============================================================

CREATE TABLE IF NOT EXISTS `player_stats` (
  `username`         VARCHAR(32)  NOT NULL,
  `playtime_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_seen`        DATETIME     NULL,
  `first_login`      DATETIME     NULL,
  `death_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`username`),
  KEY `idx_last_seen` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `player_badges` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`    VARCHAR(32)   NOT NULL,
  `badge_id`    VARCHAR(64)   NOT NULL,
  `unlocked_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_badge` (`username`, `badge_id`),
  KEY `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `badge_definitions` (
  `id`          VARCHAR(64)  NOT NULL,
  `name_zh`     VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `icon`        VARCHAR(50)  NOT NULL DEFAULT '',
  `rarity`      VARCHAR(20)  NOT NULL DEFAULT 'common',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `player_emails` (
  `username`    VARCHAR(32)  NOT NULL,
  `email`       VARCHAR(255) NOT NULL,
  `verified_at` DATETIME     NOT NULL,
  PRIMARY KEY (`username`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_verify` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32)   NOT NULL,
  `email`    VARCHAR(255)  NOT NULL,
  `code`     CHAR(6)       NOT NULL,
  `created`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires`  DATETIME      NOT NULL,
  `used`     TINYINT(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_user_code` (`username`, `code`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `badge_definitions`
  (`id`, `name_zh`, `description`, `icon`, `rarity`) VALUES
('story/mine_stone', '石器时代', '获得圆石', '⛏️', 'common'),
('story/upgrade_tools', '升级装备', '制作更好的镐', '🔨', 'common'),
('story/enter_the_nether', '地狱之旅', '进入下界', '🔥', 'rare'),
('story/enter_the_end', '末地之旅', '进入末地', '🌌', 'rare'),
('story/kill_dragon', '屠龙勇士', '击败末影龙', '🐉', 'epic'),
('nether/all_potions', '药剂大师', '制作所有药水', '🧪', 'rare'),
('nether/all_effects', '效果全满', '同时拥有所有效果', '✨', 'epic'),
('husbandry/balanced_diet', '美食家', '吃遍所有食物', '🍖', 'rare'),
('husbandry/breed_all_animals', '动物繁育家', '繁殖所有动物', '🐄', 'rare'),
('adventure/kill_all_mobs', '怪物猎人', '击杀所有种类的怪物', '⚔️', 'epic'),
('adventure/adventuring_time', '探险家', '发现所有生物群系', '🗺️', 'epic');
