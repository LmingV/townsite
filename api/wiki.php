<?php
/* ============================================================
   Wiki 投稿
   ------------------------------------------------------------
   GET  /api/wiki.php?action=approved   已通过的词条（公开，供 wiki.html 合并显示）
   GET  /api/wiki.php?action=mine       我的投稿（需登录）
   POST /api/wiki.php                   提交投稿（需登录）

   全部写在站点库，不碰 AuthMe。作者名一律取自登录态，
   不接受前端传 author —— 否则谁都能冒名投稿。
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';

if (!Core::cfg('features.wiki_submit', true)) {
    Core::fail(404, 'disabled', '投稿功能未开启');
}
$db = Core::db('site');
if (!$db) {
    Core::fail(503, 'no_site_db', '站点库未启用，投稿功能不可用。请在 config.php 里打开 site.enabled');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') Core::requireMethod('POST');   // 走 CORS 预检分支

/* ══════════ 读取 ══════════ */
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'approved';

    if ($action === 'approved') {
        /* 公开数据，任何人可读。只给已通过的。 */
        try {
            $st = $db->query(
                'SELECT id, author, cat, title, summary, body, created, updated
                   FROM wiki_submissions WHERE status = \'approved\'
                  ORDER BY COALESCE(updated, created) DESC LIMIT 100');
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-auth] wiki approved query failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取词条失败');
        }
        Core::json(['ok' => true, 'items' => array_map(__NAMESPACE__ . '\\shape', $rows)]);
    }

    if ($action === 'mine') {
        $u = Core::requireUser();
        try {
            $st = $db->prepare(
                'SELECT id, cat, title, summary, status, note, created, updated
                   FROM wiki_submissions WHERE author = ? ORDER BY created DESC LIMIT 50');
            $st->execute([$u['name']]);
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-auth] wiki mine query failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取投稿失败');
        }
        Core::json(['ok' => true, 'items' => $rows]);
    }

    Core::fail(400, 'bad_action', '未知的 action');
}

/* ══════════ 提交 ══════════ */
Core::requireMethod('POST');
Core::session();
Core::requireCsrf();
$u = Core::requireUser();

$in      = Core::input();
$cat     = trim((string)($in['cat']     ?? ''));
$title   = trim((string)($in['title']   ?? ''));
$summary = trim((string)($in['summary'] ?? ''));
$body    = trim((string)($in['body']    ?? ''));

/* ── 校验 ──
   分类必须是白名单里的，和 wiki.html 的 WIKI.categories 对应。
   加了新分类记得同步这里。 */
$CATS = ['start','land','money','play','build','trouble','rule'];

if (!in_array($cat, $CATS, true))            Core::fail(400, 'bad_cat',   '请选择一个有效的分类');
if (mb_strlen($title) < 4)                   Core::fail(400, 'short_title',  '标题太短了，至少 4 个字');
if (mb_strlen($title) > 60)                  Core::fail(400, 'long_title',   '标题请控制在 60 字以内');
if (mb_strlen($summary) < 10)                Core::fail(400, 'short_summary','摘要太短了，至少 10 个字');
if (mb_strlen($summary) > 200)               Core::fail(400, 'long_summary', '摘要请控制在 200 字以内');
if (mb_strlen($body) < 30)                   Core::fail(400, 'short_body',   '正文太短了，至少 30 个字');
if (mb_strlen($body) > 8000)                 Core::fail(400, 'long_body',    '正文超过 8000 字，请拆成多篇');

/* ── 投稿频率限制 ──
   防止一个人刷满审核队列 */
try {
    $st = $db->prepare(
        'SELECT COUNT(*) FROM wiki_submissions
          WHERE author = ? AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $st->execute([$u['name']]);
    if ((int)$st->fetchColumn() >= 5) {
        Core::fail(429, 'too_many', '一小时内最多投 5 篇，先歇会儿吧');
    }

    /* 同一人重复提交同名词条，直接挡掉 */
    $st = $db->prepare(
        'SELECT COUNT(*) FROM wiki_submissions
          WHERE author = ? AND title = ? AND status = \'pending\'');
    $st->execute([$u['name'], $title]);
    if ((int)$st->fetchColumn() > 0) {
        Core::fail(409, 'duplicate', '你已经投过一篇同名词条了，正在等审核');
    }

    /* 正文按原文存。展示时再转义 —— 存的时候转义会导致
       二次编辑越转越乱。 */
    $st = $db->prepare(
        'INSERT INTO wiki_submissions (author, cat, title, summary, body, status, created, ip)
         VALUES (?,?,?,?,?,\'pending\',NOW(),?)');
    $st->execute([$u['name'], $cat, $title, $summary, $body, Core::ip()]);
    $id = (int)$db->lastInsertId();
} catch (\PDOException $e) {
    error_log('[town-auth] wiki insert failed: ' . $e->getMessage());
    Core::fail(503, 'db_error', '提交失败，请稍后再试');
}

Core::json([
    'ok'      => true,
    'id'      => $id,
    'message' => '投稿已提交，等管理组看过就会发布。可以在「我的投稿」里查看进度。',
]);


/* 给前台用的字段整形。正文在这里不做 HTML 转换，
   交给前端按纯文本渲染，避免任何注入可能。 */
function shape($r)
{
    return [
        'id'      => (int)$r['id'],
        'author'  => $r['author'],
        'cat'     => $r['cat'],
        'title'   => $r['title'],
        'summary' => $r['summary'],
        'body'    => $r['body'],
        'date'    => substr((string)($r['updated'] ?: $r['created']), 0, 10),
    ];
}
