#!/usr/bin/env bash
# 游戏插件 API 的只读自检。不会修改数据库。

set -euo pipefail
cd "$(dirname "$0")"

red() { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }

PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then PHP_BIN="$(command -v php || true)"; fi
if [ -z "$PHP_BIN" ] || [ ! -x "$PHP_BIN" ]; then
  red "[X] 找不到 PHP CLI；可通过 PHP_BIN=/路径/php 指定"
  exit 1
fi

if [ ! -f api/config.php ]; then
  red "[X] 缺少 api/config.php"
  exit 1
fi

for file in api/game.php api/profile.php profile.html api/schema.sql; do
  if [ ! -f "$file" ]; then red "[X] 缺少 $file"; exit 1; fi
done
green "[OK] 游戏 API 文件齐全"

"$PHP_BIN" -r '
$c = require "api/config.php";
if (!($c["features"]["game_data"] ?? false)) {
    fwrite(STDERR, "[!] features.game_data 尚未开启\n");
}
if (strlen((string)($c["game_api_secret"] ?? "")) < 32) {
    fwrite(STDERR, "[X] game_api_secret 必须至少 32 个字符\n");
    exit(1);
}
if (empty($c["game_server_ips"])) {
    fwrite(STDERR, "[X] game_server_ips 不能为空\n");
    exit(1);
}
$s = $c["site"] ?? [];
if (!($s["enabled"] ?? false)) {
    fwrite(STDERR, "[X] site.enabled 未开启\n");
    exit(1);
}
$pdo = new PDO(
    sprintf("mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4", $s["host"], $s["port"], $s["dbname"]),
    $s["user"], $s["pass"],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
);
$required = ["player_stats", "player_badges", "badge_definitions", "player_emails", "email_verify"];
$missing = [];
foreach ($required as $table) {
    $q = $pdo->prepare("SHOW TABLES LIKE ?");
    $q->execute([$table]);
    if (!$q->fetchColumn()) $missing[] = $table;
}
if ($missing) {
    fwrite(STDERR, "[X] 缺少数据表：" . implode(", ", $missing) . "\n");
    exit(1);
}
echo "[OK] 配置和 MySQL 数据表正常\n";
' || { red "[X] 游戏 API 自检失败"; exit 1; }

green "[OK] 游戏 API 可以开始联调"
yellow "下一步：BASE_URL=https://你的域名 bash test-game-api.sh"
