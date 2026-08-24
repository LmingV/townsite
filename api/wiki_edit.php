<?php
/* ============================================================
   Wiki 词条编辑（markdown，编辑组专用）
   ------------------------------------------------------------
   GET  ?action=list                 所有词条（公开，供 wiki.html 合并显示）
   GET  ?action=get&slug=xxx         取单篇原文（含 rev，编辑前必须先拿）
   GET  ?action=history&slug=xxx     修改历史
   GET  ?action=rev&slug=xxx&rev=3   某个历史版本的全文
   POST {action:'save', ...}         保存（新建或更新）
   POST {action:'revert', slug, rev} 回退到某个版本
   POST {action:'delete', slug}      软删除

   ── 冲突检测 ──
   前端编辑前拿到 rev，保存时带回来。若库里的 rev 已经变了，
   说明别人在这期间存过，返回 409 并附上对方的版本，让人自己合。
   不做自动合并 —— 静默合并出错比冲突提示更麻烦。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';

if (!Core::cfg('features.wiki_edit', true)) {
    Core::fail(404, 'disabled', 'Wiki 编辑功能未开启');
}
$db = Core::db('site');
if (!$db) {
    Core::fail(503, 'no_site_db', '站点库未启用。请在 config.php 里打开 site.enabled 并导入 schema.sql');
}

$CATS = ['start','land','money','play','build','trouble','rule'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ══════════════════ 读取（公开） ══════════════════ */
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        try {
            $st = $db->query(
                'SELECT slug, cat, title, summary, alias, body, rev, editor, updated
                   FROM wiki_pages WHERE deleted = 0
                  ORDER BY updated DESC LIMIT 300');
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-wiki] list failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取词条失败');
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'slug'    => $r['slug'],
                'cat'     => $r['cat'],
                'title'   => $r['title'],
                'summary' => $r['summary'],
                'alias'   => $r['alias'],
                'body'    => $r['body'],
                'rev'     => (int)$r['rev'],
                'editor'  => $r['editor'],
                'date'    => substr((string)$r['updated'], 0, 10),
            ];
        }
        Core::json(['ok' => true, 'items' => $out, 'canEdit' => Core::isEditor()]);
    }

    if ($action === 'get') {
        $slug = trim((string)($_GET['slug'] ?? ''));
        if ($slug === '') Core::fail(400, 'no_slug', '缺少 slug');
        try {
            $st = $db->prepare(
                'SELECT slug, cat, title, summary, alias, body, rev, editor, updated
                   FROM wiki_pages WHERE slug = ? AND deleted = 0');
            $st->execute([$slug]);
            $row = $st->fetch();
        } catch (\PDOException $e) {
            error_log('[town-wiki] get failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取失败');
        }
        if (!$row) Core::fail(404, 'not_found', '词条不存在');
        $row['rev'] = (int)$row['rev'];
        Core::json(['ok' => true, 'page' => $row, 'canEdit' => Core::isEditor()]);
    }

    if ($action === 'history') {
        $slug = trim((string)($_GET['slug'] ?? ''));
        if ($slug === '') Core::fail(400, 'no_slug', '缺少 slug');
        try {
            $st = $db->prepare(
                'SELECT r.rev, r.editor, r.note, r.at, CHAR_LENGTH(r.body) AS len
                   FROM wiki_revisions r JOIN wiki_pages p ON p.id = r.page_id
                  WHERE p.slug = ? ORDER BY r.rev DESC LIMIT 100');
            $st->execute([$slug]);
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-wiki] history failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取历史失败');
        }
        foreach ($rows as &$r) { $r['rev'] = (int)$r['rev']; $r['len'] = (int)$r['len']; }
        Core::json(['ok' => true, 'items' => $rows]);
    }

    if ($action === 'rev') {
        $slug = trim((string)($_GET['slug'] ?? ''));
        $rev  = (int)($_GET['rev'] ?? 0);
        if ($slug === '' || $rev < 1) Core::fail(400, 'bad_args', '参数不对');
        try {
            $st = $db->prepare(
                'SELECT r.rev, r.title, r.summary, r.alias, r.cat, r.body, r.editor, r.note, r.at
                   FROM wiki_revisions r JOIN wiki_pages p ON p.id = r.page_id
                  WHERE p.slug = ? AND r.rev = ?');
            $st->execute([$slug, $rev]);
            $row = $st->fetch();
        } catch (\PDOException $e) {
            error_log('[town-wiki] rev failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取失败');
        }
        if (!$row) Core::fail(404, 'not_found', '该版本不存在');
        $row['rev'] = (int)$row['rev'];
        Core::json(['ok' => true, 'rev' => $row]);
    }

    Core::fail(400, 'bad_action', '未知的 action');
}

/* ══════════════════ 写入（编辑组） ══════════════════ */
Core::requireMethod('POST');
Core::session();
Core::requireCsrf();
$u = Core::requireEditor();

$in     = Core::input();
$action = (string)($in['action'] ?? 'save');

/* ── 回退 ── */
if ($action === 'revert') {
    $slug = trim((string)($in['slug'] ?? ''));
    $rev  = (int)($in['rev'] ?? 0);
    if ($slug === '' || $rev < 1) Core::fail(400, 'bad_args', '参数不对');
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT id, rev FROM wiki_pages WHERE slug = ? FOR UPDATE');
        $st->execute([$slug]);
        $p = $st->fetch();
        if (!$p) { $db->rollBack(); Core::fail(404, 'not_found', '词条不存在'); }

        $st = $db->prepare(
            'SELECT title, summary, alias, cat, body FROM wiki_revisions
              WHERE page_id = ? AND rev = ?');
        $st->execute([$p['id'], $rev]);
        $old = $st->fetch();
        if (!$old) { $db->rollBack(); Core::fail(404, 'no_rev', '该版本不存在'); }

        $new = (int)$p['rev'] + 1;
        $st = $db->prepare(
            'UPDATE wiki_pages SET title=?, summary=?, alias=?, cat=?, body=?,
                    rev=?, editor=?, updated=NOW(), deleted=0 WHERE id=?');
        $st->execute([$old['title'], $old['summary'], $old['alias'], $old['cat'],
                      $old['body'], $new, $u['name'], $p['id']]);

        $st = $db->prepare(
            'INSERT INTO wiki_revisions
              (page_id, rev, title, summary, alias, cat, body, editor, note, at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())');
        $st->execute([$p['id'], $new, $old['title'], $old['summary'], $old['alias'],
                      $old['cat'], $old['body'], $u['name'], '回退到 r' . $rev]);
        $db->commit();
    } catch (\PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[town-wiki] revert failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '回退失败');
    }
    Core::json(['ok' => true, 'rev' => $new, 'message' => '已回退到 r' . $rev]);
}

/* ── 软删除 ── */
if ($action === 'delete') {
    $slug = trim((string)($in['slug'] ?? ''));
    if ($slug === '') Core::fail(400, 'no_slug', '缺少 slug');
    try {
        $st = $db->prepare('UPDATE wiki_pages SET deleted=1, editor=?, updated=NOW() WHERE slug=?');
        $st->execute([$u['name'], $slug]);
        if (!$st->rowCount()) Core::fail(404, 'not_found', '词条不存在');
    } catch (\PDOException $e) {
        error_log('[town-wiki] delete failed: ' . $e->getMessage());
        Core::fail(503, 'db_error', '删除失败');
    }
    Core::json(['ok' => true, 'message' => '已删除（历史保留，可恢复）']);
}

/* ── 保存 ── */
if ($action !== 'save') Core::fail(400, 'bad_action', '未知的 action');

$slug    = strtolower(trim((string)($in['slug']    ?? '')));
$cat     = trim((string)($in['cat']     ?? ''));
$title   = trim((string)($in['title']   ?? ''));
$summary = trim((string)($in['summary'] ?? ''));
$alias   = trim((string)($in['alias']   ?? ''));
$body    = (string)($in['body'] ?? '');
$note    = trim((string)($in['note']    ?? ''));
$baseRev = isset($in['rev']) ? (int)$in['rev'] : 0;   // 0 = 新建

/* slug 只允许小写字母数字和连字符 —— 它会进 URL，
   放开字符集会引出编码和路由问题 */
if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,62}$/', $slug)) {
    Core::fail(400, 'bad_slug',
        'slug 只能用小写字母、数字和连字符，2-63 个字符，例如 how-to-claim-land');
}
if (!in_array($cat, $CATS, true))       Core::fail(400, 'bad_cat', '分类不对');
if (Core::slen($title) < 2)             Core::fail(400, 'short_title', '标题太短');
if (Core::slen($title) > 60)            Core::fail(400, 'long_title', '标题请控制在 60 字以内');
if (Core::slen($summary) < 5)           Core::fail(400, 'short_summary', '摘要太短');
if (Core::slen($summary) > 200)         Core::fail(400, 'long_summary', '摘要请控制在 200 字以内');
if (Core::slen($alias) > 200)           Core::fail(400, 'long_alias', '同义词太长');
if (trim($body) === '')                 Core::fail(400, 'empty_body', '正文不能为空');
if (Core::slen($body) > 60000)          Core::fail(400, 'long_body', '正文超过 6 万字，请拆分');
if (Core::slen($note) > 100)            Core::fail(400, 'long_note', '修改说明请控制在 100 字以内');

try {
    $db->beginTransaction();

    /* FOR UPDATE 锁住这一行，两人同时点保存时排队处理，
       后来的那个会看到已经 +1 的 rev，从而被判为冲突。 */
    $st = $db->prepare('SELECT id, rev, title, body, editor, updated
                          FROM wiki_pages WHERE slug = ? FOR UPDATE');
    $st->execute([$slug]);
    $cur = $st->fetch();

    if (!$cur) {
        /* 新建 */
        $st = $db->prepare(
            'INSERT INTO wiki_pages
              (slug, cat, title, summary, alias, body, rev, editor, created, updated)
             VALUES (?,?,?,?,?,?,1,?,NOW(),NOW())');
        $st->execute([$slug, $cat, $title, $summary, $alias, $body, $u['name']]);
        $pid = (int)$db->lastInsertId();
        $newRev = 1;

        $st = $db->prepare(
            'INSERT INTO wiki_revisions
              (page_id, rev, title, summary, alias, cat, body, editor, note, at)
             VALUES (?,1,?,?,?,?,?,?,?,NOW())');
        $st->execute([$pid, $title, $summary, $alias, $cat, $body, $u['name'],
                      $note !== '' ? $note : '新建']);
        $db->commit();
        Core::json(['ok' => true, 'rev' => 1, 'created' => true, 'message' => '已创建']);
    }

    /* 更新前先比 rev */
    if ($baseRev !== (int)$cur['rev']) {
        $db->rollBack();
        Core::json([
            'ok'      => false,
            'error'   => 'conflict',
            'message' => '这篇词条在你编辑期间被 ' . $cur['editor'] . ' 改过了（现在是 r'
                       . $cur['rev'] . '，你基于 r' . $baseRev . '）。'
                       . '下面是对方的版本，请手动合并后再保存。',
            'current' => [
                'rev'     => (int)$cur['rev'],
                'editor'  => $cur['editor'],
                'updated' => $cur['updated'],
                'title'   => $cur['title'],
                'body'    => $cur['body'],
            ],
        ], 409);
    }

    /* 内容没变就不产生新版本，免得历史里堆一串空提交 */
    if ($cur['body'] === $body && $cur['title'] === $title) {
        $db->rollBack();
        Core::json(['ok' => true, 'rev' => (int)$cur['rev'],
                    'unchanged' => true, 'message' => '内容没有变化，未产生新版本']);
    }

    $newRev = (int)$cur['rev'] + 1;
    $st = $db->prepare(
        'UPDATE wiki_pages SET cat=?, title=?, summary=?, alias=?, body=?,
                rev=?, editor=?, updated=NOW() WHERE id=?');
    $st->execute([$cat, $title, $summary, $alias, $body, $newRev, $u['name'], $cur['id']]);

    $st = $db->prepare(
        'INSERT INTO wiki_revisions
          (page_id, rev, title, summary, alias, cat, body, editor, note, at)
         VALUES (?,?,?,?,?,?,?,?,?,NOW())');
    $st->execute([$cur['id'], $newRev, $title, $summary, $alias, $cat, $body,
                  $u['name'], $note]);
    $db->commit();
} catch (\PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    /* slug 唯一索引冲突：两人同时新建同名词条 */
    if ($e->getCode() === '23000') {
        Core::fail(409, 'dup_slug', '这个 slug 已经被占用了，换一个短名');
    }
    error_log('[town-wiki] save failed: ' . $e->getMessage());
    Core::fail(503, 'db_error', '保存失败');
}

Core::json(['ok' => true, 'rev' => $newRev, 'message' => '已保存为 r' . $newRev]);
