<?php
/* ============================================================
   AuthMe 账号查询 + 登录失败限流
   ------------------------------------------------------------
   本文件对 AuthMe 库只发 SELECT。任何 UPDATE/INSERT/DELETE
   都不应该出现在这里 —— 玩家的游戏账号由服务器插件独占管理。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/hash.php';

class AuthMe
{
    /* 按用户名取一行。AuthMe 的 username 列存的是小写，
       realname 列保留玩家输入时的大小写。 */
    public static function find($name)
    {
        /* ── 开发模式：账号写在 config.dev.php 里，不连数据库 ──
           只有本机访问且存在 config.dev.php 时才会走到这里。
           密码格式和 AuthMe 完全一致，所以校验走的是同一套代码，
           不存在「开发能登、生产登不了」的情况。 */
        $dev = Core::cfg('dev_accounts');
        if (is_array($dev) && $dev) {
            $lname = strtolower(trim($name));
            foreach ($dev as $a) {
                if (strtolower((string)($a['username'] ?? '')) === $lname) {
                    return [
                        'username'  => $lname,
                        'realname'  => $a['realname'] ?? $a['username'],
                        'password'  => $a['password'] ?? '',
                        'salt'      => $a['salt']      ?? null,
                        'regdate'   => $a['regdate']   ?? null,
                        'lastlogin' => $a['lastlogin'] ?? null,
                        'regip'     => $a['regip']     ?? '127.0.0.1',
                        'lastip'    => $a['lastip']    ?? '127.0.0.1',
                    ];
                }
            }
            return null;      // 开发模式下不再回落到数据库
        }

        $c   = Core::cfg('authme');
        $col = $c['col'];
        $tbl = self::ident($c['table']);

        /* 列名来自配置文件而非用户输入，但仍然过一遍白名单式转义，
           避免有人在 config 里写错导致语法问题 */
        $fields = [];
        foreach (['username','realname','password','salt','regdate','lastlogin','regip','lastip'] as $k) {
            if (!empty($col[$k])) $fields[$k] = self::ident($col[$k]);
        }

        $sel = [];
        foreach ($fields as $alias => $real) $sel[] = "$real AS `$alias`";

        $sql = 'SELECT ' . implode(',', $sel) . " FROM $tbl WHERE " .
               $fields['username'] . ' = ? LIMIT 1';

        /* authme.enabled = false 时 db() 返回 null。
           不挡住的话这里会对 null 调 prepare()，直接白屏 500。
           这种情况给个明确提示，而不是让人对着白屏猜。 */
        $db = Core::db('authme');
        if (!$db) {
            Core::fail(503, 'authme_disabled',
                '登录功能未启用：网站还没连上游戏服的账号数据库。');
        }

        try {
            $st = $db->prepare($sql);
            $st->execute([strtolower(trim($name))]);
            return $st->fetch() ?: null;
        } catch (\PDOException $e) {
            error_log('[town-auth] AuthMe query failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '查询账号失败，请稍后再试');
        }
    }

    /* 反引号包裹标识符，并去掉反引号本身 */
    private static function ident($s)
    {
        return '`' . str_replace('`', '', (string)$s) . '`';
    }

    /* 校验用户名+密码。返回 ['ok'=>bool, 'row'=>?array, 'reason'=>?string] */
    public static function check($name, $password)
    {
        $row = self::find($name);
        if (!$row) {
            /* 账号不存在时也走一遍哈希运算，让耗时和"密码错误"接近，
               否则可以通过响应时间探测哪些用户名存在 */
            Hash::verify($password, '$SHA$0000000000000000$' . str_repeat('0', 64), null, 'SHA256');
            return ['ok' => false, 'row' => null, 'reason' => 'no_such_user'];
        }

        $algo = Core::cfg('authme.hash', 'auto');
        try {
            $pass = Hash::verify($password, $row['password'], $row['salt'] ?? null, $algo);
        } catch (\RuntimeException $e) {
            /* 算法认不出来 —— 这是部署问题，不是用户的错，
               要明确报出来而不是当成密码错误 */
            error_log('[town-auth] hash algo problem: ' . $e->getMessage());
            Core::fail(500, 'hash_unsupported', $e->getMessage());
        }

        return ['ok' => $pass, 'row' => $row, 'reason' => $pass ? null : 'bad_password'];
    }

    /* ── 限流 ──
       记录失败次数。用站点库；站点库没开就退化成 APCu，
       都没有就只依赖会话内计数（弱，但好过没有）。 */
    public static function tooManyFails($ip, $name)
    {
        $ipC   = Core::cfg('security.rate_ip',   ['max'=>10,'window'=>600,'lock'=>900]);
        $userC = Core::cfg('security.rate_user', ['max'=>5, 'window'=>600,'lock'=>900]);

        $db = Core::db('site');
        if (!$db) {
            /* 没有站点库时的降级方案：只按会话计数 */
            Core::session();
            $n = (int)($_SESSION['fail_count'] ?? 0);
            $t = (int)($_SESSION['fail_first'] ?? 0);
            if (time() - $t > $ipC['window']) { $n = 0; }
            return $n >= $ipC['max'];
        }

        try {
            $since = date('Y-m-d H:i:s', time() - (int)$ipC['window']);
            $st = $db->prepare('SELECT COUNT(*) FROM login_fails WHERE ip=? AND at>?');
            $st->execute([$ip, $since]);
            if ((int)$st->fetchColumn() >= (int)$ipC['max']) return true;

            $since2 = date('Y-m-d H:i:s', time() - (int)$userC['window']);
            $st = $db->prepare('SELECT COUNT(*) FROM login_fails WHERE username=? AND at>?');
            $st->execute([strtolower(trim($name)), $since2]);
            if ((int)$st->fetchColumn() >= (int)$userC['max']) return true;
        } catch (\PDOException $e) {
            /* 限流表查不了不该阻断登录，但要留日志 */
            error_log('[town-auth] rate check failed: ' . $e->getMessage());
        }
        return false;
    }

    public static function recordFail($ip, $name)
    {
        $db = Core::db('site');
        if (!$db) {
            Core::session();
            $_SESSION['fail_count'] = (int)($_SESSION['fail_count'] ?? 0) + 1;
            if (empty($_SESSION['fail_first'])) $_SESSION['fail_first'] = time();
            return;
        }
        try {
            $db->prepare('INSERT INTO login_fails (ip, username, at) VALUES (?,?,NOW())')
               ->execute([$ip, strtolower(trim($name))]);
            /* 顺手清掉一天前的旧记录，免得表无限增长 */
            $db->exec('DELETE FROM login_fails WHERE at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        } catch (\PDOException $e) {
            error_log('[town-auth] record fail failed: ' . $e->getMessage());
        }
    }

    public static function clearFails($ip, $name)
    {
        $db = Core::db('site');
        if (!$db) { Core::session(); unset($_SESSION['fail_count'], $_SESSION['fail_first']); return; }
        try {
            $db->prepare('DELETE FROM login_fails WHERE ip=? OR username=?')
               ->execute([$ip, strtolower(trim($name))]);
        } catch (\PDOException $e) {
            error_log('[town-auth] clear fails failed: ' . $e->getMessage());
        }
    }

    /* AuthMe 的时间戳是毫秒，转成秒级 */
    public static function ts($v)
    {
        $v = (int)$v;
        if ($v <= 0) return null;
        return $v > 99999999999 ? intdiv($v, 1000) : $v;
    }
}
