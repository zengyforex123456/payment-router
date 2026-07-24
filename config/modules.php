<?php
/**
 * 模块配置合并 — 由 bin/platform config:sync 自动生成
 * 生成时间: 2026-07-19 07:40:24
 * 来源模块: 38 个
 *
 * 访问: $config = require APP_ROOT . '/config/modules.php';
 *       $config['AffiliateCommission']['affiliate_commission']['default_rate']
 */

return array (
  'ABTest' => 
  array (
    'abtest' => 
    array (
    ),
  ),
  'AdPlatform' => 
  array (
    'ad_platform' => 
    array (
      'PLATFORM_FACEBOOK' => 'facebook',
      'PLATFORM_GOOGLE' => 'google',
      'PLATFORM_TIKTOK' => 'tiktok',
    ),
  ),
  'Affiliate' => 
  array (
    'affiliate' => 
    array (
      'STATUS_PENDING' => 'pending',
      'STATUS_ACTIVE' => 'active',
      'STATUS_SUSPENDED' => 'suspended',
      'STATUS_BANNED' => 'banned',
      'MIN_WITHDRAW_USD' => 50.0,
      'MIN_WITHDRAW_USDT' => 100.0,
    ),
  ),
  'AffiliateCommission' => 
  array (
    'affiliate_commission' => 
    array (
      'default_rate' => 0.2,
      'min_payout_usd' => 50.0,
      'min_payout_usdt' => 100.0,
      'auto_approve_days' => 30,
    ),
  ),
  'Campaign' => 
  array (
    'campaign' => 
    array (
      'STATUS_DRAFT' => 'draft',
      'STATUS_ACTIVE' => 'active',
      'STATUS_PAUSED' => 'paused',
      'STATUS_COMPLETED' => 'completed',
      'STATUS_ARCHIVED' => 'archived',
    ),
  ),
  'CampaignStats' => 
  array (
    'campaign_stats' => 
    array (
    ),
  ),
  'Click' => 
  array (
    'click' => 
    array (
      'STATUS_UNIQUE' => 'unique',
      'STATUS_DUPLICATE' => 'duplicate',
      'STATUS_FRAUD' => 'fraud',
    ),
  ),
  'Conversion' => 
  array (
    'conversion' => 
    array (
      'STATUS_PENDING' => 'pending',
      'STATUS_APPROVED' => 'approved',
      'STATUS_REFUNDED' => 'refunded',
      'STATUS_REJECTED' => 'rejected',
    ),
  ),
  'Copy' => 
  array (
    'copy' => 
    array (
    ),
  ),
  'CopyEvaluator' => 
  array (
    'copy_evaluator' => 
    array (
      'dimensions' => 
      array (
        0 => 'clarity',
        1 => 'persuasion',
        2 => 'grammar',
        3 => 'engagement',
        4 => 'branding',
      ),
    ),
  ),
  'DataRetention' => 
  array (
    'data_retention' => 
    array (
    ),
  ),
  'Enrichment' => 
  array (
    'enrichment' => 
    array (
    ),
  ),
  'FacebookCost' => 
  array (
    'facebook_cost' => 
    array (
    ),
  ),
  'Funnel' => 
  array (
    'funnel' => 
    array (
    ),
  ),
  'GeoIP' => 
  array (
    'geo_ip' => 
    array (
    ),
  ),
  'Growth' => 
  array (
    'growth' => 
    array (
    ),
  ),
  'Http' => 
  array (
    'http' => 
    array (
    ),
  ),
  'Intent' => 
  array (
    'intent' => 
    array (
    ),
  ),
  'LandingPage' => 
  array (
    'landing_page' => 
    array (
    ),
  ),
  'Network' => 
  array (
    'network' => 
    array (
    ),
  ),
  'Offer' => 
  array (
    'offer' => 
    array (
      'TYPE_CPA' => 'cpa',
      'TYPE_CPL' => 'cpl',
      'TYPE_CPS' => 'cps',
      'TYPE_CPC' => 'cpc',
    ),
  ),
  'Payment' => 
  array (
    'payment' => 
    array (
      'PROVIDER_STRIPE' => 'stripe',
      'PROVIDER_CRYPTO' => 'crypto',
      'PROVIDER_PADDLE' => 'paddle',
      'PROVIDER_MANUAL' => 'manual',
      'STATUS_PENDING' => 'pending',
      'STATUS_PAID' => 'paid',
      'STATUS_FAILED' => 'failed',
      'STATUS_REFUNDED' => 'refunded',
      'STATUS_DISBURSED' => 'disbursed',
      'MAX_RETRY' => 3,
      'API_BASE' => 'https://api.stripe.com/v1',
    ),
  ),
  'Payout' => 
  array (
    'payout' => 
    array (
      'STATUS_PENDING' => 'pending',
      'STATUS_PAID' => 'paid',
      'STATUS_DEFERRED' => 'deferred',
      'STATUS_CANCELLED' => 'cancelled',
      'MIN_PAYOUT_AMOUNT' => 1.0,
    ),
  ),
  'Performance' => 
  array (
    'performance' => 
    array (
    ),
  ),
  'RedirectRule' => 
  array (
    'redirect_rule' => 
    array (
    ),
  ),
  'SaasReferral' => 
  array (
    'saas_referral' => 
    array (
      'l1_rate' => 0.2,
      'l2_rate' => 0.05,
      'leaderboard_bonus' => 
      array (
        1 => 100.0,
        2 => 50.0,
        3 => 25.0,
      ),
      'referral_code_prefix' => 'REF_',
    ),
  ),
  'Security' => 
  array (
    'security' => 
    array (
      'ACTION_PASS' => 'pass',
      'ACTION_FLAG' => 'flag',
      'ACTION_BLOCK' => 'block',
    ),
  ),
  'Session' => 
  array (
    'session' => 
    array (
    ),
  ),
  'Settings' => 
  array (
    'settings' => 
    array (
    ),
  ),
  'SmartRotation' => 
  array (
    'smart_rotation' => 
    array (
    ),
  ),
  'Tenant' => 
  array (
    'tenant' => 
    array (
      'PLAN_FREE' => 'free',
      'PLAN_PRO' => 'pro',
      'PLAN_ENTERPRISE' => 'enterprise',
    ),
  ),
  'Theme' => 
  array (
    'theme' => 
    array (
      'MODE_LIGHT' => 'light',
      'MODE_DARK' => 'dark',
      'DEFAULT_THEME' => 'light',
    ),
  ),
  'Traceability' => 
  array (
    'traceability' => 
    array (
    ),
  ),
  'Tracking' => 
  array (
    'tracking' => 
    array (
      'default_attribution_window_days' => 30,
      'token_ttl_seconds' => 600,
    ),
  ),
  'TrafficSource' => 
  array (
    'traffic_source' => 
    array (
      'TYPE_DIRECT' => 'direct',
      'TYPE_SOCIAL' => 'social',
      'TYPE_SEARCH' => 'search',
      'TYPE_EMAIL' => 'email',
      'TYPE_REFERRAL' => 'referral',
      'TIER_LIVE_API' => 'live_api',
      'TIER_MANUAL_URL' => 'manual_url',
      'TIER_VARIABLES_ONLY' => 'variables_only',
      'TIER_NONE' => 'none',
    ),
  ),
  'UI' => 
  array (
    'ui' => 
    array (
    ),
  ),
  'Utils' => 
  array (
    'utils' => 
    array (
      'CIPHER' => 'AES-256-GCM',
      'TAG_LENGTH' => 16,
    ),
  ),
  'Verification' => 
  array (
    'verification' => 
    array (
    ),
  ),
);
