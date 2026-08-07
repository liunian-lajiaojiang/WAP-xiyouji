<?php
/**
 * 填海房间动作处理器
 * 对应原始项目 beach.c 的 do_fill()
 * 由 JingweiDaemon 提供核心逻辑，此处为房间动作适配层
 */

require_once __DIR__ . '/ActionHandler.php';

class FillSeaHandler extends ActionHandler
{
    public function execute(int $charId, array $action, array $params = []): array
    {
        require_once __DIR__ . '/JingweiDaemon.php';
        $result = JingweiDaemon::fillSea($charId);

        return [
            'success' => $result['success'],
            'message' => $result['message'],
            'output' => $result['message']
        ];
    }

    public function getDefaultConfig(): array
    {
        return [
            'required_item' => 'shi',
            'required_item_category' => 'weapon',
            'consume_quantity' => 1
        ];
    }
}
