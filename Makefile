# $NAME Makefile
.PHONY: up down test deploy backup restore seed lint

up:     docker compose -f docker-compose.server.yml up -d --build
down:   docker compose -f docker-compose.server.yml down
dev:    docker compose -f docker-compose.dev.yml up -d

test:   bash scripts/pipeline.sh local
deploy: bash scripts/pipeline.sh deploy
lint:   bash scripts/enforce-scripts.sh && bash scripts/enforce-architecture.sh

backup: bash scripts/backup-db.sh
restore: bash scripts/restore-db.sh
seed:   php converge db:seed

logs:   docker compose -f docker-compose.server.yml logs -f app
health: curl -s http://localhost/health | jq .
hooks:  curl -s http://localhost/hooks-dashboard.php > /dev/null && open http://localhost/hooks-dashboard.php

modules:
	@php converge module:list
module-create:
	@test -n "$(NAME)" || (echo "Usage: make module-create NAME=coupon"; exit 1)
	bash scripts/module-scaffold.sh $(NAME)

help:
	@echo "make up        启动生产环境"
	@echo "make dev       启动开发环境"
	@echo "make test      全量测试"
	@echo "make lint      结构门禁"
	@echo "make deploy    部署到服务器"
	@echo "make backup    数据库备份"
	@echo "make seed      创建管理员"
