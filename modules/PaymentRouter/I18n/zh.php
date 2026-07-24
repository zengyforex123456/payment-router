<?php
/**
 * 中文语言包 — PaymentRouter
 */
return [
    // 通用
    'common.ok'          => '确定',
    'common.cancel'      => '取消',
    'common.save'        => '保存',
    'common.delete'      => '删除',
    'common.create'      => '创建',
    'common.loading'     => '加载中...',
    'common.refresh'     => '刷新',
    'common.domain'      => '域名',
    'common.platform'    => '平台',
    'common.gateway'     => '支付网关',
    'common.weight'      => '权重',
    'common.daily_limit' => '日订单上限',
    'common.status'      => '状态',
    'common.actions'     => '操作',
    'common.other'       => '其他',

    // 导航
    'nav.payment_router'    => '支付路由',
    'nav.router_dashboard'  => '管理仪表盘',
    'nav.a_sites'           => '管理 A 站',
    'nav.b_sites'           => '管理 B 站',
    'nav.order_mappings'    => '查看映射',
    'nav.routing_strategy'  => '配置策略',

    // 仪表盘
    'dashboard.total_orders'  => '总订单',
    'dashboard.success_rate'  => '成功率',
    'dashboard.total_revenue' => '总收入',
    'dashboard.pending_orders'=> '待处理',
    'dashboard.no_data'       => '暂无数据',
    'dashboard.b_site'        => 'B 站',
    'dashboard.gateway'       => '网关',
    'dashboard.status'        => '状态',
    'dashboard.24h_orders'    => '24h 订单',
    'dashboard.success'       => '成功',
    'dashboard.failed'        => '失败',

    // A 站
    'a_sites.title'       => 'A 站列表',
    'a_sites.subtitle'    => '展示站 — 承接广告流量',
    'a_sites.add'         => '添加 A 站',
    'a_sites.create_title'=> '注册新 A 站',

    // B 站
    'b_sites.title'       => 'B 站列表',
    'b_sites.subtitle'    => '收款站 — 实际处理支付',
    'b_sites.add'         => '添加 B 站',
    'b_sites.create_title'=> '注册新 B 站',

    // 订单映射
    'mappings.title'      => '订单映射',
    'mappings.a_order'    => 'A 站订单',
    'mappings.b_order'    => 'B 站订单',
    'mappings.amount'     => '金额',
    'mappings.status'     => '状态',
    'mappings.routing'    => '路由决策',
    'mappings.time'       => '时间',
    'mappings.no_data'    => '暂无映射记录',

    // 策略
    'strategy.title'              => '轮询策略配置',
    'strategy.description'        => '选择一个预设模板或自定义参数',
    'strategy.default_method'     => '默认路由方式',
    'strategy.weighted'           => '加权随机',
    'strategy.weighted_desc'      => '按权重随机分配',
    'strategy.round_robin'        => '轮询',
    'strategy.round_robin_desc'   => '均匀轮流分配',
    'strategy.amount_threshold'   => '金额阈值',
    'strategy.amount_threshold_desc' => '大额/小额分流',
    'strategy.random'             => '纯随机',
    'strategy.random_desc'        => '随机分配',
    'strategy.cooling_threshold'  => '冷却阈值',
    'strategy.cooling_threshold_desc' => '连续失败 N 次后自动冷却',
    'strategy.cooldown_minutes'   => '冷却时间（分钟）',
    'strategy.cooldown_minutes_desc' => '冷却持续时长',

    // 错误
    'error.invalid_key'     => 'API Key 无效',
    'error.signature'       => 'HMAC 签名验证失败',
    'error.not_found'       => '未找到',
    'error.unavailable'     => '所有 B 站不可用',
    'error.db'              => '数据库连接失败',
    'error.invalid_token'   => 'Token 无效或已过期',
    'error.order_create'    => '创建订单失败',
    'error.payment_failed'  => '支付未完成',

    // P0-CE
    'license.valid'         => 'License 有效',
    'license.expired'       => 'License 已过期',
    'license.grace'         => 'License 宽限期内',
    'license.invalid'       => 'License 无效',
    'trial.active'          => '试用中',
    'trial.expired'         => '试用已到期',
    'trial.days_left'       => '剩余 {days} 天',
    'tier.community'        => '社区版',
    'tier.starter'          => '入门版',
    'tier.pro'              => '专业版',
    'tier.enterprise'       => '企业版',
    'upgrade.prompt'        => '升级到 {tier} 解锁此功能',
    'upgrade.success'       => '已升级到 {tier}',
];
