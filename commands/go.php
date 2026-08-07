<?php
/**
 * 移动命令 (go)
 */
require_once __DIR__ . '/../helpers/BanHelper.php';
require_once __DIR__ . '/../daemons/BaoxiangHandler.php';
require_once __DIR__ . '/../helpers/TempStateHelper.php';
require_once __DIR__ . '/../daemons/SeaMazeHandler.php';
require_once __DIR__ . '/../daemons/DonghaiMazeHandler.php';
require_once __DIR__ . '/../daemons/FiremountHandler.php';

// 加载任务配置
static $_questCfg = null;
if ($_questCfg === null) {
    $_questCfg = require __DIR__ . '/../config/quest.php';
}

function cmd_go(int $charId, string $direction = ''): array {
    // 检查是否昏迷（Session 标记，由 cmd_faint 或 handlePubuMaze 设置）
    if (isset($_SESSION["unconscious_{$charId}"])) {
        $unconscious = $_SESSION["unconscious_{$charId}"];
        $elapsed = time() - $unconscious['timestamp'];
        $duration = $unconscious['duration'] ?? 30;
        
        if ($elapsed < $duration) {
            $remaining = $duration - $elapsed;
            return [
                'success' => false,
                'message' => HTML_HIRED . '你昏迷中，无法移动！(剩余' . $remaining . '秒)' . HTML_NOR,
                'skip_queue' => true,
            ];
        } else {
            // 昏迷时间已过，清除状态
            unset($_SESSION["unconscious_{$charId}"]);
            // 同时清除 DB 昏迷状态
            Database::execute(
                'UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?',
                [$charId]
            );
        }
    } else {
        // 回退检查：DB 昏迷状态（应对 Session 丢失的情况）
        $dbChar = CharacterModel::find($charId);
        if ($dbChar && !empty($dbChar['unconscious_state']) && $dbChar['unconscious_state'] == 1) {
            $endTime = intval($dbChar['unconscious_end_time'] ?? 0);
            if ($endTime > 0 && time() < $endTime) {
                $remaining = $endTime - time();
                return [
                    'success' => false,
                    'message' => HTML_HIRED . '你昏迷中，无法移动！(剩余' . $remaining . '秒)' . HTML_NOR,
                    'skip_queue' => true,
                ];
            }
            // 昏迷时间已过，清除 DB 状态
            Database::execute(
                'UPDATE characters SET unconscious_state = 0, unconscious_end_time = 0 WHERE id = ?',
                [$charId]
            );
        }
    }

    // ★ 铁笼陷阱检查：在铁笼中且铁笼未打开时禁止移动
    // 还原原始LPC: tielong.c 中没有 exits，玩家被囚禁无法离开
    $char = $char ?? CharacterModel::find($charId);
    if ($char && $char['current_room'] === 'westway/tielong') {
        require_once __DIR__ . '/../helpers/TempStateHelper.php';
        $tielongOpen = TempStateHelper::get($charId, 'shizhan_tielong_open');
        if (is_array($tielongOpen)) {
            $tielongOpen = !empty($tielongOpen['_value']);
        }
        if (!$tielongOpen) {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你被关在铁笼里，无法移动！' . HTML_NOR . "\n" .
                             HTML_CYN . '你需要先扳开(break)铁笼才能出去。' . HTML_NOR,
                'skip_queue' => true,
            ];
        }
    }
    
    // 检查是否是鬼魂状态（死亡后未重生）
    // 使用数据库 is_ghost 字段（跨 session 可见，修复攻击者进程无法写受害者 session 的问题）
    if ($char && !empty($char['is_ghost'])) {
        $ghostArea = $char['current_area'] ?? '';
        // 如果鬼魂试图离开地府区域（death/），阻止移动
        // 但允许在地府内部自由移动（death/* 房间之间）
        if ($ghostArea !== 'death') {
            // 鬼魂标记存在但不在 death 区域，说明状态异常，清除鬼魂标记
            Database::execute("UPDATE characters SET is_ghost = 0 WHERE id = ?", [$charId]);
        }
    }
    
    // 修炼状态检查：打坐/冥思/修道/练习中禁止移动
    // 先检查 SESSION（快速路径）
    $trainingBlocked = false;
    $trainingMsg = '';
    if (!empty($_SESSION['pending_exercising'])) {
        $trainingBlocked = true; $trainingMsg = '你正在打坐练功，不能移动。';
    } elseif (!empty($_SESSION['pending_meditating'])) {
        $trainingBlocked = true; $trainingMsg = '你正在冥思中，不能移动。';
    } elseif (!empty($_SESSION['pending_practicing'])) {
        $trainingBlocked = true; $trainingMsg = '你正在练习技能，不能移动。';
    } elseif (!empty($_SESSION['pending_xiudao'])) {
        $trainingBlocked = true; $trainingMsg = '你正在修道中，不能移动。';
    }
    
    // 再检查数据库（防止 SESSION 并发丢失）
    if (!$trainingBlocked) {
        $dbChar = CharacterModel::find($charId);
        if ($dbChar && !empty($dbChar['training_state']) && !empty($dbChar['training_end_time'])) {
            if ($dbChar['training_end_time'] > time()) {
                $trainingBlocked = true;
                $stateNames = [
                    'exercising' => '打坐练功',
                    'meditating' => '冥思',
                    'practicing' => '练习技能',
                    'xiudao' => '修道',
                ];
                $trainingMsg = '你正在' . ($stateNames[$dbChar['training_state']] ?? '修炼') . '中，不能移动。';
            } else {
                // 已过期但未清理，清除标记
                Database::execute("UPDATE characters SET training_state = NULL, training_end_time = 0 WHERE id = ?", [$charId]);
            }
        }
    }
    
    if ($trainingBlocked) {
        return ['success' => false, 'message' => $trainingMsg, 'skip_queue' => true];
    }

    // 检查是否被监禁
    $char = CharacterModel::find($charId);
    if ($char) {
        $user = Database::queryOne("SELECT status FROM users WHERE id = ?", [$char['user_id']]);
        if ($user && $user['status'] == BanHelper::STATUS_PRISONED) {
            return ['success' => false, 'message' => '你被关在监禁房间里，无法移动！'];
        }
        
        $isBlocked = Database::queryOne('SELECT id FROM user_blocks WHERE user_id = ? AND block_type = ?', [$char['user_id'], 'move']);
        if ($isBlocked) {
            return ['success' => false, 'message' => '你的移动功能已被封禁'];
        }
        
        // 检查是否有 blocker 类型的妖怪阻挡
        $blockerResult = checkYaoguaiBlocker($charId, $char);
        if (!$blockerResult['allowed']) {
            return ['success' => false, 'message' => $blockerResult['message']];
        }
    }
    
    // 方向别名映射
    $directionAliases = [
        'n' => 'north',
        's' => 'south',
        'e' => 'east',
        'w' => 'west',
        'u' => 'up',
        'd' => 'down',
        'ne' => 'northeast',
        'nw' => 'northwest',
        'se' => 'southeast',
        'sw' => 'southwest',
        // 中文别名
        '北' => 'north',
        '南' => 'south',
        '东' => 'east',
        '西' => 'west',
        '东北' => 'northeast',
        '西北' => 'northwest',
        '东南' => 'southeast',
        '西南' => 'southwest',
        '上' => 'up',
        '下' => 'down',
    ];
    
    // 处理方向别名
    if (isset($directionAliases[$direction])) {
        $direction = $directionAliases[$direction];
    }
    
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    if (empty($direction)) {
        return ['success' => false, 'message' => '你要往哪个方向走？'];
    }
    
    // 获取当前房间
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    
    if (!$room) {
        return ['success' => false, 'message' => '当前位置无效'];
    }
    
    // 瀑布迷宫导航（pubu1-5 房间无标准出口，go 命令由迷宫逻辑接管）
    // 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
    $mazeRooms = ['dntg/hgs/pubu1', 'dntg/hgs/pubu2', 'dntg/hgs/pubu3', 'dntg/hgs/pubu4', 'dntg/hgs/pubu5'];
    if (in_array($room['room_id'], $mazeRooms)) {
        return handlePubuMaze($charId, $char, $room, strtolower($direction));
    }

    // ★ 海底莽林迷宫导航（sea/maze0~maze9）
    // 还原原始LPC sea/maze 随机传送逻辑
    if (SeaMazeHandler::isMazeRoom($room['room_id'])) {
        $seaMazeResult = SeaMazeHandler::handleMove($charId, $char, $room, strtolower($direction));
        if ($seaMazeResult !== null) {
            return $seaMazeResult;
        }
    }

    // ★ 海底迷宫导航（dntg/donghai/maze* 随机区 mazea~mazed）
    // 还原原始LPC donghai/ways[random(sizeof(ways))] 逻辑
    if (DonghaiMazeHandler::isRandomMazeRoom($room['room_id'])) {
        $donghaiMazeResult = DonghaiMazeHandler::handleMove($charId, $char, $room, strtolower($direction));
        if ($donghaiMazeResult !== null) {
            return $donghaiMazeResult;
        }
    }
    
    // ★ 火焰山迷宫导航（qujing/firemount/huoyan）
    // 燃烧时所有方向都指向自己，熄灭后出口打开
    if ($room['room_id'] === 'qujing/firemount/huoyan') {
        $firemountExits = FiremountHandler::getRoomExits($room['room_id']);
        if ($firemountExits !== null) {
            $room['exits'] = $firemountExits;
        }
    }
    
    // 查找出口
    $targetExit = null;
    foreach ($room['exits'] as $exit) {
        if (strtolower($exit['direction']) === strtolower($direction)) {
            $targetExit = $exit;
            break;
        }
    }
    
    if (!$targetExit) {
        // 检查是否是地府穿墙特殊动作
        if ($room['room_id'] === 'death/new-out6' && strtolower($direction) === 'north') {
            // 地府穿墙到酆都城
            return handleHellWallPass($charId, $room);
        }
        
        // 检查是否是从贵道门出去
        if ($room['room_id'] === 'death/guidaomen' && strtolower($direction) === 'out') {
            // 从阴间出去
            return handleHellExit($charId, $room);
        }
        
        // 聚见亭跨栏杆出去 → 传送到花果山仙石
        if ($room['room_id'] === 'ourhome/xiaoting' && strtolower($direction) === 'out') {
            return handleXiaotingOut($charId, $room);
        }
        
        // ★ 铁笼出口：铁笼打开后，out方向指向山洞内(lu1)
        // 还原原始LPC: tielong.c do_break() 中 set("exits/out", "/d/westway/lu1")
        if ($room['room_id'] === 'westway/tielong' && strtolower($direction) === 'out') {
            require_once __DIR__ . '/../helpers/TempStateHelper.php';
            $isOpen = TempStateHelper::get($charId, 'shizhan_tielong_open');
            if ($isOpen) {
                // 铁笼已打开，传送到山洞内
                $targetArea = 'westway';
                $targetRoomId = 'westway/lu1';
                
                $newRoom = Database::queryOne('SELECT * FROM rooms WHERE room_id = ?', [$targetRoomId]);
                if (!$newRoom) {
                    return ['success' => false, 'message' => '目标位置不存在'];
                }
                
                CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);
                
                // 清除铁笼打开状态
                TempStateHelper::remove($charId, 'shizhan_tielong_open');
                
                $leaveMessage = HTML_HIYEL . "{$char['name']}从铁笼中钻了出来。" . HTML_NOR . "\n";
                $arriveMessage = HTML_HIYEL . "{$char['name']}从铁笼中钻了出来。" . HTML_NOR . "\n";
                
                return [
                    'success' => true,
                    'type' => 'move',
                    'message' => HTML_HICYN . $newRoom['name'] . HTML_NOR . "\n" . ($newRoom['description'] ? $newRoom['description'] . "\n" : ''),
                    'leave_message' => $leaveMessage,
                    'arrive_message' => $arriveMessage,
                    'new_room' => $newRoom,
                    'old_room' => $room
                ];
            } else {
                return ['success' => false, 'message' => HTML_HIRED . '铁笼还是关着的，你需要先扳开(break)它。' . HTML_NOR];
            }
        }
        
        return ['success' => false, 'message' => '那个方向没有出口'];
    }
    
    // 检查门是否关闭
    if (!empty($targetExit['door_closed']) && !empty($targetExit['door_name'])) {
        return ['success' => false, 'message' => HTML_HIYEL . $targetExit['door_name'] . '是关着的，你需要先打开它。' . HTML_NOR];
    }
    
    // ★ 特殊检查：枫雪宫谈心室锁门（走廊→谈心室）
    // 参考原始LPC: /d/moon/fengxue/talkroom.c::init()
    // 当谈心室已锁门时，禁止从走廊进入
    if ($room['room_id'] === 'moon/fengxue/zoulang' && $targetExit['target_room'] === 'moon/fengxue/talkroom') {
        require_once __DIR__ . '/lock.php';
        $isLocked = isTalkroomLocked('moon/fengxue/talkroom');
        if ($isLocked) {
            return ['success' => false, 'message' => '嘿嘿！门锁着哪，你进不去！'];
        }
    }
    
    // ★ 特殊检查：水帘洞入口（石房→水帘洞内）
    // 原始LPC逻辑：Shifang.php::valid_leave() 检查 dntg/huaguo == "allow"
    if ($room['room_id'] === 'dntg/hgs/shifang' && $direction === 'east') {
        $huaguoState = Database::queryOne(
            "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = 'dntg/huaguo'",
            [$charId]
        );
        
        if (!$huaguoState || $huaguoState['state_value'] !== 'allow') {
            return [
                'success' => false,
                'message' => HTML_HIYEL . '几只小猴子跑过来冲你喊到：“我们正在选猴王，没事别来捣乱。”' . HTML_NOR . "\n" .
                            HTML_HICYN . '小猴子又急勿勿的走了。' . HTML_NOR
            ];
        }
    }
    
    // 检查房间特殊事件 - disabled_exits（无条件禁用的出口）
    if (!empty($room['special_events'])) {
        $specialEvents = is_string($room['special_events']) 
            ? json_decode($room['special_events'], true) 
            : $room['special_events'];
        
        if (isset($specialEvents['disabled_exits'][$direction])) {
            $disabledConfig = $specialEvents['disabled_exits'][$direction];
            $message = $disabledConfig['message'] ?? '你不能往那个方向走。';
            return ['success' => false, 'message' => HTML_HIYEL . $message . HTML_NOR];
        }
    }
    
    // 检查房间特殊事件 - valid_leave
    if (!empty($room['special_events'])) {
        $specialEvents = is_string($room['special_events']) 
            ? json_decode($room['special_events'], true) 
            : $room['special_events'];
        
        if (isset($specialEvents['valid_leave'][$direction])) {
            $event = $specialEvents['valid_leave'][$direction];
            
            // 条件1：npc_exists - 只要指定NPC在房间就阻止离开
            if ($event['condition'] === 'npc_exists') {
                $npcsInRoom = $room['npcs'] ?? [];
                $npcExists = checkNpcInRoom($npcsInRoom, $event['npc_id'] ?? '', $event['npc_name'] ?? '');
                
                if ($npcExists) {
                    $message = str_replace('\\n', "\n", $event['message'] ?? '你不能往那个方向走。');
                    return ['success' => false, 'message' => $message];
                }
            }

            // 条件4：multi_npc - 检查多个NPC，分别显示仍存活NPC的名字
            if ($event['condition'] === 'multi_npc') {
                $npcsInRoom = $room['npcs'] ?? [];
                $aliveNames = [];
                $npcList = $event['npc_list'] ?? [];
                foreach ($npcList as $npc) {
                    if (checkNpcInRoom($npcsInRoom, $npc['npc_id'] ?? '', $npc['npc_name'] ?? '')) {
                        $aliveNames[] = $npc['display_name'] ?? $npc['npc_name'] ?? $npc['npc_id'];
                    }
                }
                if (!empty($aliveNames)) {
                    $names = implode(', ', $aliveNames);
                    $message = str_replace('{names}', $names, $event['message'] ?? '{names}挡住了去路。');
                    $message = str_replace('\\n', "\n", $message);
                    return ['success' => false, 'message' => $message];
                }
            }
            
            // 条件2：family_check_and_npc - 非本门派成员且指定NPC在场时阻止离开
            if ($event['condition'] === 'family_check_and_npc') {
                $requiredFamily = $event['required_family'] ?? '';
                $playerFamily = $char['family'] ?? '';
                
                // 如果玩家不是本门派成员
                if ($playerFamily !== $requiredFamily) {
                    $npcsInRoom = $room['npcs'] ?? [];
                    $npcExists = checkNpcInRoom($npcsInRoom, $event['npc_id'] ?? '', $event['npc_name'] ?? '');
                    
                    if ($npcExists) {
                        $message = str_replace('\\n', "\n", $event['message'] ?? '你不能往那个方向走。');
                        return ['success' => false, 'message' => $message];
                    }
                }
            }
            
            // 条件3：quest_state - 检查角色任务状态是否满足条件
            if ($event['condition'] === 'quest_state') {
                $stateKey = $event['state_key'] ?? '';
                $requiredValue = $event['required_value'] ?? '';
                
                $row = Database::queryOne(
                    "SELECT state_value FROM character_temp_states WHERE char_id = ? AND state_key = ?",
                    [$charId, $stateKey]
                );
                $currentValue = $row ? ($row['state_value'] ?? null) : null;
                
                if ((string)$currentValue !== (string)$requiredValue) {
                    $message = str_replace('\\n', "\n", $event['message'] ?? '你不能往那个方向走。');
                    return ['success' => false, 'message' => $message];
                }
            }
        }
    }
    
    // ★ 特殊检查：宝象国野路8阻挡（三关连环）
    // 原始LPC逻辑：yelu8.c valid_leave()
    $baoxiangBlock = BaoxiangHandler::checkYelu8Block($charId, $direction, $room['room_id']);
    if (!$baoxiangBlock['allowed']) {
        return ['success' => false, 'message' => $baoxiangBlock['message']];
    }
    
    // 检查马盗的离开限制（饮马峪往西北走）
    if ($room['room_id'] === 'westway/yinma' && $direction === 'northwest') {
        require_once __DIR__ . '/../helpers/MadaoHelper.php';
        $leaveCheck = MadaoHelper::checkValidLeave($charId, $direction);
        if ($leaveCheck && !$leaveCheck['allowed']) {
            return ['success' => false, 'message' => $leaveCheck['message']];
        }
    }
    
    // 守门牛精拦路（青龙山玄英洞通道1往东进入内宫）
    if ($room['room_id'] === 'qujing/qinglong/tongdao1' && $direction === 'east') {
        require_once __DIR__ . '/../helpers/ShoumenniujingHelper.php';
        // 检查守门牛精是否在房间内
        $npcsInRoom = $room['npcs'] ?? [];
        $niujingExists = false;
        foreach ($npcsInRoom as $npc) {
            if (($npc['npc_id'] ?? '') === 'shoumenniujing' || ($npc['id'] ?? 0) == 1744) {
                $niujingExists = true;
                break;
            }
        }
        if ($niujingExists && !ShoumenniujingHelper::hasPaid($charId)) {
            return [
                'success' => false,
                'message' => HTML_HIYEL . "守门牛精伸臂拦住去路，粗声道：此乃内宫禁地，闲人不得入内！\n" .
                             "守门牛精舔了舔嘴唇：除非……你有酥合香油？给俺一瓶就放你过去。" . HTML_NOR
            ];
        }
    }
    
    // 检查吴刚的拦路（月宫玉女峰顶往北走，男性玩家会被拦）
    if ($room['room_id'] === 'moon/ontop2' && $direction === 'north') {
        $gender = $char['gender'] ?? '';
        
        // 只有男性玩家会被拦（gender字段是英文的male）
        if ($gender === 'male' || $gender === '男性') {
            // 检查吴刚是否在房间里
            $npcsInRoom = $room['npcs'] ?? [];
            $wugangExists = false;
            foreach ($npcsInRoom as $npc) {
                if ($npc['npc_id'] === 'wugang') {
                    $wugangExists = true;
                    break;
                }
            }
            
            // 如果吴刚在房间里，就拦住玩家
            if ($wugangExists) {
                $message = HTML_HIYEL . "桂花树后转出吴刚，伸出一把大板斧拦住你道：\n慢着...院子里住的全是女人，你跑进去想干什么？" . HTML_NOR;
                return ['success' => false, 'message' => $message];
            }
        }
    }
    
    // ★ 蓬莱 road1：白猿挡路（去yaxia需打败白猿）
    // 原始LPC: road1.c::valid_leave() north → yaxia
    if ($room['room_id'] === 'penglai/road1' && $direction === 'north') {
        $npcsInRoom = $room['npcs'] ?? [];
        if (checkNpcInRoom($npcsInRoom, 'baiyuan', '白猿')) {
            return [
                'success' => false,
                'message' => HTML_HIYEL . '白猿忽地跳到路中间，伸臂拦住你道：' . "\n" . '「此路不通！」' . HTML_NOR
            ];
        }
    }

    // ★ 蓬莱 undertree：三星挡路（去白云洞需三星允许）
    // 原始LPC: undertree.c::valid_leave() enter → baiyun0
    if ($room['room_id'] === 'penglai/undertree' && $direction === 'enter') {
        $npcsInRoom = $room['npcs'] ?? [];
        $hasSanxing = checkNpcInRoom($npcsInRoom, 'fuxing', '福星') ||
                      checkNpcInRoom($npcsInRoom, 'luxing', '禄星') ||
                      checkNpcInRoom($npcsInRoom, 'shouxing', '寿星');
        if ($hasSanxing) {
            return [
                'success' => false,
                'message' => HTML_HIYEL . '三星摆了摆手道：' . "\n" . '「里面乃老夫卧室，闲人免进！」' . HTML_NOR
            ];
        }
    }

    // ★ 白云洞迷宫：baiyun0 northwest 随机出口
    // 原始LPC: Baiyun0.php exits() northwest => random(20)
    if ($room['room_id'] === 'penglai/baiyun0' && $direction === 'northwest') {
        // 随机导向 baiyun0~19，保持迷路特性
        $randomNum = mt_rand(0, 19);
        $targetArea = 'penglai';
        $targetRoom = "penglai/baiyun{$randomNum}";
        $targetRoomId = $targetRoom;

        // 验证随机房间存在
        $newRoom = Database::queryOne('SELECT * FROM rooms WHERE room_id = ?', [$targetRoomId]);
        if (!$newRoom) {
            return ['success' => false, 'message' => '那个方向没有出口'];
        }
    }

    // 更新角色位置
    // 如果是白云洞随机出口，targetArea/targetRoomId/newRoom 已在上面设置，跳过此段
    if (!($room['room_id'] === 'penglai/baiyun0' && $direction === 'northwest')) {
        // 注意：去除 'd/' 前缀以匹配 rooms 表的 area 字段
        $targetArea = preg_replace('/^d\//', '', $targetExit['target_area']);
        
        // 构建完整的 room_id（格式：area/room）
        $targetRoom = $targetExit['target_room'];
        if (strpos($targetRoom, $targetArea . '/') === 0) {
            // target_room 已经包含 area 前缀（如 city/misc/kantai）
            $targetRoomId = $targetRoom;
        } else {
            // target_room 是相对路径，需要拼接 target_area
            $targetRoomId = $targetArea . '/' . $targetRoom;
        }
        
        // 先验证目标房间是否存在
        // 注意：rooms 表中的 room_id 字段存储的是完整路径（如 city/misc/kantai）
        // 所以直接使用 targetRoomId 进行查询
        $newRoom = Database::queryOne('SELECT * FROM rooms WHERE room_id = ?', [$targetRoomId]);

        if (!$newRoom) {
            // 目标房间不存在，不更新位置，只返回错误消息
            error_log("Exit leads to non-existent room: {$char['current_area']}/{$char['current_room']} -> {$direction} -> {$targetArea}/{$targetRoomId}");
            return ['success' => false, 'message' => "你请求前往的位置不存在。"];
        }
    }

    // 鬼魂状态检查：鬼魂不能离开地府区域（death/）
    // 使用数据库 is_ghost 字段替代 session（跨进程可见）
    if (!empty($char['is_ghost'])) {
        // 只允许地府内部移动，不允许离开 death 区域
        if ($targetArea !== 'death') {
            return [
                'success' => false,
                'message' => HTML_HIRED . '你已经是鬼魂了，无法离开地府。请先往北走穿过城墙投胎转世。' . HTML_NOR,
                'skip_queue' => true,
            ];
        }
    }
    
    // ★ 天魔区域：开门机制检查
    // 抓取经人后120秒，护送人才能进入天魔区域
    if (strpos($targetRoomId, 'qujing/qujingren/tianmo/') === 0) {
        $tianmoCheck = checkTianmoEntrance($charId, $char, $targetRoomId);
        if (!$tianmoCheck['allowed']) {
            return ['success' => false, 'message' => $tianmoCheck['message']];
        }
    }
    
    // ★ 天魔走廊：防守检查
    // 如果走廊有防守人，护送人/取经人需要先打败防守人才能通过
    if (preg_match('/qujing\/qujingren\/tianmo\/zoulang\d+$/', $targetRoomId)) {
        $defenderCheck = checkTianmoDefender($charId, $char, $targetRoomId);
        if (!$defenderCheck['allowed']) {
            return ['success' => false, 'message' => $defenderCheck['message']];
        }
    }
    
    // 检查巫师区域权限：wiz区域只有巫师才能进入
    if ($targetArea === 'wiz') {
        require_once MODEL_PATH . 'User.php';
        $userId = $_SESSION['user_id'] ?? 0;
        if (!UserModel::isWizard(intval($userId))) {
            return ['success' => false, 'message' => HTML_HIRED . '那里只有巫师才能进去' . HTML_NOR];
        }
    }
    
    // ★ 水下房间进入检查：water_room=1 的房间需要避水咒或龙宫弟子身份
    if (!empty($newRoom['water_room'])) {
        $familyName = $char['family'] ?? '';
        $isDragonFamily = in_array($familyName, ['龙宫', '东海龙宫']);
        
        if (!$isDragonFamily) {
            // 非龙宫弟子，检查是否持有避水咒
            $hasBishui = Database::queryOne(
                "SELECT id FROM character_inventory WHERE char_id = ? AND item_id = ? AND quantity > 0",
                [$charId, 'zhou']
            );
            
            if (!$hasBishui) {
                // 无避水咒，阻止进入，扣除气血作为惩罚
                Database::execute(
                    'UPDATE characters SET kee = GREATEST(1, kee - 30) WHERE id = ?',
                    [$charId]
                );
                
                $waterRoomName = $newRoom['name'] ?? '水下';
                $leaveMessage = HTML_HIYEL . "{$actorDisplayName}试图潜入{$waterRoomName}，却被汹涌的水流冲了回来。" . HTML_NOR;
                
                return [
                    'success' => false,
                    'message' => HTML_HIRED . "你没有避水咒护身，无法进入{$waterRoomName}！汹涌的水流将你冲了回来。" . HTML_NOR,
                    'leave_message' => $leaveMessage,
                ];
            }
        }
    }
    
    // 云彩区域法力消耗检查：在云端行走需要消耗法力
    $charCurrentRoom = $char['current_room'];
    $charArea = explode('/', $charCurrentRoom)[0] ?? '';
    if ($charArea === 'cloud') {
        $charMana = $char['mana'] ?? 0;
        if ($charMana < 10) {
            return ['success' => false, 'message' => HTML_HIRED . '你的法力不支，无法继续在云端行走！你从云端跌落……' . HTML_NOR];
        }
    }
    
    // 检查是否有坐骑
    $rideMsg = '';
    $mountData = TempStateHelper::get($charId, 'ride/mounted');
    if ($mountData && isset($mountData['npc_name'])) {
        $rideMsg = '骑着' . $mountData['npc_name'];
    }
    
    // 生成移动消息
    $directionNames = [
        'north' => '北',
        'south' => '南',
        'east' => '东',
        'west' => '西',
        'northeast' => '东北',
        'northwest' => '西北',
        'southeast' => '东南',
        'southwest' => '西南',
        'up' => '上',
        'down' => '下',
        'enter' => '里',
        'out' => '外',
    ];
    
    $dirName = $directionNames[$direction] ?? $direction;
    
    // 获取变化后的名称
    $actorDisplayName = $char['name'];
    if (function_exists('get_char_display_name')) {
        $actorDisplayName = get_char_display_name($char);
    }
    
    // 离开消息（发送给原房间的其他人）
    $leaveMessage = HTML_HIYEL . "{$actorDisplayName}" . ($rideMsg ? "({$rideMsg})" : '') . "往{$dirName}离开。" . HTML_NOR . "\n";
    
    // 到达消息（发送给新房间的其他人）
    $arriveMessage = HTML_HIYEL . "{$actorDisplayName}" . ($rideMsg ? "({$rideMsg})" : '') . "走了过来。" . HTML_NOR . "\n";
    
    // 自定义离开消息（还原原始LPC valid_leave 钩子）
    $customLeaveMessages = [
        'lingtai/uphill3' => [
            'northwest' => HTML_HIYEL . "{$actorDisplayName}挽起裤腿，跳入小溪中。" . HTML_NOR . "\n",
        ],
        // 蓬莱白云洞迷宫出入口
        'penglai/baiyun0' => [
            'northwest' => HTML_HIYEL . "{$actorDisplayName}一头钻进了白云洞深处。" . HTML_NOR . "\n",
            'out' => HTML_HIYEL . "{$actorDisplayName}从白云洞中走了出来。" . HTML_NOR . "\n",
        ],
        'penglai/undertree' => [
            'enter' => HTML_HIYEL . "{$actorDisplayName}向白云洞中探身而入。" . HTML_NOR . "\n",
        ],
        'penglai/road1' => [
            'north' => HTML_HIYEL . "{$actorDisplayName}向高耸的青石崖走去。" . HTML_NOR . "\n",
        ],
    ];
    if (isset($customLeaveMessages[$room['room_id']][$direction])) {
        $leaveMessage = $customLeaveMessages[$room['room_id']][$direction];
    }
    
    // 验证通过，更新角色位置
    CharacterModel::updatePosition(
        $charId,
        $targetArea,
        $targetRoomId
    );
    
    // 鬼魂离开地府区域时自动清除鬼魂标记（使用数据库替代 session）
    if (!empty($char['is_ghost']) && $targetArea !== 'death') {
        Database::execute("UPDATE characters SET is_ghost = 0 WHERE id = ?", [$charId]);
    }

    // 云彩区域扣除法力
    $cloudManaMsg = '';
    if ($charArea === 'cloud') {
        Database::execute('UPDATE characters SET mana = mana - 10 WHERE id = ?', [$charId]);
        $updatedChar = CharacterModel::find($charId);
        $remainingMana = $updatedChar['mana'] ?? 0;
        $cloudManaMsg = HTML_HICYN . '你消耗了10点法力在云端行走。(剩余法力: ' . $remainingMana . ')' . HTML_NOR . "\n";
    }
    
    // 蓬莱青石崖：进入时触发琼草生长（还原原始LPC: qiongcao.c invocation() 机制）
    if ($targetRoomId === 'penglai/yashang') {
        require_once DAEMON_PATH . 'QiongcaoHandler.php';
        $qiongcaoHandler = new QiongcaoHandler();
        $qiongcaoHandler->tryStartGrowth('penglai/yashang');
    }
    
    // ★ 石栈道陷阱：进入时记录进入时间（还原原始LPC: shizhan.c init() + call_out("greeting", 25)）
    // 25秒后触发陷阱，将玩家传送到铁笼
    if ($targetRoomId === 'westway/shizhan') {
        // 检查陷阱是否在冷却中（原始LPC: call_out("reg", 300) 重置陷阱）
        $trapCooldown = Database::queryOne("SELECT value FROM variables WHERE var_key = 'shizhan_trap_cooldown'");
        $isCooldown = $trapCooldown && time() < intval($trapCooldown['value']);
        
        if (!$isCooldown) {
            require_once __DIR__ . '/../helpers/TempStateHelper.php';
            TempStateHelper::set($charId, 'shizhan_enter_time', time(), 300);
        }
    }
    
    // 记录日志
    log_game('MOVE', "{$char['name']} 从 {$room['name']} 移动到 {$newRoom['name']}");
    
    // 检查新房间的NPC是否主动攻击
    $autoAttackResult = null;
    
    // 马盗特殊逻辑：先检查拦路抢劫（饮马峪）
    $madaoResult = null;
    if ($targetRoomId === 'westway/yinma') {
        require_once __DIR__ . '/../helpers/MadaoHelper.php';
        $madaoResult = MadaoHelper::onPlayerEnter($charId);
        if ($madaoResult && !empty($madaoResult['message'])) {
            // 发送马盗的拦路消息
            require_once DAEMON_PATH . 'MessageDaemon.php';
            $madaoMsg = HTML_HIYEL . $madaoResult['message'] . HTML_NOR;
            MessageDaemon::sendToPlayer($charId, $madaoMsg, 'npc_chat');
        }
    }
    
    // 如果马盗没有跳过攻击，再检查通用的autoAttack
    if (!$madaoResult || empty($madaoResult['skip_attack'])) {
        require_once __DIR__ . '/../helpers/NpcAiHelper.php';
        $autoAttack = NpcAiHelper::checkAutoAttack($charId, $targetArea, $targetRoomId);
        if ($autoAttack) {
            // 将攻击消息存入session，由room.php优先显示（避免sendToPlayer写入消息队列后
            // 被action.php的last_move_message_id包含，导致room.php轮询时跳过该消息）
            $attackMsg = HTML_HIRED . $autoAttack['message'] . HTML_NOR;
            $_SESSION['auto_attack_flash'] = [
                'message' => $attackMsg,
                'room_broadcast' => $autoAttack['room_broadcast'] ?? '',
                'timestamp' => time()
            ];
            
            // 同时将攻击消息写入消息队列，让chat.php也能显示该消息
            // （room.php不会重复显示，因为auto_attack_flash已处理；chat.php首次加载时会拉取该消息）
            require_once DAEMON_PATH . 'MessageDaemon.php';
            MessageDaemon::sendToPlayer($charId, $attackMsg, 'combat');
            
            // 广播攻击消息到房间（让其他玩家也能看到NPC主动攻击的提示）
            if (!empty($autoAttack['room_broadcast'])) {
                $roomBroadcastMsg = HTML_HIRED . '【战斗】' . HTML_NOR . ' ' . $autoAttack['room_broadcast'];
                MessageDaemon::broadcastToRoom($targetRoomId, $roomBroadcastMsg, $charId);
            }
            
            $autoAttackResult = $autoAttack;
        }
    }
    
    // ★ 玩家杀气狂暴检查（还原 LPC feature/attack.c:265 berserk 逻辑）
    // 玩家进入房间时，检查自身杀气是否触发狂暴自动攻击NPC
    // 仅在未已被NPC攻击（不在战斗中）时检查
    if (!$autoAttackResult) {
        require_once __DIR__ . '/../helpers/NpcAiHelper.php';
        require_once DAEMON_PATH . 'MessageDaemon.php';
        $berserkResult = NpcAiHelper::checkPlayerBerserk($charId, $targetArea, $targetRoomId);
        if ($berserkResult) {
            $berserkMsg = HTML_HIRED . $berserkResult['message'] . HTML_NOR;
            $_SESSION['auto_attack_flash'] = [
                'message' => $berserkMsg,
                'room_broadcast' => $berserkResult['room_broadcast'] ?? '',
                'timestamp' => time()
            ];
            
            // 写入消息队列，让chat.php也能显示
            MessageDaemon::sendToPlayer($charId, $berserkMsg, 'combat');
            
            // 广播到房间（让其他玩家看到狂暴消息）
            if (!empty($berserkResult['room_broadcast'])) {
                $roomBroadcastMsg = HTML_HIRED . '【战斗】' . HTML_NOR . ' ' . $berserkResult['room_broadcast'];
                MessageDaemon::broadcastToRoom($targetRoomId, $roomBroadcastMsg, $charId);
            }
            
            // 如果是压制（未实际攻击），不算auto_attack_result
            if ($berserkResult['mode'] !== 'suppressed') {
                $autoAttackResult = $berserkResult;
            }
        }
    }
    
    // ★ 火焰山石门：离开房间时重置砸门计数器（还原原始LPC: temp状态离开清除机制）
    if ($room['room_id'] === 'qujing/firemount/shimen') {
        require_once DAEMON_PATH . 'FiremountHandler.php';
        $state = FiremountHandler::getPlayerState($charId);
        if (isset($state['firemount_hit_door'])) {
            $state['firemount_hit_door'] = 0;
            FiremountHandler::setPlayerState($charId, $state);
        }
    }
    
    // 处理跟随者
    require_once DAEMON_PATH . 'MessageDaemon.php';
    require_once MODEL_PATH . 'Character.php';
    
    // 获取所有跟随当前玩家的玩家
    $followers = Database::queryAll(
        "SELECT id, name FROM characters WHERE following_id = ? AND online = 1",
        [$charId]
    );
    
    // 遍历所有跟随者，让他们一起移动
    foreach ($followers as $follower) {
        // 只有当跟随者在同一房间时才跟随
        $followerChar = CharacterModel::find($follower['id']);
        if (!$followerChar) continue;
        
        if ($followerChar['current_room'] !== $char['current_room']) continue;
        
        // 更新跟随者的位置
        CharacterModel::updatePosition(
            $follower['id'],
            $targetArea,
            $targetRoomId
        );
        
        // 发送跟随者的离开和到达消息
        $followerLeaveMsg = HTML_HIYEL . $follower['name'] . '跟着你往' . $dirName . '离开。' . HTML_NOR . "\n";
        $followerArriveMsg = HTML_HIYEL . $follower['name'] . '跟着你走了过来。' . HTML_NOR . "\n";
        
        // 获取跟随者的显示名称（考虑变化状态）
        $followerDisplayName = $follower['name'];
        if (function_exists('get_char_display_name')) {
            $followerDisplayName = get_char_display_name($followerChar);
        }
        
        // 发送原房间的离开消息
        $playersInOldRoom = Database::queryAll(
            'SELECT id FROM characters WHERE current_room = ? AND online = 1',
            [$room['room_id']]
        );
        foreach ($playersInOldRoom as $player) {
            if ($player['id'] == $follower['id']) continue; // 跳过自己
            $msg = HTML_HIYEL . $followerDisplayName . '跟着' . $actorDisplayName . '往' . $dirName . '离开。' . HTML_NOR . "\n";
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }
        
        // 发送新房间的到达消息
        $playersInNewRoom = Database::queryAll(
            'SELECT id FROM characters WHERE current_room = ? AND online = 1',
            [$targetRoomId]
        );
        foreach ($playersInNewRoom as $player) {
            if ($player['id'] == $follower['id']) continue; // 跳过自己
            $msg = HTML_HIYEL . $followerDisplayName . '跟着' . $actorDisplayName . '走了过来。' . HTML_NOR . "\n";
            Database::execute(
                'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                [$player['id'], $msg, 'room']
            );
        }
        
        // 给跟随者自己发消息
        $msg = HTML_HIYEL . '你跟着' . $actorDisplayName . '来到了' . $newRoom['name'] . '。' . HTML_NOR . "\n";
        Database::execute(
            'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
            [$follower['id'], $msg, 'room']
        );
        
        // 记录跟随者的移动
        log_game('MOVE', "{$follower['name']} 跟随 {$char['name']} 从 {$room['name']} 移动到 {$newRoom['name']}");
    }
    
    // 处理NPC跟随者（使用迭代方式处理整个跟随链）
    // 记录已处理的NPC，避免重复处理
    $processedNpcIds = [];
    
    // 使用队列进行广度优先遍历
    $queue = [[$charId, $actorDisplayName]];
    
    while (!empty($queue)) {
        // 取出队列中的第一个元素
        $current = array_shift($queue);
        $leaderId = $current[0];
        $leaderName = $current[1];
        
        // 查询所有跟随leaderId的NPC
        $followers = Database::queryAll(
            "SELECT n.id, n.name, n.npc_id FROM npcs n 
             INNER JOIN npc_temp nt ON n.id = nt.npc_id 
             WHERE nt.temp_key = 'leader' AND nt.temp_value = ?",
            [$leaderId]
        );
        
        foreach ($followers as $follower) {
            // 避免重复处理
            if (in_array($follower['id'], $processedNpcIds)) {
                continue;
            }
            $processedNpcIds[] = $follower['id'];
            
            // 更新NPC临时状态中的current_location
            $currentLocation = json_encode(['area' => $targetArea, 'room' => $targetRoomId]);
            Database::execute(
                "INSERT INTO npc_temp (npc_id, temp_key, temp_value) VALUES (?, 'current_location', ?) ON DUPLICATE KEY UPDATE temp_value = ?",
                [$follower['id'], $currentLocation, $currentLocation]
            );
            
            // 发送NPC跟随消息给原房间的玩家
            $playersInOldRoom = Database::queryAll(
                'SELECT id FROM characters WHERE current_room = ? AND online = 1',
                [$room['room_id']]
            );
            foreach ($playersInOldRoom as $player) {
                $msg = HTML_HIYEL . $follower['name'] . '跟着' . $leaderName . '往' . $dirName . '离开了。' . HTML_NOR . "\n";
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'room']
                );
            }
            
            // 发送NPC跟随消息给新房间的玩家
            $playersInNewRoom = Database::queryAll(
                'SELECT id FROM characters WHERE current_room = ? AND online = 1',
                [$targetRoomId]
            );
            foreach ($playersInNewRoom as $player) {
                $msg = HTML_HIYEL . $follower['name'] . '跟着' . $leaderName . '走了过来。' . HTML_NOR . "\n";
                Database::execute(
                    'INSERT INTO message_queue (char_id, message, type, created_at) VALUES (?, ?, ?, NOW())',
                    [$player['id'], $msg, 'room']
                );
            }
            
            // 记录NPC跟随者的移动
            log_game('MOVE', "{$follower['name']} 跟随 {$leaderName} 从 {$room['name']} 移动到 {$newRoom['name']}");
            
            // 将该NPC加入队列，后续处理跟随它的其他NPC
            $queue[] = [$follower['id'], $follower['name']];
        }
    }
    
    return [
        'success' => true,
        'type' => 'move',
        'message' => HTML_HICYN . $newRoom['name'] . HTML_NOR . "\n" . ($newRoom['description'] ? $newRoom['description'] . "\n" : '') . $cloudManaMsg,
        'leave_message' => $leaveMessage,      // 发送给原房间其他人
        'arrive_message' => $arriveMessage,     // 发送给新房间其他人
        'new_room' => $newRoom,
        'old_room' => $room,
        'auto_attack' => $autoAttackResult      // NPC主动攻击结果
    ];
}

/**
 * 检查指定NPC是否存在于房间中
 */
function checkNpcInRoom(array $npcsInRoom, string $npcId, string $npcName = ''): bool {
    if (empty($npcId) && empty($npcName)) {
        return false;
    }
    
    foreach ($npcsInRoom as $npc) {
        $matchById = !empty($npcId) && (
            (isset($npc['npc_id']) && stripos($npc['npc_id'], $npcId) !== false) ||
            (isset($npc['id']) && strval($npc['id']) === strval($npcId))
        );
        
        $matchByName = !empty($npcName) && (
            isset($npc['name']) && stripos($npc['name'], $npcName) !== false
        );
        
        if ($matchById || $matchByName) {
            return true;
        }
    }
    
    return false;
}

/**
 * 处理地府穿墙动作
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function handleHellWallPass(int $charId, array $currentRoom): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 检查是否是鬼魂状态（气血为1表示鬼魂）
    if ($char['kee'] > 1) {
        return [
            'success' => false,
            'message' => HTML_HIRED . '城门紧闭，阴气太盛，你无法穿过。' . HTML_NOR
        ];
    }
    
    // 检查是否等待足够时间（使用数据库 hell_enter_time 替代 session）
    $enterTime = intval($char['hell_enter_time'] ?? 0);
    $currentTime = time();
    
    if ($currentTime - $enterTime < 60) {
        $remainingTime = 60 - ($currentTime - $enterTime);
        return [
            'success' => false,
            'message' => HTML_HIRED . "你需要再等待{$remainingTime}秒才能投胎转世。" . HTML_NOR
        ];
    }
    
    // 执行穿墙进入酆都城
    // 1. 恢复气血、精力、心神到最大值
    $sql = "UPDATE characters SET 
            kee = max_kee,
            gin = max_gin,
            sen = max_sen,
            current_area = 'death',
            current_room = 'death/gateway'
            WHERE id = ?";
    Database::execute($sql, [$charId]);
    
    // 2. 清除鬼魂标记（使用数据库替代 session）
    Database::execute("UPDATE characters SET is_ghost = 0 WHERE id = ?", [$charId]);
    
    // 3. 获取目标房间信息
    $targetRoom = RoomModel::load('death', 'death/gateway');
    
    // 4. 生成消息（不广播，由 action.php 统一处理）
    $personalMsg = "\n你直直地向北边的城门走去，忽然穿过黑色的城墙进了城去。";
    
    // 5. 返回成功结果（包含 leave_message 供 action.php 广播）
    return [
        'success' => true,
        'type' => 'move',
        'message' => HTML_HICYN . ($targetRoom['name'] ?? '招魂司') . HTML_NOR . "\n" . ($targetRoom['description'] ?? '') . "\n" . $personalMsg,
        'leave_message' => HTML_HIYEL . "{$char['name']}直直地向北边的城门走去，忽然穿过黑色的城墙进了城去。" . HTML_NOR,
        'arrive_message' => HTML_HIYEL . "{$char['name']}穿过了城墙。" . HTML_NOR,
        'new_room' => $targetRoom,
        'old_room' => $currentRoom
    ];
}

/**
 * 处理从贵道门出去的动作
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function handleHellExit(int $charId, array $currentRoom): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 生成消息（不广播，由 action.php 统一处理）
    $personalMsg = "\n你使勁的向濃霧中擠了進去。";
    
    // 移动到阴间出口（death/out）
    $targetArea = 'death';
    $targetRoomId = 'death/out';
    
    // 更新角色位置
    CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);
    
    // 获取目标房间信息
    $targetRoom = RoomModel::load($targetArea, $targetRoomId);
    
    // 返回成功结果（包含 leave_message 供 action.php 广播）
    return [
        'success' => true,
        'type' => 'move',
        'message' => HTML_HICYN . ($targetRoom['name'] ?? '黑暗之中') . HTML_NOR . "\n" . ($targetRoom['description'] ?? '') . "\n" . $personalMsg,
        'leave_message' => HTML_HIYEL . "{$char['name']}使勁的向濃霧中擠了進去。" . HTML_NOR,
        'arrive_message' => HTML_HIYEL . "{$char['name']}從濃霧中出現了。" . HTML_NOR,
        'new_room' => $targetRoom,
        'old_room' => $currentRoom
    ];
}

/**
 * 瀑布迷宫导航
 * 处理 pubu1-5 房间的 go 命令（这些房间无标准出口）
 * 
 * 原始LPC逻辑：
 * - 每次尝试消耗50气力，气力不足则昏迷
 * - 1/5概率原地摔倒（留在当前房间）
 * - 否则判断方向：匹配随机方向→前进，不匹配→后退
 * 
 * 迷宫拓扑（Pubu1~5.php::do_go）：
 *   Pubu1: 前进→Pubu3, 后退→Pubu2
 *   Pubu2: 前进→Pubu3, 后退→Pubu1
 *   Pubu3: 前进→Pubu4, 后退→Pubu2
 *   Pubu4: 前进→Pubu5, 后退→Pubu3
 *   Pubu5: 前进→Tiebanqiao(铁板桥), 后退→Pubu1
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */
function handlePubuMaze(int $charId, array $char, array $room, string $direction): array {
    $currentRoomId = $room['room_id'];
    $charName = $char['name'];
    
    // 气力检查：不足50则昏迷
    if (intval($char['kee'] ?? 0) <= 50) {
        Database::execute('UPDATE characters SET kee = 1 WHERE id = ?', [$charId]);
        $_SESSION['unconscious_' . $charId] = [
            'timestamp' => time(),
            'duration' => 30,
        ];
        
        // 只通过 return 返回消息,不使用 queueMessageToSelf 避免重复显示
        // 添加 skip_queue 标志,防止 action.php 再次保存消息
        return [
            'success' => false,
            'message' => HTML_HIRED . '你气力耗尽，在湍急的水流中昏了过去……' . HTML_NOR,
            'skip_queue' => true,
        ];
    }
    
    // 扣除50气力
    Database::execute('UPDATE characters SET kee = kee - 50 WHERE id = ?', [$charId]);
    
    // 迷宫导航表
    // forward: 方向不匹配随机方向时的目标（前进）
    // backward: 方向匹配随机方向时的目标（后退）
    $mazeNav = [
        'dntg/hgs/pubu1' => ['forward' => 'dntg/hgs/pubu3', 'backward' => 'dntg/hgs/pubu2'],
        'dntg/hgs/pubu2' => ['forward' => 'dntg/hgs/pubu3', 'backward' => 'dntg/hgs/pubu1'],
        'dntg/hgs/pubu3' => ['forward' => 'dntg/hgs/pubu4', 'backward' => 'dntg/hgs/pubu2'],
        'dntg/hgs/pubu4' => ['forward' => 'dntg/hgs/pubu5', 'backward' => 'dntg/hgs/pubu3'],
        'dntg/hgs/pubu5' => ['forward' => 'dntg/hgs/tiebanqiao', 'backward' => 'dntg/hgs/pubu1'],
    ];
    
    $directions = ['west', 'east', 'south', 'north'];
    
    // 1/5 概率摔倒（random(5) 返回 0~4，0 表示摔倒，80%继续）
    if (rand(0, 4) === 0) {
        $stumbleMsg = HTML_HIYEL . '你迷迷糊糊踏出一步，一不小心摔倒在地。' . HTML_NOR;
        
        // 广播给其他玩家
        require_once DAEMON_PATH . 'MessageDaemon.php';
        MessageDaemon::broadcastToRoom($currentRoomId, 
            HTML_HIYEL . $charName . '迷迷糊糊踏出一步，一不小心摔倒在地。' . HTML_NOR,
            $charId, 'room'
        );
        
        // 返回给当前玩家,添加 skip_queue 防止重复
        return [
            'success' => false, 
            'message' => $stumbleMsg,
            'skip_queue' => true,
        ];
    }
    
    // 方向匹配：rand(0,3) 随机选一个方向，与玩家选择的方向比较
    // 匹配→后退，不匹配→前进（75%前进，25%后退）
    $randomDir = $directions[rand(0, 3)];
    $nav = $mazeNav[$currentRoomId];
    
    if ($direction !== $randomDir) {
        // 前进
        $targetRoomId = $nav['forward'];
        $moveMsg = HTML_HIYEL . '你在瀑布中找到一丝细缝，挤了出去。' . HTML_NOR;
    } else {
        // 后退
        $targetRoomId = $nav['backward'];
        $moveMsg = HTML_HIYEL . '你在瀑布中找到一丝细缝，挤了出去。' . HTML_NOR;
    }
    
    // 广播离开消息
    require_once DAEMON_PATH . 'MessageDaemon.php';
    MessageDaemon::broadcastToRoom($currentRoomId,
        HTML_HIYEL . $charName . '在瀑布中找到一丝细缝，挤了出去。' . HTML_NOR,
        $charId, 'room'
    );
    
    // 更新位置
    $targetArea = 'dntg';
    CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);
    
    // 获取目标房间信息
    $newRoom = RoomModel::load($targetArea, $targetRoomId);
    
    // 广播到达消息
    MessageDaemon::broadcastToRoom($targetRoomId,
        HTML_HIYEL . $charName . '在瀑布中找到一丝细缝，挤了进来。' . HTML_NOR,
        $charId, 'room'
    );
    
    $personalMsg = HTML_HICYN . ($newRoom['name'] ?? '瀑布中') . HTML_NOR . "\n";
    $personalMsg .= ($newRoom['description'] ?? '你似乎什么也看不清楚，只觉得四周涧水奔流，难以探到前方的出路……') . "\n";
    $personalMsg .= $moveMsg . "\n" . '（消耗50气力）';
    
    return [
        'success' => true,
        'type' => 'move',
        'message' => $personalMsg,
        'leave_message' => '',
        'arrive_message' => '',
        'new_room' => $newRoom,
        'old_room' => $room,
        'redirect' => 'room.php?area=dntg&room=' . $targetRoomId,
        'skip_queue' => true,  // 防止 action.php 重复保存消息
    ];
}

/**
 * 检查 blocker 类型妖怪的阻挡
 * 原始项目逻辑：blocker 类型妖怪会阻挡玩家移动，需等待30-120秒
 */
function checkYaoguaiBlocker(int $charId, array $char): array {
    $currentRoom = $char['current_room'];
    $currentArea = $char['current_area'];
    
    // 查找当前房间中 blocker 类型的妖怪
    $blocker = Database::queryOne(
        "SELECT id, npc_name FROM mieyao_yaoguai 
         WHERE area = ? AND room_id = ? AND monster_type = 'blocker' AND is_killed = 0 AND expires_at > NOW()",
        [$currentArea, $currentRoom]
    );
    
    if (!$blocker) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 检查是否正在等待中
    $waitKey = "blocker_wait_{$charId}_{$blocker['id']}";
    if (isset($_SESSION[$waitKey])) {
        $waitData = $_SESSION[$waitKey];
        $elapsed = time() - $waitData['start_time'];
        
        if ($elapsed >= $waitData['wait_duration']) {
            // 等待时间已过，可以移动
            unset($_SESSION[$waitKey]);
            return ['allowed' => true, 'message' => ''];
        }
        
        // 继续等待
        $remaining = $waitData['wait_duration'] - $elapsed;
        return [
            'allowed' => false,
            'message' => HTML_HIRED . "{$blocker['npc_name']}挡住了你的去路！你需要再等待{$remaining}秒才能通过。" . HTML_NOR
        ];
    }
    
    // 首次遇到 blocker，开始等待（从配置读取范围）
    $qj = $_questCfg['qujing'];
    $waitDuration = mt_rand($qj['blocker_min_seconds'], $qj['blocker_max_seconds']);
    $_SESSION[$waitKey] = [
        'start_time' => time(),
        'wait_duration' => $waitDuration
    ];
    
    return [
        'allowed' => false,
        'message' => HTML_HIRED . "{$blocker['npc_name']}挡住了你的去路！你需要等待{$waitDuration}秒才能通过。" . HTML_NOR
    ];
}

/**
 * 聚见亭跨栏杆出去
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 传送到花果山仙石(dntg/hgs/entrance)
 */
function handleXiaotingOut(int $charId, array $currentRoom): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 目标：花果山仙石
    $targetArea = 'dntg';
    $targetRoomId = 'dntg/hgs/entrance';
    
    // 更新角色位置
    CharacterModel::updatePosition($charId, $targetArea, $targetRoomId);
    
    // 获取目标房间信息
    $targetRoom = RoomModel::load($targetArea, $targetRoomId);
    
    // 生成消息
    $personalMsg = HTML_HIBLU . "\n你跨出栏杆往外一迈，一阵浓雾向你卷来．．．" . HTML_NOR;
    $personalMsg .= HTML_HIRED . "\n你眼前一阵黑．．．" . HTML_NOR;
    $personalMsg .= HTML_HIYEL . "\n\n紧接着霞光一闪，你发现自己出现在一个陌生的地方。\n\n" . HTML_NOR;
    
    return [
        'success' => true,
        'type' => 'move',
        'message' => $personalMsg . HTML_HICYN . ($targetRoom['name'] ?? '仙石') . HTML_NOR . "\n" . ($targetRoom['description'] ?? ''),
        'leave_message' => HTML_HIBLU . "{$char['name']}跨出栏杆往外一迈，一阵浓雾卷来．．．一眨眼的功夫{$char['name']}就不见了。" . HTML_NOR,
        'arrive_message' => HTML_HIYEL . "只听惊天动地的一声巨响，紧接着霞光万道，仙石崩裂---\n居然从里面蹦出一个人来！({$char['name']})" . HTML_NOR,
        'new_room' => $targetRoom,
        'old_room' => $currentRoom
    ];
}

/**
 * 检查天魔区域入口（开门机制）
 * 抓取经人后120秒，护送人才能进入天魔区域
 */
function checkTianmoEntrance(int $charId, array $char, string $targetRoomId): array {
    // 只有进入天魔庙（入口）时才检查
    if ($targetRoomId !== 'qujing/qujingren/tianmo/miao') {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 获取 obstacled 状态
    $obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1");
    
    if (!$obstacled) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 如果没有取经人被抓，任何人都可以进入
    if (empty($obstacled['cated_id'])) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 抓取经人的玩家可以自由进出
    $catedId = intval($obstacled['cated_id']);
    if ($catedId === $charId) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 检查是否是护送人
    $husongId = intval($obstacled['husong'] ?? 0);
    $isHusong = ($husongId === $charId);
    
    // 如果不是护送人，也可以进入（比如看热闹的）
    // 只有护送人才需要检查门是否打开
    if (!$isHusong) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 护送人需要检查门是否打开
    $openDoor = intval($obstacled['open_door'] ?? 0);
    
    if ($openDoor) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 门没开，检查是否到了开门时间
    // 开门时间 = 抓取经人时间 + 120秒
    // 我们用 variables 表存储抓取时间
    $catedTimeVar = Database::queryOne(
        "SELECT value FROM variables WHERE var_key = 'tianmo_cated_time'"
    );
    
    if (!$catedTimeVar || empty($catedTimeVar['value'])) {
        // 没有记录抓取时间，默认不让进
        return [
            'allowed' => false,
            'message' => HTML_HIRED . '天魔庙大门紧闭，你无法进入。' . HTML_NOR
        ];
    }
    
    $catedTime = intval($catedTimeVar['value']);
    $elapsed = time() - $catedTime;
    
    if ($elapsed >= 120) {
        // 到时间了，开门
        Database::execute("UPDATE obstacled SET open_door = 1 WHERE id = 1");
        return ['allowed' => true, 'message' => ''];
    }
    
    $remaining = 120 - $elapsed;
    return [
        'allowed' => false,
        'message' => HTML_HIRED . "天魔庙大门紧闭，还需要等待{$remaining}秒才能进入。" . HTML_NOR
    ];
}

/**
 * 检查天魔走廊防守人
 * 如果走廊有防守人，护送人/取经人需要先打败防守人才能通过
 */
function checkTianmoDefender(int $charId, array $char, string $targetRoomId): array {
    // 获取防守配置
    $defendersVar = Database::queryOne(
        "SELECT value FROM variables WHERE var_key = 'tianmo_defenders'"
    );
    
    if (!$defendersVar || empty($defendersVar['value'])) {
        return ['allowed' => true, 'message' => ''];
    }
    
    $defenders = json_decode($defendersVar['value'], true);
    if (!$defenders || !is_array($defenders)) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 检查当前走廊是否有防守人
    if (!isset($defenders[$targetRoomId])) {
        return ['allowed' => true, 'message' => ''];
    }
    
    $defenderId = intval($defenders[$targetRoomId]);
    
    // 防守人自己可以通过
    if ($defenderId === $charId) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 获取 obstacled 状态
    $obstacled = Database::queryOne("SELECT cated_id, husong FROM obstacled WHERE id = 1");
    
    if (!$obstacled) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 抓取经人的玩家可以通过
    $catedId = intval($obstacled['cated_id'] ?? 0);
    if ($catedId === $charId) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 检查是否是护送人
    $husongId = intval($obstacled['husong'] ?? 0);
    $isHusong = ($husongId === $charId);
    
    // 如果不是护送人，也可以通过（防守人只拦护送人）
    if (!$isHusong) {
        return ['allowed' => true, 'message' => ''];
    }
    
    // 护送人需要检查是否已经打败了防守人
    // 简化处理：直接阻挡，提示需要先打败防守人
    // 后续可以结合战斗系统，打败防守人后设置一个标记
    
    // 获取防守人信息
    $defenderChar = Database::queryOne(
        "SELECT name FROM characters WHERE id = ?",
        [$defenderId]
    );
    
    $defenderName = $defenderChar ? $defenderChar['name'] : '防守人';
    
    return [
        'allowed' => false,
        'message' => HTML_HIRED . "{$defenderName}挡住了你的去路！你需要先打败他才能通过。" . HTML_NOR
    ];
}

