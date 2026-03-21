# ECB Exchange Rate API

自托管汇率 API 服务，基于欧洲央行 (ECB) 每日汇率数据，支持任意货币间的交叉汇率查询；喵了个咪的＝ω＝ 。

## 功能特性

- ECB 官方数据源，覆盖约 30 种主流货币
- 文件缓存 + 按间隔自动刷新，ECB 不可用时回退到缓存
- 飞书 / Telegram Webhook 告警（刷新失败 @所有人/置顶信息） 和 汇率变动推送

## 文件结构

```
rate_api.php        # HTTP API 入口
rate_service.php    # 核心服务类
rate_config.php     # 配置文件
data/               # 自动创建，存放缓存数据

```


## 部署方法

需要 PHP 7.4+（cURL + SimpleXML）。

上传三个PHP文件到服务器，编辑 rate_config.php，然后访问：

```
https://你的域名/rate_api.php?base=CNY
```


## 配置文件

编辑 `rate_config.php`：

| 配置项 | 默认值 | 说明 |
|---|---|---|
| `refresh_hours` | `6` | 刷新间隔（小时） |
| `alert_webhook` | `''` | 飞书 / Telegram Webhook URL |
| `telegram_chat_id` | `''` | Telegram chat_id |
| `notify_rate_change` | `true` | 汇率变动推送（仅推送有变化的货币） |
| `watch_currencies` | `['CNY','GBP','USD','JPY']` | 关注汇率变动的货币 |
| `notify_base_currency` | `'EUR'` | 变动推送的基准货币（如 CNY、GBP） |
| `alert_cooldown_minutes` | `60` | 告警冷却（分钟） |



## API

```
GET /rate_api.php?base=CNY        # 查询汇率
GET /rate_api.php?action=refresh   # 强制刷新
GET /rate_api.php?action=status    # 缓存状态
```

响应示例：

```json
{
  "base": "CNY",
  "date": "2026-03-18",
  "time_last_updated": 1742380800,
  "rates": {
    "USD": 0.137741,
    "GBP": 0.109063,
    "JPY": 20.568927
  }
}
```



## 数据来源

[欧洲央行 (ECB)](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html)，每工作日 16:00 CET 更新。



