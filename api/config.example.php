<?php
/* ============================================================
   配置模板 —— 服务器上把这个文件复制成 config.php 再填密码
   ------------------------------------------------------------
       cd /www/wwwroot/你的域名/api
       cp config.example.php config.php
       nano config.php          # 填三处密码

   config.php 在 .gitignore 里，不会进版本库，也不会被
   git pull 覆盖。所以填一次就行，以后我改代码不影响它。

   ★ 填完必须验证一件事 ★
   浏览器访问 https://你的域名/api/config.php
   必须返回 403 或空白。如果显示出源码，密码就泄露了 ——
   那说明 nginx 没拦住 api 目录，见 DEPLOY.md 的 nginx 配置段。
   ============================================================ */

return [

  /* ── AuthMe 数据库（只读） ──
     你的 MC 服托管在 starmc.cn，AuthMe 库在他们那边。
     两种情况：

     1. 服务商允许远程连接数据库
        host 填他们给的地址，并让他们放行你这台服务器的 IP
        （154.36.158.143）。强烈建议单独要一个只有 SELECT
        权限的账号，别用 root。

     2. 不允许远程连接（多数便宜托管都这样）
        那网站登录功能没法直连 AuthMe。可以先把 enabled
        改成 false 关掉登录，或者问服务商能不能开只读账号。 */
  'authme' => [
    'enabled' => true,
    'host'    => '127.0.0.1',      // ← 改成服务商给的数据库地址
    'port'    => 3306,
    'dbname'  => 'authme',
    'user'    => 'web_readonly',
    'pass'    => '',               // ← 填这里
    'table'   => 'authme',

    'col' => [
      'username'  => 'username',
      'realname'  => 'realname',
      'password'  => 'password',
      'salt'      => 'salt',
      'regdate'   => 'regdate',
      'lastlogin' => 'lastlogin',
      'regip'     => 'regip',
      'lastip'    => 'ip',
    ],

    'hash' => 'auto',
  ],

  /* ── 站点自己的库（读写） ──
     Wiki 词条、投稿、登录失败记录都在这里。
     这个库在你自己的东京服务器上，用宝塔建：
       数据库 → 添加数据库 → 名称 townsite → 字符集 utf8mb4
     宝塔会自动生成密码，复制过来填到下面。 */
  'site' => [
    'enabled' => true,
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'dbname'  => 'townsite',
    'user'    => 'townsite',
    'pass'    => '',               // ← 填这里（宝塔生成的密码）
  ],

  /* ── 安全设置 ── */
  'security' => [
    'rate_ip'   => ['max' => 10, 'window' => 600, 'lock' => 900],
    'rate_user' => ['max' => 5,  'window' => 600, 'lock' => 900],

    'session_lifetime' => 86400 * 7,

    /* 生产环境必须 true。宝塔申请好 SSL 证书后就能保持 true。
       临时用 IP 访问（还没配域名和证书）时才改 false，
       配好证书记得改回来 —— false 意味着登录 cookie
       可以在明文 HTTP 上传输，能被中间人截获。 */
    'require_https' => true,

    /* 同域部署留空数组。前后端不同域才需要填。 */
    'allow_origins' => [],
  ],

  /* ── 功能开关 ── */
  'features' => [
    'profile'      => true,
    'wiki_submit'  => true,
    'wiki_edit'    => true,
    'game_data'    => false,
    'show_ip'      => false,   // 生产环境别开，IP 属于敏感信息
  ],

  /* ── Wiki 编辑组 ──
     填能编辑词条的游戏 ID。这些人登录后能直接改 Wiki，
     所以只填你信得过的人。改完立即生效，不用重启。 */
  'wiki_editors' => [
    '镇长',
  ],
];
