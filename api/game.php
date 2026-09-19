<?php
/* ============================================================
   游戏内数据绑定 API
   ------------------------------------------------------------
   GET  /api/game.php?action=search_wiki&q=关键词
   POST /api/game.php  action=update_playtime
   POST /api/game.php  action=unlock_badge
   POST /api/game.php  action=request_email_bind
   POST /api/game.php  action=verify_email
   GET  /api/game.php?action=list_roles

   所有请求都需要 secret 参数（和插件 config.yml 里的 api.secret 一致）
   POST 请求需要走 JSON body，GET 请求走 query string。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';

/* ── 功能开关 ── */
if (!Core::cfg('features.game_data', false)) {
    Core::fail(404, 'disabled', '游戏数据功能未开启');
}

/* ── IP 白名单 ── */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$allowedIPs = Core::cfg('game_server_ips', []);

if (!empty($allowedIPs) && !in_array($ip, $allowedIPs, true)) {
    error_log("[town-game] Blocked request from $ip");
    Core::fail(403, 'forbidden', 'IP 不在白名单');
}

/* ── 密钥校验 ── */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$secret = '';

if ($method === 'POST') {
    $in = Core::input();
    $secret = (string)($in['secret'] ?? '');
} else {
    $secret = (string)($_GET['secret'] ?? '');
}

$expectedSecret = Core::cfg('game_api_secret', '');
if (!$expectedSecret) {
    Core::fail(500, 'not_configured', 'config.php 里未设置 game_api_secret');
}

if (!hash_equals($expectedSecret, $secret)) {
    error_log("[town-game] Bad secret from $ip");
    Core::fail(401, 'unauthorized', '密钥不对');
}

/* ── 数据库 ── */
$db = Core::db('site');
if (!$db) {
    Core::fail(503, 'db_unavailable', '站点数据库连不上');
}

/* ══════════ GET 请求 ══════════ */
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    /* ── Wiki 搜索 ── */
    if ($action === 'search_wiki') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (Core::slen($q) < 2) {
            Core::fail(400, 'short_q', '至少输入 2 个字符');
        }

        try {
            $st = $db->prepare(
                'SELECT id, cat, title, summary
                   FROM wiki_submissions
                  WHERE status = \'approved\'
                    AND (title LIKE ? OR summary LIKE ? OR body LIKE ?)
                  ORDER BY
                    CASE WHEN title LIKE ? THEN 1 ELSE 2 END,
                    COALESCE(updated, created) DESC
                  LIMIT 5');
            $like = '%' . $q . '%';
            $st->execute([$like, $like, $like, $like]);
            $items = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-game] wiki search failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '查询失败');
        }

        Core::json(['ok' => true, 'items' => $items]);
    }

    /* ── 权限组列表 ── */
    if ($action === 'list_roles') {
        try {
            $st = $db->query(
                'SELECT username, role FROM site_roles ORDER BY role DESC');
            $roles = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-game] list_roles failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '查询失败');
        }

        Core::json(['ok' => true, 'roles' => $roles]);
    }

    Core::fail(400, 'bad_action', '未知的 action');
}

/* ══════════ POST 请求 ══════════ */
Core::requireMethod('POST');
$in = Core::input();
$action = (string)($in['action'] ?? '');

/* ── 更新游戏时长 ── */
if ($action === 'update_playtime') {
    $user = strtolower(trim((string)($in['username'] ?? '')));
    $time = max(0, (int)($in['playtime_minutes'] ?? 0));

    if (!preg_match('/^[a-z0-9_]{3,16}$/i', $user)) {
        Core::fail(400, 'bad_username', '用户名不合法');
    }

    try {
        /* 防止时长倒退（数据异常） */
        $st = $db->prepare('SELECT playtime_minutes FROM player_stats WHERE username = ?');
        $st->execute([$user]);
        $old = (int)$st->fetchColumn();

        if ($old > 0 && $time < $old - 10) {
            error_log("[town-game] Playtime rollback for $user: $old -> $time");
            Core::fail(400, 'time_rollback', '游戏时长异常（倒退了）');
        }

        $st = $db->prepare(
            'INSERT INTO player_stats (username, playtime_minutes, last_seen, first_login)
             VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                playtime_minutes = VALUES(playtime_minutes),
                last_seen = VALUES(last_seen)');
        $st->execute([$user, $time]);
    } catch (\PDOException $e) {
        error_log('[town-game] update_playtime failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '更新失败');
    }

    Core::json(['ok' => true, 'message' => '游戏时长已同步']);
}

/* ── 解锁徽章 ── */
if ($action === 'unlock_badge') {
    $user = strtolower(trim((string)($in['username'] ?? '')));
    $badge = trim((string)($in['badge_id'] ?? ''));

    if (!preg_match('/^[a-z0-9_]{3,16}$/i', $user)) {
        Core::fail(400, 'bad_username', '用户名不合法');
    }
    if (!preg_match('/^[a-z0-9_\/]{3,64}$/i', $badge)) {
        Core::fail(400, 'bad_badge', '徽章 ID 不合法');
    }

    try {
        /* 检查是否已解锁 */
        $st = $db->prepare(
            'SELECT COUNT(*) FROM player_badges WHERE username = ? AND badge_id = ?');
        $st->execute([$user, $badge]);
        $exists = (int)$st->fetchColumn() > 0;

        if (!$exists) {
            $st = $db->prepare(
                'INSERT INTO player_badges (username, badge_id, unlocked_at)
                 VALUES (?, ?, NOW())');
            $st->execute([$user, $badge]);
        }
    } catch (\PDOException $e) {
        error_log('[town-game] unlock_badge failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '解锁失败');
    }

    Core::json([
        'ok'         => true,
        'first_time' => !$exists,
        'message'    => $exists ? '这个徽章你已经有了' : '徽章解锁成功',
    ]);
}

/* ── 请求邮箱绑定 ── */
if ($action === 'request_email_bind') {
    $user  = strtolower(trim((string)($in['username'] ?? '')));
    $email = strtolower(trim((string)($in['email'] ?? '')));

    if (!preg_match('/^[a-z0-9_]{3,16}$/i', $user)) {
        Core::fail(400, 'bad_username', '用户名不合法');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Core::fail(400, 'bad_email', '邮箱格式不对');
    }

    try {
        /* 检查邮箱是否已被占用 */
        $st = $db->prepare('SELECT username FROM player_emails WHERE email = ?');
        $st->execute([$email]);
        if ($r = $st->fetch()) {
            if (strtolower($r['username']) === $user) {
                Core::fail(409, 'already_bound', '你已经绑定过这个邮箱了');
            }
            Core::fail(409, 'email_taken', '这个邮箱已被其他玩家绑定');
        }

        /* 限制频率：10 分钟内最多 3 次 */
        $st = $db->prepare(
            'SELECT COUNT(*) FROM email_verify
              WHERE username = ? AND created > DATE_SUB(NOW(), INTERVAL 10 MINUTE)');
        $st->execute([$user]);
        if ((int)$st->fetchColumn() >= 3) {
            Core::fail(429, 'too_many', '请求太频繁，请 10 分钟后再试');
        }

        /* 生成 6 位验证码 */
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expires = date('Y-m-d H:i:s', time() + 600); // 10 分钟

        $st = $db->prepare(
            'INSERT INTO email_verify (username, email, code, expires)
             VALUES (?, ?, ?, ?)');
        $st->execute([$user, $email, $code, $expires]);

        /* 发邮件 */
        $subject = '【永恒流光】邮箱绑定验证码';
        $body    = "你好 {$user},\n\n" .
                   "你的验证码是: {$code}\n\n" .
                   "请在游戏内执行: /verify-email {$code}\n\n" .
                   "10 分钟内有效。如非本人操作请忽略。";

        $from = Core::cfg('mail.from', 'noreply@yhlg.love');
        $fromName = Core::cfg('mail.from_name', '永恒流光');

        if (!mail($email, $subject, $body, "From: {$fromName} <{$from}>")) {
            error_log("[town-game] Failed to send email to $email");
            Core::fail(503, 'mail_error', '邮件发送失败，请稍后再试');
        }

    } catch (\PDOException $e) {
        error_log('[town-game] request_email_bind failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '操作失败');
    }

    Core::json(['ok' => true, 'message' => '验证码已发送到邮箱']);
}

/* ── 验证邮箱 ── */
if ($action === 'verify_email') {
    $user = strtolower(trim((string)($in['username'] ?? '')));
    $code = trim((string)($in['code'] ?? ''));

    if (!preg_match('/^[a-z0-9_]{3,16}$/i', $user)) {
        Core::fail(400, 'bad_username', '用户名不合法');
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        Core::fail(400, 'bad_code', '验证码格式不对');
    }

    try {
        $db->beginTransaction();

        $st = $db->prepare(
            'SELECT email, expires FROM email_verify
              WHERE username = ? AND code = ? AND used = 0
              ORDER BY created DESC LIMIT 1');
        $st->execute([$user, $code]);
        $r = $st->fetch();

        if (!$r) {
            $db->rollBack();
            Core::fail(404, 'bad_code', '验证码不对或已过期');
        }
        if (strtotime($r['expires']) < time()) {
            $db->rollBack();
            Core::fail(410, 'expired', '验证码已过期');
        }

        /* 标记已使用 */
        $st = $db->prepare(
            'UPDATE email_verify SET used = 1 WHERE username = ? AND code = ?');
        $st->execute([$user, $code]);

        /* 邮箱归站点库管理，不修改 AuthMe 的账号表 */
        $st = $db->prepare(
            'INSERT INTO player_emails (username, email, verified_at)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                email = VALUES(email), verified_at = VALUES(verified_at)');
        $st->execute([$user, $r['email']]);

        $db->commit();

    } catch (\PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[town-game] verify_email failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '验证失败');
    }

    Core::json(['ok' => true, 'message' => '邮箱绑定成功']);
}

Core::fail(400, 'bad_action', '未知的 action');
