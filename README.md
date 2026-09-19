# 永恒流光官网

Minecraft 服务器「永恒流光」官方网站。前端使用原生 HTML/CSS/JavaScript，后端使用 PHP 与 PDO MySQL。

## 功能

- AuthMe 游戏账号登录
- Wiki 浏览、投稿、审核与版本编辑
- 玩家资料、游戏时长和成就徽章
- 玩家工单与管理组回复
- 站长、管理组、编辑组权限
- 游戏插件 API（默认关闭）

## 运行要求

- PHP 8.1 或更高
- PHP 扩展：`pdo_mysql`、`mbstring`、`openssl`
- MySQL 5.7+/8.0 或 MariaDB 10.3+
- 生产环境：Nginx + PHP-FPM + HTTPS

项目只使用 MySQL，不使用 SQLite。站点数据放在 `townsite` 库，AuthMe 玩家账号保留在游戏服自己的数据库中。

## 本地开发

Windows 本地配置文件为 `api/config.dev.php`，该文件被 Git 忽略。项目附带的开发配置含测试账号：

- `Steve / test123`
- `Alex / alex456`
- `镇长 / admin888`

运行：

```powershell
powershell -ExecutionPolicy Bypass -File .\dev-server.ps1
```

然后访问 `http://127.0.0.1:8000/`。仅测试登录时可以关闭 `site.enabled`；Wiki、工单、后台和数据持久化需要本地 MySQL。

## 生产部署

完整步骤见 [DEPLOY.md](DEPLOY.md)。核心流程：

```bash
git clone <私有仓库地址> /www/wwwroot/yhlg-site
cd /www/wwwroot/yhlg-site
cp api/config.example.php api/config.php
# 编辑 api/config.php 后导入数据库
mysql -u townsite -p townsite < api/schema.sql
bash deploy.sh
```

ZIP/SFTP 上传后使用：

```bash
bash deploy.sh --no-pull
```

Nginx 安全规则参考 `nginx.example.conf`。

## 目录

```text
api/                    PHP API、配置模板和完整数据库结构
migrations/             已有站点的增量 MySQL 迁移
index.html               首页
wiki.html                Wiki 与游玩手册
ticket.html              玩家工单
profile.html             玩家资料
admin.html               管理后台
auth.js                  登录组件
deploy.sh                Linux 部署/更新检查
nginx.example.conf       Nginx 配置示例
```

## 配置与安全

- `api/config.php`、`api/config.dev.php`、`api/selftest.php` 不进入 Git。
- AuthMe 数据库账号只授予 `SELECT` 权限。
- 站点数据库账号只授权 `townsite` 库。
- 生产环境保持 `security.require_https = true`。
- 部署后访问 `/api/config.php` 必须返回 403/404。
- 生产服务器不能保留 `api/config.dev.php` 或 `api/selftest.php`。

## 数据库

全新安装只导入 `api/schema.sql`。该文件包含 Wiki、权限、审计、工单和可选游戏数据所需的全部表，可重复执行且不会删除已有数据。

已有站点按需执行：

```bash
mysql -u townsite -p townsite < migrations/005_game_sync.sql
mysql -u townsite -p townsite < migrations/006_tickets.sql
```
