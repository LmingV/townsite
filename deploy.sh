#!/usr/bin/env bash
# ============================================================
#  服务器端部署脚本
#  ------------------------------------------------------------
#  放在服务器上，每次要上线新改动就执行它：
#      cd /www/wwwroot/你的域名 && ./deploy.sh
#
#  它做四件事：拉代码 → 检查配置 → 查语法 → 修权限。
#  任何一步失败就停下，不会把坏代码留在线上。
#
#  首次使用前给执行权限：chmod +x deploy.sh
# ============================================================

set -euo pipefail          # 出错即停，未定义变量报错，管道错误不吞

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
  c_y "[!] config.php 里有数据库密码是空的，相关功能会连不上"
fi

# ── 3. 拉代码 ──
echo
echo "拉取最新代码…"
if [ ! -d .git ]; then
  c_r "[X] 这不是 git 仓库。首次部署请先 git clone"
  exit 1
fi

BEFORE="$(git rev-parse HEAD)"
git fetch --quiet origin
BRANCH="$(git rev-parse --abbrev-ref HEAD)"

# 用 reset --hard 而不是 pull：服务器上不该有本地修改，
# 有的话也应该丢掉，避免合并冲突卡住部署。
# config.php 不在版本库里，不会被这一步影响。
git reset --hard "origin/$BRANCH" --quiet
AFTER="$(git rev-parse HEAD)"

if [ "$BEFORE" = "$AFTER" ]; then
  echo "  已是最新（$(echo "$AFTER" | cut -c1-7)）"
else
  c_g "  更新：$(echo "$BEFORE" | cut -c1-7) → $(echo "$AFTER" | cut -c1-7)"
  git log --oneline "$BEFORE..$AFTER" 2>/dev/null | sed 's/^/    /' | head -10
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
  c_y "    代码已经拉下来了但有问题。回退上一个版本："
  c_y "        git reset --hard $BEFORE"
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

# ── 6. 权限 ──
# 宝塔的 nginx 跑在 www 用户下。属主不对会 403。
echo
if id www >/dev/null 2>&1; then
  chown -R www:www "$ROOT" 2>/dev/null || c_y "[!] 改属主失败，可能需要 sudo"
  c_g "[OK] 属主设为 www:www"
fi
# 目录 755、文件 644：够 nginx 读，不给多余写权限
find "$ROOT" -type d -not -path '*/.git/*' -exec chmod 755 {} \; 2>/dev/null || true
find "$ROOT" -type f -not -path '*/.git/*' -exec chmod 644 {} \; 2>/dev/null || true
chmod +x deploy.sh 2>/dev/null || true
# 配置文件收紧：只有属主能读，别人连读都不行
chmod 600 api/config.php 2>/dev/null || true
c_g "[OK] 权限已设置（config.php 已收紧为 600）"

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
