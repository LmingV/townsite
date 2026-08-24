<?php
/* ============================================================
   POST /api/logout.php
   ------------------------------------------------------------
   只清网站这边的会话，不影响玩家在游戏里的登录状态。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';

Core::requireMethod('POST');
Core::session();
Core::requireCsrf();

$_SESSION = [];

/* 顺手把 cookie 也过期掉，不然浏览器会一直带着一个空会话 */
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'] ?? '',
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();

Core::json(['ok' => true]);
