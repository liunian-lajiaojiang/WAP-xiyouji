<?php
/**
 * 取经进度查询页面
 */

session_save_path(__DIR__ . '/../sessions');
session_start();
require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['char_id'])) {
    header('Location: /login.php');
    exit;
}

$charId = $_SESSION['char_id'];

// 获取角色信息
$char = Database::queryOne(
    "SELECT c.*, q.quest_id, q.status, q.start_time, q.complete_time 
     FROM characters c 
     LEFT JOIN character_quests q ON c.id = q.char_id AND q.quest_type = 'qujing_escort' 
     WHERE c.id = ?",
    [$charId]
);

if (!$char) {
    die('角色不存在');
}

require_once __DIR__ . '/../daemons/QujingHandler.php';
$allQuests = QujingHandler::getAllQuests();
$currentQuest = $char['quest_id'] ?? 'yingchou';
$questStatus = $char['status'] ?? null;

// 获取已完成关卡
$completedQuests = Database::queryAll(
    "SELECT quest_id, complete_time, reward FROM qujing_history WHERE char_id = ?",
    [$charId]
);
$completedIds = array_column($completedQuests, 'quest_id');

// 计算进度
$completedCount = count($completedIds);
$totalCount = count($allQuests);
$progress = $totalCount > 0 ? round($completedCount / $totalCount * 100) : 0;

// 获取obstacled状态
$obstacled = Database::queryOne("SELECT * FROM obstacled WHERE id = 1");

// 计算总奖励道行
$totalReward = array_sum(array_column($completedQuests, 'reward'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>取经进度 - 西游记</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="../assets/css/qujing_progress.css">
</head>
<body>
    <div class="container">
        <h1>西天取经进度</h1>
        
        <div class="status-box">
            <h2>当前状态</h2>
            <div class="info-row">
                <span>角色名称</span>
                <span><?php echo htmlspecialchars($char['name']); ?></span>
            </div>
            <div class="info-row">
                <span>当前道行</span>
                <span><?php echo number_format($char['daoxing']); ?> 年</span>
            </div>
            <div class="info-row">
                <span>取经状态</span>
                <span>
                    <?php if ($questStatus === 'active'): ?>
                        <span class="status-tag tag-current">护送中</span>
                    <?php elseif (in_array('lingshan', $completedIds)): ?>
                        <span class="status-tag tag-done">取经完成</span>
                    <?php else: ?>
                        未开始
                    <?php endif; ?>
                </span>
            </div>
            <div class="info-row">
                <span>已通关</span>
                <span><?php echo $completedCount; ?> / <?php echo $totalCount; ?> 关</span>
            </div>
            
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $progress; ?>%">
                    <?php echo $progress; ?>%
                </div>
            </div>
        </div>
        
        <?php if ($completedCount > 0): ?>
        <div class="reward-box">
            <p>取经累计奖励</p>
            <p class="total-reward"><?php echo number_format($totalReward); ?> 年道行</p>
        </div>
        <?php endif; ?>
        
        <h2>取经关卡列表</h2>
        <div class="quest-grid">
            <?php foreach ($allQuests as $quest): ?>
                <?php 
                $isCompleted = in_array($quest['id'], $completedIds);
                $isCurrent = ($quest['id'] === $currentQuest && $questStatus === 'active');
                $class = $isCompleted ? 'completed' : ($isCurrent ? 'current' : '');
                ?>
                <div class="quest-item <?php echo $class; ?>">
                    <div class="quest-name">
                        <?php echo htmlspecialchars($quest['name']); ?>
                        <?php if ($isCompleted): ?>
                            <span class="status-tag tag-done">已完成</span>
                        <?php elseif ($isCurrent): ?>
                            <span class="status-tag tag-current">当前</span>
                        <?php endif; ?>
                    </div>
                    <div class="quest-daoxing">道行要求: <?php echo number_format($quest['min_daoxing']); ?>年</div>
                    <?php 
                    $def = QujingHandler::getQuestDefinition($quest['id']);
                    if ($def): ?>
                    <div class="quest-reward">奖励: +<?php echo number_format($def['reward']); ?>年</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <h2>取经说明</h2>
        <div class="status-box">
            <p>西天取经是西游记中最具挑战性的任务，需要护送唐僧经历<b>28个关卡</b>才能到达灵山。</p>
            <p><b>任务触发：</b>前往皇宫大殿，等待皇帝下旨招募护送武士，然后向唐僧申请护送。</p>
            <p><b>任务要求：</b>道行需达到当前关卡要求，且不能是妖魔职业。</p>
            <p><b>完成奖励：</b></p>
            <ul>
                <li>每关完成后获得道行奖励</li>
                <li>完成所有关卡后，可向如来佛祖领取终极奖励：
                    <ul>
                        <li>潜能点 10000-30000</li>
                        <li>所有技能+1级</li>
                        <li>救命毫毛三根（可复活3次）</li>
                        <li>无字真经（基本武功可升至200级）</li>
                    </ul>
                </li>
            </ul>
        </div>
        
        <a href="/functions/room.php?area=city&room=entrance" class="back-link">&larr; 返回房间</a>
    </div>
</body>
</html>
