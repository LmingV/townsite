#!/usr/bin/env bash
# ============================================================
#  服务器端部署脚本
#  ------------------------------------------------------------
#  Git 部署：bash deploy.sh
#  ZIP/SFTP 上传：bash deploy.sh --no-pull
#
#  它做四件事：拉代码 → 检查配置 → 查语法 → 修权限。
#  任何一步失败就停下，不会把坏代码留在线上。
#
#  首次使用前给执行权限：chmod +x deploy.sh
# ============================================================

set -euo pipefail          # 出错即停，未定义变量报错，管道错误不吞

PULL_CODE=1
if [ "${1:-}" = "--no-pull" ]; then
  PULL_CODE=0
elif [ "$#" -gt 0 ]; then
  echo "用法：bash deploy.sh [--no-pull]"
  exit 2
fi

cd "$(dirname "$0")"
ROOT="$(pwd)"

c_r() { printf '\033[31m%s\033[0m\n' "$*"; }
c_g() { printf '\033[32m%s\033[0m\n' "$*"; }
c_y() { printf '\033[33m%s\033[0m\n' "$*"; }

echo
c_g "=========================================="
c_g "  永恒流光官网 · 部署"
c_g "=========================================="
echo "  目录：$ROOT"
echo

# ── 1. 找 PHP ──
# 宝塔的 PHP 不在 PATH 里，装在 /www/server/php/版本号/bin/
PHP=""
if command -v php >/dev/null 2>&1; then
  PHP="$(command -v php)"
else
  # 挑版本号最大的那个
  for d in $(ls -d /www/server/php/*/bin 2>/dev/null | sort -rV); do
    if [ -x "$d/php" ]; then PHP="$d/php"; break; fi
  done
fi
if [ -z "$PHP" ]; then
  c_r "[X] 找不到 php。宝塔里装了 PHP 吗？"
  exit 1
fi
c_g "[OK] PHP: $("$PHP" -r 'echo PHP_VERSION;') ($PHP)"

if ! "$PHP" -r 'exit(version_compare(PHP_VERSION, "8.1.0", ">=") ? 0 : 1);'; then
  c_r "[X] PHP 版本过低，需要 PHP 8.1 或更高"
  exit 1
fi

MISSING_EXT=""
for ext in pdo_mysql mbstring openssl; do
  if ! "$PHP" -r "exit(extension_loaded('$ext') ? 0 : 1);"; then
    MISSING_EXT="$MISSING_EXT $ext"
  fi
done
if [ -n "$MISSING_EXT" ]; then
  c_r "[X] 缺少 PHP 扩展：$MISSING_EXT"
  exit 1
fi
c_g "[OK] PHP 扩展：pdo_mysql mbstring openssl"

# ── 2. 配置文件必须就位 ──
# 这一步放在拉代码之前：config.php 不在版本库里，
# 万一没建，拉完代码网站会直接 500。
if [ ! -f api/config.php ]; then
  c_r "[X] api/config.php 不存在"
  echo
  echo "  第一次部署需要先建配置文件："
  echo "      cp api/config.example.php api/config.php"
  echo "      nano api/config.php        # 填数据库密码"
  echo
  exit 1
fi
c_g "[OK] 配置文件就位"

# 检查密码是否还是空的（新手最常见的疏漏）
if "$PHP" -r '
$c = include "api/config.php";
$bad = [];
if (($c["site"]["enabled"] ?? false) && ($c["site"]["pass"] ?? "") === "") $bad[] = "site";
if (($c["authme"]["enabled"] ?? true) && ($c["authme"]["pass"] ?? "") === "") $bad[] = "authme";
exit($bad ? 1 : 0);
' 2>/dev/null; then
  c_g "[OK] 数据库密码已填"
else
  c_r "[X] config.php 里有已启用数据库的密码为空"
  exit 1
fi

# 站长没配的话谁也进不去管理后台，包括你自己。
# config.php 不在版本库里，所以每次都值得提醒一次。
if "$PHP" -r '
$c = include "api/config.php";
exit(trim((string)($c["site_owner"] ?? "")) === "" ? 1 : 0);
' 2>/dev/null; then
  c_g "[OK] 站长已配置"
else
  c_r "[X] config.php 里没有 site_owner，管理后台谁也进不去"
  exit 1
fi

if "$PHP" -r '$c=include "api/config.php"; exit(($c["security"]["require_https"] ?? true) ? 0 : 1);'; then
  c_g "[OK] 已强制 HTTPS"
else
  c_y "[!] require_https=false，只能用于证书配置前的临时调试"
fi

# ── 3. 拉代码 ──
if [ "$PULL_CODE" -eq 1 ]; then
  echo
  echo "拉取最新代码…"
  if [ ! -d .git ]; then
    c_r "[X] 这不是 Git 仓库；ZIP/SFTP 上传请运行 bash deploy.sh --no-pull"
    exit 1
  fi

  BRANCH="$(git rev-parse --abbrev-ref HEAD)"
  if [ "$BRANCH" = "HEAD" ]; then
    c_r "[X] 当前处于 detached HEAD，请先切换到部署分支"
    exit 1
  fi
  if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    c_r "[X] 服务器上有未提交的源码改动，已停止更新以免覆盖"
    git status --short --untracked-files=no
    exit 1
  fi

  BEFORE="$(git rev-parse HEAD)"
  git fetch --quiet origin
  git merge --ff-only "origin/$BRANCH" --quiet
  AFTER="$(git rev-parse HEAD)"

  if [ "$BEFORE" = "$AFTER" ]; then
    echo "  已是最新（$(echo "$AFTER" | cut -c1-7)）"
  else
    c_g "  更新：$(echo "$BEFORE" | cut -c1-7) → $(echo "$AFTER" | cut -c1-7)"
    git log --oneline "$BEFORE..$AFTER" 2>/dev/null | sed 's/^/    /' | head -10
  fi
else
  c_y "[!] 已跳过 Git 拉取，检查当前上传的文件"
fi

# ── 4. PHP 语法检查 ──
# 关键一步。语法错误会让整站白屏，必须在生效前拦住。
echo
echo "检查 PHP 语法…"
BAD=0
while IFS= read -r f; do
  if ! out="$("$PHP" -l "$f" 2>&1)"; then
    c_r "  [X] $f"
    echo "$out" | sed 's/^/      /'
    BAD=$((BAD+1))
  fi
done < <(find api -name '*.php' -type f)

if [ "$BAD" -gt 0 ]; then
  c_r "[X] $BAD 个文件有语法错误"
  exit 1
fi
c_g "[OK] PHP 语法全部正常"

# ── 5. 确认危险文件没上线 ──
echo
for f in api/config.dev.php api/selftest.php; do
  if [ -f "$f" ]; then
    c_r "[X] $f 出现在服务器上了！"
    echo "    这个文件含测试账号或会暴露环境信息，必须删除："
    echo "        rm $f"
    echo "    同时检查 .gitignore 是否漏了它。"
    exit 1
  fi
done
c_g "[OK] 开发专用文件没有上线"

# ── 5.5 新表建了没 ──
# schema.sql 加了表但没导的话，后台会报错。
# 这里只查不建 —— 自动执行 DDL 太容易在出错时留下半截结构。
echo
MISS="$("$PHP" -r '
$c = include "api/config.php";
$s = $c["site"] ?? null;
if (!$s || !($s["enabled"] ?? false)) { echo "__DISABLED__"; exit; }
try {
    $pdo = new PDO(
        sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4",
                $s["host"], (int)$s["port"], $s["dbname"]),
        $s["user"], $s["pass"],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    $miss = [];
    $tables = [
        "login_fails", "wiki_pages", "wiki_revisions", "wiki_submissions",
        "site_roles", "audit_log", "tickets"
    ];
    if (($c["features"]["game_data"] ?? false)) {
        $tables = array_merge($tables, [
            "player_stats", "player_badges", "badge_definitions",
            "player_emails", "email_verify"
        ]);
    }
    foreach ($tables as $t) {
        $q = $pdo->prepare("SHOW TABLES LIKE ?");
        $q->execute([$t]);
        if (!$q->fetch()) $miss[] = $t;
    }
    echo implode(" ", $miss);
} catch (Exception $e) { echo "__ERR__"; }
' 2>/dev/null || echo "__ERR__")"

if [ "$MISS" = "__DISABLED__" ]; then
  c_y "[!] 站点数据库未启用，Wiki 投稿、工单和后台不可用"
elif [ "$MISS" = "__ERR__" ]; then
  c_r "[X] 连不上站点数据库"
  exit 1
elif [ -n "$MISS" ]; then
  c_r "[X] 缺少数据表：$MISS"
  c_y "    导入完整结构（可重复执行，不会动已有数据）："
  c_y "        mysql -u townsite -p townsite < api/schema.sql"
  exit 1
else
  c_g "[OK] 数据表齐全"
fi

# ── 6. 权限 ──
# 宝塔的 PHP-FPM 通常跑在 www 用户下。源码保留部署用户为属主，
# 只把组设为 www，避免 Web 进程拥有修改源码的权限。
echo
if id www >/dev/null 2>&1; then
  chgrp -R www "$ROOT" 2>/dev/null || c_y "[!] 修改文件组失败，可能需要 sudo"
  find "$ROOT" -type d -not -path '*/.git/*' -exec chmod 750 {} \; 2>/dev/null || true
  find "$ROOT" -type f -not -path '*/.git/*' -exec chmod 640 {} \; 2>/dev/null || true
  chmod 640 api/config.php 2>/dev/null || true
  c_g "[OK] 文件组设为 www，Web 进程只有读取权限"
else
  find "$ROOT" -type d -not -path '*/.git/*' -exec chmod 755 {} \; 2>/dev/null || true
  find "$ROOT" -type f -not -path '*/.git/*' -exec chmod 644 {} \; 2>/dev/null || true
  chmod 600 api/config.php 2>/dev/null || true
  c_y "[!] 系统没有 www 用户，请确认 PHP-FPM 用户能读取站点文件"
fi
chmod +x deploy.sh 2>/dev/null || true
if [ -d .git ]; then chmod -R go-rwx .git 2>/dev/null || true; fi

echo
c_g "=========================================="
c_g "  部署完成"
c_g "=========================================="
echo
c_y "  上线后请手动验证这两件事："
echo "    1. 浏览器访问 https://你的域名/api/config.php"
echo "       必须是 403 或空白。显示出源码 = 密码泄露。"
echo "    2. 访问首页，确认在线人数和 Wiki 正常。"
echo
