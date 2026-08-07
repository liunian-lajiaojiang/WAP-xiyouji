<?php
/**
 * 状态命令 (score) - 查看角色详细状态
 */
require_once HELPER_PATH . 'SectHelper.php';
require_once HELPER_PATH . 'MoneyHelper.php';
require_once DAEMON_PATH . 'ApprenticeHandler.php';

function cmd_score(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $output = [];
    $output[] = HTML_HICYN . '╔══════════════════════════════════════╗' . HTML_NOR;
    $output[] = HTML_HICYN . '║          ' . HTML_HIWHT . '角 色 状 态' . HTML_HICYN . '          ║' . HTML_NOR;
    $output[] = HTML_HICYN . '╚══════════════════════════════════════╝' . HTML_NOR;
    $output[] = '';
    
    // 基本信息
    $output[] = HTML_HIGRN . '【基本信息】' . HTML_NOR;
    $output[] = '  姓名：' . HTML_HIWHT . $char['name'] . HTML_NOR;
    $output[] = '  等级：' . HTML_HIYEL . 'Lv.' . $char['level'] . HTML_NOR;
    $output[] = '  种族：' . h($char['race']);
    $output[] = '  性别：' . ($char['gender'] === GENDER_MALE ? '男' : '女');
    $output[] = '';
    
    // 三维属性
    $output[] = HTML_HIGRN . '【生命状态】' . HTML_NOR;
    
    // 气血
    $keePercent = intval(($char['kee'] / $char['max_kee']) * 100);
    $keeColor = $keePercent > 80 ? HTML_HIGRN : ($keePercent > 50 ? HTML_HIYEL : HTML_HIRED);
    $output[] = '  气血：' . $keeColor . $char['kee'] . '/' . $char['max_kee'] . HTML_NOR . " ({$keePercent}%)";
    
    // 精气
    $ginPercent = intval(($char['gin'] / $char['max_gin']) * 100);
    $ginColor = $ginPercent > 80 ? HTML_HIGRN : ($ginPercent > 50 ? HTML_HIYEL : HTML_HIRED);
    $output[] = '  精气：' . $ginColor . $char['gin'] . '/' . $char['max_gin'] . HTML_NOR . " ({$ginPercent}%)";
    
    // 神气
    $senPercent = intval(($char['sen'] / $char['max_sen']) * 100);
    $senColor = $senPercent > 80 ? HTML_HIGRN : ($senPercent > 50 ? HTML_HIYEL : HTML_HIRED);
    $output[] = '  神气：' . $senColor . $char['sen'] . '/' . $char['max_sen'] . HTML_NOR . " ({$senPercent}%)";
    $output[] = '';
    
    // 五维属性
    $output[] = HTML_HIGRN . '【先天属性】' . HTML_NOR;
    $output[] = '  力量：' . $char['str'] . '  悟性：' . $char['int'];
    $output[] = '  根骨：' . $char['con'] . '  身法：' . $char['dex'];
    $output[] = '  胆识：' . $char['cor'] . '  福缘：' . $char['cps'];
    $output[] = '  容貌：' . $char['per'];
    $output[] = '';
    
    // 经验信息
    $output[] = HTML_HIGRN . '【修行状态】' . HTML_NOR;
    $output[] = '  总经验：' . number_format($char['experience']);
    $output[] = '  战斗经验：' . number_format($char['combat_exp']);
    $output[] = '  道行：' . number_format($char['daoxing']);
    $output[] = '  潜能：' . number_format($char['potential']);
    $output[] = '';
    
    // 内力/灵力/法力
    if ($char['max_force'] > 0 || $char['max_atman'] > 0 || $char['max_mana'] > 0) {
        $output[] = HTML_HIGRN . '【能量状态】' . HTML_NOR;
        
        if ($char['max_force'] > 0) {
            $forcePercent = intval(($char['force'] / $char['max_force']) * 100);
            $output[] = '  内力：' . $char['force'] . '/' . $char['max_force'] . " ({$forcePercent}%)";
        }
        
        if ($char['max_atman'] > 0) {
            $atmanPercent = intval(($char['atman'] / $char['max_atman']) * 100);
            $output[] = '  灵力：' . $char['atman'] . '/' . $char['max_atman'] . " ({$atmanPercent}%)";
        }
        
        if ($char['max_mana'] > 0) {
            $manaPercent = intval(($char['mana'] / $char['max_mana']) * 100);
            $output[] = '  法力：' . $char['mana'] . '/' . $char['max_mana'] . " ({$manaPercent}%)";
        }
        $output[] = '';
    }
    
    // 财富
    $output[] = HTML_HIGRN . '【财富状况】' . HTML_NOR;
    $money = MoneyHelper::getMoneyInventory($charId);
    $wealth = [];
    if ($money['gold'] > 0) {
        $wealth[] = HTML_HIYEL . $money['gold'] . ' 两黄金' . HTML_NOR;
    }
    if ($money['silver'] > 0) {
        $wealth[] = HTML_GRN . $money['silver'] . ' 两白银' . HTML_NOR;
    }
    if ($money['coin'] > 0) {
        $wealth[] = HTML_CYN . $money['coin'] . ' 铜钱' . HTML_NOR;
    }
    
    if (!empty($wealth)) {
        $output[] = '  ' . implode('，', $wealth);
    } else {
        $output[] = '  身无分文';
    }
    $output[] = '';
    
    // 门派与师徒信息
    $output[] = HTML_HIGRN . '【门派归属】' . HTML_NOR;
    $sectInfo = SectHelper::getCharacterSect($charId);
    if ($sectInfo) {
        // 门派名称
        $output[] = '  【门 派】' . HTML_HICYN . $sectInfo['sect_name'] . HTML_NOR;
        
        // 职位
        $output[] = '  【职 位】' . $sectInfo['sect_rank'];
        
        // 代数
        $generation = (int)$sectInfo['generation'];
        if ($generation > 0) {
            $genChars = ['零','一','二','三','四','五','六','七','八','九','十'];
            $genText = ($generation <= 10) ? $genChars[$generation] : (string)$generation;
            $output[] = '  【代 数】第' . $genText . '代弟子';
        }
        
        // 师父信息
        $masterResult = ApprenticeHandler::getMasterInfo($charId);
        if ($masterResult['success'] && !empty($masterResult['data'])) {
            $master = $masterResult['data'];
            $output[] = '  【师 父】' . HTML_HIYEL . $master['name'] . HTML_NOR;
        } else {
            $output[] = '  【师 父】无（自行入门）';
        }
        
        // 入门时间
        if (!empty($sectInfo['enter_time'])) {
            $enterDate = date('Y-m-d', strtotime($sectInfo['enter_time']));
            $output[] = '  【入门时间】' . $enterDate;
        }
        
        // 弟子数
        $apprenticeResult = ApprenticeHandler::getApprentices($charId);
        $apprenticeCount = count($apprenticeResult['data'] ?? []);
        $output[] = '  【弟子数】' . $apprenticeCount . ' 人';
    } else {
        $output[] = '  【门 派】' . HTML_GRN . '无门无派（江湖散人）' . HTML_NOR;
    }
    $output[] = '';
    
    // 当前位置
    $output[] = HTML_HIGRN . '【当前位置】' . HTML_NOR;
    $output[] = '  区域：' . h($char['current_area']);
    $output[] = '  房间：' . h($char['current_room']);
    $output[] = '';
    
    $output[] = HTML_HICYN . '══════════════════════════════════════' . HTML_NOR;
    
    return [
        'success' => true,
        'type' => 'score_display',
        'output' => implode("\n", $output)
    ];
}

// 别名支持
if (!function_exists('cmd_hp')) {
    function cmd_hp(int $charId, string $param = ''): array {
        return cmd_score($charId, $param);
    }
}

