<?php
/**
 * Home Handler
 *
 * 房产动作处理器
 *
 * 处理 apply house 动作（在房管所房间触发）
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../helpers/HomeHelper.php';

class HomeHandler extends ActionHandler {

    public function getDefaultConfig(): array {
        return [
            'buy_house_cost' => 1000000,  // 购房金额（金币）
        ];
    }

    /**
     * 执行房产相关动作
     *
     * @param int $charId 角色ID
     * @param array $action 动作配置（来自数据库）
     * @param array $params 额外参数（来自用户输入）
     * @return array 执行结果
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }

            $arg = $params['arg'] ?? '';

            switch ($arg) {
                case 'house':
                    return $this->handleBuyHouse($charId, $character, $action);
                default:
                    return ['success' => false, 'message' => '未知的房产操作', 'data' => null];
            }

        } catch (\Exception $e) {
            error_log("HomeHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '房产操作失败', 'data' => null];
        }
    }

    /**
     * 处理购房申请
     */
    private function handleBuyHouse(int $charId, array $character, array $action): array {
        $cfg = $this->mergeConfig($this->getDefaultConfig(), $this->parseConfig($action));
        $cost = $cfg['buy_house_cost'];
        $costText = number_format($cost / 10000) . '万';

        // 验证购房条件
        $check = HomeHelper::canBuyHome($charId);
        if (!$check['can']) {
            return ['success' => false, 'message' => $check['message']];
        }

        // 检查配偶是否在同一房间
        $spouseId = $character['couple_id'] ?? null;
        if (empty($spouseId)) {
            return ['success' => false, 'message' => '你还没有结婚，无法购房！'];
        }

        $spouse = CharacterModel::find(intval($spouseId));
        if (!$spouse) {
            return ['success' => false, 'message' => '找不到你的配偶信息。'];
        }

        $currentRoom = $character['current_room'] ?? '';
        if ($spouse['current_room'] !== $currentRoom || $spouse['current_area'] !== ($character['current_area'] ?? '')) {
            return ['success' => false, 'message' => '你的配偶不在身边，购房需要双方同时在房管所。'];
        }

        if (empty($spouse['online'])) {
            return ['success' => false, 'message' => '你的配偶目前不在线。'];
        }

        // 检查金币（从配置读取金额）
        $gold = $character['gold'] ?? 0;
        if ($gold < $cost) {
            return ['success' => false, 'message' => "你的金币不足{$costText}，无法购置房产。"];
        }

        // 扣除金币
        require_once __DIR__ . '/../helpers/MoneyHelper.php';
        $deductResult = MoneyHelper::deductMoney($charId, $cost);
        if (!$deductResult) {
            return ['success' => false, 'message' => '金币扣除失败，请检查你的资金。'];
        }

        // 调用 HomeHelper 购买房产
        $result = HomeHelper::buyHome($charId, intval($spouseId));
        if (!$result['success']) {
            // 购买失败，退还金币
            MoneyHelper::addMoney($charId, $cost);
            return $result;
        }

        return [
            'success' => true,
            'message' => $result['message'] . "\n花费{$costText}金币。",
        ];
    }
}
