<?php
/* ============================================================
   POST /api/login.php   { username, password }
   ------------------------------------------------------------
   用 AuthMe 库里的账号密码登录。全程只对 AuthMe 库做 SELECT。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/authme.php';

Core::requireMethod('POST');
Core::session();
Core::requireCsrf();

$in   = Core::input();
$name = trim((string)($in['username'] ?? ''));
$pass = (string)($in['password'] ?? '');

/* ── 基本校验 ──
   长度上限是为了不把超长输入喂给哈希函数（有些算法会很慢）。
   字符集不做严格限制：离线服允许中文 ID，卡死玩家不合适。 */
if ($name === '' || $pass === '') {
    Core::fail(400, 'missing_field', '请填写游戏 ID 和密码');
}
if (mb_strlen($name) > 32 || strlen($pass) > 256) {
    Core::fail(400, 'too_long', '输入过长');
}

$ip = Core::ip();

/* ── 限流 ──
   放在校验密码之前，避免被当成撞库的免费预言机 */
if (AuthMe::tooManyFails($ip, $name)) {
    Core::fail(429, 'rate_limited',
        '尝试次数过多，请稍后再试。如果忘记密码，请在游戏里用 /changepassword 修改，或联系管理员');
}

$r = AuthMe::check($name, $pass);

if (!$r['ok']) {
    AuthMe::recordFail($ip, $name);
    /* 「账号不存在」和「密码错误」返回同一句话。
       区分开会让人能拿这个接口枚举玩家 ID。 */
    Core::fail(401, 'bad_credentials', '游戏 ID 或密码不正确');
}

$row = $r['row'];

/* ── 登录成功 ──
   换一个新 session id，防会话固定攻击 */
session_regenerate_id(true);

$_SESSION['user'] = [
    'name'      => $row['realname'] ?: $row['username'],  // 显示用，保留大小写
    'lname'     => $row['username'],                      // 小写，查询用
    'login_at'  => time(),
    'login_ip'  => $ip,
];
/* 换了 session id，csrf token 也重新生成 */
unset($_SESSION['csrf']);
$csrf = Core::csrfToken();

AuthMe::clearFails($ip, $name);

Core::json([
    'ok'   => true,
    'user' => ['name' => $_SESSION['user']['name']],
    'csrf' => $csrf,
]);
