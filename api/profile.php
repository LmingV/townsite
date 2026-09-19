<?php
/* ============================================================
   玩家资料 API
   ------------------------------------------------------------
   GET  /api/profile.php?action=stats&username=玩家名

   返回玩家的游戏数据和徽章
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') Core::requireMethod('GET');

Core::requireMethod('GET');

$action   = $_GET['action'] ?? '';
$username = strtolower(trim((string)($_GET['username'] ?? '')));

if ($action !== 'stats') {
    Core::fail(400, 'bad_action', '未知的 action');
}

if (!preg_match('/^[a-z0-9_]{3,16}$/i', $username)) {
    Core::fail(400, 'bad_username', '用户名不合法');
}

$db = Core::db('site');
if (!$db) {
    Core::fail(503, 'db_unavailable', '站点数据库连不上');
}

$data = [
    'game'   => null,
    'badges' => [],
];

/* ── 游戏统计数据 ── */
try {
    $st = $db->prepare(
        'SELECT playtime_minutes, last_seen, first_login, death_count
           FROM player_stats WHERE username = ?');
    $st->execute([$username]);
    $r = $st->fetch();

    if ($r) {
        $data['game'] = [
            'playtime_minutes' => (int)$r['playtime_minutes'],
            'playtime_hours'   => round((int)$r['playtime_minutes'] / 60, 1),
            'last_seen'        => $r['last_seen'],
            'first_login'      => $r['first_login'],
            'death_count'      => (int)$r['death_count'],
        ];
    }
} catch (\PDOException $e) {
    error_log('[town-profile] game stats query failed: ' . $e->getMessage());
    // 不中断，继续查徽章
}

/* ── 徽章列表 ── */
try {
    $st = $db->prepare(
        'SELECT b.badge_id, b.unlocked_at, d.name_zh, d.description, d.icon, d.rarity
           FROM player_badges b
           LEFT JOIN badge_definitions d ON b.badge_id = d.id
          WHERE b.username = ?
          ORDER BY b.unlocked_at DESC');
    $st->execute([$username]);
    $data['badges'] = $st->fetchAll();
} catch (\PDOException $e) {
    error_log('[town-profile] badges query failed: ' . $e->getMessage());
    // 不中断，返回空数组
}

Core::json([
    'ok'   => true,
    'data' => $data,
]);
