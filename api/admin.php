<?php
/* ============================================================
   /api/admin.php  —— 管理后台接口
   ------------------------------------------------------------
   GET  ?action=pending            待审投稿列表           管理组
   GET  ?action=submission&id=N    某篇投稿全文           管理组
   GET  ?action=roles              权限组名单             管理组
   GET  ?action=find_user&q=xxx    在 AuthMe 里找玩家     管理组
   GET  ?action=audit              最近的操作留痕         管理组

   POST {action:'approve', id, slug}          通过并发布   管理组
   POST {action:'reject',  id, note}          退回         管理组
   POST {action:'set_role', username, role}   设权限       见下
   POST {action:'remove_role', username}      撤权限       见下

   权限边界：
     审核投稿      → 管理组（admin / owner）
     设/撤编辑组   → 管理组
     设/撤管理组   → 只有站长（owner）
   管理组之间互不干涉，是为了防止一个管理号被盗后连锁提权。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';
require_once __DIR__ . '/lib/authme.php';

$CATS = ['start','land','money','play','build','trouble','rule'];

/* ══════════ 读 ══════════ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    Core::session();
    $me = Core::requireAdmin();
    $db = Core::db('site');
    if (!$db) Core::fail(503, 'db_unavailable', '站点数据库连不上');

    $action = $_GET['action'] ?? 'pending';

    /* ── 待审投稿 ──
       只回列表需要的字段，正文按需再取，免得列表页拖一堆 TEXT。 */
    if ($action === 'pending') {
        $st = $db->query(
            'SELECT id, author, cat, title, summary, created,
                    CHAR_LENGTH(body) AS len
               FROM wiki_submissions
              WHERE status = \'pending\'
              ORDER BY created ASC
              LIMIT 200');
        $rows = [];
        foreach ($st->fetchAll() as $r) {
            $rows[] = [
                'id'      => (int)$r['id'],
                'author'  => $r['author'],
                'cat'     => $r['cat'],
                'title'   => $r['title'],
                'summary' => $r['summary'],
                'created' => $r['created'],
                'len'     => (int)$r['len'],
            ];
        }

        /* 顺手带上各状态的条数，后台顶部显示 */
        $cnt = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($db->query('SELECT status, COUNT(*) c FROM wiki_submissions GROUP BY status')
                    ->fetchAll() as $r) {
            $cnt[$r['status']] = (int)$r['c'];
        }

        Core::json(['ok' => true, 'list' => $rows, 'count' => $cnt]);
    }

    /* ── 单篇全文 ──
       正文原样返回，前端用 textContent 渲染，不做 HTML 转换。 */
    if ($action === 'submission') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id < 1) Core::fail(400, 'bad_id', '参数不对');

        $st = $db->prepare('SELECT * FROM wiki_submissions WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) Core::fail(404, 'not_found', '这篇投稿不存在，可能已被处理');

        $out = [
            'id'      => (int)$r['id'],
            'author'  => $r['author'],
            'cat'     => $r['cat'],
            'title'   => $r['title'],
            'summary' => $r['summary'],
            'body'    => $r['body'],
            'status'  => $r['status'],
            'note'    => $r['note'],
            'created' => $r['created'],
            'updated' => $r['updated'],
        ];
        /* 投稿人 IP 只给站长看，管理组看不到 */
        if (Core::isOwner($me)) $out['ip'] = $r['ip'];

        /* 建议一个 slug，管理点通过时可以直接用或改掉。
           标题多是中文，转不出可读的拉丁 slug，所以用 sub-id 兜底。 */
        $guess = strtolower($r['title']);
        $guess = preg_replace('/[^a-z0-9]+/', '-', $guess);
        $guess = trim((string)$guess, '-');
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,62}$/', (string)$guess)) {
            $guess = 'sub-' . (int)$r['id'];
        }
        $out['slug_guess'] = $guess;

        Core::json(['ok' => true, 'sub' => $out]);
    }

    /* ── 权限组名单 ── */
    if ($action === 'roles') {
        $rows = [];
        foreach ($db->query(
            'SELECT username, realname, role, granted_by, granted_at, note
               FROM site_roles ORDER BY role DESC, granted_at DESC')->fetchAll() as $r) {
            $rows[] = [
                'username'   => $r['username'],
                'realname'   => $r['realname'] ?: $r['username'],
                'role'       => $r['role'],
                'granted_by' => $r['granted_by'],
                'granted_at' => $r['granted_at'],
                'note'       => $r['note'],
            ];
        }
        Core::json([
            'ok'      => true,
            'list'    => $rows,
            'owner'   => (string)Core::cfg('site_owner', ''),
            /* config 里的兜底名单也显示出来，否则「明明有人能编辑
               却不在名单里」会让人困惑 */
            'cfg_editors' => array_values((array)Core::cfg('wiki_editors', [])),
            'can_set_admin' => Core::isOwner($me),
        ]);
    }

    /* ── 找玩家 ──
       从 AuthMe 库模糊搜，用来确认要授权的 ID 是否真的存在，
       避免把权限授给一个拼错的名字。 */
    if ($action === 'find_user') {
        $q = trim((string)($_GET['q'] ?? ''));
        if (Core::slen($q) < 2) Core::fail(400, 'short_q', '至少输入 2 个字符');

        $adb = Core::db('authme');
        if (!$adb) Core::fail(503, 'authme_disabled', '账号数据库未启用');

        $c   = Core::cfg('authme');
        $col = $c['col'];
        $tbl = '`' . str_replace('`', '', $c['table']) . '`';
        $cu  = '`' . str_replace('`', '', $col['username']) . '`';
        $cr  = !empty($col['realname'])
             ? '`' . str_replace('`', '', $col['realname']) . '`' : $cu;

        try {
            $st = $adb->prepare(
                "SELECT $cu AS username, $cr AS realname FROM $tbl
                  WHERE $cu LIKE ? ORDER BY $cu LIMIT 20");
            /* 转义 LIKE 的通配符，否则输入 % 会列出全部玩家 */
            $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'],
                                strtolower($q));
            $st->execute(['%' . $like . '%']);
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-admin] find_user failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '查询失败');
        }

        /* 顺带标出这些人当前的角色，前端好显示「已是编辑组」 */
        $out = [];
        foreach ($rows as $r) {
            $lname = strtolower($r['username']);
            $st2 = $db->prepare('SELECT role FROM site_roles WHERE username = ?');
            $st2->execute([$lname]);
            $cur = $st2->fetchColumn();
            $out[] = [
                'username' => $lname,
                'realname' => $r['realname'] ?: $r['username'],
                'role'     => $cur ?: '',
                'is_owner' => strtolower(trim((string)Core::cfg('site_owner', ''))) === $lname,
            ];
        }
        Core::json(['ok' => true, 'list' => $out]);
    }

    /* ── 操作留痕 ── */
    if ($action === 'audit') {
        $st = $db->query(
            'SELECT `actor`, `action`, `target`, `detail`, `at` FROM `audit_log`
              ORDER BY `id` DESC LIMIT 100');
        Core::json(['ok' => true, 'list' => $st->fetchAll()]);
    }

    Core::fail(400, 'bad_action', '未知的 action');
}

/* ══════════ 写 ══════════ */
Core::requireMethod('POST');
Core::session();
Core::requireCsrf();
$me = Core::requireAdmin();
$db = Core::db('site');
if (!$db) Core::fail(503, 'db_unavailable', '站点数据库连不上');

$in     = Core::input();
$action = (string)($in['action'] ?? '');

/* ── 通过投稿 ──
   把投稿内容落成一篇正式词条，同时把投稿标记 approved。
   两件事放一个事务里：不能出现「词条建了但投稿还挂着待审」
   或者反过来的半成品状态。 */
if ($action === 'approve') {
    $id   = (int)($in['id'] ?? 0);
    $slug = strtolower(trim((string)($in['slug'] ?? '')));
    if ($id < 1) Core::fail(400, 'bad_id', '参数不对');
    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,62}$/', $slug)) {
        Core::fail(400, 'bad_slug',
            'slug 只能用小写字母、数字和连字符，2-63 个字符。中文标题可以用 sub-' . $id);
    }

    try {
        $db->beginTransaction();

        /* 锁住这一行。两个管理同时点通过时，后来的那个会看到
           status 已经不是 pending，从而被下面挡掉。 */
        $st = $db->prepare(
            'SELECT * FROM wiki_submissions WHERE id = ? FOR UPDATE');
        $st->execute([$id]);
        $r = $st->fetch();

        if (!$r) {
            $db->rollBack();
            Core::fail(404, 'not_found', '这篇投稿不存在');
        }
        if ($r['status'] !== 'pending') {
            $db->rollBack();
            Core::fail(409, 'already_done',
                '这篇投稿已经被处理过了（当前状态：' . $r['status'] . '），刷新看看');
        }

        $cat = in_array($r['cat'], $CATS, true) ? $r['cat'] : 'start';

        /* 建词条。slug 撞了就报错让管理换一个 —— 不自动加后缀，
           因为自动生成的 slug 后面没人看得懂。 */
        $st = $db->prepare(
            'INSERT INTO wiki_pages
              (slug, cat, title, summary, alias, body, rev, editor, created, updated)
             VALUES (?,?,?,?,\'\',?,1,?,NOW(),NOW())');
        $st->execute([$slug, $cat, $r['title'], $r['summary'], $r['body'], $r['author']]);
        $pid = (int)$db->lastInsertId();

        /* 首个版本的作者记投稿人，不是审核人 —— 内容是他写的 */
        $st = $db->prepare(
            'INSERT INTO wiki_revisions
              (page_id, rev, title, summary, alias, cat, body, editor, note, at)
             VALUES (?,1,?,?,\'\',?,?,?,?,NOW())');
        $st->execute([$pid, $r['title'], $r['summary'], $cat, $r['body'], $r['author'],
                      '投稿通过（审核：' . $me['name'] . '）']);

        $st = $db->prepare(
            'UPDATE wiki_submissions SET status=\'approved\', updated=NOW() WHERE id=?');
        $st->execute([$id]);

        $db->commit();
    } catch (\PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->getCode() === '23000') {
            Core::fail(409, 'dup_slug',
                'slug「' . $slug . '」已经被别的词条占用了，换一个');
        }
        error_log('[town-admin] approve failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '操作失败，请稍后再试');
    }

    Core::audit('approve', (string)$id, $r['title'] . ' → ' . $slug);
    Core::json(['ok' => true, 'slug' => $slug,
                'message' => '已通过并发布为 /' . $slug]);
}

/* ── 退回投稿 ──
   必须写原因。投稿人能在「我的投稿」里看到这句话，
   不写原因的退回等于让人白等一场。 */
if ($action === 'reject') {
    $id   = (int)($in['id'] ?? 0);
    $note = trim((string)($in['note'] ?? ''));
    if ($id < 1) Core::fail(400, 'bad_id', '参数不对');
    if (Core::slen($note) < 4)   Core::fail(400, 'short_note', '请写一句退回原因，投稿人能看到');
    if (Core::slen($note) > 200) Core::fail(400, 'long_note', '原因请控制在 200 字以内');

    try {
        $st = $db->prepare(
            'UPDATE wiki_submissions SET status=\'rejected\', note=?, updated=NOW()
              WHERE id=? AND status=\'pending\'');
        $st->execute([$note, $id]);
        if ($st->rowCount() === 0) {
            Core::fail(409, 'already_done', '这篇投稿不存在或已被处理，刷新看看');
        }
    } catch (\PDOException $e) {
        error_log('[town-admin] reject failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '操作失败');
    }

    Core::audit('reject', (string)$id, $note);
    Core::json(['ok' => true, 'message' => '已退回，投稿人能看到你写的原因']);
}

/* ── 设权限组 ──
   editor 管理组能设，admin 只有站长能设。 */
if ($action === 'set_role') {
    $target = strtolower(trim((string)($in['username'] ?? '')));
    $role   = (string)($in['role'] ?? '');
    $note   = trim((string)($in['note'] ?? ''));

    if ($target === '')                          Core::fail(400, 'no_user', '请填游戏 ID');
    if (Core::slen($target) > 32)                Core::fail(400, 'long_user', 'ID 过长');
    if (!in_array($role, ['editor','admin'], true)) Core::fail(400, 'bad_role', '权限组只能是编辑组或管理组');
    if (Core::slen($note) > 200)                 Core::fail(400, 'long_note', '备注太长');

    /* 任命管理组是站长专属 */
    if ($role === 'admin' && !Core::isOwner($me)) {
        Core::fail(403, 'not_owner',
            '只有站长能任命管理组。你可以设定编辑组。');
    }

    /* 站长本人不入表。他的身份来自配置文件，
       往表里塞一条只会让人误以为可以在这里改掉他。 */
    $owner = strtolower(trim((string)Core::cfg('site_owner', '')));
    if ($target === $owner) {
        Core::fail(400, 'is_owner',
            '这是站长账号，权限写在配置文件里，不用也不能在这里设。');
    }

    /* 管理组不能改另一个管理组的权限（包括把人从 admin 降成 editor） */
    if (!Core::isOwner($me)) {
        $st = $db->prepare('SELECT role FROM site_roles WHERE username = ?');
        $st->execute([$target]);
        if ($st->fetchColumn() === 'admin') {
            Core::fail(403, 'target_is_admin',
                '对方是管理组，只有站长能改管理组的权限。');
        }
    }

    /* 确认这个 ID 真的存在，防止把权限授给拼错的名字 ——
       授给不存在的 ID 不会立刻报错，等发现时早忘了。 */
    $real = $target;
    $adb  = Core::db('authme');
    if ($adb) {
        $row = AuthMe::find($target);
        if (!$row) {
            Core::fail(404, 'no_such_player',
                '游戏里没有这个 ID：' . $target . '。检查一下拼写，或先让他进服注册。');
        }
        /* AuthMe 的 realname 是 varchar(255)，site_roles 只留了 32。
           不截断的话严格模式下会直接报错，而不是默默存进去。 */
        $real = Core::scut($row['realname'] ?: $row['username'], 32);
    }

    try {
        $st = $db->prepare(
            'INSERT INTO site_roles (username, realname, role, granted_by, granted_at, note)
             VALUES (?,?,?,?,NOW(),?)
             ON DUPLICATE KEY UPDATE
               realname=VALUES(realname), role=VALUES(role),
               granted_by=VALUES(granted_by), granted_at=NOW(), note=VALUES(note)');
        $st->execute([$target, $real, $role,
                      Core::scut($me['name'], 32), $note]);
    } catch (\PDOException $e) {
        error_log('[town-admin] set_role failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '操作失败');
    }

    Core::audit('set_role', $target, $role . ($note !== '' ? '（' . $note . '）' : ''));
    Core::json([
        'ok'      => true,
        'message' => $real . ' 已设为' . ($role === 'admin' ? '管理组' : '编辑组') . '，下次他刷新页面生效',
    ]);
}

/* ── 撤销权限组 ── */
if ($action === 'remove_role') {
    $target = strtolower(trim((string)($in['username'] ?? '')));
    if ($target === '') Core::fail(400, 'no_user', '请填游戏 ID');

    $owner = strtolower(trim((string)Core::cfg('site_owner', '')));
    if ($target === $owner) {
        Core::fail(400, 'is_owner', '站长权限写在配置文件里，不能在这里撤。');
    }

    $st = $db->prepare('SELECT role FROM site_roles WHERE username = ?');
    $st->execute([$target]);
    $cur = $st->fetchColumn();
    if (!$cur) Core::fail(404, 'not_found', '他本来就不在任何权限组里');

    /* 撤管理组也是站长专属 */
    if ($cur === 'admin' && !Core::isOwner($me)) {
        Core::fail(403, 'not_owner', '只有站长能撤销管理组。');
    }

    try {
        $st = $db->prepare('DELETE FROM site_roles WHERE username = ?');
        $st->execute([$target]);
    } catch (\PDOException $e) {
        error_log('[town-admin] remove_role failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '操作失败');
    }

    Core::audit('remove_role', $target, '原为 ' . $cur);

    /* config 里的兜底名单不受影响，提醒一句免得以为没生效 */
    $extra = '';
    foreach ((array)Core::cfg('wiki_editors', []) as $cfgName) {
        if (strtolower(trim((string)$cfgName)) === $target) {
            $extra = '。注意他还在 config.php 的 wiki_editors 兜底名单里，'
                   . '要完全撤销请一并删掉那行';
            break;
        }
    }

    Core::json(['ok' => true, 'message' => '已撤销 ' . $target . ' 的权限组' . $extra]);
}

Core::fail(400, 'bad_action', '未知的 action');
