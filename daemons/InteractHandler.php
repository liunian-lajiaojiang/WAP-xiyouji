<?php
/**
 * Interact Handler
 * 
 * 交互处理器
 * 处理物品交互（开棺材等）和掌门NPC交互（拜师、请教、门派信息）
 * 通过 config JSON 配置交互条件、伤害、目标房间等
 * 
 * 从 ActionRouter::handleLegacyAction 的 open 分支迁移
 * 2026-05-20: 新增掌门NPC交互逻辑
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/../helpers/SectHelper.php';

class InteractHandler extends ActionHandler {
    
    // =========================================================
    // 1. 物品交互
    // =========================================================
    
    /**
     * 执行物品交互动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $arg = $params['arg'] ?? '';
            
            // 1. 检查房间（支持用 | 分隔多房间，如 "death/out|death/road3"）
            $requiredRoom = $config['required_room'] ?? '';
            $currentRoom = $character['current_room'];
            $wrongRoomMessage = $config['wrong_room_message'] ?? '这里没有可以交互的东西。';
            
            if (!empty($requiredRoom)) {
                $requiredRooms = explode('|', $requiredRoom);
                if (!in_array($currentRoom, $requiredRooms)) {
                    return ['success' => false, 'message' => $wrongRoomMessage];
                }
            }
            
            // 2. 检查参数
            $validParams = $config['valid_params'] ?? [];
            $noParamMessage = $config['no_param_message'] ?? '你要做什么？';
            
            if (!empty($validParams)) {
                // 如果 arg 为空，但有 validParams，默认使用第一个
                if (empty($arg)) {
                    $arg = $validParams[0];
                }
                // 检查 arg 是否在 validParams 中
                if (!in_array($arg, $validParams)) {
                    return ['success' => false, 'message' => $noParamMessage];
                }
            }
            
            // 3. 计算伤害
            $damageMin = $config['damage_min'] ?? 10;
            $damageMax = $config['damage_max'] ?? 20;
            $damage = random_int($damageMin, $damageMax);
            
            // 4. 检查属性是否足够
            $statCheck = $config['stat_check'] ?? 'sen';
            $statValue = intval($character[$statCheck] ?? 0);
            
            require_once __DIR__ . '/../includes/db.php';
            
            if ($statValue > $damage) {
                // 成功：属性足够
                $successSelfMessage = $config['success_self_message'] ?? '你成功交互了。';
                $successBroadcastTemplate = $config['success_broadcast_template'] ?? '';
                $arriveBroadcastTemplate = $config['arrive_broadcast_template'] ?? '';
                $targetArea = $config['target_area'] ?? '';
                $targetRoom = $config['target_room'] ?? '';
                
                // 广播消息到离开的房间
                if (!empty($successBroadcastTemplate)) {
                    $broadcastMessage = $successBroadcastTemplate;
                    $broadcastMessage = str_replace('{name}', $character['name'], $broadcastMessage);
                    $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
                    $this->broadcastToRoom($currentRoom, $broadcastMessage, intval($charId));
                }
                
                // 扣除属性
                Database::execute(
                    'UPDATE characters SET ' . $statCheck . ' = ' . $statCheck . ' - ? WHERE id = ?',
                    [$damage, $charId]
                );
                
                // 恢复 gin（如果配置了）
                if (!empty($config['gin_restore'])) {
                    Database::execute(
                        'UPDATE characters SET gin = max_gin WHERE id = ?',
                        [$charId]
                    );
                }
                
                // 移动到目标房间
                if (!empty($targetRoom)) {
                    Database::execute(
                        'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
                        [$targetArea, $targetRoom, $charId]
                    );
                    
                    // 广播消息到到达的房间
                    if (!empty($arriveBroadcastTemplate)) {
                        $arriveMessage = $arriveBroadcastTemplate;
                        $arriveMessage = str_replace('{name}', $character['name'], $arriveMessage);
                        $arriveMessage = (defined('HIY') ? HIY : '') . $arriveMessage . (defined('NOR') ? NOR : '');
                        $this->broadcastToRoom($targetRoom, $arriveMessage, intval($charId));
                    }
                    
                    $redirectUrl = room_url($targetArea, $targetRoom);
                    
                    return [
                        'success' => true,
                        'message' => $successSelfMessage,
                        'redirect' => $redirectUrl
                    ];
                }
                
                return [
                    'success' => true,
                    'message' => $successSelfMessage
                ];
                
            } else {
                // 失败：属性不足
                $failSelfMessage = $config['fail_self_message'] ?? '你的力量不够，交互失败。';
                $failBroadcastTemplate = $config['fail_broadcast_template'] ?? '';
                
                // 广播消息
                if (!empty($failBroadcastTemplate)) {
                    $broadcastMessage = $failBroadcastTemplate;
                    $broadcastMessage = str_replace('{name}', $character['name'], $broadcastMessage);
                    $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
                    $this->broadcastToRoom($currentRoom, $broadcastMessage, intval($charId));
                }
                
                // 扣除属性（即使失败也扣除）
                Database::execute(
                    'UPDATE characters SET ' . $statCheck . ' = ' . $statCheck . ' - ? WHERE id = ?',
                    [$damage, $charId]
                );
                
                return [
                    'success' => true,
                    'message' => $failSelfMessage
                ];
            }
            
        } catch (\Exception $e) {
            error_log("InteractHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '物品交互执行失败', 'data' => null];
        }
    }

    // =========================================================
    // 2. 掌门NPC交互
    // =========================================================

    /**
     * 检查NPC是否为门派掌门
     *
     * @param int $npcId NPC的ID
     * @return array|null 门派配置数组（含 'key' 字段），非掌门返回 null
     */
    public static function checkSectMaster(int $npcId): ?array
    {
        return SectHelper::getSectByNpcId($npcId);
    }

    /**
     * 处理对掌门NPC的交互（greet/bow）
     * 返回掌门的门派介绍对话
     *
     * @param int    $charId 角色ID
     * @param int    $npcId  NPC的ID
     * @param string $action 交互动作（greet/bow/apprentice/ask）
     * @return array ['success'=>bool, 'message'=>string, 'data'=>array|null]
     */
    public static function handleSectMasterInteract(int $charId, int $npcId, string $action = 'greet'): array
    {
        try {
            $sect = SectHelper::getSectByNpcId($npcId);
            if (!$sect) {
                return ['success' => false, 'message' => '此人不掌管任何门派。', 'data' => null];
            }

            $sectName   = $sect['name'] ?? $sect['key'];
            $masterName = $sect['master_npc'] ?? '掌门';
            $sectKey    = $sect['key'];

            // 获取角色信息
            require_once __DIR__ . '/../includes/db.php';
            $character = Database::queryOne(
                'SELECT id, name, family, level, betrayal_count FROM characters WHERE id = ?',
                [$charId]
            );
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在。', 'data' => null];
            }

            // 获取NPC信息
            require_once __DIR__ . '/../models/Npc.php';
            $npc = NpcModel::find($npcId);
            $npcName = $npc ? $npc['name'] : $masterName;

            switch ($action) {
                case 'greet':
                case 'bow':
                    return self::sectMasterGreet($character, $npcName, $sect);

                case 'ask':
                    return self::sectMasterAsk($character, $npcName, $sect);

                case 'apprentice':
                    return self::sectMasterApprenticeHint($character, $npcName, $sect);

                default:
                    return self::sectMasterGreet($character, $npcName, $sect);
            }

        } catch (\Exception $e) {
            error_log('InteractHandler::handleSectMasterInteract error: ' . $e->getMessage());
            return ['success' => false, 'message' => '与掌门交互失败，请稍后再试。', 'data' => null];
        }
    }

    /**
     * 掌门NPC问候/鞠躬响应 - 给出门派介绍
     */
    private static function sectMasterGreet(array $character, string $npcName, array $sect): array
    {
        $sectName   = $sect['name'] ?? $sect['key'];
        $desc       = $sect['description'] ?? '';
        $charFamily = $character['family'] ?? '';
        $sectKey    = $sect['key'];

        // 如果已是该门派弟子，给出欢迎回来消息
        if ($charFamily === $sectKey) {
            $rank = '';
            require_once __DIR__ . '/../includes/db.php';
            $member = Database::queryOne(
                'SELECT sect_rank FROM sect_members WHERE character_id = ? AND is_active = 1',
                [$character['id']]
            );
            if ($member) {
                $rank = '【' . $member['sect_rank'] . '】';
            }

            $message = sprintf(
                '%s微笑道：「%s，你回来了。继续勤加修炼，为%s争光。」%s',
                $npcName,
                $character['name'],
                $sectName,
                $rank ? '\n你目前是' . $sectName . '的' . $rank . '。' : ''
            );
            return ['success' => true, 'message' => $message, 'data' => [
                'sect_key' => $sectKey,
                'is_member' => true,
            ]];
        }

        // 非弟子，给出招贤纳士消息
        $skills = $sect['skills']['exclusive'] ?? [];
        $skillList = !empty($skills) ? '，本门绝学包括' . implode('、', array_values($skills)) : '';

        $message = sprintf(
            '%s对你微微点头，说道：「%s——%s%s。\n你若有心向道，可用 apprentice 拜师入门。」',
            $npcName,
            $sectName,
            $desc,
            $skillList
        );

        return ['success' => true, 'message' => $message, 'data' => [
            'sect_key' => $sectKey,
            'is_member' => false,
            'sect_name' => $sectName,
            'master_name' => $npcName,
        ]];
    }

    /**
     * 掌门NPC请教响应 - 给出入门条件说明
     */
    private static function sectMasterAsk(array $character, string $npcName, array $sect): array
    {
        $sectName = $sect['name'] ?? $sect['key'];
        $reqs     = $sect['requirements'] ?? [];
        $charFamily = $character['family'] ?? '';
        $sectKey  = $sect['key'];

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

            $message = sprintf(
                '%s说道：「身为%s弟子，当勤修本门武学。」%s',
                $npcName,
                $sectName,
                $skillInfo
            );
            return ['success' => true, 'message' => $message];
        }

        // 构建入门条件说明
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
                    $npcName,
                    $betrayalCount
                );
            } else {
                $betrayalWarning = sprintf(
                    '\n%s皱眉道：「你已背叛门派%d次，若再叛，后果自负。」',
                    $npcName,
                    $betrayalCount
                );
            }
        }

        $message = sprintf(
            '%s说道：「想加入%s？%s」%s\n你可以使用 apprentice %s 向我拜师。',
            $npcName,
            $sectName,
            $condText,
            $betrayalWarning,
            $npcName
        );

        return ['success' => true, 'message' => $message, 'data' => [
            'sect_key' => $sectKey,
            'requirements' => $reqs,
        ]];
    }

    /**
     * 掌门NPC拜师提示
     */
    private static function sectMasterApprenticeHint(array $character, string $npcName, array $sect): array
    {
        $sectName = $sect['name'] ?? $sect['key'];
        $charFamily = $character['family'] ?? '';
        $sectKey = $sect['key'];

        if ($charFamily === $sectKey) {
            return ['success' => false, 'message' => '你已经是' . $sectName . '的弟子了。'];
        }

        return ['success' => true, 'message' => sprintf(
            '%s点头道：「你想拜入%s？请使用 apprentice %s 正式行拜师之礼。」',
            $npcName,
            $sectName,
            $npcName
        )];
    }
}

