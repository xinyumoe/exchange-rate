<?php
/**
 * ECB 汇率服务
 * 从欧洲央行获取汇率数据，计算任意货币间的交叉汇率
 * 输出格式兼容 exchangerate-api.com/v4/latest/{CURRENCY}
 */
class ExchangeRateService
{
    const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    protected $dataDir;
    protected $dataFile;
    protected $refreshHours;
    protected $alertWebhookUrl;
    protected $alertCooldownMinutes;
    protected $alertTimeFile;
    protected $watchCurrencies;
    protected $notifyRateChange;
    protected $notifyBaseCurrency;
    protected $telegramChatId;

    public function __construct($config = [])
    {
        $this->dataDir = $config['data_dir'] ?? __DIR__ . '/data';
        $this->dataFile = $this->dataDir . '/ecb_rates.json';
        $this->refreshHours = max(1, intval($config['refresh_hours'] ?? 6));
        $this->alertWebhookUrl = $config['alert_webhook'] ?? '';
        $this->alertCooldownMinutes = max(1, intval($config['alert_cooldown_minutes'] ?? 60));
        $this->alertTimeFile = $this->dataDir . '/last_alert.txt';
        $this->watchCurrencies = $config['watch_currencies'] ?? ['CNY', 'GBP', 'USD', 'JPY'];
        $this->notifyRateChange = $config['notify_rate_change'] ?? true;
        $this->notifyBaseCurrency = strtoupper(trim($config['notify_base_currency'] ?? 'EUR'));
        $this->telegramChatId = $config['telegram_chat_id'] ?? '';

        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * 获取以任意货币为基础的汇率
     */
    public function getRates($base_currency)
    {
        $base_currency = strtoupper(trim($base_currency));
        $eurRates = $this->getEurBasedRates();

        if (empty($eurRates) || !isset($eurRates['rates'])) {
            throw new \Exception('无可用的汇率数据');
        }

        $rates = $eurRates['rates'];
        $rates['EUR'] = 1.0;

        if (!isset($rates[$base_currency])) {
            throw new \Exception("不支持的货币: {$base_currency}");
        }

        $baseRate = $rates[$base_currency];

        $converted = [];
        foreach ($rates as $currency => $rate) {
            $converted[$currency] = round($rate / $baseRate, 6);
        }
        ksort($converted);

        // 输出头信息
        return [
            'provider' => 'European Central Bank (ECB)',
            'terms' => 'https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html',
            'base' => $base_currency,
            'date' => $eurRates['date'] ?? date('Y-m-d'),
            'time_last_updated' => $eurRates['last_updated'] ?? time(),
            'rates' => $converted,
        ];
    }

    /**
     * 获取 EUR 基础汇率数据，自动刷新
     */
    protected function getEurBasedRates()
    {
        $data = $this->loadFromFile();

        $needRefresh = false;
        if (empty($data) || !isset($data['last_updated'])) {
            $needRefresh = true;
        } else {
            $elapsed = time() - $data['last_updated'];
            if ($elapsed >= $this->refreshHours * 3600) {
                $needRefresh = true;
            }
        }

        if ($needRefresh) {
            try {
                $newData = $this->fetchFromECB();
                if (!empty($newData['rates'])) {
                    $newData['last_updated'] = time();
                    $this->sendRateChangeNotification($data, $newData);
                    $this->saveToFile($newData);
                    return $newData;
                } else {
                    throw new \Exception('ECB 返回的汇率数据为空');
                }
            } catch (\Exception $e) {
                $this->sendAlert('ECB汇率刷新失败: ' . $e->getMessage());

                // 失败时回退到上一次成功的数据
                if (!empty($data) && isset($data['rates'])) {
                    return $data;
                }
                throw $e;
            }
        }

        return $data;
    }

    /**
     * 从 ECB XML 获取汇率
     */
    protected function fetchFromECB()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::ECB_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ExchangeRateService/1.0');
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            throw new \Exception('ECB 请求失败: ' . $error);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            throw new \Exception('ECB 返回 HTTP ' . $httpCode);
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            throw new \Exception('ECB XML 解析失败');
        }

        $xml->registerXPathNamespace('ecb', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
        $cubes = $xml->xpath('//ecb:Cube[@currency][@rate]');

        if (empty($cubes)) {
            throw new \Exception('ECB XML 中未找到汇率数据');
        }

        $dateCubes = $xml->xpath('//ecb:Cube[@time]');
        $date = !empty($dateCubes) ? (string)$dateCubes[0]['time'] : date('Y-m-d');

        $rates = [];
        foreach ($cubes as $cube) {
            $currency = (string)$cube['currency'];
            $rate = (float)$cube['rate'];
            if ($rate > 0) {
                $rates[$currency] = $rate;
            }
        }

        return [
            'base' => 'EUR',
            'date' => $date,
            'rates' => $rates,
        ];
    }

    /**
     * 强制刷新
     */
    public function forceRefresh()
    {
        try {
            $oldData = $this->loadFromFile();
            $newData = $this->fetchFromECB();
            $newData['last_updated'] = time();
            $this->sendRateChangeNotification($oldData, $newData);
            $this->saveToFile($newData);
            return [
                'status' => 'success',
                'date' => $newData['date'],
                'currencies' => count($newData['rates']),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
        } catch (\Exception $e) {
            $this->sendAlert('手动刷新ECB汇率失败: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * 缓存状态
     */
    public function getStatus()
    {
        $data = $this->loadFromFile();
        if (empty($data)) {
            return ['has_data' => false, 'message' => '尚未获取过汇率数据'];
        }

        $elapsed = time() - ($data['last_updated'] ?? 0);
        $nextRefresh = max(0, $this->refreshHours * 3600 - $elapsed);
        $h = floor($nextRefresh / 3600);
        $m = floor(($nextRefresh % 3600) / 60);

        return [
            'has_data' => true,
            'date' => $data['date'] ?? 'unknown',
            'last_updated' => date('Y-m-d H:i:s', $data['last_updated'] ?? 0),
            'currencies' => count($data['rates'] ?? []),
            'refresh_interval_hours' => $this->refreshHours,
            'next_refresh_in_seconds' => $nextRefresh,
            'next_refresh_in' => "{$h}h {$m}m",
        ];
    }

    protected function loadFromFile()
    {
        if (!file_exists($this->dataFile)) {
            return null;
        }
        $content = file_get_contents($this->dataFile);
        if (empty($content)) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    protected function saveToFile($data)
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $tmpFile = $this->dataFile . '.tmp';
        if (file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            rename($tmpFile, $this->dataFile);
        } else {
            throw new \Exception('无法写入汇率缓存文件');
        }
    }

    /**
     * 汇率更新时推送变化摘要
     */
    protected function sendRateChangeNotification($oldData, $newData)
    {
        if (!$this->notifyRateChange || empty($this->alertWebhookUrl)) {
            return;
        }

        $oldRates = $oldData['rates'] ?? [];
        $newRates = $newData['rates'] ?? [];
        if (empty($oldRates) || empty($newRates)) {
            return;
        }

        // 以配置的基准货币转换汇率
        $base = $this->notifyBaseCurrency;
        $oldRates['EUR'] = 1.0;
        $newRates['EUR'] = 1.0;

        if (!isset($oldRates[$base]) || !isset($newRates[$base])) {
            $base = 'EUR';
        }

        $oldBase = $oldRates[$base];
        $newBase = $newRates[$base];

        $lines = [];
        foreach ($this->watchCurrencies as $cur) {
            if ($cur === $base || !isset($newRates[$cur])) {
                continue;
            }
            $newVal = $newRates[$cur] / $newBase;
            if (isset($oldRates[$cur])) {
                $oldVal = $oldRates[$cur] / $oldBase;
                if ($oldVal == $newVal) {
                    continue;
                }
                $diff = $newVal - $oldVal;
                $pct = $oldVal > 0 ? ($diff / $oldVal) * 100 : 0;
                $sign = $diff >= 0 ? '+' : '';
                $lines[] = sprintf("%s: %.6f → %.6f (%s%.4f%%)", $cur, $oldVal, $newVal, $sign, $pct);
            } else {
                $lines[] = sprintf("%s: (新增) %.6f", $cur, $newVal);
            }
        }

        if (empty($lines)) {
            return;
        }

        $oldDate = $oldData['date'] ?? '未知';
        $newDate = $newData['date'] ?? '未知';
        $text = "📊 ECB 汇率已更新 ({$oldDate} → {$newDate})\n"
            . "基准: {$base}\n"
            . implode("\n", $lines);

        $this->sendWebhook($text);
    }

    protected function sendAlert($message)
    {
        if (empty($this->alertWebhookUrl)) {
            return;
        }

        // 冷却时间内不重复发送告警
        if (file_exists($this->alertTimeFile)) {
            $lastAlertTime = (int) file_get_contents($this->alertTimeFile);
            if ((time() - $lastAlertTime) < $this->alertCooldownMinutes * 60) {
                return;
            }
        }
        file_put_contents($this->alertTimeFile, (string) time(), LOCK_EX);

        $this->sendWebhook("[汇率告警] " . date('Y-m-d H:i:s') . "\n{$message}", true);
    }

    /**
     * 发送 Webhook 消息（自动识别飞书 / Telegram）
     * @param string $text 消息文本
     * @param bool $mentionAll 是否 @所有人（仅告警时）
     */
    protected function sendWebhook($text, $mentionAll = false)
    {
        if (empty($this->alertWebhookUrl)) {
            return;
        }

        $isTelegram = strpos($this->alertWebhookUrl, 'api.telegram.org') !== false;

        if ($isTelegram) {
            if (empty($this->telegramChatId)) {
                return;
            }
            $payload = json_encode([
                'chat_id' => $this->telegramChatId,
                'text' => $text,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // 飞书
            if ($mentionAll) {
                $text = "<at user_id=\"all\">所有人</at>\n" . $text;
            }
            $payload = json_encode([
                'msg_type' => 'text',
                'content' => ['text' => $text],
            ], JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->alertWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);

        // Telegram 告警消息自动置顶（Bot 需要置顶权限）
        if ($isTelegram && $mentionAll && $response) {
            $result = json_decode($response, true);
            if (!empty($result['ok']) && !empty($result['result']['message_id'])) {
                $messageId = $result['result']['message_id'];
                $botToken = '';
                if (preg_match('#/bot([^/]+)/sendMessage#', $this->alertWebhookUrl, $m)) {
                    $botToken = $m[1];
                }
                if ($botToken) {
                    $pinUrl = "https://api.telegram.org/bot{$botToken}/pinChatMessage";
                    $pinPayload = json_encode([
                        'chat_id' => $this->telegramChatId,
                        'message_id' => $messageId,
                    ]);
                    $ch2 = curl_init();
                    curl_setopt($ch2, CURLOPT_URL, $pinUrl);
                    curl_setopt($ch2, CURLOPT_POST, true);
                    curl_setopt($ch2, CURLOPT_POSTFIELDS, $pinPayload);
                    curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
                    curl_exec($ch2);
                }
            }
        }
    }
}
