<?php
/**
 * 看自己命令 (look_self) - 查看自己的外观描述、装备和健康状态
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once HELPER_PATH . 'SectHelper.php';

function cmd_look_self(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $output = [];
    
    // 名字与等级
    $output[] = HTML_HIYEL . $char['name'] . HTML_NOR . '（Lv.' . $char['level'] . '）';
    
    // 称号/门派职位
    $sectInfo = SectHelper::getCharacterSect($charId);
    if ($sectInfo) {
        $generation = (int)$sectInfo['generation'];
        $genChars = ['零','一','二','三','四','五','六','七','八','九','十'];
        $genText = ($generation > 0 && $generation <= 10) ? $genChars[$generation] : (string)$generation;
        
        if ($generation > 0) {
            $output[] = HTML_CYN . $sectInfo['sect_name'] . '门下第' . $genText . '代弟子，' . $sectInfo['sect_rank'] . HTML_NOR;
        } else {
            $output[] = HTML_CYN . $sectInfo['sect_name'] . $sectInfo['sect_rank'] . HTML_NOR;
        }
    } else {
        $output[] = HTML_GRN . '无门无派（江湖散人）' . HTML_NOR;
    }
    
    $output[] = '';
    
    // 外观描述（参考原始项目 look.c 的 look_living + per_status_msg）
    $gender = $char['gender'] ?? GENDER_UNKNOWN;
    $age = isset($char['age']) ? intval($char['age']) : 20;
    $per = isset($char['per']) ? intval($char['per']) : 10;
    
    // 性别代词
    $genderSelf = match($gender) {
        GENDER_MALE   => '你',
        GENDER_FEMALE => '你',
        default       => '你',
    };
    
    // 年龄描述
    $race = $char['race'] ?? RACE_HUMAN;
    if ($race === RACE_HUMAN || $race === '人类') {
        if ($age < 10) {
            $output[] = $genderSelf . '看起来不到十岁。';
        } elseif ($age < 14) {
            $output[] = $genderSelf . '看起来十来岁。';
        } else {
            $output[] = $genderSelf . '看起来约' . chinese_number(intval($age / 10) * 10) . '来岁。';
        }
    }
    
    // 容貌描述（复用 look.php 中的 getPerDescription 逻辑）
    if (function_exists('getPerDescription')) {
        $perMsg = getPerDescription($age, $per, $gender);
        if (!empty($perMsg)) {
            $output[] = $genderSelf . $perMsg;
        }
    }
    
    $output[] = '';
    
    // 当前装备（参考原始项目 look.c 的 inventory_look，装备项用 HIC "□" 标记）
    $equipment = CharacterModel::getEquipment($charId);
    if (!empty($equipment)) {
        $output[] = HTML_HIGRN . '你身上穿戴著：' . HTML_NOR;
        foreach ($equipment as $eq) {
            $slotLabel = '';
            if (!empty($eq['equip_slot'])) {
                $slotLabel = ArmorHelper::getSlotName($eq['equip_slot']);
            } elseif (!empty($eq['item_type']) && $eq['item_type'] === 'weapon') {
                $slotLabel = '武器';
            }
            $slotDisplay = $slotLabel ? '（' . $slotLabel . '）' : '';
            $output[] = HIC . '  □' . HTML_NOR . $eq['item_name'] . HTML_CYN . $slotDisplay . HTML_NOR;
        }
        $output[] = '';
    }
    
    // 健康状态概览
    $output[] = HTML_HIGRN . '健康状态：' . HTML_NOR;
    
    // 气血
    $keePercent = $char['max_kee'] > 0 ? intval(($char['kee'] / $char['max_kee']) * 100) : 0;
    $keeColor = $keePercent > 80 ? HTML_HIGRN : ($keePercent > 50 ? HIYEL : HIRED);
    $output[] = '  气血：' . $keeColor . $char['kee'] . '/' . $char['max_kee'] . HTML_NOR;
    
    // 精气
    $ginPercent = $char['max_gin'] > 0 ? intval(($char['gin'] / $char['max_gin']) * 100) : 0;
    $ginColor = $ginPercent > 80 ? HTML_HIGRN : ($ginPercent > 50 ? HIYEL : HIRED);
    $output[] = '  精气：' . $ginColor . $char['gin'] . '/' . $char['max_gin'] . HTML_NOR;
    
    // 神气
    $senPercent = $char['max_sen'] > 0 ? intval(($char['sen'] / $char['max_sen']) * 100) : 0;
    $senColor = $senPercent > 80 ? HTML_HIGRN : ($senPercent > 50 ? HIYEL : HIRED);
    $output[] = '  神气：' . $senColor . $char['sen'] . '/' . $char['max_sen'] . HTML_NOR;
    
    return [
        'success' => true,
        'type' => 'look_self',
        'output' => implode("\n", $output)
    ];
}

