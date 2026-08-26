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

    /* ============================================================
       权限组
       ------------------------------------------------------------
       三级：owner > admin > editor > 普通用户

         owner   站长。硬写在 config.php 的 site_owner 里，不入库。
                 这样表被清空或误删也进得去后台，也没人能靠改表把站长降权。
         admin   管理组。能审核投稿、能设定/撤销编辑组。
                 按设计管理组之间互不干涉 —— 只有 owner 能任命管理组，
                 防止一个管理号被盗后连锁提权。
         editor  编辑组。能直接改 Wiki 词条，不走投稿。

       身份存在 site_roles 表里，另外 config.php 的 wiki_editors
       仍然生效并与表取并集，所以建表之前的老配置不会失效。

       比较统一用小写。strtolower 不动多字节字符，中文 ID 也安全。
       ============================================================ */

    private static $roleCache = [];

    /* 规范化用户名：取登录态里的小写名，没有就退回显示名 */
    private static function uname($u = null)
    {
        $u = $u ?: self::user();
        if (!$u) return '';
        return strtolower(trim((string)($u['lname'] ?? $u['name'] ?? '')));
    }

    public static function isOwner($u = null)
    {
        $me = self::uname($u);
        if ($me === '') return false;
        $owner = strtolower(trim((string)self::cfg('site_owner', '')));
        return $owner !== '' && $owner === $me;
    }

    /* 查这个人的角色，返回 'owner' | 'admin' | 'editor' | ''。
       一次请求内缓存，避免同一次请求里反复查库。 */
    public static function role($u = null)
    {
        $me = self::uname($u);
        if ($me === '') return '';
        if (isset(self::$roleCache[$me])) return self::$roleCache[$me];

        $r = '';

        if (self::isOwner($u)) {
            $r = 'owner';
        } else {
            /* config 里的 wiki_editors 是额外白名单，与表取并集 */
            $list = self::cfg('wiki_editors', []);
            if (is_array($list)) {
                foreach ($list as $e) {
                    if (strtolower(trim((string)$e)) === $me) { $r = 'editor'; break; }
                }
            }

            /* 表里的角色优先级更高（admin 能盖过 config 的 editor）。
               查表失败不应该让整站 403 —— 表还没建的时候，
               config 里的 editor 判定仍然要能用，所以异常只记日志。 */
            try {
                $db = self::db('site');
                if ($db) {
                    $st = $db->prepare('SELECT role FROM site_roles WHERE username = ? LIMIT 1');
                    $st->execute([$me]);
                    $row = $st->fetch();
                    if ($row && $row['role'] === 'admin')  $r = 'admin';
                    elseif ($row && $row['role'] === 'editor' && $r === '') $r = 'editor';
                }
            } catch (\PDOException $e) {
                error_log('[town-auth] role lookup failed: ' . $e->getMessage());
            }
        }

        return self::$roleCache[$me] = $r;
    }

    /* admin 和 owner 都算管理组 */
    public static function isAdmin($u = null)
    {
        $r = self::role($u);
        return $r === 'admin' || $r === 'owner';
    }

    /* 管理组和站长天然拥有编辑权，不用再单独授 editor */
    public static function isEditor($u = null)
    {
        $r = self::role($u);
        return $r === 'editor' || $r === 'admin' || $r === 'owner';
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

    public static function requireAdmin()
    {
        $u = self::requireUser();
        if (!self::isAdmin($u)) {
            self::fail(403, 'not_admin', '这个页面只有管理组能进。');
        }
        return $u;
    }

    public static function requireOwner()
    {
        $u = self::requireUser();
        if (!self::isOwner($u)) {
            self::fail(403, 'not_owner', '只有站长能改权限组。');
        }
        return $u;
    }

    /* ── 操作留痕 ──
       审核和授权都要记。写失败不该让主流程回滚 ——
       日志没记上是小事，投稿审不了是大事。 */
    public static function audit($action, $target, $detail = '')
    {
        try {
            $db = self::db('site');
            if (!$db) return;
            /* action 在部分 MySQL 版本里是保留字，加反引号保险 */
            $st = $db->prepare(
                'INSERT INTO `audit_log` (`actor`, `action`, `target`, `detail`, `at`, `ip`)
                 VALUES (?, ?, ?, ?, NOW(), ?)');
            /* 各列都按建表时的长度截一刀。严格模式下超长会报错，
               为了记日志把主流程搞挂了不值得。 */
            $st->execute([
                self::scut(self::uname(), 32),
                self::scut($action, 32),
                self::scut($target, 64),
                self::scut($detail, 255),
                self::ip(),
            ]);
        } catch (\PDOException $e) {
            error_log('[town-auth] audit write failed: ' . $e->getMessage());
        }
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

    /* ── 字符串长度（按字符数，不是字节数） ──
       中文一个字在 UTF-8 里占 3 字节，用 strlen 判断「标题至少 2 个字」
       会把「领地」算成 6 而通过，把限制彻底判错。

       mbstring 扩展在多数环境都有，但不是必装。缺了就退回
       用正则数一遍 UTF-8 字符 —— 慢一点，但不会因为环境差异
       让整个接口挂掉。 */
    public static function slen($s)
    {
        $s = (string)$s;
        if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
        $n = preg_match_all('/./us', $s);
        return $n === false ? strlen($s) : $n;
    }

    /* 按字符截断，同样带 mbstring 缺失时的退路 */
    public static function scut($s, $len)
    {
        $s = (string)$s;
        if (function_exists('mb_substr')) return mb_substr($s, 0, $len, 'UTF-8');
        if (preg_match_all('/./us', $s, $m) === false) return substr($s, 0, $len);
        return implode('', array_slice($m[0], 0, $len));
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
