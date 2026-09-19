# Linux 生产部署

本项目的生产组合为 **Nginx + PHP 8.1/8.2-FPM + MySQL 5.7+/8.0 或 MariaDB 10.3+**。站点库和 AuthMe 库都通过 PDO MySQL 连接，不使用 SQLite。

## 1. 服务器环境

Debian/Ubuntu 示例：

```bash
sudo apt update
sudo apt install -y nginx git mysql-client php8.2-fpm php8.2-cli php8.2-mysql php8.2-mbstring php8.2-curl php8.2-xml
```

如果系统仓库没有 PHP 8.2，可使用 PHP 8.1，或在宝塔面板安装 PHP 8.2、Nginx 和 MySQL。

## 2. 获取源码

推荐使用私有 GitHub/Gitee 仓库：

```bash
sudo mkdir -p /www/wwwroot/yhlg-site
sudo chown "$USER":"$USER" /www/wwwroot/yhlg-site
git clone <你的私有仓库地址> /www/wwwroot/yhlg-site
cd /www/wwwroot/yhlg-site
```

也可以上传 ZIP/SFTP，解压到 `/www/wwwroot/yhlg-site`。生产服务器不能包含 `api/config.dev.php` 或 `api/selftest.php`。

## 3. 创建站点数据库

在宝塔或 MySQL 中创建 `townsite` 数据库和专用账号。示例中的密码必须替换：

```sql
CREATE DATABASE `townsite` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'townsite'@'localhost' IDENTIFIED BY '替换成强密码';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON `townsite`.* TO 'townsite'@'localhost';
FLUSH PRIVILEGES;
```

导入完整结构。`schema.sql` 可重复执行，不会删除已有数据：

```bash
cd /www/wwwroot/yhlg-site
mysql -u townsite -p townsite < api/schema.sql
```

如果数据库由宝塔创建，通常已有足够权限，直接导入即可。

全新安装只需导入 `api/schema.sql`。`migrations/` 用于已有站点按功能补表。

## 4. 创建生产配置

```bash
cp api/config.example.php api/config.php
nano api/config.php
```

至少填写：

- `site`：上一步创建的 MySQL 地址、库名、账号和密码。
- `authme`：游戏服 AuthMe 的只读数据库账号；服务商不允许远程连接时先将 `enabled` 设为 `false`。
- `site_owner`：站长的游戏 ID。
- `security.require_https`：生产域名启用证书后保持 `true`。
- `game_api_secret`：只有启用游戏插件 API 时才填写随机长密钥。
- `game_server_ips`：只有启用游戏插件 API时才填写游戏服出口 IP。

`api/config.php` 已被 `.gitignore` 排除，不能提交到仓库。

## 5. 配置 Nginx

参考 `nginx.example.conf`，修改：

- `server_name`
- `root`
- `fastcgi_pass` 对应的 PHP-FPM socket

宝塔用户将示例中的 `location` 规则合并到站点配置，不要覆盖宝塔生成的 SSL 配置。然后检查并重载：

```bash
sudo nginx -t
sudo systemctl reload nginx
```

为域名申请 Let's Encrypt 证书并强制 HTTPS。不要使用 `php -S` 运行生产网站。

## 6. 首次检查

Git 部署：

```bash
cd /www/wwwroot/yhlg-site
chmod +x deploy.sh
bash deploy.sh
```

ZIP/SFTP 部署：

```bash
bash deploy.sh --no-pull
```

脚本会检查 PHP 版本、扩展、配置、PHP 语法、危险开发文件、数据库表和文件权限。

## 7. 上线验证

逐项验证：

1. `https://你的域名/` 能打开首页。
2. `https://你的域名/api/me.php` 返回 JSON。
3. `https://你的域名/api/config.php` 返回 403/404，绝不能显示源码。
4. 使用游戏账号登录，打开 Wiki、工单和管理后台。
5. 检查 PHP-FPM 与 Nginx 错误日志没有新增错误。

## 8. 后续更新

本地提交并推送后，在服务器执行：

```bash
cd /www/wwwroot/yhlg-site
bash deploy.sh
```

部署脚本只允许 Git 快进更新；服务器源码被手动修改时会停止，不会强制覆盖。
