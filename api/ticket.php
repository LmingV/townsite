<?php
/* ============================================================
   工单系统
   ------------------------------------------------------------
   GET  /api/ticket.php?action=mine     我的工单（需登录）
   POST /api/ticket.php                 提交工单（需登录）

   管理接口在 admin.php 里（查看全部工单、回复、关闭）
   ============================================================ */

namespace Town\Auth;

require_once __DIR__ . '/lib/core.php';

if (!Core::cfg('features.ticket', true)) {
    Core::fail(404, 'disabled', '工单功能未开启');
}
$db = Core::db('site');
if (!$db) {
    Core::fail(503, 'no_site_db', '站点库未启用，工单功能不可用');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') Core::requireMethod('POST');

/* ══════════ 读取 ══════════ */
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'mine';

    if ($action === 'mine') {
        $u = Core::requireUser();
        try {
            $st = $db->prepare(
                'SELECT id, category, subject, content, status, reply,
                        created, replied_at, replied_by
                   FROM tickets WHERE author = ?
                  ORDER BY created DESC LIMIT 50');
            $st->execute([$u['name']]);
            $rows = $st->fetchAll();
        } catch (\PDOException $e) {
            error_log('[town-ticket] mine query failed: ' . $e->getMessage());
            Core::fail(503, 'db_error', '读取工单失败');
        }

        $items = [];
        foreach ($rows as $r) {
            $items[] = [
                'id'         => (int)$r['id'],
                'category'   => $r['category'],
                'subject'    => $r['subject'],
                'content'    => $r['content'],
                'status'     => $r['status'],
                'reply'      => $r['reply'],
                'created'    => $r['created'],
                'replied_at' => $r['replied_at'],
                'replied_by' => $r['replied_by'],
            ];
        }
        Core::json(['ok' => true, 'tickets' => $items]);
    }

    Core::fail(400, 'bad_action', '未知的 action');
}

/* ══════════ 提交 ══════════ */
Core::requireMethod('POST');
Core::session();
Core::requireCsrf();
$u = Core::requireUser();

$in       = Core::input();
$category = trim((string)($in['category'] ?? ''));
$subject  = trim((string)($in['subject']  ?? ''));
$content  = trim((string)($in['content']  ?? ''));

/* ── 校验 ── */
$CATS = ['bug', 'appeal', 'suggestion', 'other'];
$CAT_CN = [
    'bug'        => '漏洞/BUG',
    'appeal'     => '申诉',
    'suggestion' => '建议',
    'other'      => '其他',
];

if (!in_array($category, $CATS, true))   Core::fail(400, 'bad_cat', '请选择一个有效的分类');
if (Core::slen($subject) < 4)            Core::fail(400, 'short_subject', '标题太短了，至少 4 个字');
if (Core::slen($subject) > 60)           Core::fail(400, 'long_subject', '标题请控制在 60 字以内');
if (Core::slen($content) < 10)           Core::fail(400, 'short_content', '内容太短了，至少 10 个字');
if (Core::slen($content) > 2000)         Core::fail(400, 'long_content', '内容超过 2000 字，请精简一下');

/* ── 频率限制 ── */
try {
    $st = $db->prepare(
        'SELECT COUNT(*) FROM tickets
          WHERE author = ? AND created > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $st->execute([$u['name']]);
    if ((int)$st->fetchColumn() >= 3) {
        Core::fail(429, 'too_many', '一小时内最多提交 3 个工单');
    }

    /* 同一人重复提交同标题工单 */
    $st = $db->prepare(
        'SELECT COUNT(*) FROM tickets
          WHERE author = ? AND subject = ? AND status IN (\'open\',\'replied\')');
    $st->execute([$u['name'], $subject]);
    if ((int)$st->fetchColumn() > 0) {
        Core::fail(409, 'duplicate', '你已经提交过相同标题的工单了，请在原工单里补充');
    }

    $st = $db->prepare(
        'INSERT INTO tickets (author, category, subject, content, status, created, ip)
         VALUES (?,?,?,?,\'open\',NOW(),?)');
    $st->execute([$u['name'], $category, $subject, $content, Core::ip()]);
    $id = (int)$db->lastInsertId();
} catch (\PDOException $e) {
    error_log('[town-ticket] insert failed: ' . $e->getMessage());
    Core::fail(503, 'db_error', '提交失败，请稍后再试');
}

Core::json([
    'ok'      => true,
    'id'      => $id,
    'message' => '工单已提交，管理组会尽快处理。你可以在「我的工单」里查看进度。',
]);
