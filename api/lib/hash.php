<?php
/* ============================================================
   AuthMe 密码校验
   ------------------------------------------------------------
   AuthMe 支持十几种哈希算法，这里实现了实际会遇到的那几种。
   两个容易踩的坑，写在前面：

   1) SHA256 和 SALTEDSHA512 的前缀都是 $SHA$，光看前缀分不出来，
      只能靠哈希段的长度区分（64 位十六进制 = SHA256，128 位 = SHA512）。
   2) 比较必须用 hash_equals，普通 === 会因为提前返回而泄漏时序信息。

   这个文件不写库、不碰密码明文以外的任何东西，
   校验完密码明文就该被丢弃。
   ============================================================ */

namespace Town\Auth;

class Hash
{
    /* 校验密码。
       $plain    用户输入的明文
       $stored   数据库 password 列的值
       $salt     数据库 salt 列的值（多数算法用不到，传 null 即可）
       $algo     'auto' 或指定算法名
       返回 true/false；无法识别算法时抛异常，避免"静默拒绝所有人"。*/
    public static function verify($plain, $stored, $salt = null, $algo = 'auto')
    {
        $stored = (string)$stored;
        if ($stored === '') return false;

        if ($algo === 'auto' || $algo === null || $algo === '') {
            $algo = self::detect($stored);
        }
        $algo = strtoupper($algo);

        switch ($algo) {
            case 'SHA256':       return self::saltedDouble($plain, $stored, 'sha256');
            case 'SALTEDSHA512':
            case 'SHA512':       return self::saltedDouble($plain, $stored, 'sha512');
            case 'SALTED2MD5':   return self::saltedDouble($plain, $stored, 'md5');

            case 'BCRYPT':
            case 'BCRYPT2Y':
                /* password_verify 自己处理 salt 和 cost，也是时序安全的 */
                return password_verify($plain, $stored);

            case 'ARGON2':
            case 'ARGON2ID':
                return password_verify($plain, $stored);

            case 'PBKDF2':
                return self::pbkdf2($plain, $stored);

            case 'XFBCRYPT':
                /* XenForo 风格，密码列里塞的是序列化数据，少见，不支持 */
                throw new \RuntimeException('XFBCRYPT 需要额外解析 XenForo 数据，暂不支持');

            /* 无盐裸哈希。AuthMe 里已不推荐，但老服可能还在用 */
            case 'MD5':          return hash_equals(strtolower($stored), md5($plain));
            case 'SHA1':         return hash_equals(strtolower($stored), sha1($plain));
            case 'PLAINTEXT':
                /* 真有服这么存。照样支持，但要提醒用户改掉 */
                return hash_equals($stored, (string)$plain);

            /* 需要独立 salt 列的几种 */
            case 'MD5VB':
                if ($salt === null || $salt === '') return false;
                return hash_equals(strtolower($stored), md5(md5($plain) . $salt));
            case 'PHPBB':
            case 'WORDPRESS':
                throw new \RuntimeException("$algo 使用论坛自有的哈希实现，暂不支持");

            default:
                throw new \RuntimeException(
                    "无法识别的哈希算法：$algo。请在 config.php 里把 authme.hash " .
                    "改成 AuthMe config.yml 中 passwordHash 的实际值。"
                );
        }
    }

    /* 从密码字段的格式反推算法 */
    public static function detect($stored)
    {
        /* $2a$ / $2y$ / $2b$ 开头是 bcrypt */
        if (preg_match('/^\$2[aby]\$/', $stored))       return 'BCRYPT';
        if (strpos($stored, '$argon2') === 0)           return 'ARGON2';
        if (strpos($stored, '$pbkdf2') === 0)           return 'PBKDF2';

        /* $SHA$salt$hash —— SHA256 和 SALTEDSHA512 共用这个前缀，
           只能靠哈希段长度区分。这是本文件最容易出错的地方。 */
        if (strpos($stored, '$SHA$') === 0) {
            $p = explode('$', $stored);          // ['', 'SHA', salt, hash]
            if (count($p) >= 4) {
                $len = strlen($p[3]);
                if ($len === 64)  return 'SHA256';
                if ($len === 128) return 'SALTEDSHA512';
            }
            return 'SHA256';                     // 兜底按默认算法
        }

        if (strpos($stored, '$MD5$') === 0)             return 'SALTED2MD5';

        /* 裸哈希，只能按长度猜 */
        if (preg_match('/^[a-f0-9]{32}$/i', $stored))   return 'MD5';
        if (preg_match('/^[a-f0-9]{40}$/i', $stored))   return 'SHA1';
        if (preg_match('/^[a-f0-9]{64}$/i', $stored))   return 'SHA256_RAW';
        if (preg_match('/^[a-f0-9]{128}$/i', $stored))  return 'SHA512_RAW';

        return 'UNKNOWN';
    }

    /* $XXX$salt$hash 形式：hash = algo( algo(password) + salt ) */
    private static function saltedDouble($plain, $stored, $algo)
    {
        $p = explode('$', $stored);
        if (count($p) < 4 || $p[2] === '' || $p[3] === '') return false;
        $salt = $p[2];
        $want = $p[3];
        $got  = hash($algo, hash($algo, $plain) . $salt);
        return hash_equals(strtolower($want), $got);
    }

    /* AuthMe 的 PBKDF2 格式：$pbkdf2$iterations$salt$hash */
    private static function pbkdf2($plain, $stored)
    {
        $p = explode('$', $stored);
        if (count($p) < 5) return false;
        $iter = (int)$p[2];
        $salt = $p[3];
        $want = $p[4];
        if ($iter <= 0) return false;
        $got = hash_pbkdf2('sha1', $plain, $salt, $iter, 64, false);
        return hash_equals(strtolower($want), strtolower($got));
    }
}
