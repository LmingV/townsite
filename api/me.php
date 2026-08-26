<?php
/* ============================================================
   GET /api/me.php
   ------------------------------------------------------------
   返回当前登录状态。未登录也返回 200（只是 user 为 null），
   这样前端可以拿它同时做「探测登录态」和「取 CSRF token」两件事。

   带 ?profile=1 时额外返回资料（注册时间、最后登录），
   这部分从 AuthMe 库实时查，因为玩家可能在网页登录后又进过游戏。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/authme.php';

Core::requireMethod('GET');
Core::session();

$u = Core::user();

/* 角色一并返回，前端据此决定显不显示「管理后台」入口。
   这只是界面开关：前端能改，后端不能 —— 真正的拦截在每个接口里各自做。 */
$role = $u ? Core::role($u) : '';

$resp = [
    'ok'    => true,
    'user'  => $u ? ['name' => $u['name'], 'role' => $role] : null,
    'csrf'  => Core::csrfToken(),
    'feat'  => [
        'profile'     => (bool)Core::cfg('features.profile', true),
        'wiki_submit' => (bool)Core::cfg('features.wiki_submit', true),
        'game_data'   => (bool)Core::cfg('features.game_data', false),
        'wiki_edit'   => $u ? Core::isEditor($u) : false,
        'admin'       => $u ? Core::isAdmin($u)  : false,
        'owner'       => $u ? Core::isOwner($u)  : false,
    ],
];

/* ── 个人资料 ── */
if ($u && !empty($_GET['profile']) && Core::cfg('features.profile', true)) {
    $row = AuthMe::find($u['lname']);
    if ($row) {
        $reg  = AuthMe::ts($row['regdate']   ?? 0);
        $last = AuthMe::ts($row['lastlogin'] ?? 0);

        $profile = [
            'name'      => $row['realname'] ?: $row['username'],
            'regdate'   => $reg,
            'lastlogin' => $last,
            /* 注册至今多少天，前端直接显示 */
            'days'      => $reg ? (int)floor((time() - $reg) / 86400) : null,
        ];

        /* IP 属于敏感信息，默认不返回。要开就在 config 里打开，
           且只返回给玩家自己 —— 这个接口本来就只查当前登录者。 */
        if (Core::cfg('features.show_ip', false)) {
            $profile['regip']  = $row['regip'] ?? null;
            $profile['lastip'] = $row['lastip'] ?? null;
        }

        $resp['profile'] = $profile;
    }
}

Core::json($resp);
