<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * NPC交互页面
 */

// 加载游戏配置（定义MODEL_PATH、DAEMON_PATH等常量）
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Npc.php';
require_once HELPER_PATH . 'RankHelper.php';

// 要求登录
require_login();

$npcId = $_GET['id'] ?? 0;
$npc = NpcModel::find($npcId);

// DEBUG - 移除这行后请删除
if ($_GET['debug'] ?? false) {
    var_dump($npcId);
    var_dump($npc);
    exit;
}

if (!$npc) {
    die('NPC不存在 (ID: ' . $npcId . ')');
}

$charId = get_char_id();
$playerFollowing = Database::queryOne("SELECT following_id FROM characters WHERE id = ?", [$charId]);
$followingId = $playerFollowing['following_id'] ?? null;

$isPlayerAvatar = false;
$avatarCharId = null;

$wzAvatar = Database::queryOne(
    "SELECT char_id FROM wuzhuangyuan_avatars WHERE npc_id = ? AND status = 'active'",
    [$npcId]
);
if ($wzAvatar && $wzAvatar['char_id'] > 0) {
    $isPlayerAvatar = true;
    $avatarCharId = $wzAvatar['char_id'];
}

$ptAvatar = Database::queryOne(
    "SELECT char_id FROM pantaohui_avatars WHERE npc_id = ? AND status = 'active'",
    [$npcId]
);
if ($ptAvatar && $ptAvatar['char_id'] > 0) {
    $isPlayerAvatar = true;
    $avatarCharId = $ptAvatar['char_id'];
}

/**
 * 根据年龄生成年龄描述
 */
function getAgeDesc(int $age, string $gender = 'male', bool $hasTitle = false): string {
    if ($age <= 0) {
        return '看起来年纪不详';
    }
    
    // 如果有头衔称呼，只返回年龄段，不返回具体称谓
    if ($hasTitle) {
        if ($age < 14) {
            return "{$age}岁左右";
        } elseif ($age < 20) {
            return "{$age}岁左右";
        } elseif ($age < 35) {
            return "{$age}岁左右";
        } elseif ($age < 50) {
            return "{$age}岁左右";
        } elseif ($age < 70) {
            return "{$age}多岁";
        } else {
            return "{$age}岁左右";
        }
    }
    
    // 没有头衔时，返回完整描述
    // 根据性别选择称谓
    $maleTerms = ['孩童', '少年', '青年', '中年男子', '老人'];
    $femaleTerms = ['孩童', '少女', '青年女子', '中年妇人', '老妪', '老妇人'];
    
    $terms = ($gender === 'female') ? $femaleTerms : $maleTerms;
    
    if ($age < 14) {
        return "一位{$age}岁多的{$terms[0]}";
    } elseif ($age < 20) {
        return "一位{$age}岁多的{$terms[1]}";
    } elseif ($age < 35) {
        return "一位{$age}多岁的{$terms[2]}";
    } elseif ($age < 50) {
        return "一位{$age}多岁的{$terms[3]}";
    } elseif ($age < 70) {
        return "一位{$age}多岁的{$terms[4]}";
    } else {
        return "一位{$age}多岁的{$terms[5]}";
    }
}

/**
 * 根据 per (容貌) 和 gender 生成样貌描述（使用原始项目逻辑）
 */
function getAppearanceDesc(int $per, int $age, string $gender): string {
    // 儿童 (age < 14)
    if ($age < 14) {
        if ($per >= 25) return '眉清目秀，灵气十足';
        elseif ($per >= 20) return '明眸皓齿，神色活泼';
        elseif ($per >= 15) return '大头大脑，憨头憨脑';
        else return '蓬头垢面，衣衫褴褛';
    }
    
    // 男性
    if ($gender === 'male') {
        if ($per >= 25) return '身材伟岸英挺，顾盼之间，气度非凡';
        elseif ($per >= 20) return '英武矫健，器宇轩昂';
        elseif ($per >= 15) return '相貌平平，没什么好看的';
        else return '长的一副惨不忍睹，人人避之唯恐不及的模样';
    }
    
    // 女性
    if ($gender === 'female') {
        if ($per >= 25) return '长发如云，肌肤胜雪，不知倾倒了多少英雄豪杰';
        elseif ($per >= 20) return '面容娇嫩艳丽，身形苗条婀娜';
        elseif ($per >= 15) return '虽算不上绝色佳人，也有几分姿色';
        else return '长相比较难看';
    }
    
    return '';
}

$displayNpc = $npc;
$displayGender = $npc['gender'] ?? 'male';
$displayAge = $npc['age'] ?? 0;
$displayPer = $npc['per'] ?? 10;
$pronoun = '他';

if ($isPlayerAvatar && $avatarCharId > 0) {
    $char = Database::queryOne(
        "SELECT name, gender, age, per FROM characters WHERE id = ?",
        [$avatarCharId]
    );
    if ($char) {
        $displayGender = $char['gender'] ?? 'male';
        $displayAge = $char['age'] ?? 0;
        $displayPer = $char['per'] ?? 10;
    }
}

if ($displayGender === 'female') {
    $pronoun = '她';
}

// 获取装备信息
$equipment = [];
try {
    if ($isPlayerAvatar && $avatarCharId > 0) {
        $equipResult = Database::queryAll(
                'SELECT ci.item_id, ci.equip_slot, ci.category, gi.name as item_name 
                 FROM character_inventory ci 
                 LEFT JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category 
                 WHERE ci.char_id = ? AND ci.equipped = 1',
                [$avatarCharId]
            );
            
            foreach ($equipResult as $equip) {
                $equipment[] = [
                    'item_id' => $equip['item_id'],
                    'slot' => $equip['equip_slot'],
                    'name' => $equip['item_name'] ?: $equip['item_id']
                ];
            }
    } else {
        $equipResult = Database::queryAll(
            'SELECT ne.item_id, ne.equip_slot, ne.category, gi.name as item_name 
             FROM npc_equipment ne 
             LEFT JOIN items gi ON ne.item_id = gi.item_id AND ne.category = gi.category 
             WHERE ne.npc_id = ? AND ne.worn = 1',
            [$npcId]
        );
        
        foreach ($equipResult as $equip) {
            $equipment[] = [
                'item_id' => $equip['item_id'],
                'slot' => $equip['equip_slot'],
                'name' => $equip['item_name'] ?: $equip['item_id']
            ];
        }
    }
} catch (Exception $e) {
    $equipment = [];
}

// 获取头衔称呼
$respectTitle = RankHelper::queryRespect($npc);
$rudeTitle = RankHelper::queryRude($npc);

// 生成描述
$hasTitle = !empty($respectTitle) && $respectTitle !== '未知';
$ageDesc = getAgeDesc($displayAge, $displayGender, $hasTitle);
$appearanceDesc = getAppearanceDesc($displayPer, $displayAge, $displayGender);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
    <title><?php echo h($npc['name']); ?>_WAP西游记2012</title>
    <link rel="shortcut icon" href="../assets/images/favicon.ico">
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/npc.css">
</head>
<body>
<div class="npc-content">
<div class="npc-title"><?php if (!empty($npc['title'])): ?>【<?php echo h($npc['title']); ?>】<?php endif; ?><?php echo h($npc['name']); ?>(<?php echo h($npc['npc_id']); ?>)<?php echo h($npc['family_name']); ?></div>

<div class="npc-description">
<?php echo nl2br(h(str_replace('\\n', "\n", $npc['description'] ?? ''))); ?>
</div>
<br>
<div class="npc-info">
<?php if ($hasTitle): ?>
<?php echo $pronoun; ?>是一位<?php echo h($ageDesc); ?>的<?php echo h($respectTitle); ?>。
<?php else: ?>
<?php echo $pronoun; ?> <?php echo h($ageDesc); ?>。
<?php endif; ?>
</div>

<div class="npc-info">生得<?php echo h($appearanceDesc); ?></div>

<?php if (!empty($equipment)): ?>
<div class="npc-info"><span>只见<?php echo $pronoun; ?>：</span>
<br>
<?php 
foreach ($equipment as $index => $equip):
    // 根据装备槽位确定描述词
    $slotText = '';
    switch ($equip['slot']) {
        case 'armor':
            $slotText = '身穿';
            break;
        case 'head':
            $slotText = '头戴';
            break;
        case 'neck':
            $slotText = '颈挂';
            break;
        case 'waist':
            $slotText = '腰系';
            break;
        case 'feet':
            $slotText = '脚穿';
            break;
        case 'hands':
            $slotText = '手戴';
            break;
        case 'weapon':
            $slotText = '手持';
            break;
        case 'misc':
        default:
            // 对于misc类型，根据物品名称智能判断（按照原始LPC项目的装备类型优先级）
            $itemName = strtolower($equip['name']);
            if (strpos($itemName, '冠') !== false || strpos($itemName, '帽') !== false ||
                strpos($itemName, '盔') !== false || strpos($itemName, '巾') !== false) {
                $slotText = '头戴 ';
            } elseif (strpos($itemName, '衣') !== false || strpos($itemName, '袍') !== false || 
                      strpos($itemName, '衫') !== false || strpos($itemName, '甲') !== false ||
                      strpos($itemName, '铠') !== false || strpos($itemName, '袈裟') !== false ||
                      strpos($itemName, '布') !== false) {
                $slotText = '身穿';
            } elseif (strpos($itemName, '裙') !== false) {
                // 裙装特殊处理：道冠裙是头饰，其他裙是衣装
                if (strpos($itemName, '道冠') !== false) {
                    $slotText = '头戴';
                } else {
                    $slotText = '身穿';
                }
            } elseif (strpos($itemName, '鞋') !== false || strpos($itemName, '靴') !== false) {
                $slotText = '脚穿';
            } elseif (strpos($itemName, '剑') !== false || strpos($itemName, '刀') !== false ||
                      strpos($itemName, '枪') !== false || strpos($itemName, '棍') !== false ||
                      strpos($itemName, '杖') !== false || strpos($itemName, '斧') !== false ||
                      strpos($itemName, '锤') !== false || strpos($itemName, '鞭') !== false ||
                      strpos($itemName, '棒') !== false) {
                $slotText = '手持';
            } elseif (strpos($itemName, '书') !== false || strpos($itemName, '册') !== false || strpos($itemName, '卷') !== false) {
                $slotText = '携带';
            } else {
                $slotText = '携带';
            }
            break;
    }
    
    // 如果不是第一个装备，添加换行
    if ($index > 0) {
        echo "<br>";
    }
    
    // 装备名称可点击查看详细信息
    echo h($slotText) . '<a href="item.php?id=' . urlencode($equip['item_id']) . '&category=' . urlencode($equip['category'] ?? '') . '">' . h($equip['name']) . '</a>';
endforeach;
?>
</div>
<?php endif; ?>

</div>
<hr>
<?php 
// 获取NPC的特殊动作配置
$specialActions = [];
if (!empty($npc['actions'])) {
    $specialActions = json_decode($npc['actions'], true);
}

// 获取玩家信息用于权限判定
$playerSkills = []; // 玩家技能列表
$playerItems = [];  // 玩家物品列表

if ($charId) {
    // 获取玩家技能
    $skillsResult = Database::queryAll(
        'SELECT cs.skill_id, gs.name, gs.type FROM character_skills cs JOIN skills gs ON cs.skill_id = gs.skill_id WHERE cs.char_id = ?',
        [$charId]
    );
    foreach ($skillsResult as $skill) {
        $playerSkills[] = $skill['skill_id'];
    }
    
    // 获取玩家背包物品
    $itemsResult = Database::queryAll(
        'SELECT ci.item_id, ci.category, gi.name, ci.quantity FROM character_inventory ci JOIN items gi ON ci.item_id = gi.item_id AND ci.category = gi.category WHERE ci.char_id = ? AND ci.quantity > 0',
        [$charId]
    );
    $playerItemsData = [];
    foreach ($itemsResult as $item) {
        $playerItems[] = $item['item_id'];
        $playerItemsData[] = [
            'item_id' => $item['item_id'],
            'name' => $item['name'],
            'quantity' => intval($item['quantity'])
        ];
    }
}

// ★ 查询玩家身上所有法宝（用于"祭法宝"弹窗选择）
// 注意：character_inventory 的 category 和 items 的 category 可能不一致（如 obj vs qujing），
// 所以只用 item_id 做 JOIN，避免查不到结果。
$playerFabaos = [];
if ($charId) {
    $fabaoResult = Database::queryAll(
        "SELECT ci.id, ci.item_id, i.name, i.trap_type, i.is_real, i.trap_ratio,
                ci.equipped, ci.quantity
         FROM character_inventory ci
         JOIN items i ON ci.item_id = i.item_id
         WHERE ci.char_id = ? AND i.fabao = 1 AND ci.quantity > 0
         ORDER BY ci.equipped DESC, ci.id ASC",
        [$charId]
    );
    $playerFabaos = $fabaoResult ?: [];
}
$hasFabaos = !empty($playerFabaos);

/**
 * 检查玩家是否有权限执行特殊动作
 */
function canPerformAction($action, $playerSkills, $playerItems) {
    // 检查技能要求
    if (!empty($action['required_skill'])) {
        $requiredSkill = $action['required_skill'];
        // 支持格式：“门派/技能名" 或 "技能名"
        if (strpos($requiredSkill, '/') !== false) {
            // 需要特定门派的技能
            if (!in_array($requiredSkill, $playerSkills)) {
                return false;
            }
        } else {
            // 只需要技能名（不限门派）
            $hasSkill = false;
            foreach ($playerSkills as $skill) {
                if (strpos($skill, '/' . $requiredSkill) !== false || $skill === $requiredSkill) {
                    $hasSkill = true;
                    break;
                }
            }
            if (!$hasSkill) {
                return false;
            }
        }
    }
    
    // 检查物品要求
    if (!empty($action['required_item'])) {
        $requiredItem = $action['required_item'];
        if (!in_array($requiredItem, $playerItems)) {
            return false;
        }
    }
    
    return true;
}
?>

<?php 
// 先收集可见的动作
$visibleActions = [];
$npcArea = $npc['spawn_area'] ?? '';
$npcRoom = $npc['spawn_room'] ?? '';
if (!empty($specialActions)) {
    foreach ($specialActions as $action) {
        if (isset($action['type']) && $action['type'] === 'accept_object') {
            continue;
        }
        if (!canPerformAction($action, $playerSkills, $playerItems)) {
            continue;
        }
        $visibleActions[] = $action;
    }
}
?>
<?php if (!empty($visibleActions)): ?>
<div id="npc-action-result" style="display: none; padding: 8px; margin: 5px 0; border: 1px solid #444; background:#1a1a1a;"></div>
<div class="npc-actions">
<?php foreach ($visibleActions as $action): 
    $requirements = [];
    if (!empty($action['required_skill'])) {
        $requirements[] = "需要{$action['required_skill']}技能";
    }
    if (!empty($action['required_item'])) {
        $requirements[] = "需要{$action['required_item']}";
    }
    $reqText = !empty($requirements) ? ' (' . implode('，', $requirements) . ')' : '';
    $actionCmd = !empty($action['action_cmd']) ? $action['action_cmd'] : ($action['type'] ?? 'interact');
    
    // 喜宴按钮样式：去掉花哨颜色，统一字体，保留链接原始颜色
    $style = 'font-family:"Microsoft YaHei","微软雅黑","PingFang SC",sans-serif; font-size:14px; font-weight:normal;';
    if (strpos($actionCmd, ' ') !== false) {
        $parts = explode(' ', $actionCmd, 2);
        $cmd = $parts[0];
        $param = $parts[1] ?? '';
        ?>
        <a href="javascript:void(0);" onclick="npcAction('<?php echo addslashes($cmd); ?>', '<?php echo addslashes($npcArea); ?>', '<?php echo addslashes($npcRoom); ?>', '<?php echo addslashes($param); ?>', <?php echo $npcId; ?>)" style="<?php echo $style; ?>"><?php echo h($action['action_name']); ?></a><?php echo $reqText; ?>&nbsp;
        <?php
    } else {
        ?>
        <a href="javascript:void(0);" onclick="npcAction('<?php echo addslashes($actionCmd); ?>', '<?php echo addslashes($npcArea); ?>', '<?php echo addslashes($npcRoom); ?>', '', <?php echo $npcId; ?>)" style="<?php echo $style; ?>"><?php echo h($action['action_name']); ?></a><?php echo $reqText; ?>&nbsp;
        <?php
    }
endforeach; ?>
</div>
<?php endif; ?>

<?php 
// 显示询问话题
$inquiryData = !empty($npc['inquiry']) ? json_decode($npc['inquiry'], true) : [];
if (!is_array($inquiryData)) $inquiryData = [];

// 过滤掉general和内部属性，收集可显示的话题
$visibleTopics = [];
// 需要排除的内部属性（不是可询问的话题）
$internalProperties = ['accept_items', 'accepted_items', 'reward_item', 'response_message'];
foreach ($inquiryData as $topic => $response) {
    if ($topic !== 'general' && !in_array($topic, $internalProperties)) {
        $visibleTopics[$topic] = $response;
    }
}

// ★ 开封解谜：如果有待完成的 ask 任务指向此NPC，添加任务话题
require_once __DIR__ . '/../helpers/QuestHelper.php';
$charId = get_char_id();
$pendingQuests = QuestHelper::getPendingQuests($charId);
foreach ($pendingQuests as $quest) {
    if (($quest['quest_type'] ?? '') === 'ask' && ($quest['target_id'] ?? '') === ($npc['npc_id'] ?? '')) {
        $questTopic = $quest['object_name'] ?? '';
        if ($questTopic && !isset($visibleTopics[$questTopic])) {
            $visibleTopics[$questTopic] = '[解谜] ' . $questTopic;
        }
    }
}

// 常见话题键 → 中文显示文本
$topicLabelMap = [
    'name'        => '您是？',
    'here'        => '这儿是？',
    'life'        => '还魂',
    'quest'       => '有什么任务？',
    'job'         => '有什么工作？',
    'sell'        => '买些东西',
    'buy'         => '买些东西',
    'cure'        => '请帮我治病',
    'heal'        => '请帮我治病',
    'sect'        => '门派',
    'apprentice'  => '拜师',
    'master'      => '师傅',
    'skill'       => '技能',
    'learn'       => '学习',
    'map'         => '地图',
    'rumor'       => '传言',
    'news'        => '新闻',
    'help'        => '帮助',
    // 婚礼相关话题
    '婚礼'        => '婚礼',
    '结婚'        => '结婚',
    '离婚'        => '离婚',
    '做媒'        => '做媒',
    '价钱'        => '价钱',
    '价格'        => '价钱',
    '费用'        => '价钱',
    'money'       => '价钱',
    'price'       => '价钱',
    // 喜福会相关话题 - 只保留主键，避免重复显示
    'party'       => '喜宴',
    '喜宴'        => '喜宴',
    '婚宴'        => '喜宴',
    '宴'          => '',  // 不显示，会被喜宴覆盖
    '席'          => '',  // 不显示，会被酒席覆盖
    '酒席'        => '酒席',
    // 其他常见话题
    'rumors'      => '传言',
    'task'        => '任务',
    'work'        => '工作',
    'fight'       => '比武',
    'combat'      => '比武',
    'train'       => '修炼',
    'practice'    => '练习',
    'give'        => '给予',
    'accept'      => '接受',
    // 蟠桃会相关话题
    '蟠桃会'      => '蟠桃会',
    '封神榜'      => '封神榜',
    '申请'        => '申请神位',
    '御批'        => '御批',
    '挑战'        => '挑战',
];

// 按 response 功能去重：同一功能（相同回答或相同 callable）只保留一个话题键
// 比如"算命"、"算卦"、"suanming" 都是调用 suanming，只显示一个
function deduplicateTopicsByResponse(array $topics, array $labelMap): array {
    $groups = [];
    foreach ($topics as $topic => $response) {
        // 生成 response 签名，用于判断是否同一功能
        if (is_string($response)) {
            $sig = 'str:' . $response;
        } elseif (is_array($response) && isset($response[0]) && $response[0] === 'callable') {
            // callable 格式：["callable", "method_name"] 或 ["callable", "method_name", extra]
            // 如果有额外参数，签名包含参数，确保不同话题独立显示
            $extraParams = isset($response[2]) ? ':' . json_encode(array_slice($response, 2)) : '';
            $sig = 'callable:' . ($response[1] ?? '') . $extraParams;
        } else {
            $sig = 'other:' . json_encode($response, JSON_UNESCAPED_UNICODE);
        }
        if (!isset($groups[$sig])) {
            $groups[$sig] = [];
        }
        $groups[$sig][] = $topic;
    }

    $result = [];
    foreach ($groups as $sig => $topicList) {
        if (count($topicList) === 1) {
            // 只有一个话题，直接保留
            $topic = $topicList[0];
            $result[$topic] = $topics[$topic];
            continue;
        }

        // 多个话题同一功能，选最优的作为代表
        // 优先级：1. 在 labelMap 里有映射的  2. 含中文字符的  3. 较长的
        usort($topicList, function($a, $b) use ($labelMap) {
            $aInMap = isset($labelMap[$a]) && $labelMap[$a] !== '';
            $bInMap = isset($labelMap[$b]) && $labelMap[$b] !== '';
            if ($aInMap !== $bInMap) return $bInMap ? 1 : -1;

            $aHasCn = preg_match('/[\x{4e00}-\x{9fff}]/u', $a);
            $bHasCn = preg_match('/[\x{4e00}-\x{9fff}]/u', $b);
            if ($aHasCn !== $bHasCn) return $bHasCn ? 1 : -1;

            return strlen($b) - strlen($a);
        });

        $bestTopic = $topicList[0];
        $result[$bestTopic] = $topics[$bestTopic];
    }

    return $result;
}

$visibleTopics = deduplicateTopicsByResponse($visibleTopics, $topicLabelMap);

if (!empty($visibleTopics)):
    // 先按显示文本去重：同一显示文本只保留第一个话题键
    $displayedLabels = [];
    $uniqueTopics = [];
    foreach ($visibleTopics as $topic => $response) {
        $topicLabel = $topicLabelMap[$topic] ?? $topic;
        // 空标签跳过（如"宴"、"席"这些会被其他覆盖）
        if ($topicLabel === '') {
            continue;
        }
        // 已显示过的标签跳过
        if (isset($displayedLabels[$topicLabel])) {
            continue;
        }
        $displayedLabels[$topicLabel] = true;
        $uniqueTopics[$topic] = $response;
    }
?>
<?php foreach ($uniqueTopics as $topic => $response): ?>
        <?php $topicLabel = $topicLabelMap[$topic] ?? $topic; ?>
        <?php if ($topic === '功名' || $topic === '科举'): ?>
        <a href="javascript:void(0)" onclick="askNpcTopic(<?= $npcId ?>, '<?= h($topic) ?>')"
           ><?= h($topicLabel) ?>&nbsp;
        </a>
        <?php else: ?>
        <a href="action.php?action=ask&npc_id=<?= $npcId ?>&topic=<?= urlencode($topic) ?>"
           ><?= h($topicLabel) ?>&nbsp;
        </a>
        <?php endif; ?>
    <?php endforeach; ?>
<?php else: ?>
<a href="action.php?action=talk&npc_id=<?php echo $npcId; ?>">对话</a>&nbsp;
<?php endif; ?>
<?php
// 检查是否是商人并显示购买链接（放在对话/话题之后）
if (isset($npc['merchant']) && $npc['merchant']) {
    $shopType = $npc['shop_type'] ?? 'vendor';
    $tradeLink = 'trade.php?npc_id=' . $npcId;
    if ($shopType == 'bank') {
        echo '<a href="' . $tradeLink . '">钱庄</a>';
    } elseif ($shopType == 'hockshop') {
        echo '<a href="' . $tradeLink . '">当铺</a>';
    } else {
        echo '<a href="' . $tradeLink . '">购买</a>';
    }
    echo '&nbsp;';
}
?>

<div class="actions">
<?php if (($npc['combat_exp'] ?? 0) > 0): ?>
<a href="action.php?action=fight&npc_id=<?php echo $npcId; ?>">切磋</a>&nbsp;
<a href="action.php?action=kill&npc_id=<?php echo $npcId; ?>">击杀</a>&nbsp;
<?php endif; ?>

<?php if ($hasFabaos): ?>
<a href="javascript:void(0);" onclick="showFabaoModal()">祭法宝</a>
<?php else: ?>
<a href="action.php?action=ji&param=<?php echo urlencode($npc['name']); ?>">祭法宝</a>
<?php endif; ?>
<?php
// 检测玩家是否携带天魔茧，如果有就显示"收"链接
// 直接查询，不依赖$playerItems数组（避免category不匹配的问题）
$hasTianmojian = false;
if ($charId) {
    $tianmojianCheck = Database::queryOne(
        "SELECT ci.quantity FROM character_inventory ci 
         WHERE ci.char_id = ? AND ci.item_id = 'tianmojian' AND ci.quantity > 0",
        [$charId]
    );
    if ($tianmojianCheck && intval($tianmojianCheck['quantity']) > 0) {
        $hasTianmojian = true;
    }
}
if ($hasTianmojian):
?>
&nbsp;<a href="action.php?action=shou&param=tianmojian&npc_id=<?php echo $npcId; ?>" style="color:#FF00FF;">收</a>
<?php endif; ?>
<br>
<a href="action.php?action=huimeng&npc_id=<?php echo $npcId; ?>">回梦</a>&nbsp;
<a href="action.php?action=cast&param=<?php echo urlencode('mihun on ' . $npc['name']); ?>">迷魂</a>&nbsp;
<a href="action.php?action=examine&npc_id=<?php echo $npcId; ?>">探查</a>&nbsp;
<br>
<?php if ($followingId == -$npcId): ?>
<a href="action.php?action=follow">取消跟随</a>&nbsp;
<?php else: ?>
<a href="action.php?action=follow&npc_id=<?php echo $npcId; ?>">跟随</a>&nbsp;
<?php endif; ?>
<a href="action.php?action=transform&npc_id=<?php echo $npcId; ?>">变成</a>&nbsp;
<a href="inventory.php?give_to=<?php echo $npcId; ?>&give_to_name=<?php echo urlencode($npc['name']); ?>&give_to_type=npc">给予</a>
<br>
<?php
// 婚礼服务检查：轿夫头NPC特殊处理
$npcStringId = $npc['npc_id'] ?? '';
if ($npcStringId === 'jftou' || $npcStringId === 'jiaofu tou') {
    $weddingInJob = Database::queryOne(
        "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'in_job'",
        [$npcId]
    );
    if ($weddingInJob && $weddingInJob['temp_value'] == '1') {
        // 检查当前玩家是否是新娘
        $weddingBride = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'bride'",
            [$npcId]
        );
        if ($weddingBride && $weddingBride['temp_value'] == $charId) {
            // 检查是否已经在路上（新娘已在轿中）
            $weddingOnWay = Database::queryOne(
                "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'on_way'",
                [$npcId]
            );
            if (!$weddingOnWay || $weddingOnWay['temp_value'] != '1') {
                echo '<a href="action.php?action=enter_palanquin&npc_id=' . $npcId . '" style="color:#FF69B4; font-weight:bold;">进入花轿</a>&nbsp;';
            }
        }
        // 检查当前玩家是否是新郎
        $weddingGroom = Database::queryOne(
            "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'groom'",
            [$npcId]
        );
        if ($weddingGroom && $weddingGroom['temp_value'] == $charId) {
            // 检查新娘是否已上轿
            $weddingOnWay = Database::queryOne(
                "SELECT temp_value FROM npc_temp WHERE npc_id = ? AND temp_key = 'on_way'",
                [$npcId]
            );
            if ($weddingOnWay && $weddingOnWay['temp_value'] == '1') {
                echo '<a href="action.php?action=arrive_destination&npc_id=' . $npcId . '" style="color:#FFD700; font-weight:bold;">到达目的地</a>&nbsp;';
            }
        }
    }
}
?>

<?php
// 检查是否是袁天罡并且有灭妖任务可以放弃
if ($npcId === 136 && $charId) {
    $hasMieyaoTask = Database::queryOne(
        "SELECT id FROM mieyao_yaoguai WHERE owner_id = ? AND is_killed = 0",
        [$charId]
    );
    if ($hasMieyaoTask) {
        echo '<a href="action.php?action=abandon_mieyao&npc_id=' . $npcId . '" onclick="return confirm(\'确定要放弃灭妖任务吗？\')">放弃灭妖任务</a><br>';
    }
}

require_once HELPER_PATH . 'SectHelper.php';

$sect = SectHelper::getSectByNpcId($npcId);
if ($sect) {
    $playerFamily = '';
    if ($charId) {
        $charInfo = Database::queryOne('SELECT family FROM characters WHERE id = ?', [$charId]);
        $playerFamily = $charInfo['family'] ?? '';
    }
    
    if (empty($playerFamily)) {
        echo '<a href="action.php?action=apprentice&npc_id=' . $npcId . '">拜师</a>';
    } else {
        // 如果玩家已有门派，显示判师链接
        echo '<a href="action.php?action=leaveSect&confirm=1" onclick="return confirm(\'确定要背叛师门吗？将承受惩罚！\')">判师</a>';
        
        // 只有当玩家属于该NPC所属门派时，才显示学习技能链接
        if ($playerFamily === $sect['key']) {
            echo '&nbsp;<a href="skills_learn.php?npc_id=' . $npcId . '"> 学习</a>';
        }
    }
}
?>
</div>
<br>
<a href="#" onclick="javascript:history.back(-1);">返回</a>
<hr>
<a href="room.php">返回游戏</a>

<!-- ★ 法宝选择弹窗 -->
<?php if ($hasFabaos): ?>
<div id="fabao-modal-overlay" class="fabao-modal-overlay" onclick="hideFabaoModal(event)">
    <div class="fabao-modal" onclick="event.stopPropagation()">
        <h3>选择法宝祭起对付 <?= h($npc['name']) ?></h3>
        <?php foreach ($playerFabaos as $fabao): ?>
            <?php
                $isEquipped = !empty($fabao['equipped']);
                $isReal = !empty($fabao['is_real']);
                $trapLabel = '';
                if ($fabao['trap_type'] === 'trap') $trapLabel = '困敌';
                elseif ($fabao['trap_type'] === 'bind') $trapLabel = '束缚';
                $badgeHtml = '';
                if ($isEquipped) $badgeHtml = '<span class="fabao-badge equipped">已装备</span>';
                if ($trapLabel) $badgeHtml .= '<span class="fabao-badge" style="background:#2a3a5e;color:#88aaff;">' . $trapLabel . '</span>';
            ?>
            <div class="fabao-item" onclick="submitFabaoJi(<?= $fabao['id'] ?>, '<?= addslashes(h($fabao['name'])) ?>')">
                <div>
                    <div class="fabao-name"><?= h($fabao['name']) ?><?= $isEquipped ? ' ✓' : '' ?></div>
                    <div class="fabao-info"><?= h(ucfirst($fabao['trap_type'] ?? '未知')) ?> 命中:<?= intval($fabao['trap_ratio'] ?? 50) ?>%</div>
                </div>
                <div><?= $badgeHtml ?></div>
            </div>
        <?php endforeach; ?>
        <button class="btn-cancel" onclick="hideFabaoModal()">取消</button>
    </div>
</div>
<?php endif; ?>

<!-- 科举考试弹窗 -->
<div id="examModal" class="exam-modal-overlay">
    <div class="exam-modal">
        <h3>【科举考试】</h3>
        <div id="examQuestions" style="max-height: 40vh; overflow-y: auto; margin-bottom: 15px; text-align: left;">
            <p style="color: #aaa; text-align: center;">加载中...</p>
        </div>
        <div style="margin-bottom: 15px;">
            <input type="text" id="examAnswerInput" placeholder="请输入答案（如：ABC）" 
                   style="width: 100%; padding: 10px; font-size: 16px; text-align: center; background-color: #3d3d3d; border: 2px solid #555; border-radius: 4px; color: #fff;" 
                   maxlength="3" oninput="this.value = this.value.toUpperCase()">
        </div>
        <div class="exam-modal-buttons">
            <button class="exam-modal-btn primary" onclick="submitExamAnswer()">提交答案</button>
            <button class="exam-modal-btn secondary" onclick="closeExamModal()">取消考试</button>
        </div>
    </div>
</div>

<script>
window.npcPageData = {
    npcName: '<?= addslashes($npc['name']) ?>',
    npcId: <?= $npcId ?>
};
</script>
<script src="../assets/js/npc.js"></script>
</body>
</html>

