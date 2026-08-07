<?php
/**
 * Npc Task Handler
 * 
 * NPC任务交互处理器
 * 处理与NPC的任务类交互，如要斋饭等
 * 通过 config JSON 配置NPC ID、物品、数量限制等
 * 
 * 从 ActionRouter::handleLegacyAction 的 yao_zhaifan 分支迁移
 * 2026-05-20: 新增掌门NPC特殊交互（greet/bow/ask）
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../helpers/SectHelper.php';

class NpcTaskHandler extends ActionHandler {
    
    /**
     * 执行NPC任务交互动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $npcId = $config['npc_id'] ?? '';
            $itemId = $config['item_id'] ?? '';
            $maxQuantity = $config['max_quantity'] ?? 5;
            $npcNameDefault = $config['npc_name_default'] ?? 'NPC';
            $successTemplate = $config['success_message_template'] ?? '{npc_name}给了你一个物品。';
            $failTemplate = $config['fail_message_template'] ?? '{npc_name}摇摇头说："你已经拿了很多了，不要再贪心了。"';
            $wrongRoomMessage = $config['wrong_room_message'] ?? '这里没有可以交互的地方。';
            
            // 获取当前房间
            $currentRoom = $character['current_room'];
            $actionRoomId = $action['room_id'] ?? '';
            
            // 检查房间是否正确
            if ($currentRoom !== $actionRoomId) {
                return ['success' => false, 'message' => $wrongRoomMessage];
            }
            
            // 查找NPC
            $npcName = $npcNameDefault;
            if (!empty($npcId)) {
                require_once __DIR__ . '/../models/Npc.php';
                $npc = NpcModel::findByNpcId($npcId);
                if ($npc) {
                    $npcName = $npc['name'];
                }
            }
            
            // 检查物品数量限制
            if (!empty($itemId) && $maxQuantity > 0) {
                require_once __DIR__ . '/../includes/db.php';
                $existingItem = Database::queryOne(
                    'SELECT quantity FROM character_inventory WHERE char_id = ? AND item_id = ?',
                    [$charId, $itemId]
                );
                
                if ($existingItem && $existingItem['quantity'] >= $maxQuantity) {
                    $message = str_replace('{npc_name}', $npcName, $failTemplate);
                    return ['success' => false, 'message' => $message];
                }
            }
            
            // 检查是否为掌门NPC，如果是则提供掌门专属交互提示
            if (!empty($npcId)) {
                $npcIntId = is_numeric($npcId) ? intval($npcId) : 0;
                if ($npcIntId > 0) {
                    $sect = SectHelper::getSectByNpcId($npcIntId);
                    if ($sect) {
                        $sectName = $sect['name'] ?? $sect['key'];
                        $masterName = $npcName;
                        $sectKey = $sect['key'];
                        
                        $skills = $sect['skills']['exclusive'] ?? [];
                        $skillList = !empty($skills) ? '，本门绝学：' . implode('、', array_values($skills)) : '';
                        
                        $message = sprintf(
                            '%s是%s掌门。你可以：\n  - 使用 apprentice %s 拜师入门\n  - 使用 greet/bow 向%s行礼\n  - 使用 ask %s about 门派 了解详情%s',
                            $masterName,
                            $sectName,
                            $masterName,
                            $masterName,
                            $masterName,
                            $skillList
                        );
                        return ['success' => true, 'message' => $message, 'data' => [
                            'is_sect_master' => true,
                            'sect_key' => $sectKey,
                            'sect_name' => $sectName,
                            'master_name' => $masterName,
                        ]];
                    }
                }
            }
            
            // 给予物品
            if (!empty($itemId)) {
                require_once __DIR__ . '/../models/Item.php';
                ItemModel::addToInventory($charId, $itemId, 1);
            }
            
            $message = str_replace('{npc_name}', $npcName, $successTemplate);
            return ['success' => true, 'message' => $message];
            
        } catch (\Exception $e) {
            error_log("NpcTaskHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => 'NPC任务交互执行失败', 'data' => null];
        }
    }

    // =========================================================
    // 掌门NPC交互方法
    // =========================================================

    /**
     * 处理对掌门NPC的 greet/bow 交互
     * 返回门派介绍对话
     *
     * @param int    $charId 角色ID
     * @param int    $npcId  NPC的ID
     * @param string $action 动作类型（greet/bow）
     * @return array
     */
    public static function handleSectMasterGreet(int $charId, int $npcId, string $action = 'greet'): array
    {
        $sect = SectHelper::getSectByNpcId($npcId);
        if (!$sect) {
            return ['success' => false, 'message' => '此人不掌管任何门派。'];
        }

        $sectName   = $sect['name'] ?? $sect['key'];
        $masterName = $sect['master_npc'] ?? '掌门';
        $sectKey    = $sect['key'];

        require_once __DIR__ . '/../includes/db.php';
        $character = Database::queryOne(
            'SELECT id, name, family FROM characters WHERE id = ?',
            [$charId]
        );
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        $charFamily = $character['family'] ?? '';

        // 已是该门派弟子
        if ($charFamily === $sectKey) {
            $member = Database::queryOne(
                'SELECT sect_rank FROM sect_members WHERE character_id = ? AND is_active = 1',
                [$charId]
            );
            $rank = $member ? '【' . $member['sect_rank'] . '】' : '';
            return ['success' => true, 'message' => sprintf(
                '%s微笑道：「%s，你回来了。继续勤加修炼，为%s争光。」%s',
                $masterName,
                $character['name'],
                $sectName,
                $rank ? '\n你目前是' . $sectName . '的' . $rank . '。' : ''
            )];
        }

        // 非弟子 - 给出门派介绍
        $skills = $sect['skills']['exclusive'] ?? [];
        $skillList = !empty($skills) ? '，本门绝学包括' . implode('、', array_values($skills)) : '';

        return ['success' => true, 'message' => sprintf(
            '%s对你微微点头，说道：「%s——%s%s。\n你若有心向道，可用 apprentice 拜师入门。」',
            $masterName,
            $sectName,
            $sect['description'] ?? '',
            $skillList
        )];
    }

    /**
     * 处理对掌门NPC的 ask 交互
     * 返回入门条件说明
     *
     * @param int    $charId 角色ID
     * @param int    $npcId  NPC的ID
     * @return array
     */
    public static function handleSectMasterAsk(int $charId, int $npcId): array
    {
        $sect = SectHelper::getSectByNpcId($npcId);
        if (!$sect) {
            return ['success' => false, 'message' => '此人不掌管任何门派。'];
        }

        $sectName   = $sect['name'] ?? $sect['key'];
        $masterName = $sect['master_npc'] ?? '掌门';
        $sectKey    = $sect['key'];

        require_once __DIR__ . '/../includes/db.php';
        $character = Database::queryOne(
            'SELECT id, name, family, level, betrayal_count FROM characters WHERE id = ?',
            [$charId]
        );
        if (!$character) {
            return ['success' => false, 'message' => '角色不存在。'];
        }

        $charFamily = $character['family'] ?? '';

        // 已是该门派弟子
        if ($charFamily === $sectKey) {
            $skills = $sect['skills']['exclusive'] ?? [];
            $skillInfo = '';
            if (!empty($skills)) {
                $skillInfo = '\n本门绝学：' . implode('、', array_values($skills));
            }
            $bonusSkills = $sect['skills']['bonus'] ?? [];
            if (!empty($bonusSkills)) {
                $bonusInfo = [];
                foreach ($bonusSkills as $skey => $bval) {
                    $bonusInfo[] = $skey . '(+' . $bval . ')';
                }
                $skillInfo .= '\n门派加成：' . implode('、', $bonusInfo);
            }
            return ['success' => true, 'message' => sprintf(
                '%s说道：「身为%s弟子，当勤修本门武学。」%s',
                $masterName,
                $sectName,
                $skillInfo
            )];
        }

        // 非弟子 - 给出入门条件
        $reqs = $sect['requirements'] ?? [];
        $conditions = [];
        $minLevel = $reqs['min_level'] ?? 1;
        if ($minLevel > 1) {
            $conditions[] = '等级至少' . $minLevel . '级';
        }
        $race = $reqs['race'] ?? null;
        if (!empty($race)) {
            $raceNames = is_array($race) ? implode('、', $race) : $race;
            $conditions[] = '种族为' . $raceNames;
        }
        $gender = $reqs['gender'] ?? null;
        if (!empty($gender)) {
            $genderName = $gender === 'male' ? '男性' : '女性';
            $conditions[] = '性别为' . $genderName;
        }

        $condText = !empty($conditions) ? '入门条件：' . implode('，', $conditions) . '。' : '本门来者不拒，无需特殊条件。';

        // 检查背叛次数
        $betrayalWarning = '';
        $betrayalCount = (int)($character['betrayal_count'] ?? 0);
        $globalConfig = SectHelper::getSectConfig();
        $maxBetrayal = $globalConfig['join_config']['max_betrayals_allowed'] ?? 3;
        if ($betrayalCount > 0) {
            if ($betrayalCount > $maxBetrayal) {
                $betrayalWarning = sprintf(
                    '\n%s摇头叹道：「你已背叛门派%d次，本门不敢收你。」',
                    $masterName,
                    $betrayalCount
                );
            } else {
                $betrayalWarning = sprintf(
                    '\n%s皱眉道：「你已背叛门派%d次，若再叛，后果自负。」',
                    $masterName,
                    $betrayalCount
                );
            }
        }

        return ['success' => true, 'message' => sprintf(
            '%s说道：「想加入%s？%s」%s\n你可以使用 apprentice %s 向我拜师。',
            $masterName,
            $sectName,
            $condText,
            $betrayalWarning,
            $masterName
        )];
    }
}

