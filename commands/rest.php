<?php
/**
 * 休息命令 - 触发自然恢复
 */

function cmd_rest($charId, $param = '') {
    require_once __DIR__ . '/../helpers/AutoRecoverHelper.php';
    
    return AutoRecoverHelper::checkAndRecover($charId);
}

function cmd_xiu($charId, $param = '') {
    return cmd_rest($charId, $param);
}
?>