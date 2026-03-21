<?php
/**
 * 汇率 API 配置文件
 */
return [
    // ECB 数据刷新间隔（小时），ECB 每个工作日约 16:00 CET 更新一次，建议 4-12 小时
    'refresh_hours' => 6,

    // 汇率数据文件存储目录（默认当前目录下的 data/）
    'data_dir' => __DIR__ . '/data',

    // 汇率刷新失败时发送告警的 Webhook URL
    // 飞书: https://open.larksuite.com/open-apis/bot/v2/hook/xxx
    // Telegram: https://api.telegram.org/bot<TOKEN>/sendMessage
    // 留空则不发送告警
    'alert_webhook' => '',

    // Telegram chat_id（仅当 alert_webhook 为 Telegram URL 时需要）
    // 获取方法: 给 Bot 发消息后访问 https://api.telegram.org/bot<TOKEN>/getUpdates
    // Telegram 推送告警需要置顶消息权限
    'telegram_chat_id' => '',

    // 是否在汇率更新时推送变动摘要（true 开启 / false 关闭）
    'notify_rate_change' => true,

    // 汇率更新时重点关注的货币列表（推送变动摘要）
    'watch_currencies' => ['CNY', 'GBP', 'USD', 'JPY'],

    // 变动推送的基准货币（默认 EUR，可改为 CNY/GBP/USD 等）
    // 例如设为 CNY 时推送内容将显示: GBP: 0.109 → 0.110（基准: CNY）
    'notify_base_currency' => 'EUR',

    // 告警冷却时间（分钟），同一时间段内最多发送一次告警，防止刷屏
    'alert_cooldown_minutes' => 60,
];
