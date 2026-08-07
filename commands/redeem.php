<?php
/**
 * 兑换码系统
 * 
 * 用法：redeem <兑换码>
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';
require_once __DIR__ . '/../models/Character.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function cmd_redeem($charId, $param = '') {
    $me = CharacterModel::find($charId);
    if (!$me) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $code = trim($param);
    if (empty($code)) {
        return ['success' => false, 'message' => '请输入兑换码！用法：redeem <兑换码>'];
    }

    return redeemCode($charId, $me, strtoupper($code));
}
