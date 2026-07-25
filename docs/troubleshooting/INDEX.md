# Troubleshooting Guide

## Index
- [Dokku EXPOSE 端口不匹配导致网站不可访问](dokku-expose-port-mismatch.md) — Dockerfile EXPOSE 8080 导致 Dokku nginx 监听 8080 而非 80
- [MySQL 8.0 自签名证书导致 PHP mysqli 连接失败](dokku-mysql-tls-self-signed.md) — PHP new mysqli() 拒绝 MySQL 8.0 自签名 TLS 证书，导致入口脚本超时
- [dokku proxy:disable → proxy:enable 清除域名设置](dokku-proxy-disable-resets-domain.md) — proxy:disable 清 Nginx 配置，proxy:enable 重建时用服务器 hostname 替代自定义域名
- [迁移文件存在但未真正执行 — 表缺列](mysql-schema-migration-gap.md) — MigrationRunner 记录了迁移但 ALTER TABLE 失败（MySQL < 8.0.29 不支持 IF NOT EXISTS）
- [Windows Store Python 无法解析 Git Bash /e/project/ 路径](windows-python-gitbash-path.md) — python3 从 WindowsApps 运行时无法处理 Git Bash 的 Unix 风格路径
- [Dokku MySQL 容器 root 密码不可直接使用](dokku-mysql-root-password-access.md) — docker exec dokku.mysql.X mysql -u root 需要正确的 MYSQL_ROOT_PASSWORD 环境变量
