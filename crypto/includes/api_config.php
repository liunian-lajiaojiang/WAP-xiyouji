<?php
/**
 * API配置
 */

return [
    'btc' => [
        [
            'name' => 'Gate.io',
            'url' => 'https://api.gateio.ws/api/v4/futures/usdt/tickers?contract=BTC_USDT',
            'parser' => fn($data) => isset($data[0]['last']) ? (float)$data[0]['last'] : null
        ],
        [
            'name' => 'OKX',
            'url' => 'https://www.okx.com/api/v5/market/ticker?instId=BTC-USDT-SWAP',
            'parser' => fn($data) => isset($data['data'][0]['last']) ? (float)$data['data'][0]['last'] : null
        ],
        [
            'name' => 'Binance',
            'url' => 'https://fapi.binance.me/fapi/v2/ticker/price?symbol=BTCUSDT',
            'parser' => fn($data) => isset($data['price']) ? (float)$data['price'] : null
        ]
    ],
    'eth' => [
        [
            'name' => 'Gate.io',
            'url' => 'https://api.gateio.ws/api/v4/futures/usdt/tickers?contract=ETH_USDT',
            'parser' => fn($data) => isset($data[0]['last']) ? (float)$data[0]['last'] : null
        ],
        [
            'name' => 'OKX',
            'url' => 'https://www.okx.com/api/v5/market/ticker?instId=ETH-USDT-SWAP',
            'parser' => fn($data) => isset($data['data'][0]['last']) ? (float)$data['data'][0]['last'] : null
        ],
        [
            'name' => 'Binance',
            'url' => 'https://fapi.binance.me/fapi/v2/ticker/price?symbol=ETHUSDT',
            'parser' => fn($data) => isset($data['price']) ? (float)$data['price'] : null
        ]
    ]
];
