<?php
/**
 * 查看命令 (look)
 */
require_once DAEMON_PATH . 'NatureDaemon.php';
require_once HELPER_PATH . 'SectHelper.php';

function cmd_look(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);
    
    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }
    
    // 如果没有参数，查看当前房间
    if (empty($param)) {
        $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
        
        if (!$room) {
            return ['success' => false, 'message' => '房间不存在'];
        }
        
        // 构建房间描述
        $output = [];
        $output[] = HTML_HICYN . $room['name'] . HTML_NOR;
        $output[] = '';
        
        if ($room['description']) {
            $output[] = $room['description'];
            $output[] = '';
        }
        
        // 显示出口
        // ★ 铁笼房间特殊处理：铁笼打开后显示 out 出口
        $exits = $room['exits'] ?? [];
        if ($room['room_id'] === 'westway/tielong') {
            require_once __DIR__ . '/../helpers/TempStateHelper.php';
            $tielongOpen = TempStateHelper::get($charId, 'shizhan_tielong_open');
            if (is_array($tielongOpen)) {
                $tielongOpen = !empty($tielongOpen['_value']);
            }
            if ($tielongOpen) {
                $exits[] = ['direction' => 'out', 'target_room' => 'westway/lu1'];
            }
        }
        
        if (!empty($exits)) {
            $exitDirs = array_column($exits, 'direction');
            $output[] = HTML_YEL . '这里明显的出口是 ' . HTML_BOLD . implode('、', $exitDirs) . HTML_NOR . HTML_YEL . '。' . HTML_NOR;
            $output[] = '';
        } else {
            $output[] = '这里没有任何明显的出路。';
            $output[] = '';
        }
        
        // 显示NPC
        if (!empty($room['npcs'])) {
            $output[] = HTML_GRN . '这里的人物：' . HTML_NOR;
            foreach ($room['npcs'] as $npc) {
                // 显示格式：名字 (id)
                $npcId = isset($npc['npc_id']) ? $npc['npc_id'] : '';
                if ($npcId) {
                    $output[] = '  ' . $npc['name'] . '(' . $npcId . ')';
                } else {
                    $output[] = '  ' . $npc['name'];
                }
            }
            $output[] = '';
        }
        
        // 显示物品
        if (!empty($room['items'])) {
            $output[] = HTML_MAG . '这里的物品：' . HTML_NOR;
            foreach ($room['items'] as $item) {
                $output[] = '  ' . $item['item_name'];
            }
            $output[] = '';
        }
        
        // 如果是室外，显示天气信息
        if ($room['outdoors']) {
            $output[] = HTML_CYN . NatureDaemon::getWeatherDescription() . HTML_NOR;
        }
        
        return [
            'success' => true,
            'type' => 'room_display',
            'output' => implode("\n", $output),
            'room' => $room
        ];
    } else {
        // 查看特定对象
        return inspectTarget($charId, $param);
    }
}

/**
 * 查看目标对象
 */
function inspectTarget(int $charId, string $target): array {
    $char = CharacterModel::find($charId);
    $room = RoomModel::getFullInfo($char['current_area'], $char['current_room']);
    
    // 检查是否是尚在线的其他玩家
    $roomPlayers = CharacterModel::getRoomPlayers($char['current_area'], $char['current_room'], $charId);
    foreach ($roomPlayers as $player) {
        if (stripos($player['name'], $target) !== false) {
            return viewPlayer($char, $player);
        }
    }

    // 检查是否是NPC
    foreach ($room['npcs'] as $npc) {
        if (stripos($npc['name'], $target) !== false || stripos($npc['npc_id'], $target) !== false) {
            return viewNpc($char, $npc);
        }
    }
    
    // 检查是否是物品
    foreach ($room['items'] as $item) {
        if (stripos($item['item_name'], $target) !== false || stripos($item['item_id'], $target) !== false) {
            return viewItem($item);
        }
    }
    
    // 检查是否是房间固定对象（fixed_objects）
    if (!empty($room['fixed_objects'])) {
        foreach ($room['fixed_objects'] as $obj) {
            if (stripos($obj['name'], $target) !== false || stripos($obj['object_id'], $target) !== false) {
                $output = [];
                $output[] = HTML_HIYEL . '一块' . $obj['name'] . HTML_NOR;
                $output[] = '';
                if (!empty($obj['description'])) {
                    $output[] = trim($obj['description']);
                } else {
                    $output[] = '没什么特别的。';
                }
                return [
                    'success' => true,
                    'type' => 'fixed_object',
                    'output' => implode("\n", $output),
                    'skip_queue' => true
                ];
            }
        }
    }
    
    // 检查是否是出口
    foreach ($room['exits'] as $exit) {
        if (stripos($exit['direction'], $target) !== false) {
            $targetRoom = RoomModel::load($exit['target_area'], $exit['target_room']);
            if ($targetRoom) {
                return [
                    'success' => true,
                    'type' => 'peek',
                    'output' => "你向" . $exit['direction'] . "望去：\n" . $targetRoom['name'] . "\n" . $targetRoom['description']
                ];
            }
        }
    }
    
    // ★ 乐府诗社特殊处理: look poem — 动态显示当前题目和上一首完整诗词
    // 移植自 LPC clubpoem.c do_look("poem")
    // 显示: 上一首诗的完整内容 + 当前打乱后的题目
    $roomId = $room['room_id'] ?? $char['current_room'];
    if ($roomId === 'city/clubpoem' && $target === 'poem') {
        // 确保 poem_rounds 表存在
        $tableExists = Database::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'poem_rounds'"
        );
        if ($tableExists) {
            // 获取当前题
            $currentRound = Database::queryOne(
                "SELECT * FROM poem_rounds WHERE is_current = 1 ORDER BY id DESC LIMIT 1"
            );
            // 获取上一首诗（is_current=0 的最新记录）
            $previousRound = Database::queryOne(
                "SELECT * FROM poem_rounds WHERE is_current = 0 ORDER BY id DESC LIMIT 1"
            );

            $output = [];

            // 显示上一首诗的完整内容 (LPC: author1, title1, poem1[])
            if ($previousRound && $previousRound['poem_content']) {
                $output[] = '';
                $output[] = '    ' . $previousRound['poem_author'] . '：' . $previousRound['poem_title'];
                $prevLines = json_decode($previousRound['poem_content'], true);
                if (is_array($prevLines)) {
                    foreach ($prevLines as $line) {
                        $output[] = '    ' . $line;
                    }
                }
                $output[] = '';
                $output[] = '';
            }

            // 显示当前题目 (LPC: enscript("当前题目：　　　"+curr_show))
            if ($currentRound) {
                $output[] = HTML_HIYEL . '当前题目：　　　' . $currentRound['scrambled'] . HTML_NOR;
                if ($currentRound['is_answered']) {
                    $output[] = HTML_GRN . '（已被 ' . $currentRound['answered_by'] . ' 答对）' . HTML_NOR;
                }
            }
            $output[] = '';

            return [
                'success' => true,
                'type' => 'item_desc',
                'output' => implode("\n", $output),
                'skip_queue' => true
            ];
        }
        // 表不存在则继续走静态 item_descs
    }

    // ★ 骰子房特殊处理: look table — 动态显示当前赌桌状态
    // 移植自 LPC shaizi-room.c do_look("table")
    if ($roomId === 'city/shaizi-room' && $target === 'table') {
        $tableExists = Database::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shaizi_rounds'"
        );
        if ($tableExists) {
            $round = Database::queryOne("SELECT * FROM shaizi_rounds ORDER BY id DESC LIMIT 1");
            $output = [];

            if (!$round) {
                $output[] = '桌上空空荡荡，还没有开始赌局。';
            } else {
                $status = (int)$round['status'];
                $statusText = ['等待庄家', '下注中', '掷骰中', '已结算'][$status] ?? '未知';

                $output[] = HTML_HIYEL . '【赌桌状态：' . $statusText . '】' . HTML_NOR;

                // 庄家信息
                if ($round['dealer_name']) {
                    $dealerTag = ((int)$round['dealer_char_id'] === 0) ? '（NPC）' : '';
                    $output[] = '庄家：' . $round['dealer_name'] . $dealerTag;
                    $output[] = '赌注上限：' . $round['max_bet'] . '文铜钱';
                } else {
                    $output[] = '庄家：虚位以待';
                }

                // 获取下注列表
                $bets = Database::queryAll(
                    "SELECT * FROM shaizi_bets WHERE round_id = ? ORDER BY is_dealer DESC, roll_order ASC",
                    [(int)$round['id']]
                );

                if (!empty($bets)) {
                    $output[] = '';
                    $output[] = '本轮下注：';
                    foreach ($bets as $b) {
                        $role = (int)$b['is_dealer'] === 1 ? '庄家' : '闲家';
                        $line = '  ' . $b['char_name'] . '（' . $role . '）：' . $b['bet_amount'] . '文';
                        if ($b['point_name'] !== null) {
                            $line .= ' → ' . $b['point_name'];
                        }
                        if ($b['is_win'] !== null && (int)$b['is_dealer'] === 0) {
                            $line .= (int)$b['is_win'] === 1 ? ' → 赢' : ' → 输';
                        }
                        $output[] = $line;
                    }
                    $output[] = '总下注：' . $round['total_bet'] . '文铜钱';
                } else {
                    $output[] = '尚无人下注。';
                }

                // 庄家点数（结算后）
                if ($status === 3 && $round['dealer_point_name']) {
                    $output[] = '';
                    $output[] = HTML_HIYEL . '庄家点数：' . $round['dealer_point_name'] . HTML_NOR;
                }
            }

            return [
                'success' => true,
                'type' => 'item_desc',
                'output' => implode("\n", $output),
                'skip_queue' => true
            ];
        }
        // 表不存在则继续走静态 item_descs
    }

    // ★ 拱猪房特殊处理: look table — 动态显示当前牌局状态
    // 移植自 LPC piggy.c look_table()
    // 四个拱猪房(北/南=普通, 东/西=双人搭档)，各自独立牌桌
    if (in_array($roomId, ['city/piggy_n', 'city/piggy_s', 'city/piggy_e', 'city/piggy_w'], true) && $target === 'table') {
        $tableExists = Database::queryOne(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'piggy_rounds'"
        );
        if ($tableExists) {
            $round = Database::queryOne("SELECT * FROM piggy_rounds WHERE room_id = ? ORDER BY id DESC LIMIT 1", [$roomId]);
            $output = [];

            if (!$round) {
                $output[] = '一张石桌，桌面镶了一块大理石，摸上去极为光滑。桌上空空荡荡。';
            } else {
                $status = (int)$round['status'];
                $statusText = ['等人', '等发牌', '等卖牌', '出牌', '算分'][$status] ?? '未知';
                $seats = json_decode($round['seats'] ?? '{}', true) ?: [];
                $gameState = json_decode($round['game_state'] ?? '{}', true) ?: [];
                $isPartner = ($round['game_mode'] ?? 'normal') === 'partner';

                $modeText = $isPartner ? '（搭档模式）' : '';
                $output[] = HTML_HIYEL . '【拱猪牌桌：' . $statusText . $modeText . '】' . HTML_NOR;

                // 座位信息
                $dirNames = ['east' => '东', 'north' => '北', 'west' => '西', 'south' => '南'];
                $partnerMap = ['east' => 'west', 'west' => 'east', 'north' => 'south', 'south' => 'north'];
                foreach (['east', 'north', 'west', 'south'] as $dir) {
                    $s = $seats[$dir] ?? ['char_name' => '「空」', 'status' => 'empty'];
                    $name = $s['char_name'] ?? '「空」';
                    if ($name === '「空」') {
                        $output[] = '  ' . $dirNames[$dir] . '家：虚位以待';
                    } else {
                        $npcTag = !empty($s['is_npc']) ? '（NPC）' : '';
                        $partnerTag = '';
                        if ($isPartner) {
                            $pDir = $partnerMap[$dir];
                            $pName = $seats[$pDir]['char_name'] ?? '「空」';
                            if ($pName !== '「空」') {
                                $partnerTag = '（搭档：' . $pName . '）';
                            }
                        }
                        $output[] = '  ' . $dirNames[$dir] . '家：' . $name . $npcTag . $partnerTag;
                    }
                }

                // 出牌阶段的桌面牌
                if ($status === 3 && !empty($gameState['table_cards'])) {
                    $output[] = '';
                    $output[] = '桌面上的牌：';
                    $cardNames = ['spade' => '黑桃', 'heart' => '红桃', 'diamond' => '方片', 'club' => '草花'];
                    $cnum = ['？', '１', '２', '３', '４', '５', '６', '７', '８', '９', 'Ｔ', 'Ｊ', 'Ｑ', 'Ｋ', 'Ａ'];
                    // 牌名映射函数
                    $getCardName = function($idx) use ($gameState) {
                        if ($idx === 0) return '';
                        $suits = ['spade' => 1, 'heart' => 14, 'diamond' => 27, 'club' => 40];
                        $suitNames = ['spade' => '黑桃', 'heart' => '红桃', 'diamond' => '方片', 'club' => '草花'];
                        foreach ($suits as $suit => $base) {
                            if ($idx >= $base && $idx < $base + 13) {
                                $rank = 15 - ($idx - $base);
                                $cnum = ['？', '１', '２', '３', '４', '５', '６', '７', '８', '９', 'Ｔ', 'Ｊ', 'Ｑ', 'Ｋ', 'Ａ'];
                                return $suitNames[$suit] . $cnum[$rank];
                            }
                        }
                        return '？';
                    };
                    foreach (['east', 'north', 'west', 'south'] as $dir) {
                        $idx = $gameState['table_cards'][$dir] ?? 0;
                        if ($idx > 0) {
                            $output[] = '  ' . $dirNames[$dir] . '家出了：' . $getCardName($idx);
                        }
                    }
                    // 当前轮次和花色
                    $gi = $gameState['game_info'] ?? [];
                    $roundNum = $gi['round'] ?? 0;
                    $next = $gi['next'] ?? '';
                    $suit = $gi['suit'] ?? '';
                    $suitText = $suit ? $cardNames[$suit] : '未定';
                    $output[] = '  第' . $roundNum . '轮，当前花色：' . $suitText;
                    if ($next && isset($seats[$next])) {
                        $output[] = '  轮到' . ($seats[$next]['char_name'] ?? '?') . '出牌';
                    }
                }

                // 卖牌信息
                if ($status >= 2 && !empty($gameState['sold'])) {
                    $sold = $gameState['sold'];
                    $hasSold = false;
                    $cardLabels = ['pig' => '猪', 'blood' => '血', 'sheep' => '羊', 'doubler' => '变压器'];
                    $soldLines = [];
                    foreach (['pig', 'blood', 'sheep', 'doubler'] as $misc) {
                        if (isset($sold[$misc]) && $sold[$misc][0] !== 'not') {
                            $hasSold = true;
                            $flag = $sold[$misc][0] === 'm' ? '明卖' : '暗卖';
                            $seller = $sold[$misc][1] ?? '';
                            $sellerName = $seller ? ($seats[$seller]['char_name'] ?? '?') : '?';
                            $soldLines[] = $sellerName . $flag . $cardLabels[$misc];
                        }
                    }
                    if ($hasSold) {
                        $output[] = '';
                        $output[] = '卖牌情况：' . implode('，', $soldLines);
                    }
                }

                // 算分阶段显示结果
                if ($status === 4 && $round['result_summary']) {
                    $output[] = '';
                    $output[] = HTML_HIYEL . '本局结果：' . HTML_NOR;
                    $output[] = str_replace("\n", "\n  ", $round['result_summary']);
                }

                // 积分表
                $scoring = json_decode($round['scoring'] ?? '{}', true);
                if ($scoring && !empty($scoring['sitting'])) {
                    $output[] = '';
                    $output[] = '当前总分：';
                    foreach (['east', 'north', 'west', 'south'] as $dir) {
                        $name = $seats[$dir]['char_name'] ?? '「空」';
                        if ($name === '「空」') continue;
                        $sitting = $scoring['sitting'][$dir] ?? 0;
                        $hand = $scoring['hand'][$dir] ?? 0;
                        $output[] = '  ' . $dirNames[$dir] . '家 ' . $name . '：盘分' . $hand . '，总分' . $sitting;
                    }
                }
            }

            return [
                'success' => true,
                'type' => 'item_desc',
                'output' => implode("\n", $output),
                'skip_queue' => true
            ];
        }
        // 表不存在则继续走静态 item_descs
    }

    // 检查房间物品描述（对应 LPC 的 item_desc，如牌子、告示等）
    // 使用 room_id 查询，确保匹配完整路径格式
    $itemDesc = Database::queryOne(
        "SELECT * FROM room_item_descs WHERE room_id = ? AND (item_key = ? OR item_name LIKE ?) AND enabled = 1 LIMIT 1",
        [$roomId, $target, '%' . $target . '%']
    );
    if ($itemDesc) {
        $description = str_replace("\n", "<br>", $itemDesc['description']);
        return [
            'success' => true,
            'type' => 'item_desc',
            'output' => HTML_HIYEL . $itemDesc['item_name'] . '<br>' . HTML_NOR . $description,
            'skip_queue' => true
        ];
    }
    
    return ['success' => false, 'message' => '你要看什么？'];
}

/**
 * 查看其他玩家
 */
function viewPlayer(array $viewer, array $player): array {
    $output = [];
    
    $gender = isset($player['gender']) ? $player['gender'] : 'unknown';
    $genderText = match($gender) {
        GENDER_MALE   => '他',
        GENDER_FEMALE => '她',
        default       => 'TA',
    };
    
    $output[] = HTML_HIYEL . $player['name'] . HTML_NOR . '（Lv.' . $player['level'] . '）';
    $output[] = '';
    
    // 外貌描述
    $output[] = $genderText . '是一位江湖游侠。';
    
    // 门派信息
    $playerSect = SectHelper::getCharacterSect((int)$player['id']);
    if ($playerSect) {
        $generation = (int)$playerSect['generation'];
        $genChars = ['零','一','二','三','四','五','六','七','八','九','十'];
        $genText = ($generation > 0 && $generation <= 10) ? $genChars[$generation] : (string)$generation;
        $sectDisplay = $playerSect['sect_name'];
        
        if ($generation > 0) {
            $output[] = HTML_CYN . '此人是' . $sectDisplay . '门下第' . $genText . '代弟子，为' . $playerSect['sect_rank'] . '。' . HTML_NOR;
        } else {
            $output[] = HTML_CYN . '此人属于' . $sectDisplay . '，为' . $playerSect['sect_rank'] . '。' . HTML_NOR;
        }
    } else {
        $output[] = HTML_GRN . '此人目前无门无派。' . HTML_NOR;
    }
    
    return [
        'success' => true,
        'type'    => 'player_view',
        'output'  => implode("\n", $output),
        'player'  => $player,
        'skip_queue' => true, // 玩家查看不需要保存到队列
    ];
}

/**
 * 查看NPC
 */
function viewNpc(array $char, array $npc): array {
    $output = [];
    
    // 显示名字和ID
    $npcId = isset($npc['npc_id']) ? $npc['npc_id'] : '';
    if ($npcId) {
        $output[] = HTML_HIYEL . $npc['name'] . '(' . $npcId . ')' . HTML_NOR;
    } else {
        $output[] = HTML_HIYEL . $npc['name'] . HTML_NOR;
    }
    
    if ($npc['title']) {
        $output[] = '(' . $npc['title'] . ')';
    }
    
    $output[] = '';
    
    if ($npc['description']) {
        $output[] = $npc['description'];
    } else {
        // 安全地获取性别
        $gender = isset($npc['gender']) ? $npc['gender'] : 'unknown';
        $genderText = match($gender) {
            GENDER_MALE => '他',
            GENDER_FEMALE => '她',
            default => '它'
        };
        
        // 判断种族/class
        $class = isset($npc['class']) ? $npc['class'] : '';
        $race = isset($npc['race']) ? $npc['race'] : RACE_HUMAN;
        
        // 根据class和属性判断具体身份
        $identity = getIdentityDescription($class, $race, $npc);
        
        // 安全地获取年龄并显示
        $age = isset($npc['age']) ? intval($npc['age']) : 0;
        if ($age > 0 && ($race === RACE_HUMAN || $race === '人类')) {
            $ageDesc = chinese_number(intval($age / 10) * 10);
            $output[] = "{$genderText}是一位{$ageDesc}多岁的{$identity}。";
        } else {
            $output[] = "{$genderText}是一位{$identity}。";
        }
        
        // 添加容貌描述（参考原始项目 look.c 的 per_status_msg 函数）
        $per = isset($npc['per']) ? intval($npc['per']) : 10;
        $perMsg = getPerDescription($age, $per, $gender);
        if (!empty($perMsg)) {
            $output[] = $genderText . $perMsg;
        }
    }
    
    // 判断是否是门派所有子NPC（根据 master_npc_id 匹配）
    $npcNumId = isset($npc['npc_id']) ? (int)$npc['npc_id'] : 0;
    if ($npcNumId > 0) {
        $sectConfig = SectHelper::getSectConfig();
        foreach ($sectConfig['sects'] ?? [] as $sectKey => $sectDef) {
            if ((int)($sectDef['master_npc_id'] ?? 0) === $npcNumId) {
                $output[] = '';
                $output[] = HTML_HIYEL . '此人为' . $sectDef['name'] . '掌门。' . HTML_NOR;
                break;
            }
        }
    }
    
    return [
        'success' => true,
        'type' => 'npc_view',
        'output' => implode("\n", $output),
        'npc' => $npc
    ];
}

/**
 * 获取身份描述（根据class、race和属性判断）
 */
function getIdentityDescription(string $class, string $race, array $npc): string {
    $daoxing = isset($npc['daoxing']) ? intval($npc['daoxing']) : 0;
    $maxMana = isset($npc['max_mana']) ? intval($npc['max_mana']) : 0;
    $combatExp = isset($npc['combat_exp']) ? intval($npc['combat_exp']) : 0;
    
    // 根据class判断
    switch ($class) {
        case 'xian':
        case 'immortal':
            return '老神仙';
        case 'bonze':
        case 'monk':
            return '老和尚';
        case 'taoist':
        case 'dao':
            return '老道长';
        case 'general':
        case 'warrior':
            return '将军';
        case 'scholar':
            return '书生';
        case 'merchant':
            return '商人';
        case 'beggar':
            return '乞丐';
        case 'yaomo':
        case 'demon':
            return '妖魔';
        case 'youling':
        case 'ghost':
            return '幽灵';
        case 'beast':
            return '兽类';
        case 'dragon':
            return '龙王';
        default:
            // class为空时，先根据race判断
            if (empty($class)) {
                if ($race === '野兽' || $race === RACE_MONSTER) {
                    // 野兽：根据combat_exp判断
                    if ($combatExp > 100000) {
                        return '凶猛的妖兽';
                    } elseif ($combatExp > 10000) {
                        return '野兽';
                    } else {
                        return '小动物';
                    }
                } elseif ($race === '妖魔' || $race === RACE_DEMON) {
                    // 妖魔：根据daoxing判断
                    if ($daoxing > 500000) {
                        return '妖王';
                    } elseif ($daoxing > 100000) {
                        return '妖怪';
                    } else {
                        return '小妖';
                    }
                }
                // 人类或其他种族，继续按属性推断
            }
            // 根据属性推断
            if ($daoxing > 100000 || $maxMana > 500) {
                return '老神仙';
            } elseif ($combatExp > 50000) {
                return '武林高手';
            } else {
                return '老者';
            }
    }
}

/**
 * 获取容貌描述（参考原始项目 look.c 的 per_status_msg 函数）
 * 注意：这里不使用随机，而是根据per值返回固定的描述
 */
function getPerDescription(int $age, int $per, string $gender): string {
    // 小孩（年龄 < 14）
    if ($age < 14) {
        if ($per >= 25) {
            return '眉清目秀，灵气十足。';
        } elseif ($per >= 20) {
            return '虎头虎脑，神色机灵。';
        } elseif ($per >= 15) {
            return '个头矮矮，傻里傻气的。';
        } else {
            return '光头光脑，肮脏邋遢。';
        }
    }
    
    // 男性
    if ($gender === GENDER_MALE) {
        if ($per >= 25) {
            return '身材伟岸英挺，举手之间，气派非凡。';
        } elseif ($per >= 20) {
            return '英姿勃勃，一表人材。';
        } elseif ($per >= 15) {
            return '相貌平平，没什么好看的。';
        } else {
            return '长的一副穷凶极恶，人人不敢恭维的模样。';
        }
    }
    
    // 女性
    if ($gender === GENDER_FEMALE) {
        if ($per >= 25) {
            return '肤如凝脂，赛雪欺霜，不知倾倒了多少英雄好汉。';
        } elseif ($per >= 20) {
            return '面容娇好，肤色白皙，煞是动人。';
        } elseif ($per >= 15) {
            return '长相不算难看，也算有几分姿色。';
        } else {
            return '长相比较难看。';
        }
    }
    
    return '';
}

/**
 * 查看物品
 */
function viewItem(array $item): array {
    $output = [];
    $output[] = HTML_HIMAG . $item['item_name'] . HTML_NOR;
    $output[] = '';
    
    if ($item['description']) {
        $output[] = $item['description'];
    } else {
        $output[] = '这是一件普通的物品。';
    }
    
    return [
        'success' => true,
        'type' => 'item_view',
        'output' => implode("\n", $output),
        'item' => $item
    ];
}

