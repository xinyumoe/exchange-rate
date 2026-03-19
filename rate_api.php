<?php
/**
 * 汇率 HTTP API 入口
 *
 * 用法：
 *   GET rate_api.php?base=CNY          获取以 CNY 为基础的所有汇率
 *   GET rate_api.php?action=refresh    强制刷新汇率数据
 *   GET rate_api.php?action=status     查看缓存状态
 *
 */

require_once __DIR__ . '/rate_service.php';

$config = require __DIR__ . '/rate_config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$service = new ExchangeRateService($config);

$action = isset($_GET['action']) ? trim($_GET['action']) : '';

try {
    if ($action === 'refresh') {
        header('Cache-Control: no-cache');
        $result = $service->forceRefresh();
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'status') {
        header('Cache-Control: no-cache');
        $result = $service->getStatus();
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        header('Cache-Control: public, max-age=1800');
        $base = isset($_GET['base']) ? trim($_GET['base']) : '';
        if (empty($base) || !preg_match('/^[A-Za-z]{3}$/', $base)) {
            http_response_code(400);
            echo json_encode(['error' => '喵了个咪的，请提供有效的货币代码，如 ?base=CNY']);
            exit;
        }

        $data = $service->getRates(strtoupper($base));
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
