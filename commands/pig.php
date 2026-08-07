<?php
/**
 * 拱猪游戏命令 (pig)
 * 用法: pig [start|play <编号>|status|rank|quit]
 */
require_once HELPER_PATH . 'PigGameHelper.php';

function cmd_pig(int $charId, string $param = ''): array {
    $parts = explode(' ', trim($param), 2);
    $subCommand = $parts[0] ?? '';
    $arg = $parts[1] ?? '';

    if (empty($subCommand)) {
        $subCommand = 'start';
    }

    switch ($subCommand) {
        case 'start':
            return PigGameHelper::startGame($charId);

        case 'play':
            $cardIndex = intval($arg);
            if ($cardIndex <= 0) {
                return [
                    'success' => false,
                    'message' => '请指定要出的牌编号，例如：pig play 1'
                ];
            }
            return PigGameHelper::playCard($charId, $cardIndex);

        case 'status':
            return PigGameHelper::getGameStatus($charId);

        case 'quit':
            return PigGameHelper::quitGame($charId);

        case 'rank':
            return PigGameHelper::getRankingsDisplay($charId);

        default:
            return [
                'success' => false,
                'message' => '拱猪命令用法：pig start(开始) | pig play 数字(出牌) | pig status(状态) | pig rank(排行) | pig quit(退出)'
            ];
    }
}
