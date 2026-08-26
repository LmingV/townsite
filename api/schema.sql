-- ============================================================
-- 站点自己的库。在 townsite 库里执行。
-- ★ 不要在 AuthMe 的库里执行这个文件 ★
-- 站点数据和玩家账号分开存，网站出问题波及不到游戏。
-- ============================================================

-- 登录失败记录，用于限流。会自动清理一天前的数据。
CREATE TABLE IF NOT EXISTS `login_fails` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip`       VARCHAR(45)  NOT NULL,          -- 45 是 IPv6 的最大长度
  `username` VARCHAR(32)  NOT NULL,          -- 小写用户名
  `at`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ip_at`   (`ip`, `at`),            -- 按 IP 统计失败次数
  KEY `idx_user_at` (`username`, `at`),      -- 按用户名统计
  KEY `idx_at`      (`at`)                   -- 清理旧数据用
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Wiki 词条（markdown，可多人编辑）
-- ------------------------------------------------------------
-- pages 存当前版本，revisions 存每一次修改的完整快照。
-- 不存 diff 而存全文：词条通常几 KB，一千次修改也才几 MB，
-- 换来的是回退和对比都不用重算，实现简单得多。
-- ============================================================
CREATE TABLE IF NOT EXISTS `wiki_pages` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`      VARCHAR(64)  NOT NULL,          -- URL 用的短名，如 how-to-claim-land
  `cat`       VARCHAR(32)  NOT NULL,
  `title`     VARCHAR(120) NOT NULL,
  `summary`   VARCHAR(400) NOT NULL,
  `alias`     VARCHAR(255) NOT NULL DEFAULT '', -- 搜索用的同义词，空格分隔
  `body`      MEDIUMTEXT   NOT NULL,          -- markdown 原文
  `rev`       INT UNSIGNED NOT NULL DEFAULT 1,-- 版本号，每次保存 +1。冲突检测靠它
  `editor`    VARCHAR(32)  NOT NULL,          -- 最后修改人
  `created`   DATETIME     NOT NULL,
  `updated`   DATETIME     NOT NULL,
  `deleted`   TINYINT(1)   NOT NULL DEFAULT 0,-- 软删除，历史仍可查
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),              -- slug 唯一，两人同时新建同名会被挡住
  KEY `idx_cat`     (`cat`, `deleted`),
  KEY `idx_updated` (`updated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wiki_revisions` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `page_id`   INT UNSIGNED NOT NULL,
  `rev`       INT UNSIGNED NOT NULL,
  `title`     VARCHAR(120) NOT NULL,
  `summary`   VARCHAR(400) NOT NULL,
  `alias`     VARCHAR(255) NOT NULL DEFAULT '',
  `cat`       VARCHAR(32)  NOT NULL,
  `body`      MEDIUMTEXT   NOT NULL,
  `editor`    VARCHAR(32)  NOT NULL,
  `note`      VARCHAR(200) NOT NULL DEFAULT '', -- 本次改了什么，编辑时可填
  `at`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_page_rev` (`page_id`, `rev`),
  KEY `idx_page_at` (`page_id`, `at`),
  CONSTRAINT `fk_rev_page` FOREIGN KEY (`page_id`)
    REFERENCES `wiki_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wiki 词条投稿
CREATE TABLE IF NOT EXISTS `wiki_submissions` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `author`    VARCHAR(32)  NOT NULL,         -- 投稿人的游戏 ID（登录态里取的，不是前端传的）
  `cat`       VARCHAR(32)  NOT NULL,         -- 分类 id，对应 wiki.html 里 WIKI.categories 的 id
  `title`     VARCHAR(120) NOT NULL,
  `summary`   VARCHAR(400) NOT NULL,
  `body`      TEXT         NOT NULL,         -- 正文，纯文本，展示时才转 HTML
  `status`    ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `note`      VARCHAR(255) NULL,             -- 审核意见，退回时告诉投稿人原因
  `created`   DATETIME     NOT NULL,
  `updated`   DATETIME     NULL,
  `ip`        VARCHAR(45)  NULL,             -- 留痕用，前台不展示
  PRIMARY KEY (`id`),
  KEY `idx_status_created` (`status`, `created`),   -- 前台只取 approved
  KEY `idx_author`         (`author`),              -- 「我的投稿」
  KEY `idx_cat`            (`cat`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- 建数据库账号
-- ------------------------------------------------------------
-- 下面两条请把 '换成强密码' 改掉再执行。
-- 密码建议 20 位以上随机字符，反正只填在 config.php 里，不用记。
-- ============================================================

-- 1) AuthMe 只读账号 —— 这是整套方案的安全底线。
--    只给 SELECT，网站就永远改不了玩家的账号密码。
--    把 authme 换成你 AuthMe 实际的库名。
--
--   CREATE USER 'web_readonly'@'localhost' IDENTIFIED BY '换成强密码';
--   GRANT SELECT ON `authme`.`authme` TO 'web_readonly'@'localhost';
--
--    注意上面授权精确到了表级，比 GRANT SELECT ON authme.* 更严。
--    如果 AuthMe 的表名你改过，这里跟着改。

-- 2) 站点库账号 —— 需要读写，但只能碰 townsite 库。
--
--   CREATE DATABASE IF NOT EXISTS `townsite`
--     DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   CREATE USER 'townsite'@'localhost' IDENTIFIED BY '换成另一个强密码';
--   GRANT SELECT, INSERT, UPDATE, DELETE ON `townsite`.* TO 'townsite'@'localhost';
--
--   FLUSH PRIVILEGES;

-- ============================================================
-- 审核投稿（暂时手动，够用）
-- ------------------------------------------------------------
--   看待审的：
--     SELECT id, author, cat, title, created FROM wiki_submissions
--     WHERE status='pending' ORDER BY created;
--
--   看某一篇的全文：
--     SELECT * FROM wiki_submissions WHERE id=1\G
--
--   通过：
--     UPDATE wiki_submissions SET status='approved', updated=NOW() WHERE id=1;
--
--   退回并写明原因（投稿人能在「我的投稿」里看到）：
--     UPDATE wiki_submissions SET status='rejected', note='内容与现有词条重复',
--       updated=NOW() WHERE id=1;
--
--   ※ 上面这些是后台页面出现之前的手动办法，现在可以直接用
--     https://你的域名/admin.html 点按钮审核，SQL 留着备查。
-- ============================================================


-- ============================================================
-- 网站权限组
-- ------------------------------------------------------------
-- 一个人只有一条记录，role 取最高的那个：
--   editor  能直接编辑 Wiki 词条
--   admin   能审核投稿 + 能设定/撤销编辑组（管理组自己动不了管理组）
--
-- 站长（config.php 的 site_owner，即 IN7_）不在这张表里，
-- 硬写在配置文件中。这样即使表被清空或误删，站长仍能进后台，
-- 也没人能通过改这张表把站长降权。
--
-- config.php 里的 wiki_editors 仍然有效，与本表取并集，
-- 所以老配置不会因为建了这张表而失效。
-- ============================================================
CREATE TABLE IF NOT EXISTS `site_roles` (
  `username`   VARCHAR(32)  NOT NULL,           -- 小写游戏 ID，和 authme.username 对齐
  `realname`   VARCHAR(32)  NOT NULL DEFAULT '',-- 保留大小写，仅供后台显示
  `role`       ENUM('editor','admin') NOT NULL,
  `granted_by` VARCHAR(32)  NOT NULL,           -- 谁授的，留痕
  `granted_at` DATETIME     NOT NULL,
  `note`       VARCHAR(255) NOT NULL DEFAULT '',-- 备注，可写授权原因
  PRIMARY KEY (`username`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 审核与授权动作留痕。谁在什么时候通过/退回了哪篇投稿、给谁提了权。
-- 出现争议时能查，也能看出某个管理是不是在乱点。
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor`   VARCHAR(32)  NOT NULL,              -- 操作人
  `action`  VARCHAR(32)  NOT NULL,              -- approve / reject / set_role / remove_role
  `target`  VARCHAR(64)  NOT NULL,              -- 投稿 id 或被改权限的用户名
  `detail`  VARCHAR(255) NOT NULL DEFAULT '',
  `at`      DATETIME     NOT NULL,
  `ip`      VARCHAR(45)  NULL,
  PRIMARY KEY (`id`),
  KEY `idx_at`    (`at`),
  KEY `idx_actor` (`actor`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
