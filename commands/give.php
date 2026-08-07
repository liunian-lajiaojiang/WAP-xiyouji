<?php
/**
 * 给予命令 (give) - 给予物品给NPC或玩家
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 *
 * 用法: give <物品> to <某人>
 * 或: give <某人> <物品>
 */
require_once __DIR__ . '/../helpers/WeightHelper.php';
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../helpers/GaoNpcHelper.php';

// 加载任务配置（缓存）
static $questConfig = null;
if ($questConfig === null) {
    $questConfig = require __DIR__ . '/../config/quest.php';
}

function cmd_give(int $charId, string $param = ''): array {
    if (empty($param)) {
        return ['success' => false, 'message' => '你要给谁什么东西？'];
    }
    
    // 解析参数: give 银子 to 乞丐 或 give 乞丐 银子
    $targetName = null;
    $itemName = null;
    $category = $_GET['category'] ?? $_POST['category'] ?? '';
    
    if (!empty($_GET['item_id']) && !empty($_GET['npc_id'])) {
        $itemName = $_GET['item_name'] ?? $_GET['item_id'];
        $npc = Database::queryOne('SELECT id, name FROM npcs WHERE id = ?', [intval($_GET['npc_id'])]);
        if ($npc) {
            $targetName = $npc['name'];
        } else {
            return ['success' => false, 'message' => '目标NPC不存在'];
        }
    } elseif (!empty($_GET['item_id']) && !empty($_GET['target'])) {
        $itemName = $_GET['item_name'] ?? $_GET['item_id'];
        $targetName = $_GET['target_name'] ?? '';
        if (empty($targetName)) {
            return ['success' => false, 'message' => '请指定目标名称'];
        }
    } elseif (preg_match('/^(.+?)\s+to\s+(.+)$/i', $param, $matches)) {
        $itemName = trim($matches[1]);
        $targetName = trim($matches[2]);
    } else if (preg_match('/^(\S+)\s+(.+)$/', $param, $matches)) {
        $targetName = trim($matches[1]);
        $itemName = trim($matches[2]);
    } else {
        return ['success' => false, 'message' => '你要给谁什么东西？'];
    }
    
    // 获取角色信息
    $char = CharacterModel::find($charId);
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // is_busy() 检查（统一使用 is_player_busy）
    if (is_player_busy($charId)) {
        return ['success' => false, 'message' => '你正忙着呢。'];
    }
    
    // 获取当前房间
    $roomId = $char['room_id'] ?? '';
    if (empty($roomId)) {
        return ['success' => false, 'message' => '你不在任何房间中。'];
    }
    
    // 查找目标(可能是NPC或玩家)
    $target = findTargetInRoomForGive($roomId, $targetName, $charId);
    
    if (!$target) {
        return ['success' => false, 'message' => '这里没有这个人。'];
    }
    
    // 不能给自己
    if ($target['id'] == $charId) {
        return ['success' => true, 'message' => 'Ok.'];
    }
    
    // 检查目标是否忙碌
    if (isset($target['type']) && $target['type'] === 'npc') {
        // NPC的忙碌状态可以从配置中读取
        if (isset($target['busy']) && $target['busy']) {
            return ['success' => false, 'message' => '对方正忙着呢，没时间理你。'];
        }
    }
    
    // 查找要给予的物品
    require_once MODEL_PATH . 'Item.php';
    $inventory = ItemModel::getCharacterItems($charId);
    $targetItem = null;
    
    foreach ($inventory as $item) {
        $displayName = $item['name'];
        if ($item['item_id'] === 'photo' && !empty($item['category'])) {
            $displayName = $item['category'] . '照片';
        }
        $matchName = stripos($displayName, $itemName) !== false || 
                     stripos($item['item_id'], $itemName) !== false;
        $matchCategory = empty($category) || ($item['category'] ?? '') === $category;
        if ($matchName && $matchCategory) {
            $targetItem = $item;
            break;
        }
    }
    
    if (!$targetItem) {
        return ['success' => false, 'message' => '你身上没有这样东西。'];
    }
    
    // 检查物品是否可以给予
    if (isset($targetItem['no_give']) && $targetItem['no_give']) {
        $noGiveMsg = is_string($targetItem['no_give']) ? $targetItem['no_give'] : '这样东西不能随意丢弃。';
        return ['success' => false, 'message' => $noGiveMsg];
    }
    
    // 执行给予
    $result = doGive($charId, $target, $targetItem);
    
    return $result;
}

/**
 * 在房间中查找目标(NPC或玩家)
 */
if (!function_exists('findTargetInRoomForGive')) {
function findTargetInRoomForGive(string $roomId, string $targetName, int $excludeCharId): ?array {
    // 先查找NPC
    $npcs = NpcModel::getRoomNpcs($roomId);
    foreach ($npcs as $npc) {
        if (stripos($npc['name'], $targetName) !== false || 
            stripos($npc['npc_id'], $targetName) !== false) {
            $npc['type'] = 'npc';
            return $npc;
        }
    }
    
    // 再查找其他玩家(简化版本,实际需要从在线玩家列表中查找)
    // 这里暂时只支持NPC
    
    return null;
}
}

/**
 * 执行给予操作
 */
function doGive(int $giverId, array $receiver, array $item): array {
    $giver = CharacterModel::find($giverId);
    
    // 检查接收者是否愿意接受
    if (isset($receiver['no_accept']) && $receiver['no_accept']) {
        return ['success' => false, 'message' => '对方好像不愿意收下你的东西。'];
    }
    
    // 如果是NPC,检查是否有accept_object方法
    if ($receiver['type'] === 'npc') {
        $receiverName = $receiver['name'] ?? '';
        $itemId = $item['item_id'] ?? '';
        $itemUnit = $item['unit'] ?? '件';
        $itemName = $item['item_name'] ?? $item['name'] ?? '';
        
        // 高员外接收物品（放在最前面，确保一定会被执行）
        if (($receiver['npc_id'] ?? '') === 'gao' || ($receiver['id'] ?? 0) == 206) {
            // 处理玉佩（高翠兰任务）- 支持多种玉佩
            $isYupei = false;
            if ($itemId === 'xiaojie' || $itemId === 'tong-pai' || $itemId === 'yupei') {
                $isYupei = true;
            }
            if (mb_strpos($itemName, '玉佩') !== false) {
                $isYupei = true;
            }
            
            if ($isYupei) {
                // 检查玩家是否已经完成过这个任务
                $questCompleted = Database::queryOne(
                    "SELECT 1 FROM character_temp_states WHERE char_id = ? AND state_key = 'gao_yupei_quest'",
                    [$giverId]
                );
                
                if ($questCompleted) {
                    return ['success' => false, 'message' => '高员外摇了摇头：这玉佩你已经送回来了，多谢你的好意。'];
                }
                
                // 移除玩家的玉佩
                $invId = $item['id'] ?? 0;
                if ($invId > 0) {
                    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
                } else {
                    Database::execute(
                        "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                        [$giverId, $itemId, $item['category'] ?? '']
                    );
                }
                
                // 标记任务完成
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'gao_yupei_quest', '1')
                     ON DUPLICATE KEY UPDATE state_value = '1'",
                    [$giverId]
                );
                
                // 给予奖励（从配置文件读取）
                $cfg = $questConfig['rewards']['gao_peiyu'];
                $silverReward = $cfg['silver'];
                $expReward = $cfg['exp'];
                $daoxingReward = $cfg['daoxing'];
                
                Database::execute(
                    "UPDATE characters SET silver = silver + ?, combat_exp = combat_exp + ?, daoxing = daoxing + ? WHERE id = ?",
                    [$silverReward, $expReward, $daoxingReward, $giverId]
                );
                
                $message = "高员外接过玉佩，双手颤抖，老泪纵横：\n";
                $message .= "「这……这是翠兰的玉佩！怎么会在你手里？」\n";
                $message .= "你告诉高员外，玉佩是在清风寨内室找到的。\n";
                $message .= "高员外听完，扑通一声跪倒在地：\n";
                $message .= "「多谢大侠！多谢大侠救了小女！\n";
                $message .= "  小老儿无以为报，这点心意，请您务必收下！」\n";
                $message .= "（你获得了：白银 {$silverReward} 两，经验 {$expReward}，道行 {$daoxingReward}）";
                
                return [
                    'success' => true,
                    'message' => HTML_HICYN . $message . HTML_NOR
                ];
            }
            
            // 处理mmmmmm物品（原始LPC逻辑）
            if ($itemId === 'mmmmmm') {
                $gaoResult = GaoNpcHelper::handleGaoItemInteraction($receiver, $giver, $itemId);
                if ($gaoResult['success']) {
                    return [
                        'success' => true,
                        'message' => HTML_HICYN . $gaoResult['message'] . HTML_NOR
                    ];
                } else if ($gaoResult['message'] !== null) {
                    return ['success' => false, 'message' => $gaoResult['message']];
                }
            }
            
            // 其他物品，高员外不要
            return ['success' => false, 'message' => '高员外摇了摇头：此物我不需要。'];
        }
        
        // 检查NPC是否接受该物品（仅当NPC明确配置了可接受物品列表时才限制）
        if (!empty($receiver['accept_items']) && is_array($receiver['accept_items'])) {
            $accepted = false;
            foreach ($receiver['accept_items'] as $acceptItem) {
                $displayName = $item['name'];
                if ($item['item_id'] === 'photo' && !empty($item['category'])) {
                    $displayName = $item['category'] . '照片';
                }
                if (stripos($acceptItem, $itemId) !== false || 
                    stripos($acceptItem, $displayName) !== false) {
                    $accepted = true;
                    break;
                }
            }
            
            if (!$accepted) {
                return ['success' => false, 'message' => "{$receiver['name']}不要你的东西。"];
            }
        }
        
        // 袁天罡接收饭盒 - 设置yuan-learn标记
        if (($receiver['npc_id'] ?? '') === 'yuantiangang' || ($receiver['id'] ?? 0) == 136) {
            if ($itemId !== 'fanhe') {
                return ['success' => false, 'message' => "袁天罡摇了摇头：此物我不需要。"];
            }

            $invId = $item['id'] ?? 0;
            if ($invId > 0) {
                Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
            } else {
                Database::execute(
                    "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                    [$giverId, $itemId, $item['category'] ?? '']
                );
            }

            $charFamily = $giver['family'] ?? '';
            if ($charFamily === 'wuzhuang') {
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'yuan-learn', '1')
                     ON DUPLICATE KEY UPDATE state_value = '1'",
                    [$giverId]
                );
                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁天罡笑眯眯地接过饭盒，仔细地品尝了一番。\n" .
                        "袁天罡微微点头：不错不错，难得你有这份孝心。\n" .
                        "（你已获得袁天罡的认可，日后可向其学习道术）" . HTML_NOR
                ];
            } else {
                Database::execute("UPDATE characters SET silver = silver + 1 WHERE id = ?", [$giverId]);
                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁天罡笑眯眯地接过饭盒，仔细地品尝了一番。\n" .
                        "袁天罡笑道：劳烦了劳烦了，一点心意，不成敬意。" . HTML_NOR
                ];
            }
        }
        
        // 朱紫国国王接收乌金丹
        if (($receiver['npc_id'] ?? '') === 'king' || ($receiver['id'] ?? 0) == 718) {
            // 条件1：战斗经验 < 10000 → 赶走
            $combatExp = intval($char['combat_exp'] ?? 0);
            if ($combatExp < 10000) {
                return ['success' => false, 'message' => "国王说：「你乳臭未干，懂什么药？也来捣乱！」\n国王勃然大怒，喝令侍从将你赶了出去。"];
            }

            // 条件2：玩家已完成任务
            $cured = Database::queryOne(
                "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'zhuzi_cured'",
                [$giverId]
            );
            if ($cured && $cured['state_value'] == '1') {
                return ['success' => false, 'message' => "国王说：「多谢多谢，无需再拜多礼。」"];
            }

            // 条件3：国王已被治愈（全局）
            $kingCured = Database::queryOne(
                "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'cured'",
                [$receiver['id'] ?? 0]
            );
            if ($kingCured && $kingCured['temp_value'] == '1') {
                return ['success' => false, 'message' => "国王说：「朕躬已安，不劳费心。」"];
            }

            if ($itemId !== 'wujindan') {
                return ['success' => false, 'message' => "国王摇了摇头：此物对朕的病无用。"];
            }
            
            // 移除玩家的乌金丹
            $invId = $item['id'] ?? 0;
            if ($invId > 0) {
                Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
            } else {
                Database::execute(
                    "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                    [$giverId, $itemId, $item['category'] ?? '']
                );
            }
            
            // 记录给药次数
            $giveCount = 0;
            $state = Database::queryOne(
                "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'zhuzi_yao_count'",
                [$giverId]
            );
            if ($state) {
                $giveCount = intval($state['state_value']);
            }
            $giveCount++;
            
            Database::execute(
                "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'zhuzi_yao_count', ?)
                 ON DUPLICATE KEY UPDATE state_value = ?",
                [$giverId, $giveCount, $giveCount]
            );
            
            // 根据给药次数返回不同消息
            if ($giveCount == 1) {
                $message = "国王接过乌金丹，半信半疑地服下。\n过了一会儿，国王皱了皱眉：这药...似乎有些效果，但还不够。";
            } else if ($giveCount == 2) {
                $message = "国王再次服下药丸，脸色渐渐红润起来。\n国王点了点头：嗯，好多了，再服一剂应该就能痊愈了。";
            } else {
                // 第3次及以上，国王痊愈
                // 设置痊愈标记（玩家标记）
                Database::execute(
                    "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'zhuzi_cured', '1')
                     ON DUPLICATE KEY UPDATE state_value = '1'",
                    [$giverId]
                );
                // 设置国王全局痊愈标记
                Database::execute(
                    "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'cured', '1')
                     ON DUPLICATE KEY UPDATE temp_value = '1'",
                    [$receiver['id'] ?? 0]
                );
                
                $message = "国王服下第三颗乌金丹，只觉得神清气爽，百病全消！\n国王大喜：好药！好药！真是神医啊！\n国王感激地说道：多谢壮士为朕治好这顽疾，朕必有重赏！\n（朱紫国制药任务完成！）";
            }
            
            return [
                'success' => true,
                'message' => HTML_HICYN . $message . HTML_NOR
            ];
        }
        
        // 李玉娘送饭给玩家
        if ((($receiver['npc_id'] ?? '') === 'liyu' || ($receiver['id'] ?? 0) == 89) && $itemId === 'fanhe') {
            $hasFanhe = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = 'fanhe' LIMIT 1",
                [$giverId]
            );
            if ($hasFanhe) {
                return ['success' => false, 'message' => "你已经有了饭盒，快去送饭吧！"];
            }
            Database::execute(
                "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, 'fanhe', 'food', 1)",
                [$giverId]
            );
            return [
                'success' => true,
                'message' => HTML_HICYN . "李玉娘四下打量了一番，将一个热腾腾的饭盒塞到你手中。\n" .
                    "李玉娘低声说道：劳烦了，帮我把饭送给天监台的袁天罡吧。" . HTML_NOR
            ];
        }
        
        // 却俟大师送饭任务
        if (strpos($receiverName, '却俟') !== false && $itemId === 'fan_cai') {
            $invId = $item['id'] ?? 0;
            if ($invId > 0) {
                Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
            } else {
                Database::execute(
                    "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                    [$giverId, $itemId, $item['category'] ?? '']
                );
            }

            try {
                Database::execute(
                    "UPDATE characters SET silver = silver + {$questConfig['rewards']['songfan']['silver']}, combat_exp = combat_exp + {$questConfig['rewards']['songfan']['combat_exp']}, deliver_food_time = NOW() WHERE id = ?",
                    [$giverId]
                );
            } catch (\Exception $e) {
                error_log('DeliverFood reward error: ' . $e->getMessage());
            }
            return [
                'success' => true,
                'message' => HTML_HIGRN . "你将饭菜交给却俟大师，他满意地点点头。" . HTML_NOR . "\n" .
                    HTML_HICYN . "奖励：银两+500，经验+200" . HTML_NOR
            ];
        }

        // 守门牛精收油放行（青龙山玄英洞通道）
        if (($receiver['npc_id'] ?? '') === 'shoumenniujing' || ($receiver['id'] ?? 0) == 1744) {
            require_once __DIR__ . '/../helpers/ShoumenniujingHelper.php';
            $giveResult = ShoumenniujingHelper::handleGive($giverId, $item);
            
            if ($giveResult && !empty($giveResult['consume_item'])) {
                $invId = $item['id'] ?? 0;
                if ($invId > 0) {
                    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
                } else {
                    Database::execute(
                        "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                        [$giverId, $itemId, $item['category'] ?? '']
                    );
                }
            }
            
            return [
                'success' => $giveResult['success'] ?? true,
                'message' => HTML_HIYEL . ($giveResult['message'] ?? '守门牛精收下了你的东西。') . HTML_NOR
            ];
        }

        // 马盗收钱放行（饮马峪拦路抢劫）
        if (($receiver['npc_id'] ?? '') === 'madao' || ($receiver['id'] ?? 0) == 522) {
            require_once __DIR__ . '/../helpers/MadaoHelper.php';
            $giveResult = MadaoHelper::handleGive($giverId, $item);
            
            if ($giveResult && !empty($giveResult['consume_item'])) {
                $invId = $item['id'] ?? 0;
                if ($invId > 0) {
                    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
                } else {
                    Database::execute(
                        "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                        [$giverId, $itemId, $item['category'] ?? '']
                    );
                }
            }
            
            return [
                'success' => $giveResult['success'] ?? true,
                'message' => HTML_HIYEL . ($giveResult['message'] ?? '马盗收下了你的东西。') . HTML_NOR
            ];
        }

        // 袁守诚接收物品（金色鲤鱼、桂花酒袋）
        if (($receiver['npc_id'] ?? '') === 'shouchen' || ($receiver['id'] ?? 0) == 30) {
            $invId = $item['id'] ?? 0;
            
            // 金色鲤鱼 - 算命付费
            if ($itemId === 'golden_carp') {
                if ($invId > 0) {
                    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
                } else {
                    Database::execute(
                        "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                        [$giverId, $itemId, $item['category'] ?? '']
                    );
                }

                // 设置付费状态（24小时有效）
                $expireTime = date('Y-m-d H:i:s', time() + 86400);
                $stateValue = json_encode([
                    'paid' => true,
                    'pay_time' => date('Y-m-d H:i:s'),
                    'expire_time' => $expireTime
                ]);
                
                Database::execute(
                    'INSERT INTO character_temp_states (char_id, state_key, state_value, expire_time) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expire_time = VALUES(expire_time), updated_at = NOW()',
                    [$giverId, 'suanming/paid', $stateValue, $expireTime]
                );

                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁守诚满面堆欢：多谢施主，在下正需要这个，有什么问题您尽管问！" . HTML_NOR
                ];
            }
            
            // 桂花酒袋 - 赠送天书
            if ($itemId === 'guihua-jiudai') {
                if ($invId > 0) {
                    Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
                } else {
                    Database::execute(
                        "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                        [$giverId, $itemId, $item['category'] ?? '']
                    );
                }

                // 检查是否已经领过
                $hasReceived = Database::queryOne(
                    "SELECT id FROM character_temp_states WHERE char_id = ? AND state_key = 'received_wine'",
                    [$giverId]
                );
                
                if (!$hasReceived) {
                    // 奖励天书
                    Database::execute(
                        "INSERT INTO character_inventory (char_id, item_id, category, quantity) VALUES (?, 'nowords', 'book', 1)",
                        [$giverId]
                    );
                    
                    // 设置标记
                    Database::execute(
                        "INSERT INTO character_temp_states (char_id, state_key, state_value) VALUES (?, 'received_wine', '1') ON DUPLICATE KEY UPDATE state_value = '1'",
                        [$giverId]
                    );
                }

                return [
                    'success' => true,
                    'message' => HTML_HICYN . "袁守诚接过桂花酒袋，微微一笑，说道：这位施主跟小道投缘！这里我也有一点小意思，请笑纳。" . HTML_NOR
                ];
            }
            
            // 其他物品
            return ['success' => false, 'message' => "袁守诚摇了摇头：此物我不需要。"];
        }

        // 检查是否是任务相关
        if (isset($receiver['quest_give']) && $receiver['quest_give']) {
            $invId = $item['id'] ?? 0;
            if ($invId > 0) {
                Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
            } else {
                Database::execute(
                    "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                    [$giverId, $itemId, $item['category'] ?? '']
                );
            }
            
            return [
                'success' => true,
                'message' => "你给{$receiverName}一{$itemUnit}{$itemName}。\n{$receiverName}由衷地向你道谢。"
            ];
        }
        
        // 给予金钱给NPC(直接销毁)
        if (isset($item['is_money']) && $item['is_money']) {
            $invId = $item['id'] ?? 0;
            if ($invId > 0) {
                Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
            } else {
                Database::execute(
                    "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                    [$giverId, $itemId, $item['category'] ?? '']
                );
            }
            
            return [
                'success' => true,
                'message' => "你拿出{$item['name']}给{$receiverName}。"
            ];
        }
        
        // 普通物品给予NPC(从背包移除)
        $invId = $item['id'] ?? 0;
        if ($invId > 0) {
            Database::execute("DELETE FROM character_inventory WHERE id = ?", [$invId]);
        } else {
            Database::execute(
                "DELETE FROM character_inventory WHERE char_id = ? AND item_id = ? AND COALESCE(category, '') = ?",
                [$giverId, $itemId, $item['category'] ?? '']
            );
        }
        
        log_game('GIVE', "{$giver['name']} 给予 {$receiverName} {$itemName}");
        
        return [
            'success' => true,
            'message' => "你给{$receiverName}一{$itemUnit}{$itemName}。\n{$receiverName}由衷地向你道谢。"
        ];
    }
    
    // 如果接收者是玩家，检查负重
    if (isset($receiver['id']) || isset($receiver['char_id'])) {
        $receiverId = $receiver['id'] ?? $receiver['char_id'];
        $canPickUp = WeightHelper::canPickUp($receiverId, $item['item_id'], $item['quantity'] ?? 1);
        if (!$canPickUp['success']) {
            return ['success' => false, 'message' => "{$receiver['name']}背不动了！"];
        }
    }

    // 如果是玩家(简化版,实际需要添加到对方背包)
    return ['success' => false, 'message' => '暂时不支持给予玩家物品。'];
}

