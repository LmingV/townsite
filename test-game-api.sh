#!/bin/bash
# ============================================================
#  游戏数据绑定 - API 测试脚本
#  ------------------------------------------------------------
#  用法: bash test-game-api.sh
# ============================================================

set -e

echo ""
echo "=========================================="
echo "  永恒流光 - 游戏 API 测试"
echo "=========================================="
echo ""

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# 配置
BASE_URL="${BASE_URL:-https://yhlg.love}"
SECRET=""

# 从 config.php 读取密钥，避免依赖 grep -P
if [ -f "api/config.php" ] && command -v php >/dev/null 2>&1; then
    SECRET=$(php -r '$c=require "api/config.php"; echo $c["game_api_secret"] ?? "";' 2>/dev/null || echo "")
fi

if [ -z "$SECRET" ]; then
    echo -e "${RED}✗ 无法读取 game_api_secret${NC}"
    echo "请确保 api/config.php 存在且已配置 game_api_secret"
    exit 1
fi

echo "API 地址: $BASE_URL"
echo "密钥: ${SECRET:0:8}... (已部分隐藏)"
echo ""

# 测试计数
PASS=0
FAIL=0

test_api() {
    local name="$1"
    local method="$2"
    local url="$3"
    local data="$4"

    echo -n "测试: $name ... "

    if [ "$method" = "GET" ]; then
        RESPONSE=$(curl -s -w "\n%{http_code}" "$BASE_URL$url" 2>/dev/null)
    else
        RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL$url" \
            -H "Content-Type: application/json" \
            -d "$data" 2>/dev/null)
    fi

    HTTP_CODE=$(echo "$RESPONSE" | tail -1)
    BODY=$(echo "$RESPONSE" | head -n -1)

    if [ "$HTTP_CODE" = "200" ]; then
        if echo "$BODY" | grep -q '"ok":true'; then
            echo -e "${GREEN}✓ 通过${NC} (HTTP $HTTP_CODE)"
            ((PASS++))
            return 0
        else
            echo -e "${RED}✗ 失败${NC} (响应: $BODY)"
            ((FAIL++))
            return 1
        fi
    else
        echo -e "${RED}✗ 失败${NC} (HTTP $HTTP_CODE)"
        echo "   响应: $BODY"
        ((FAIL++))
        return 1
    fi
}

# 1. Wiki 搜索
echo "1. Wiki 搜索"
test_api "搜索'领地'" "GET" "/api/game.php?action=search_wiki&q=领地&secret=$SECRET" ""
echo ""

# 2. 游戏时长上报
echo "2. 游戏时长上报"
test_api "上报时长" "POST" "/api/game.php" \
    "{\"action\":\"update_playtime\",\"username\":\"testplayer\",\"playtime_minutes\":123,\"secret\":\"$SECRET\"}"
echo ""

# 3. 徽章解锁
echo "3. 徽章解锁"
test_api "解锁徽章" "POST" "/api/game.php" \
    "{\"action\":\"unlock_badge\",\"username\":\"testplayer\",\"badge_id\":\"story/mine_stone\",\"secret\":\"$SECRET\"}"
echo ""

# 4. 邮箱绑定请求
echo "4. 邮箱绑定请求"
test_api "请求绑定" "POST" "/api/game.php" \
    "{\"action\":\"request_email_bind\",\"username\":\"testplayer\",\"email\":\"test@example.com\",\"secret\":\"$SECRET\"}"
echo ""

# 5. 玩家资料查询
echo "5. 玩家资料查询"
test_api "查询资料" "GET" "/api/profile.php?action=stats&username=testplayer" ""
echo ""

# 6. 权限组列表
echo "6. 权限组列表"
test_api "查询权限" "GET" "/api/game.php?action=list_roles&secret=$SECRET" ""
echo ""

# 总结
echo "=========================================="
echo "  测试完成"
echo "=========================================="
echo -e "${GREEN}通过: $PASS${NC}  ${RED}失败: $FAIL${NC}"
echo ""

if [ $FAIL -eq 0 ]; then
    echo -e "${GREEN}✓ 所有测试通过！${NC}"
    echo ""
    echo "下一步:"
    echo "1. 访问 $BASE_URL/profile.html?u=testplayer 查看测试数据"
    echo "2. 部署游戏插件，参考 PLUGIN_DEPLOY.md"
    exit 0
else
    echo -e "${RED}✗ 有 $FAIL 个测试失败${NC}"
    echo ""
    echo "排查建议:"
    echo "1. 检查 api/config.php 里的 game_api_secret 是否正确"
    echo "2. 检查 features.game_data 是否设为 true"
    echo "3. 检查数据库表是否已创建 (运行 migrations/005_game_sync.sql)"
    echo "4. 查看服务器日志: tail -f /www/wwwlogs/yhlg.love.log"
    exit 1
fi
