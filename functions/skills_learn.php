<?php
/**
 * 师徒授业独立页面
 * 向玩家师父学习技能
 */

session_save_path(__DIR__ . '/../sessions');
session_start();

define('IN_GAME', true);

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

// 确保数据库表结构完整
Database::addMarriedColumn();
Database::addSleepInvitationsTable();
Database::addKeeZeroTimeColumn();
Database::addGuestStatusColumn();
Database::addBabyColumns();

// 加载模型
require_once MODEL_PATH . 'Character.php';
require_once MODEL_PATH . 'Room.php';
require_once MODEL_PATH . 'Npc.php';
require_once MODEL_PATH . 'Item.php';
require_once MODEL_PATH . 'User.php';

// 加载守护进程
require_once DAEMON_PATH . 'CombatDaemon.php';
require_once DAEMON_PATH . 'MessageDaemon.php';
require_once DAEMON_PATH . 'NatureDaemon.php';
require_once DAEMON_PATH . 'LoginDaemon.php';
require_once DAEMON_PATH . 'CommandDaemon.php';
require_once DAEMON_PATH . 'ActionRouter.php';
require_once DAEMON_PATH . 'ApprenticeHandler.php';
require_once DAEMON_PATH . 'QujingHandler.php';

// 加载辅助类
require_once HELPER_PATH . 'ArmorHelper.php';
require_once HELPER_PATH . 'WeaponHelper.php';
require_once HELPER_PATH . 'FabaoHelper.php';
require_once HELPER_PATH . 'SkillManager.php';
require_once HELPER_PATH . 'SpellHelper.php';
require_once HELPER_PATH . 'AttributeHelper.php';
require_once HELPER_PATH . 'ExpHelper.php';
require_once HELPER_PATH . 'CombatMessages.php';
require_once HELPER_PATH . 'CombatSystemHelper.php';

// 加载命令函数
$commandFiles = glob(__DIR__ . '/../commands/*.php');
foreach ($commandFiles as $file) {
    require_once $file;
}

// 要求登录
require_login();

$charId = get_char_id();
$char = CharacterModel::find($charId);

if (!$char) {
    redirect('../index.php');
    exit;
}

// 运行定时检查
QujingHandler::runTimedChecks();

// 获取参数
$masterId = intval($_GET['master_id'] ?? $_POST['master_id'] ?? 0);
$npcId = intval($_GET['npc_id'] ?? $_POST['npc_id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!$masterId && !$npcId) {
    redirect('room.php');
    exit;
}

// =========================================================
// NPC 门派掌门学习分支（沿用本页风格，不再使用弹窗）
// =========================================================
if ($npcId > 0) {
    require_once HELPER_PATH . 'SectHelper.php';

    // ★ 玉鼠精惩罚检查：背叛无底洞后向玉鼠精学技能触发惩罚
    // 还原原始LPC yushu.c::prevent_learn() 逻辑
    if ($npcId === 652) {
        require_once HELPER_PATH . 'YushuPunishHelper.php';
        if (YushuPunishHelper::hasBetrayedWudidong($charId)) {
            $punishResult = YushuPunishHelper::executePunishment($charId);
            if (!empty($punishResult['success'])) {
                $_SESSION['flash_message'] = [
                    'type' => 'error',
                    'content' => $punishResult['message'] ?? '玉鼠精拒绝传授你技能。',
                    'timestamp' => time()
                ];
                redirect('room.php');
                exit;
            }
        }
    }

    // 检查 NPC 是否是掌门
    $sect = SectHelper::getSectByNpcId($npcId);
    if (!$sect) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '该NPC不是门派掌门，无法传授技能。',
            'timestamp' => time()
        ];
        redirect('npc.php?id=' . $npcId);
        exit;
    }

    // 获取玩家门派
    $charInfo = Database::queryOne('SELECT family, name, potential FROM characters WHERE id = ?', [$charId]);
    $playerFamily = $charInfo['family'] ?? '';

    if (empty($playerFamily)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你还没有加入任何门派，请先拜师。',
            'timestamp' => time()
        ];
        redirect('npc.php?id=' . $npcId);
        exit;
    }

    if ($playerFamily !== $sect['key']) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你不是本门弟子，无法在此学习技能。',
            'timestamp' => time()
        ];
        redirect('npc.php?id=' . $npcId);
        exit;
    }

    // 加载门派专属、重点技能
    $sectSkills = SectHelper::getSectSkills($playerFamily);
    $exclusiveSkills = $sectSkills['exclusive'] ?? [];
    $importantSkills = $sectSkills['important'] ?? [];

    // 加载通用基础技能：所有师父都应该能教的基础技能
    $sectsConfig = require __DIR__ . '/../config/sects.php';
    $commonSkills = $sectsConfig['common_skills'] ?? [];

    $allSkills = [];

    // 1. 门派专属技能（type=exclusive，有30%加成）
    foreach ($exclusiveSkills as $skillId => $skillName) {
        $level = SkillManager::querySkill($charId, $skillId);
        $allSkills[] = [
            'id' => $skillId,
            'name' => $skillName,
            'level' => $level,
            'type' => 'exclusive',
        ];
    }

    // 2. 门派重点技能（type=important，有15%加成）
    foreach ($importantSkills as $skillId => $skillName) {
        if (!isset($exclusiveSkills[$skillId])) {
            $level = SkillManager::querySkill($charId, $skillId);
            $allSkills[] = [
                'id' => $skillId,
                'name' => $skillName,
                'level' => $level,
                'type' => 'important',
            ];
        }
    }

    // 3. 通用基础技能（type=basic）：所有师父都能教，无额外加成
    foreach ($commonSkills as $skillId => $skillInfo) {
        $skillName = $skillInfo['name'] ?? $skillId;
        if (!isset($exclusiveSkills[$skillId]) && !isset($importantSkills[$skillId])) {
            $level = SkillManager::querySkill($charId, $skillId);
            $allSkills[] = [
                'id' => $skillId,
                'name' => $skillName,
                'level' => $level,
                'type' => 'basic',
            ];
        }
    }

    $enabledSkill = $_SESSION['enabled_skill_' . $charId] ?? '';
    $potential = intval($charInfo['potential'] ?? 0);

    // 读取 flash message
    $flashMessage = null;
    if (!empty($_SESSION['flash_message'])) {
        $flashMessage = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
    }
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>技能学习 - <?php echo h($sect['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/skills_learn.css">
</head>
<body>
    <div class="learn-modal">
        <h3><?php echo h($sect['name']); ?> · 技能学习 <span><a href="npc.php?id=<?php echo $npcId; ?>">返回</a></span></h3>
        <div class="learn-modal-desc">选择你要修炼的武学技能</div>

        <?php if ($flashMessage): ?>
        <div class="flash-msg <?php echo ($flashMessage['type'] ?? '') === 'success' ? 'success' : 'error'; ?>">
            <?php echo h($flashMessage['content'] ?? ''); ?>
        </div>
        <?php endif; ?>

        <?php if (empty($allSkills)): ?>
        <div class="flash-msg error">该门派暂无可学习的技能。</div>
        <?php else: ?>
        <div class="learn-potential">
            <strong>潜能：</strong><?php echo $potential; ?> 点
        </div>

        <div class="learn-skill-grid">
            <?php foreach ($allSkills as $skill):
                $isEnabled = ($skill['id'] === $enabledSkill);
            ?>
            <div class="learn-skill-btn<?php echo $isEnabled ? ' enabled' : ''; ?>" id="skill-<?php echo h($skill['id']); ?>">
                <div class="learn-skill-info">
                    <div class="learn-skill-name"><?php echo h($skill['name']); ?></div>
                    <div class="learn-skill-actions">
                        <?php if (!$isEnabled): ?>
                        <button class="learn-btn enable" onclick="enableSkill('<?php echo h($skill['id']); ?>')">设为练习</button>
                        <?php else: ?>
                        <span class="learn-btn enabled-mark">✓ 当前练习</span>
                        <?php endif; ?>
                        <button class="learn-btn practice" onclick="practiceSkill('<?php echo h($skill['id']); ?>')">修炼</button>
                    </div>
                </div>
                <div class="learn-skill-level">Lv.<?php echo intval($skill['level']); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="npc.php?id=<?php echo $npcId; ?>" class="learn-modal-close">返回</a>
    </div>

    <script src="../assets/js/skills_learn.js"></script>
</body>
</html>
    <?php
    exit;
}
// =========================================================
// NPC 学习分支结束
// =========================================================

// 处理学习操作
if ($action === 'learn' && !empty($_POST['skill_id'])) {
    $skillId = $_POST['skill_id'];

    // 检查师徒关系
    if (!ApprenticeHandler::isApprenticeOf($charId, $masterId)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '对方不是你的师父。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 检查是否忙碌
    if (is_player_busy($charId)) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你正忙着呢，无法学习技能。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 检查技能是否存在
    $skillInfo = Database::queryOne('SELECT skill_id, name, type FROM skills WHERE skill_id = ?', [$skillId]);
    if (!$skillInfo) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '该技能不存在。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }
    $skillType = $skillInfo['type'] ?? 'martial';

    // 获取师父信息
    $master = Database::queryOne('SELECT id, name, current_room, sen, max_sen, int AS master_int FROM characters WHERE id = ?', [$masterId]);
    if (!$master) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '师父不存在。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 同一房间检查
    if (($master['current_room'] ?? '') !== ($char['current_room'] ?? '')) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '师父不在你身边。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 师父掌握该技能检查
    $masterLevel = SkillManager::getSkillLevel($masterId, $skillId);
    if ($masterLevel < 1) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '师父还没有掌握这项技能。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 初始门槛：必须已有 ≥1 级
    $playerLevel = SkillManager::getSkillLevel($charId, $skillId);
    if ($playerLevel < 1) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你怎么也弄不明白，需先学会基础才能向玩家师父学习。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 100级硬上限
    if ($playerLevel >= 100) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你跟' . $master['name'] . '已经没办法再指点了，玩家师父教授上限为100级。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 潜能检查
    $playerInfo = Database::queryOne('SELECT potential, learned_points, sen, max_sen, combat_exp, daoxing, int AS player_int FROM characters WHERE id = ?', [$charId]);
    $potential = intval($playerInfo['potential'] ?? 0);
    $learnedPoints = intval($playerInfo['learned_points'] ?? 0);
    $availablePotential = $potential - $learnedPoints;
    if ($availablePotential <= 0) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你的潜能已经耗尽，无法再学习。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 精神消耗：300 / 悟性
    $playerInt = AttributeHelper::queryInt($char);
    $playerInt = max(1, $playerInt);
    $senCost = intval(300 / $playerInt);
    $senCost = max(1, $senCost);

    // 徒弟精神检查
    $playerSen = intval($playerInfo['sen'] ?? 0);
    if ($playerSen < $senCost) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '你的精神不足，无法集中精力学习（需要 ' . $senCost . ' 点精神）。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 师父精神检查
    $masterSenCost = $senCost + 1;
    $masterSen = intval($master['sen'] ?? 0);
    if ($masterSen < $masterSenCost) {
        $_SESSION['flash_message'] = [
            'type' => 'error',
            'content' => '师父精神不济，无法继续指点你。',
            'timestamp' => time()
        ];
        redirect('skills_learn.php?master_id=' . $masterId);
        exit;
    }

    // 经验限制检查
    $combatExp = intval($playerInfo['combat_exp'] ?? 0);
    $daoxing = intval($playerInfo['daoxing'] ?? 0);
    if ($skillType === 'martial') {
        $expNeeded = intval(pow($playerLevel, 3) / 10);
        if ($combatExp < $expNeeded) {
            $_SESSION['flash_message'] = [
                'type' => 'error',
                'content' => '你的战斗经验不足，无法领悟更高深的武技（需要 ' . $expNeeded . ' 点战斗经验）。',
                'timestamp' => time()
            ];
            redirect('skills_learn.php?master_id=' . $masterId);
            exit;
        }
    } elseif ($skillType === 'magic') {
        $expNeeded = intval(pow($playerLevel, 3) / 10);
        if ($daoxing < $expNeeded) {
            $_SESSION['flash_message'] = [
                'type' => 'error',
                'content' => '你的道行不足，无法领悟更高深的法术（需要 ' . $expNeeded . ' 点道行）。',
                'timestamp' => time()
            ];
            redirect('skills_learn.php?master_id=' . $masterId);
            exit;
        }
    }

    // 计算提升量：random(int) + 1
    $amount = mt_rand(0, $playerInt - 1) + 1;

    // 升级判定
    $skillData = Database::queryOne(
        "SELECT level, exp FROM character_skills WHERE char_id = ? AND skill_id = ? LIMIT 1",
        [$charId, $skillId]
    );

    $currentLevel = intval($skillData['level'] ?? $playerLevel);
    $currentExp = intval($skillData['exp'] ?? 0);
    $newExp = $currentExp + $amount;
    $leveledUp = 0;

    $beyondMaster = $currentLevel >= $masterLevel;

    if (!$beyondMaster) {
        $maxLevel = min(100, $masterLevel);
        while ($currentLevel < $maxLevel) {
            $expNeeded = intval(pow($currentLevel + 1, 2));
            if ($newExp <= $expNeeded) {
                break;
            }
            $newExp -= $expNeeded;
            $currentLevel++;
            $leveledUp++;
        }
    }

    // 扣除消耗
    Database::execute(
        "UPDATE characters SET learned_points = learned_points + 1, sen = GREATEST(0, sen - ?) WHERE id = ?",
        [$senCost, $charId]
    );
    Database::execute(
        "UPDATE characters SET sen = GREATEST(0, sen - ?) WHERE id = ?",
        [$masterSenCost, $masterId]
    );

    // 更新技能经验
    if ($skillData) {
        Database::execute(
            "UPDATE character_skills SET level = ?, exp = ? WHERE char_id = ? AND skill_id = ?",
            [$currentLevel, $newExp, $charId, $skillId]
        );
    } else {
        Database::execute(
            "INSERT INTO character_skills (char_id, skill_id, level, exp) VALUES (?, ?, ?, ?)",
            [$charId, $skillId, $currentLevel, $newExp]
        );
    }

    // 设置 busy 1 秒
    set_player_busy($charId, 1);

    // 构建消息
    $skillName = $skillInfo['name'] ?? $skillId;
    $message = '你向' . $master['name'] . '请教' . $skillName . '，';

    if ($leveledUp > 0) {
        $message .= '有所领悟，' . $skillName . '提升到了 ' . $currentLevel . ' 级！';
    } elseif ($beyondMaster) {
        $message .= '你积累了 ' . $amount . ' 点经验，但已超过师父，需要自己修炼才能突破。';
    } else {
        $message .= '略有心得，' . $skillName . '熟练度增加了 ' . $amount . ' 点。';
    }
    $message .= '（消耗潜能 1 点，精神 ' . $senCost . ' 点）';

    // 给师父发消息
    MessageDaemon::sendPrivateMessage(
        $masterId,
        $char['name'] . '向你请教' . $skillName . '，你耐心地指点了' . (intval($char['gender']) === 2 ? '她' : '他') . '一番。（消耗精神 ' . $masterSenCost . ' 点）',
        $charId
    );

    $_SESSION['flash_message'] = [
        'type' => 'success',
        'content' => $message,
        'timestamp' => time()
    ];

    redirect('skills_learn.php?master_id=' . $masterId);
    exit;
}

// 获取页面数据
$master = Database::queryOne('SELECT id, name, family, current_room, sen, max_sen FROM characters WHERE id = ?', [$masterId]);
if (!$master) {
    redirect('room.php');
    exit;
}

// 检查师徒关系
$isMyMaster = ApprenticeHandler::isApprenticeOf($charId, $masterId);

// 玩家悟性
$playerInt = AttributeHelper::queryInt($char);

// 玩家数据
$playerInfo = Database::queryOne('SELECT potential, learned_points, sen, max_sen, combat_exp, daoxing FROM characters WHERE id = ?', [$charId]);
$potential = intval($playerInfo['potential'] ?? 0);
$learnedPoints = intval($playerInfo['learned_points'] ?? 0);
$availablePotential = $potential - $learnedPoints;
$combatExp = intval($playerInfo['combat_exp'] ?? 0);
$daoxing = intval($playerInfo['daoxing'] ?? 0);

// 获取师父的所有技能
$masterSkills = SkillManager::getAllSkills($masterId);

// 过滤可教技能
$teachableSkills = [];
foreach ($masterSkills as $ms) {
    $skillId = $ms['skill_id'] ?? '';
    $masterLevel = intval($ms['level'] ?? 0);
    if (SkillManager::isBaseSkillType($skillId)) {
        continue;
    }
    if ($masterLevel < 1) {
        continue;
    }

    $skillType = $ms['type'] ?? 'martial';
    $playerLevel = SkillManager::getSkillLevel($charId, $skillId);

    $expBlocked = false;
    $expBlockReason = '';
    if ($playerLevel >= 100) {
        $expBlocked = true;
        $expBlockReason = '已达100级硬上限';
    } elseif ($playerLevel < 1) {
        $expBlocked = true;
        $expBlockReason = '需先学会基础(≥1级)';
    } elseif ($skillType === 'martial' && $playerLevel > 0) {
        $expNeeded = intval(pow($playerLevel, 3) / 10);
        if ($combatExp < $expNeeded) {
            $expBlocked = true;
            $expBlockReason = '战斗经验不足(需' . $expNeeded . ')';
        }
    } elseif ($skillType === 'magic' && $playerLevel > 0) {
        $expNeeded = intval(pow($playerLevel, 3) / 10);
        if ($daoxing < $expNeeded) {
            $expBlocked = true;
            $expBlockReason = '道行不足(需' . $expNeeded . ')';
        }
    }

    $beyondMaster = $playerLevel >= $masterLevel;

    $teachableSkills[] = [
        'id' => $skillId,
        'name' => $ms['name'] ?? $skillId,
        'type' => $skillType,
        'master_level' => $masterLevel,
        'player_level' => $playerLevel,
        'exp_blocked' => $expBlocked,
        'exp_block_reason' => $expBlockReason,
        'beyond_master' => $beyondMaster,
    ];
}

// 按技能类型分组
$grouped = [];
foreach ($teachableSkills as $ts) {
    $type = $ts['type'] ?: '其他';
    if (!isset($grouped[$type])) {
        $grouped[$type] = [];
    }
    $grouped[$type][] = $ts;
}

// 读取 flash message
$flashMessage = null;
if (!empty($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>师徒授业 - <?php echo h($master['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/light-theme.css">
    <script src="../assets/js/theme-init.js"></script>
    <link rel="stylesheet" href="../assets/css/footer.css">
    <link rel="stylesheet" href="../assets/css/skills_learn.css">
</head>
<body>
    <div class="learn-modal">
        <h3><?php echo h($master['name']); ?> · 师徒授业 <span><a href="character.php?id=<?php echo $masterId; ?>">返回</a></span></h3>
        <div class="learn-modal-desc">选择你要学习的技能</div>

        <?php if ($flashMessage): ?>
        <div class="flash-msg <?php echo $flashMessage['type'] === 'success' ? 'success' : 'error'; ?>">
            <?php echo h($flashMessage['content']); ?>
        </div>
        <?php endif; ?>

        <?php if (!$isMyMaster): ?>
        <div class="flash-msg error">
            对方不是你的师父，无法学习技能。
        </div>
        <?php elseif (empty($teachableSkills)): ?>
        <div class="flash-msg error">
            师父目前没有可以传授给你的技能。
        </div>
        <?php else: ?>
        <div class="learn-potential">
            <strong>可用潜能：</strong><?php echo $availablePotential; ?> 点（每次学习消耗 1 点）
        </div>
        <div class="learn-stats">
            你的精神：<span><?php echo intval($playerInfo['sen'] ?? 0); ?></span>
            &nbsp;|&nbsp; 师父精神：<span><?php echo intval($master['sen'] ?? 0); ?></span>
            <br>
            玩家师父教授上限：<span>100级</span>，超过师父等级只能积累经验。
        </div>

        <div class="learn-skill-grid">
            <?php foreach ($grouped as $type => $skills): ?>
            <?php foreach ($skills as $skill):
                $isBeyond = $skill['beyond_master'];
                $isBlocked = $skill['exp_blocked'];
                $canLearn = !$isBlocked && $availablePotential > 0 && $isMyMaster;
            ?>
            <div class="learn-skill-btn<?php echo $isBeyond ? ' beyond' : ''; ?><?php echo ($isBlocked || !$canLearn) ? ' disabled' : ''; ?>"
                 onclick="<?php echo $canLearn ? "learnSkill('" . h($skill['id']) . "')" : ''; ?>">
                <div class="learn-skill-info">
                    <div class="learn-skill-name"><?php echo h($skill['name']); ?></div>
                    <div class="learn-skill-desc<?php echo $isBlocked ? ' blocked' : ($isBeyond ? ' beyond' : ''); ?>">
                        <?php if ($isBlocked): ?>
                            <?php echo h($skill['exp_block_reason']); ?>
                        <?php elseif ($isBeyond): ?>
                            已超过师父（只积累不升级）
                        <?php else: ?>
                            点击向师父请教
                        <?php endif; ?>
                    </div>
                </div>
                <div>
                    <div class="learn-skill-level">Lv.<?php echo $skill['player_level']; ?></div>
                    <div class="learn-skill-level master-level">师 Lv.<?php echo $skill['master_level']; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="character.php?id=<?php echo $masterId; ?>" class="learn-modal-close">返回</a>
    </div>

    <form id="learn-form" method="post" action="skills_learn.php" style="display:none;">
        <input type="hidden" name="action" value="learn">
        <input type="hidden" name="master_id" value="<?php echo $masterId; ?>">
        <input type="hidden" name="skill_id" id="learn-skill-id" value="">
    </form>

    <script src="../assets/js/skills_learn.js"></script>
</body>
</html>
