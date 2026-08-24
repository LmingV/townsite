<?php
/* ============================================================
   公共库：配置加载、数据库连接、会话、限流、响应输出
   ============================================================ */

namespace Town\Auth;

class Core
{
    private static $cfg = null;
    private static $pdo = [];
    private static $dev = false;

    /* 请求是否来自本机。开发模式的唯一开关条件 ——
       只要不是回环地址，config.dev.php 就一律不生效，
       所以万一它被误传到线上也不会打开测试账号。 */
    public static function isLoopback()
    {
        if (PHP_SAPI === 'cli') return true;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return in_array($ip, ['127.0.0.1', '::1', '0:0:0:0:0:0:0:1'], true);
    }

    /* 当前是否处于开发模式（存在 config.dev.php 且来自本机） */
    public static function isDev()
    {
        self::cfg();          // 确保配置已加载，$dev 才有意义
        return self::$dev;
    }

    private static function merge($base, $over)
    {
        foreach ($over as $k => $v) {
            if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
                $base[$k] = self::merge($base[$k], $v);
            } else {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    /* ── 配置 ── */
    public static function cfg($path = null, $default = null)
    {
        if (self::$cfg === null) {
            /* 先占位再做别的。否则 fail() → json() → cors() → cfg()
               会再走进这里，变成无限递归。 */
            self::$cfg = [];

            $cfg  = [];
            $base = __DIR__ . '/../config.php';
            if (is_file($base)) {
                $l = require $base;
                if (is_array($l)) $cfg = $l;
            }

            /* 开发配置覆盖在正式配置之上，且只在本机访问时生效 */
            $devf = __DIR__ . '/../config.dev.php';
            if (is_file($devf) && self::isLoopback()) {
                $l = require $devf;
                if (is_array($l)) { $cfg = self::merge($cfg, $l); self::$dev = true; }
            }

            if (!$cfg) {
                self::fail(500, 'server_misconfigured',
                    '缺少配置文件。正式部署请填 api/config.php；本地开发请放 api/config.dev.php');
            }
            self::$cfg = $cfg;
        }
        if ($path === null) return self::$cfg;
        $cur = self::$cfg;
        foreach (explode('.', $path) as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
            $cur = $cur[$k];
        }
        return $cur;
    }

    /* ── 数据库 ──
       $which: 'authme'（只读）或 'site'（读写） */
    public static function db($which = 'authme')
    {
        if (isset(self::$pdo[$which])) return self::$pdo[$which];
        $c = self::cfg($which);
        if (!$c) self::fail(500, 'server_misconfigured', "config.php 缺少 $which 配置");
        /* enabled 缺省视为开启（旧配置没这个键），显式 false 才关。
           authme 关掉的用途：MC 服托管在别处、数据库不允许远程连接时，
           先把登录功能停掉，网站其余部分照常可用。 */
        if (array_key_exists('enabled', $c) && !$c['enabled']) return null;

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                       $c['host'], (int)$c['port'], $c['dbname']);
        try {
            $pdo = new \PDO($dsn, $c['user'], $c['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                /* 关掉模拟预处理，让 MySQL 真正做参数绑定 */
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (\PDOException $e) {
            /* 不把数据库错误原文吐给前端，那可能含库名、账号等信息 */
            error_log('[town-auth] DB connect failed (' . $which . '): ' . $e->getMessage());
            self::fail(503, 'db_unavailable', '数据库连接失败，请稍后再试');
        }
        return self::$pdo[$which] = $pdo;
    }

    /* ── 会话 ── */
    public static function session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;

        $https = self::isHttps();
        if (self::cfg('security.require_https', true) && !$https) {
            self::fail(400, 'https_required',
                '本站要求通过 HTTPS 访问。若在本地调试，可临时把 config.php 里 require_https 改为 false');
        }

        session_set_cookie_params([
            'lifetime' => (int)self::cfg('security.session_lifetime', 604800),
            'path'     => '/',
            'httponly' => true,          // JS 读不到 cookie，降低 XSS 危害
            'secure'   => $https,
            'samesite' => 'Lax',         // 跨站请求不带 cookie，防 CSRF
        ]);
        session_name('TOWNSESS');
        session_start();
    }

    public static function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') return true;
        if (($_SERVER['SERVER_PORT'] ?? '') == 443) return true;
        /* 走了反向代理（宝塔常见）时看这个头 */
        if (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') return true;
        return false;
    }

    public static function user()
    {
        self::session();
        return $_SESSION['user'] ?? null;
    }

    public static function requireUser()
    {
        $u = self::user();
        if (!$u) self::fail(401, 'not_logged_in', '请先登录');
        return $u;
    }

    /* ── 是否在 Wiki 编辑组里 ──
       比较用小写，避免大小写差异导致「明明加了却没权限」。
       中文 ID 用 mb 无关的直接比较即可（strtolower 不动多字节字符）。 */
    public static function isEditor($u = null)
    {
        $u = $u ?: self::user();
        if (!$u) return false;
        $list = self::cfg('wiki_editors', []);
        if (!is_array($list) || !$list) return false;
        $me = strtolower(trim((string)($u['lname'] ?? $u['name'] ?? '')));
        foreach ($list as $e) {
            if (strtolower(trim((string)$e)) === $me) return true;
        }
        return false;
    }

    public static function requireEditor()
    {
        $u = self::requireUser();
        if (!self::isEditor($u)) {
            self::fail(403, 'not_editor',
                '你不在 Wiki 编辑组里。想参与编辑请联系管理组，或用「投稿」提交新词条。');
        }
        return $u;
    }

    /* ── CSRF ── */
    public static function csrfToken()
    {
        self::session();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function requireCsrf()
    {
        self::session();
        $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
        $have = $_SESSION['csrf'] ?? '';
        if (!$have || !$sent || !hash_equals($have, (string)$sent)) {
            self::fail(403, 'bad_csrf', '请求校验失败，请刷新页面重试');
        }
    }

    /* ── 客户端 IP ── */
    public static function ip()
    {
        /* 只在明确经过本机反代时才信这些头。直接暴露在公网的 PHP
           不应该信任 X-Forwarded-For，那是可以伪造的。 */
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = ['127.0.0.1', '::1'];
        if (in_array($remote, $trusted, true)) {
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ($xff) {
                $first = trim(explode(',', $xff)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
            }
        }
        return $remote;
    }

    /* ── 输出 ── */
    public static function json($data, $code = 200)
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            self::cors();
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function fail($code, $err, $msg)
    {
        self::json(['ok' => false, 'error' => $err, 'message' => $msg], $code);
    }

    public static function cors()
    {
        $allow = self::cfg('security.allow_origins', []);
        if (!$allow) return;                       // 同域部署，不需要 CORS
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && in_array($origin, $allow, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
    }

    /* ── 只接受指定的请求方法 ── */
    public static function requireMethod($m)
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            self::cors();
            header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type,X-CSRF-Token');
            http_response_code(204); exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $m) {
            self::fail(405, 'bad_method', '请求方法不对');
        }
    }

    /* ── 读 JSON 请求体 ── */
    public static function input()
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) return $_POST;
        $j = json_decode($raw, true);
        return is_array($j) ? $j : $_POST;
    }
}
