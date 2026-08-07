<?php
/**
 * 装备武器命令 (wield) - 装备武器
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
require_once HELPER_PATH . 'WeaponHelper.php';
require_once HELPER_PATH . 'ArmorHelper.php';

function cmd_wield(int $charId, string $itemName = '', string $category = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($itemName)) {
        return ['success' => false, 'message' => '你要装备什么武器？'];
    }
    
    // 支持 wield all
    if ($itemName === 'all') {
        return cmd_wield_all($charId);
    }
    
    // 读取 inv_id 参数，优先用精确 ID 定位物品
    $invId = intval($_GET['inv_id'] ?? $_POST['inv_id'] ?? 0);
    
    // 查找要装备的物品
    $targetItem = null;
    if ($invId > 0) {
        require_once __DIR__ . '/../models/Item.php';
        $found = ItemModel::findInInventoryById($invId);
        if ($found && $found['char_id'] == $charId) {
            $targetItem = $found;
        }
    }
    
    if (!$targetItem) {
        // fallback：遍历背包按名称/category 匹配
        $inventory = CharacterModel::getInventory($charId);
        foreach ($inventory as $item) {
            $matchId = stripos($item['item_id'], $itemName) !== false;
            $matchName = stripos($item['item_name'], $itemName) !== false;
            $matchCategory = empty($category) || ($item['category'] ?? '') === $category;
            
            if (($matchId || $matchName) && $matchCategory) {
                $targetItem = $item;
                break;
            }
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => '你身上没有这样东西。'];
    }
    
    // 检查是否已装备
    if ($targetItem['equipped']) {
        return ['success' => false, 'message' => '你已经装备着了。'];
    }
    
    // 检查是否为武器类型(根据type字段或物品ID推断)
    $isWeapon = $targetItem['item_type'] === 'weapon';
    if (!$isWeapon && $targetItem['item_type'] === 'misc') {
        $isWeapon = isWeaponItem($targetItem['item_id'], $targetItem['item_name']);
    }
    
    if (!$isWeapon) {
        if ($targetItem['item_type'] === 'armor') {
            return ['success' => false, 'message' => '这是防具，请使用 wear 命令穿戴。'];
        }
        return ['success' => false, 'message' => '你只能装备可当作武器的东西。'];
    }
    
    // 检查是否有no_wield限制
    if (isset($targetItem['no_wield']) && $targetItem['no_wield']) {
        return ['success' => false, 'message' => '这个武器不能被装备。'];
    }
    
    // 获取武器标志
    $flag = $targetItem['flag'] ?? parseWeaponFlag($targetItem['effects'] ?? '');
    
    // 检查双手武器限制
    if ($flag & WeaponHelper::FLAG_TWO_HANDED) {
        // 双手武器：必须空出双手
        $currentWeapon = WeaponHelper::getEquippedWeapon($charId);
        $secondaryWeapon = WeaponHelper::getEquippedSecondaryWeapon($charId);
        $shield = ArmorHelper::getEquippedItem($charId, 'shield');
        
        if ($currentWeapon || $secondaryWeapon || $shield) {
            return ['success' => false, 'message' => '你必须空出双手才能装备双手武器。'];
        }
        
        // 装备为主手武器
        if ($invId > 0) {
            $result = WeaponHelper::equipWeaponById($charId, $invId, 'main');
        } else {
            $result = WeaponHelper::equipWeapon($charId, $targetItem['item_id'], 'main', $targetItem['category'] ?? '');
        }
        
        if (!$result) {
            return ['success' => false, 'message' => '装备失败。'];
        }
        
    } else {
        // 单手武器
        $currentWeapon = WeaponHelper::getEquippedWeapon($charId);
        
        if (!$currentWeapon) {
            // 没有主手武器，直接装备
            if ($invId > 0) {
                $result = WeaponHelper::equipWeaponById($charId, $invId, 'main');
            } else {
                $result = WeaponHelper::equipWeapon($charId, $targetItem['item_id'], 'main', $targetItem['category'] ?? '');
            }
            
            if (!$result) {
                return ['success' => false, 'message' => '装备失败。'];
            }
            
        } else {
            // 已有主手武器，检查是否可以装备副手
            $secondaryWeapon = WeaponHelper::getEquippedSecondaryWeapon($charId);
            $shield = ArmorHelper::getEquippedItem($charId, 'shield');
            
            if ($secondaryWeapon || $shield) {
                return ['success' => false, 'message' => '你必须空出一只手来使用武器。'];
            }
            
            // 检查是否可以作为副手武器
            if ($flag & WeaponHelper::FLAG_SECONDARY) {
                // 可以作为副手武器
                if ($invId > 0) {
                    $result = WeaponHelper::equipWeaponById($charId, $invId, 'secondary');
                } else {
                    $result = WeaponHelper::equipWeapon($charId, $targetItem['item_id'], 'secondary', $targetItem['category'] ?? '');
                }
                
                if (!$result) {
                    return ['success' => false, 'message' => '装备失败。'];
                }
                
                // 检查双武器技能兼容性
                checkDualWeaponCompatibility($charId, $currentWeapon, $targetItem);
                
            } else {
                return ['success' => false, 'message' => '你必须先放下你目前装备的武器。'];
            }
        }
    }
    
    // 生成装备消息
    $message = generateWieldMessage($targetItem);
    
    $logItemName = $targetItem['item_name'] ?? $targetItem['name'] ?? $targetItem['item_id'];
    log_game('WIELD', "{$char['name']} 装备 {$logItemName}");
    
    return [
        'success' => true,
        'message' => $message,
        'item' => $targetItem
    ];
}

/**
 * 装备所有可装备的武器
 */
function cmd_wield_all(int $charId): array {
    $inventory = CharacterModel::getInventory($charId);
    $count = 0;
    $messages = [];
    
    foreach ($inventory as $item) {
        // 跳过已装备的物品
        if ($item['equipped']) {
            continue;
        }
        
        // 只处理武器类型
        if ($item['item_type'] !== 'weapon') {
            continue;
        }
        
        // 尝试装备
        $currentWeapon = WeaponHelper::getEquippedWeapon($charId);
        
        if (!$currentWeapon) {
            // 装备为主手
            $result = WeaponHelper::equipWeapon($charId, $item['item_id'], 'main', $item['category'] ?? '');
            if ($result) {
                $count++;
                $messages[] = generateWieldMessage($item);
            }
        } else {
            // 检查是否可以装备副手
            $flag = $item['flag'] ?? parseWeaponFlag($item['effects'] ?? '');
            if ($flag & WeaponHelper::FLAG_SECONDARY) {
                $secondaryWeapon = WeaponHelper::getEquippedSecondaryWeapon($charId);
                $shield = ArmorHelper::getEquippedItem($charId, 'shield');
                
                if (!$secondaryWeapon && !$shield) {
                    $result = WeaponHelper::equipWeapon($charId, $item['item_id'], 'secondary', $item['category'] ?? '');
                    if ($result) {
                        $count++;
                        $messages[] = generateWieldMessage($item);
                        break; // 只装备一个副手
                    }
                }
            }
        }
    }
    
    if ($count === 0) {
        return ['success' => false, 'message' => '没有可以装备的武器。'];
    }
    
    return [
        'success' => true,
        'message' => implode("\n", $messages) . "\nOk.\n",
        'count' => $count
    ];
}

/**
 * 检查双武器技能兼容性
 */
function checkDualWeaponCompatibility(int $charId, array $mainWeapon, array $secondaryWeapon): void {
    // 获取两个武器的技能类型
    $mainSkillType = $mainWeapon['apply']['skill_type'] ?? $mainWeapon['skill_type'] ?? '';
    $secondarySkillType = $secondaryWeapon['apply']['skill_type'] ?? $secondaryWeapon['skill_type'] ?? '';
    
    // 如果技能类型相同但具体武器类型不同，设置特殊标记
    if ($mainSkillType && $mainSkillType == $secondarySkillType) {
        // 这里可以添加双武器技能的额外逻辑
        // 例如：设置 use_apply_action 标记
    }
}

/**
 * 生成装备武器消息
 */
function generateWieldMessage(array $item): string {
    $itemName = $item['item_name'] ?? $item['name'] ?? $item['item_id'];
    $unit = $item['unit'] ?? '把';
    
    return "你装备{$unit}{$itemName}作武器。";
}

function parseWeaponFlag(string $effects): int {
    if (empty($effects)) {
        return 0;
    }
    
    $flag = 0;
    $effectsArray = json_decode($effects, true);
    
    if (is_array($effectsArray)) {
        if (isset($effectsArray['two_handed']) && $effectsArray['two_handed']) {
            $flag |= WeaponHelper::FLAG_TWO_HANDED;
        }
        if (isset($effectsArray['secondary']) && $effectsArray['secondary']) {
            $flag |= WeaponHelper::FLAG_SECONDARY;
        }
    }
    
    return $flag;
}

function isWeaponItem(string $itemId, string $itemName): bool {
    $itemId = strtolower($itemId);
    $itemName = strtolower($itemName);
    
    $weaponKeywords = [
        'sword', 'blade', 'jian', 'knife', 'dao', 'dagger', 'blade', 'axe', 'hatchet', 
        'spear', 'lance', 'gun', 'bow', 'crossbow', 'hammer', 'mace', 'club', 'staff',
        'whip', 'chain', 'sickle', 'scimitar', 'katana', 'rapier', 'saber', 'broadsword',
        '长', '剑', '刀', '枪', '棍', '棒', '斧', '锤', '鞭', '叉', '锏', '钩', 
        '戟', '矛', '钺', '铲', '钯', '戈', '弩', '弓', '杖', '镰', '匕首', '短剑'
    ];
    
    foreach ($weaponKeywords as $keyword) {
        if (strpos($itemId, $keyword) !== false || strpos($itemName, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

