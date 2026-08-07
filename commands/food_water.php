<?php
/**
 * 吃食物命令
 */

function cmd_eat($charId, $param = '')
{
    require_once __DIR__ . '/../helpers/FoodWaterHelper.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../models/Item.php';
    require_once __DIR__ . '/../models/Character.php';
    require_once __DIR__ . '/../helpers/HerbHelper.php';
    
    // 如果指定了物品名，尝试吃该物品
    if (!empty($param)) {
        $inventory = ItemModel::getCharacterItems($charId);
        $foundItem = null;
        
        foreach ($inventory as $item) {
            if (strpos($item['name'], $param) !== false || $item['item_id'] === $param) {
                $foundItem = $item;
                break;
            }
        }
        
        if ($foundItem) {
            // 特殊处理：人参果
            if ($foundItem['item_id'] === 'renshen-guo') {
                require_once DAEMON_PATH . 'RenshenEventHandler.php';
                require_once DAEMON_PATH . 'MessageDaemon.php';
                
                // 消耗人参果
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$charId, $foundItem['item_id'], $foundItem['category'] ?? '']
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, $foundItem['item_id'], $foundItem['category'] ?? '']
                );
                
                // 调用事件处理器的人参果效果
                $result = RenshenEventHandler::handleEatRenshenGuo($charId);
                return $result;
            }
            
            // 特殊处理：琼草（还原原始LPC: qiongcao.c do_eat()）
            if ($foundItem['item_id'] === 'qiongcao') {
                require_once DAEMON_PATH . 'QiongcaoHandler.php';
                require_once DAEMON_PATH . 'MessageDaemon.php';
                
                // 消耗琼草
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$charId, $foundItem['item_id'], $foundItem['category'] ?? '']
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, $foundItem['item_id'], $foundItem['category'] ?? '']
                );
                
                // 调用琼草食用效果
                $result = QiongcaoHandler::handleEatQiongcao($charId);
                return $result;
            }
            
            // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
            if ($foundItem['item_id'] === 'biou') {
                require_once __DIR__ . '/../models/Character.php';
                require_once DAEMON_PATH . 'MessageDaemon.php';
                
                $char = CharacterModel::find($charId);
                if (!$char) {
                    return ['success' => false, 'message' => '角色不存在。'];
                }
                
                // 消耗碧藕
                $biouCat = $foundItem['category'] ?? 'obj';
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$charId, 'biou', $biouCat]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, 'biou', $biouCat]
                );
                
                // 恢复食物和水到最大值
                $maxFood = intval($char['max_food'] ?? 100);
                $maxWater = intval($char['max_water'] ?? 100);
                Database::execute(
                    'UPDATE characters SET food = ?, water = ? WHERE id = ?',
                    [$maxFood, $maxWater, $charId]
                );
                
                // 追踪食用次数
                $eatBiouRow = Database::queryOne(
                    "SELECT temp_value FROM character_temp WHERE char_id = ? AND temp_key = 'eat_biou'",
                    [$charId]
                );
                $eatBiouCount = $eatBiouRow ? intval($eatBiouRow['temp_value']) : 0;
                $eatBiouCount++;
                
                // 更新食用次数
                Database::execute(
                    "INSERT INTO character_temp (char_id, temp_key, temp_value) VALUES (?, 'eat_biou', ?)
                     ON DUPLICATE KEY UPDATE temp_value = ?",
                    [$charId, $eatBiouCount, $eatBiouCount]
                );
                
                $forceGain = 2 + mt_rand(0, 1); // 2-3点永久内力
                
                if ($eatBiouCount > 10) {
                    // 吃太多了，反而有害
                    $maxForce = intval($char['max_force'] ?? 100);
                    if ($maxForce > 100) {
                        Database::execute(
                            'UPDATE characters SET max_force = max_force - 5 WHERE id = ?',
                            [$charId]
                        );
                    }
                    // 重置食用计数
                    Database::execute(
                        "UPDATE character_temp SET temp_value = '0' WHERE char_id = ? AND temp_key = 'eat_biou'",
                        [$charId]
                    );
                    
                    $msg = HTML_HIGRN . "你吃了一枚碧藕，脸色突然转绿，浑身发烫，内力竟大为减弱！" . HTML_NOR . "\n";
                    $msg .= HTML_HIRED . "（碧藕食用过量，永久内力-5。碧藕虽好，不可贪吃。）" . HTML_NOR;
                    
                    MessageDaemon::broadcastToRoom(
                        $char['current_room'] ?? '',
                        HTML_HIGRN . ($char['name'] ?? '你') . "吃了一枚碧藕，脸色突然转绿，浑身发烫！" . HTML_NOR,
                        $charId, 'room'
                    );
                    
                    return ['success' => true, 'message' => $msg];
                }
                
                // 正常效果：增加永久内力
                Database::execute(
                    'UPDATE characters SET max_force = max_force + ? WHERE id = ?',
                    [$forceGain, $charId]
                );
                
                // 减龄效果（年龄 > 1382400 时生效，约16天游戏时间）
                $ageModify = intval($char['age_modify'] ?? 0);
                $age = intval($char['age'] ?? 20);
                if ($age > 50) {
                    $ageReduce = 2;
                    Database::execute(
                        'UPDATE characters SET age_modify = age_modify - ? WHERE id = ?',
                        [$ageReduce, $charId]
                    );
                }
                
                $msg = HTML_HIGRN . "你吃了一枚碧藕，脸色顿时红润起来，仿佛年轻了几岁！" . HTML_NOR . "\n";
                $msg .= HTML_HIGRN . "（永久内力+{$forceGain}，食物和水已满。）" . HTML_NOR;
                
                MessageDaemon::broadcastToRoom(
                    $char['current_room'] ?? '',
                    HTML_HIGRN . ($char['name'] ?? '你') . "吃了一枚碧藕，浑身散发出一阵雪白的光芒！" . HTML_NOR,
                    $charId, 'room'
                );
                
                return ['success' => true, 'message' => $msg];
            }
            
            // 特殊处理：猕猴桃（还原原始LPC: mihoutao.c do_eat()）
            if ($foundItem['item_id'] === 'mihoutao') {
                require_once __DIR__ . '/../models/Character.php';
                require_once DAEMON_PATH . 'MessageDaemon.php';
                
                $char = CharacterModel::find($charId);
                if (!$char) {
                    return ['success' => false, 'message' => '角色不存在。'];
                }
                
                // 消耗猕猴桃
                $mihoutaoCat = $foundItem['category'] ?? 'obj';
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ? AND category = ?',
                    [$charId, 'mihoutao', $mihoutaoCat]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND category = ? AND quantity <= 0',
                    [$charId, 'mihoutao', $mihoutaoCat]
                );
                
                // 食物值回满（原始LPC: me->set("food", me->max_food_capacity())）
                $maxFood = intval($char['max_food'] ?? 100);
                Database::execute(
                    'UPDATE characters SET food = ? WHERE id = ?',
                    [$maxFood, $charId]
                );
                
                // 永久最大内力+1（原始LPC: me->add_maximum_force(1)）
                Database::execute(
                    'UPDATE characters SET max_force = max_force + 1 WHERE id = ?',
                    [$charId]
                );
                
                // 永久最大法力+1（原始LPC: me->add_maximum_mana(1)）
                Database::execute(
                    'UPDATE characters SET max_mana = max_mana + 1 WHERE id = ?',
                    [$charId]
                );
                
                $msg = HTML_HIGRN . "你吃下一颗猕猴桃，忍不住抓耳挠腮，高兴得直想翻跟头！" . HTML_NOR;
                
                MessageDaemon::broadcastToRoom(
                    $char['current_room'] ?? '',
                    HTML_HIGRN . ($char['name'] ?? '你') . "吃下一颗猕猴桃，抓耳挠腮，高兴得直想翻跟头！" . HTML_NOR,
                    $charId, 'room'
                );
                
                return ['success' => true, 'message' => $msg];
            }
            
            // 特殊处理：取经人的肉
            if ($foundItem['item_id'] === 'qujingren_rou') {
                require_once __DIR__ . '/../models/Character.php';
                
                // 消耗物品
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
                    [$charId, $foundItem['item_id']]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
                    [$charId, $foundItem['item_id']]
                );
                
                // 增加食物值
                $foodValue = !empty($foundItem['food_value']) ? (int)$foundItem['food_value'] : 50;
                $result = FoodWaterHelper::eat($charId, $foodValue);
                
                // 获取角色信息
                $char = CharacterModel::find($charId);
                
                // 增加道行
                $expGain = 3000;
                $oldDaoxing = $char['daoxing'] ?? 0;
                $newDaoxing = $oldDaoxing + $expGain;
                
                Database::execute(
                    'UPDATE characters SET daoxing = ? WHERE id = ?',
                    [$newDaoxing, $charId]
                );
                
                // 清除no_qujing标记
                $hadNoQujing = false;
                if (!empty($char['obstacle/no_qujing'])) {
                    $hadNoQujing = true;
                    Database::execute(
                        'UPDATE characters SET `obstacle/no_qujing` = 0 WHERE id = ?',
                        [$charId]
                    );
                }
                
                if ($result['success']) {
                    $result['message'] = "你吃掉了{$foundItem['name']}。\n" . $result['message'];
                    $result['message'] .= "\n你觉得一股暖流涌遍全身，道行增长了 {$expGain} 点！（当前：{$newDaoxing}）";
                    if ($hadNoQujing) {
                        $result['message'] .= "\n你心中的取经禁忌消失了，又可以参加取经了！";
                    }
                }
                return $result;
            }
            
            // 检查是否是有治疗效果的药物
            if (!empty($foundItem['sen_heal']) || !empty($foundItem['kee_heal']) || !empty($foundItem['gin_heal']) || !empty($foundItem['force_heal']) || !empty($foundItem['mana_heal'])) {
                return useMedicine($charId, $foundItem);
            }
            
            // 检查是否是饮品类食物（酒袋、茶等应该用"喝"而不是"吃"）
            $isDrinkLike = isDrinkLikeItem($foundItem);
            
            if ($isDrinkLike) {
                // 饮品类物品：使用喝水逻辑
                $waterValue = !empty($foundItem['water_value']) ? (int)$foundItem['water_value'] : 50;
                
                // 消耗物品
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
                    [$charId, $foundItem['item_id']]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
                    [$charId, $foundItem['item_id']]
                );
                
                // 增加饮水值
                $result = FoodWaterHelper::drink($charId, $waterValue);
                if ($result['success']) {
                    $result['message'] = "你喝掉了{$foundItem['name']}。\n" . $result['message'];
                    
                    // 处理酒醉效果
                    $drunkMsg = handleDrunkEffect($charId, $foundItem);
                    if (!empty($drunkMsg)) {
                        $result['message'] .= $drunkMsg;
                    }
                }
                return $result;
            }
            
            // 吃食物物品
            if ($foundItem['type'] === 'food' || !empty($foundItem['food_value'])) {
                $foodValue = !empty($foundItem['food_value']) ? (int)$foundItem['food_value'] : 50;
                
                // 消耗物品
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
                    [$charId, $foundItem['item_id']]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
                    [$charId, $foundItem['item_id']]
                );
                
                // 增加食物值
                $result = FoodWaterHelper::eat($charId, $foodValue);
                if ($result['success']) {
                    $result['message'] = "你吃掉了{$foundItem['name']}。\n" . $result['message'];
                }
                return $result;
            }
        }
        
        return ['success' => false, 'message' => "你没有找到可以吃的东西。"];
    }
    
    // 默认吃50点食物（简单版本）
    return FoodWaterHelper::eat($charId, 50);
}

/**
 * 判断物品是否为饮品类（酒、茶等）
 * 这些物品虽然type='food'，但应该用"喝"而不是"吃"
 */
function isDrinkLikeItem(array $item): bool {
    $name = $item['name'] ?? '';
    $itemId = $item['item_id'] ?? '';
    
    // 名称或ID中包含饮品关键词
    $drinkKeywords = ['酒', '茶', '汤', '汁', '水袋', 'jiudai', 'jiunang', 'tea', 'wine', 'beer', 'mijiu'];
    foreach ($drinkKeywords as $keyword) {
        if (strpos($name, $keyword) !== false || strpos($itemId, $keyword) !== false) {
            return true;
        }
    }
    
    // water_value > food_value 且 water_value > 0 的物品主要是饮品
    $waterValue = (int)($item['water_value'] ?? 0);
    $foodValue = (int)($item['food_value'] ?? 0);
    if ($waterValue > 0 && $waterValue > $foodValue) {
        return true;
    }
    
    return false;
}

/**
 * 处理喝酒增加酒醉值
 * @param int $charId 角色ID
 * @param array $item 物品数据
 * @return string 酒醉提示消息
 */
function handleDrunkEffect(int $charId, array $item): string {
    require_once __DIR__ . '/../helpers/StatusEffectHelper.php';
    require_once __DIR__ . '/../models/Character.php';
    
    $drunkApply = (int)($item['drunk_apply'] ?? 0);
    
    // 如果没有配置酒醉值，检查是否是酒类物品
    if ($drunkApply <= 0) {
        $name = $item['name'] ?? '';
        $itemId = $item['item_id'] ?? '';
        $wineKeywords = ['酒', 'jiu', 'wine', 'beer', 'alcohol'];
        $isWine = false;
        foreach ($wineKeywords as $keyword) {
            if (stripos($name, $keyword) !== false || stripos($itemId, $keyword) !== false) {
                $isWine = true;
                break;
            }
        }
        if (!$isWine) {
            return ''; // 不是酒，不增加酒醉
        }
        $drunkApply = 10; // 默认酒醉值
    }
    
    // 获取当前酒醉值
    $currentDrunk = StatusEffectHelper::getDrunkLevel($charId);
    $newDrunkValue = $currentDrunk + $drunkApply;
    
    // 获取角色信息，计算酒醉上限
    $char = CharacterModel::find($charId);
    $con = $char ? (int)($char['con'] ?? 10) : 10;
    $maxForce = $char ? (int)($char['max_force'] ?? 0) : 0;
    $drunkLimit = $con * 6 + (int)($maxForce / 50);
    
    // 计算持续时间
    $duration = max(15, (int)($newDrunkValue * 1.5));
    
    // 添加或更新酒醉状态
    StatusEffectHelper::addCondition($charId, StatusEffectHelper::TYPE_DRUNK, [
        'value' => $newDrunkValue,
        'duration' => $duration,
        'source' => 'drink_' . ($item['item_id'] ?? '')
    ]);
    
    // 返回酒醉提示
    if ($newDrunkValue > $drunkLimit) {
        return "\n\n你感到天旋地转，一头栽倒在地，不省人事！";
    } else if ($newDrunkValue > (int)($drunkLimit / 5)) {
        return "\n\n你觉得脑中昏昏沉沉，身子轻飘飘地，大概是醉了。";
    } else if ($newDrunkValue > (int)($drunkLimit / 10)) {
        return "\n\n你感到一阵酒意上冲，眼皮有些沉重了。";
    } else {
        return "\n\n你微微有些醉意。";
    }
}

function useMedicine($charId, $item) {
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    $senHeal   = !empty($item['sen_heal'])   ? (int)$item['sen_heal']   : 0;
    $keeHeal   = !empty($item['kee_heal'])   ? (int)$item['kee_heal']   : 0;
    $ginHeal   = !empty($item['gin_heal'])   ? (int)$item['gin_heal']   : 0;
    $forceHeal = !empty($item['force_heal']) ? (int)$item['force_heal'] : 0;
    $manaHeal  = !empty($item['mana_heal'])  ? (int)$item['mana_heal']  : 0;
    
    // 消耗物品
    Database::execute(
        'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
        [$charId, $item['item_id']]
    );
    Database::execute(
        'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
        [$charId, $item['item_id']]
    );
    
    $messages = [];
    
    // 恢复精神值
    if ($senHeal > 0) {
        $newSen = min($char['max_sen'], $char['sen'] + $senHeal);
        $actualSen = $newSen - $char['sen'];
        if ($actualSen > 0) {
            Database::execute('UPDATE characters SET sen = ? WHERE id = ?', [$newSen, $charId]);
            $messages[] = "精神 +{$actualSen}";
        } else {
            $messages[] = "精神已满，无需恢复";
        }
    }
    
    // 恢复气血值
    if ($keeHeal > 0) {
        $newKee = min($char['max_kee'], $char['kee'] + $keeHeal);
        $actualKee = $newKee - $char['kee'];
        if ($actualKee > 0) {
            Database::execute('UPDATE characters SET kee = ?, near_death_time = 0 WHERE id = ?', [$newKee, $charId]);
            $messages[] = "气血 +{$actualKee}";
            if (intval($char['near_death_time'] ?? 0) > 0 && $char['kee'] <= 0) {
                $messages[] = "濒死状态解除";
            }
        } else {
            $messages[] = "气血已满，无需恢复";
        }
    }
    
    // 恢复精气值
    if ($ginHeal > 0) {
        $newGin = min($char['max_gin'] ?? ($char['gin'] ?? 100), ($char['gin'] ?? 0) + $ginHeal);
        $actualGin = $newGin - ($char['gin'] ?? 0);
        if ($actualGin > 0) {
            Database::execute('UPDATE characters SET gin = ? WHERE id = ?', [$newGin, $charId]);
            $messages[] = "精气 +{$actualGin}";
        } else {
            $messages[] = "精气已满，无需恢复";
        }
    }
    
    // 恢复内力值（force = 内力）
    if ($forceHeal > 0) {
        $newForce = min($char['max_force'] ?? ($char['force'] ?? 0), ($char['force'] ?? 0) + $forceHeal);
        $actualForce = $newForce - ($char['force'] ?? 0);
        if ($actualForce > 0) {
            Database::execute('UPDATE characters SET `force` = ? WHERE id = ?', [$newForce, $charId]);
            $messages[] = "内力 +{$actualForce}";
        } else {
            $messages[] = "内力已满，无需恢复";
        }
    }
    
    // 恢复法力值（mana = 法力）
    if ($manaHeal > 0) {
        $newMana = min($char['max_mana'] ?? ($char['mana'] ?? 0), ($char['mana'] ?? 0) + $manaHeal);
        $actualMana = $newMana - ($char['mana'] ?? 0);
        if ($actualMana > 0) {
            Database::execute('UPDATE characters SET mana = ? WHERE id = ?', [$newMana, $charId]);
            $messages[] = "法力 +{$actualMana}";
        } else {
            $messages[] = "法力已满，无需恢复";
        }
    }
    
    if (empty($messages)) {
        return ['success' => false, 'message' => "服用了{$item['name']}，但没有任何效果。"];
    }
    
    return [
        'success' => true,
        'message' => "你服用了{$item['name']}。\n效果：" . implode('，', $messages),
        'broadcast_message' => $char['name'] . "服用了{$item['name']}。"
    ];
}

/**
 * 喝水命令
 */

function cmd_drink($charId, $param = '')
{
    require_once __DIR__ . '/../helpers/FoodWaterHelper.php';
    require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';
    
    // 如果指定了物品名，尝试喝该物品
    if (!empty($param)) {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/../models/Item.php';
        
        $inventory = ItemModel::getCharacterItems($charId);
        $foundItem = null;
        
        foreach ($inventory as $item) {
            if (strpos($item['name'], $param) !== false || $item['item_id'] === $param) {
                $foundItem = $item;
                break;
            }
        }
        
        if ($foundItem) {
            // 优先检查是否为液体容器 (max_liquid > 0)
            if (LiquidContainerHelper::isLiquidContainer($foundItem)) {
                // 如果背包中该容器尚未初始化液体状态，则初始化
                if (!LiquidContainerHelper::isInitializedLiquidContainer($foundItem)) {
                    $defaults = LiquidContainerHelper::getDefaultLiquid($foundItem['item_id']);
                    LiquidContainerHelper::initLiquidState(
                        $charId, $foundItem['id'],
                        (int)$foundItem['max_liquid'],
                        $defaults['type'], $defaults['name']
                    );
                    // 重新查询以获取更新后的字段
                    $foundItem['liquid_remaining'] = (int)$foundItem['max_liquid'];
                    $foundItem['liquid_type'] = $defaults['type'];
                    $foundItem['liquid_name'] = $defaults['name'];
                }
                return LiquidContainerHelper::drinkFromContainer($charId, $foundItem);
            }
            
            // 非液体容器：检查是否可以喝（一次性消耗品）
            $canDrink = ($foundItem['type'] === 'drink' || $foundItem['type'] === 'water' 
                         || !empty($foundItem['water_value']) 
                         || isDrinkLikeItem($foundItem));
            
            if ($canDrink) {
                $waterValue = !empty($foundItem['water_value']) ? (int)$foundItem['water_value'] : 50;
                
                // 消耗物品
                Database::execute(
                    'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
                    [$charId, $foundItem['item_id']]
                );
                Database::execute(
                    'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
                    [$charId, $foundItem['item_id']]
                );
                
                // 增加饮水值
                $result = FoodWaterHelper::drink($charId, $waterValue);
                if ($result['success']) {
                    $result['message'] = "你喝掉了{$foundItem['name']}。\n" . $result['message'];
                    
                    // 处理酒醉效果
                    $drunkMsg = handleDrunkEffect($charId, $foundItem);
                    if (!empty($drunkMsg)) {
                        $result['message'] .= $drunkMsg;
                    }
                }
                return $result;
            }
        }
    }
    
    // 默认喝50点水（简单版本）
    return FoodWaterHelper::drink($charId, 50);
}

/**
 * 装水命令 (fill)
 * 从当前房间的水源灌满液体容器
 */
function cmd_fill($charId, $param = '')
{
    require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../models/Item.php';
    require_once __DIR__ . '/../models/Character.php';

    if (empty($param)) {
        return ['success' => false, 'message' => "指令格式：fill <容器名称>\n例如：fill 桂花酒袋"];
    }

    // 查找背包中的容器
    $inventory = ItemModel::getCharacterItems($charId);
    $foundItem = null;

    foreach ($inventory as $item) {
        if (strpos($item['name'], $param) !== false || $item['item_id'] === $param) {
            $foundItem = $item;
            break;
        }
    }

    if (!$foundItem) {
        return ['success' => false, 'message' => "你没有找到{$param}。"];
    }

    if (!LiquidContainerHelper::isLiquidContainer($foundItem)) {
        return ['success' => false, 'message' => "{$foundItem['name']}不能装液体。"];
    }

    // 初始化液体状态（如果需要）
    if (!LiquidContainerHelper::isInitializedLiquidContainer($foundItem)) {
        $defaults = LiquidContainerHelper::getDefaultLiquid($foundItem['item_id']);
        LiquidContainerHelper::initLiquidState(
            $charId, $foundItem['id'],
            (int)$foundItem['max_liquid'],
            $defaults['type'], $defaults['name']
        );
        $foundItem['liquid_remaining'] = (int)$foundItem['max_liquid'];
        $foundItem['liquid_type'] = $defaults['type'];
        $foundItem['liquid_name'] = $defaults['name'];
    }

    // 检查当前房间是否有水源
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    $waterSource = LiquidContainerHelper::getRoomWaterSource(
        $char['current_area'] ?? '',
        $char['current_room'] ?? ''
    );

    // 特殊处理：银药盏可以在有马的房间接马尿
    if (!$waterSource && $foundItem['item_id'] === 'yaozhan') {
        require_once __DIR__ . '/../models/Npc.php';
        $roomId = ($char['current_area'] ?? '') . '/' . ($char['current_room'] ?? '');
        $npcs = NpcModel::getByRoom($roomId);
        $hasHorse = false;
        foreach ($npcs as $npc) {
            if (stripos($npc['npc_id'], 'horse') !== false || 
                stripos($npc['name'], '马') !== false) {
                $hasHorse = true;
                break;
            }
        }
        
        if ($hasHorse) {
            // 装满马尿
            $maxLiquid = (int)($foundItem['max_liquid'] ?? 0);
            $remaining = (int)($foundItem['liquid_remaining'] ?? 0);
            $invId = $foundItem['id'] ?? 0;
            $oldLiquidName = $foundItem['liquid_name'] ?? '';
            
            $messages = [];
            if ($remaining > 0 && $oldLiquidName) {
                $messages[] = "你将银药盏里剩下的{$oldLiquidName}倒掉。";
            }
            
            Database::execute(
                "UPDATE character_inventory SET liquid_remaining = ?, liquid_type = ?, liquid_name = ? WHERE id = ?",
                [$maxLiquid, 'horse_urine', '马尿', $invId]
            );
            
            $messages[] = '你拿着银药盏走到马旁，接了满满一盏马尿。';
            $messages[] = '一股骚臭味扑面而来...';
            $messages[] = "银药盏里面装满了马尿";
            
            return [
                'success' => true,
                'message' => implode("\n", $messages),
                'skip_queue' => true,
                'remaining' => $maxLiquid,
                'liquid_name' => '马尿'
            ];
        }
    }

    if (!$waterSource) {
        return ['success' => false, 'message' => "这里没有地方可以装水。"];
    }

    return LiquidContainerHelper::fillContainer($charId, $foundItem, $waterSource);
}

/**
 * 倒掉命令 (pour)
 * 将液体容器中的液体倒掉，清空容器
 */
function cmd_pour($charId, $param = '')
{
    require_once __DIR__ . '/../helpers/LiquidContainerHelper.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../models/Item.php';

    if (empty($param)) {
        return ['success' => false, 'message' => "指令格式：pour <容器名称>\n例如：pour 酒盏"];
    }

    // 查找背包中的容器
    $inventory = ItemModel::getCharacterItems($charId);
    $foundItem = null;

    foreach ($inventory as $item) {
        if (strpos($item['name'], $param) !== false || $item['item_id'] === $param) {
            $foundItem = $item;
            break;
        }
    }

    if (!$foundItem) {
        return ['success' => false, 'message' => "你没有找到{$param}。"];
    }

    if (!LiquidContainerHelper::isLiquidContainer($foundItem)) {
        return ['success' => false, 'message' => "{$foundItem['name']}不是液体容器，没法倒。"];
    }

    // 初始化液体状态（如果需要）
    if (!LiquidContainerHelper::isInitializedLiquidContainer($foundItem)) {
        $defaults = LiquidContainerHelper::getDefaultLiquid($foundItem['item_id']);
        LiquidContainerHelper::initLiquidState(
            $charId, $foundItem['id'],
            (int)$foundItem['max_liquid'],
            $defaults['type'], $defaults['name']
        );
        $foundItem['liquid_remaining'] = (int)$foundItem['max_liquid'];
        $foundItem['liquid_type'] = $defaults['type'];
        $foundItem['liquid_name'] = $defaults['name'];
    }

    $remaining = (int)($foundItem['liquid_remaining'] ?? 0);
    $liquidName = $foundItem['liquid_name'] ?? '液体';
    $itemName = $foundItem['name'] ?? '容器';
    $invId = $foundItem['id'] ?? 0;

    if ($remaining <= 0) {
        return ['success' => false, 'message' => "{$itemName}是空的，没什么可倒的。"];
    }

    // 清空液体
    $sql = "UPDATE character_inventory SET liquid_remaining = 0 WHERE id = ?";
    Database::execute($sql, [$invId]);

    $msg = "你将{$itemName}里的{$liquidName}全部倒掉了。";

    return [
        'success' => true,
        'message' => $msg,
        'remaining' => 0
    ];
}

/**
 * 使用药品命令 (use)
 * 药品类型物品：有治疗效果则用药逻辑，否则按食物/饮品逻辑消耗
 */
function cmd_use(int $charId, string $param): array {
    require_once __DIR__ . '/../helpers/FoodWaterHelper.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../models/Item.php';
    require_once __DIR__ . '/../models/Character.php';
    require_once __DIR__ . '/../helpers/HerbHelper.php';

    $param = trim($param);
    if ($param === '') {
        return ['success' => false, 'message' => '你要使用什么？'];
    }

    $char = CharacterModel::find($charId);
    $charName = $char['name'] ?? '某人';

    // 从背包查找物品
    $inventory = ItemModel::getCharacterItems($charId);
    $foundItem = null;
    foreach ($inventory as $item) {
        if ($item['item_id'] === $param || strpos($item['name'], $param) !== false) {
            $foundItem = $item;
            break;
        }
    }

    if (!$foundItem) {
        return ['success' => false, 'message' => '你没有这样东西。'];
    }

    if ($foundItem['type'] === 'mount_token') {
        require_once __DIR__ . '/../helpers/TempStateHelper.php';
        
        // 检查是否已经有坐骑
        $ownedMount = TempStateHelper::get($charId, 'ride/owned_mount');
        if ($ownedMount) {
            return ['success' => false, 'message' => '你已经拥有' . ($ownedMount['npc_name'] ?? '坐骑') . '了，不能同时拥有多匹坐骑。'];
        }
        
        // 解析坐骑信息
        $mountNpcId = str_replace('horse_token_', '', $foundItem['item_id']);
        $mountNames = [
            'lvm' => '驴子',
            'bai' => '白马',
            'huang' => '黄骠马',
            'zaohong' => '枣红马',
            'heima' => '黑马',
            'da' => '大宛马',
            'baoma' => '宝马'
        ];
        $mountDodges = [
            'lvm' => 10,
            'bai' => 15,
            'huang' => 18,
            'zaohong' => 20,
            'heima' => 20,
            'da' => 22,
            'baoma' => 25
        ];
        
        $mountName = $mountNames[$mountNpcId] ?? '坐骑';
        $mountDodge = $mountDodges[$mountNpcId] ?? 10;
        
        // 存储拥有的坐骑信息
        $mountData = [
            'npc_id' => $mountNpcId,
            'npc_name' => $mountName,
            'dodge_bonus' => $mountDodge,
            'obtain_time' => time()
        ];
        TempStateHelper::set($charId, 'ride/owned_mount', $mountData);
        
        // 消耗马牌
        Database::execute(
            'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
            [$charId, $foundItem['item_id']]
        );
        Database::execute(
            'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
            [$charId, $foundItem['item_id']]
        );
        
        return [
            'success' => true,
            'message' => "你使用了{$foundItem['name']}，一道金光闪过，{$mountName}出现在你身边，成为了你的专属坐骑！\n你可以使用 mount {$mountName} 命令来骑乘它。",
            'broadcast_message' => "{$charName}使用了{$foundItem['name']}，获得了一匹{$mountName}。"
        ];
    }
    
    if ($foundItem['type'] !== 'drug') {
        return ['success' => false, 'message' => '这样东西不能这样使用。'];
    }

    // 特殊处理：人参果
    if ($foundItem['item_id'] === 'renshen-guo') {
        require_once DAEMON_PATH . 'RenshenEventHandler.php';
        require_once DAEMON_PATH . 'MessageDaemon.php';
        
        // 消耗人参果
        Database::execute(
            'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
            [$charId, $foundItem['item_id']]
        );
        Database::execute(
            'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
            [$charId, $foundItem['item_id']]
        );
        
        // 调用事件处理器的人参果效果
        return RenshenEventHandler::handleEatRenshenGuo($charId);
    }

    $senHeal   = (int)($foundItem['sen_heal']   ?? 0);
    $keeHeal   = (int)($foundItem['kee_heal']   ?? 0);
    $ginHeal   = (int)($foundItem['gin_heal']   ?? 0);
    $forceHeal = (int)($foundItem['force_heal'] ?? 0);
    $manaHeal  = (int)($foundItem['mana_heal']  ?? 0);
    $foodValue = (int)($foundItem['food_value'] ?? 0);
    $waterValue = (int)($foundItem['water_value'] ?? 0);

    // 有治疗属性 → 走药品逻辑
    if ($senHeal > 0 || $keeHeal > 0 || $ginHeal > 0 || $forceHeal > 0 || $manaHeal > 0) {
        return useMedicine($charId, $foundItem);
    }

    // 有食物值 → 当食物吃
    if ($foodValue > 0) {
        Database::execute(
            'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
            [$charId, $foundItem['item_id']]
        );
        Database::execute(
            'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
            [$charId, $foundItem['item_id']]
        );
        $result = FoodWaterHelper::eat($charId, $foodValue);
        if ($result['success']) {
            $result['message'] = "你服用了{$foundItem['name']}。\n" . $result['message'];
            $result['broadcast_message'] = "{$charName}服用了{$foundItem['name']}。";
        }
        return $result;
    }

    // 有饮水值 → 当饮品喝
    if ($waterValue > 0) {
        Database::execute(
            'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
            [$charId, $foundItem['item_id']]
        );
        Database::execute(
            'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
            [$charId, $foundItem['item_id']]
        );
        $result = FoodWaterHelper::drink($charId, $waterValue);
        if ($result['success']) {
            $result['message'] = "你喝下了{$foundItem['name']}。\n" . $result['message'];
            $result['broadcast_message'] = "{$charName}喝下了{$foundItem['name']}。";
            
            // 处理酒醉效果
            $drunkMsg = handleDrunkEffect($charId, $foundItem);
            if (!empty($drunkMsg)) {
                $result['message'] .= $drunkMsg;
            }
        }
        return $result;
    }

    // 没有任何效果 — 药草生吃会导致腹痛
    if (HerbHelper::isHerb($foundItem['item_id'] ?? '')) {
        // 消耗药草
        Database::execute(
            'UPDATE character_inventory SET quantity = quantity - 1 WHERE char_id = ? AND item_id = ?',
            [$charId, $foundItem['item_id']]
        );
        Database::execute(
            'DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity <= 0',
            [$charId, $foundItem['item_id']]
        );
        
        // 药草生吃导致腹痛：减少气血
        $keeLoss = 10 + mt_rand(0, 20);
        Database::execute(
            'UPDATE characters SET kee = GREATEST(0, kee - ?), eff_kee = GREATEST(0, eff_kee - ?) WHERE id = ?',
            [$keeLoss, $keeLoss, $charId]
        );
        
        $herbName = HerbHelper::getHerbName($foundItem['item_id']) ?? $foundItem['name'];
        $msg = HTML_HICYN . "你生吃了{$herbName}，顿觉腹中绞痛，气血翻腾！" . HTML_NOR;
        $msg .= "\n" . HTML_HIRED . "（{$herbName}是制药原料，生吃会导致腹痛。损失气血{$keeLoss}点。）" . HTML_NOR;
        
        MessageDaemon::broadcastToRoom(
            $char['current_room'] ?? '',
            HTML_HICYN . ($char['name'] ?? '你') . "生吃了{$herbName}，突然捂着肚子蹲了下去，脸色惨白！" . HTML_NOR,
            $charId, 'room'
        );
        
        return ['success' => true, 'message' => $msg];
    }
    
    return ['success' => false, 'message' => "你服用了{$foundItem['name']}，但没有任何效果。"];
}
