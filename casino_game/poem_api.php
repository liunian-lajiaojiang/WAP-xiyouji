<?php
session_save_path(__DIR__ . '/../sessions');
session_start();

/**
 * 乐府诗社 AJAX 后端 API
 *
 * 猜诗游戏 + 懒推进状态机
 * 移植自 LPC: d/city/clubpoem.c
 *
 * 接口:
 *   GET  poem_api.php?action=status   → 获取当前题目状态（含懒推进）
 *   POST poem_api.php?action=answer   → 答题
 *
 * 状态机:
 *   每轮题目存活 60 秒（移植自 LPC call_out("do_test",60)）
 *   到时自动生成新题，旧题保留为"上一题"仍可作答
 *   最多保留 2 道题（当前题 + 上一题）
 *
 * 奖励机制（移植自 LPC poem_reward1）:
 *   随机三选一: 道行+4~9 / 潜能+3~6 / 读书识字+4~9
 *
 * 诗词打乱算法（移植自 LPC mixup）:
 *   随机交换 1~2 组字符对的位置
 */

require_once __DIR__ . '/../config/game.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/db.php';
require_once MODEL_PATH . 'Character.php';
require_once HELPER_PATH . 'MoneyHelper.php';
require_once HELPER_PATH . 'SkillManager.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
$charId = get_char_id();
$char = CharacterModel::getFullInfo($charId);
if (!$char) {
    echo json_encode(['success' => false, 'message' => '角色不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── 常量 ──────────────────────────────────────────────
const QUESTION_DURATION = 60;    // 每题存活秒数 — LPC: call_out("do_test",60)
const MAX_WRONG_ATTEMPTS = 10;   // 答错上限 — LPC: poem/wrong>10 → poem_penalty
const POEM_COUNT = 319;          // 诗词总数 — LPC: POEMS=319

// ─── 自动建表 ──────────────────────────────────────────
ensureTablesExist();

// ─── 辅助函数 ──────────────────────────────────────────

/**
 * 确保 poems, poem_rounds, poem_answers 表存在
 */
function ensureTablesExist(): void {
    // poems 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'poems'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `poems` (
            `id` int NOT NULL AUTO_INCREMENT,
            `author` varchar(100) NOT NULL COMMENT '作者',
            `title` varchar(200) NOT NULL COMMENT '诗题',
            `content` text NOT NULL COMMENT '诗句JSON数组',
            `line_count` int NOT NULL DEFAULT 0 COMMENT '诗句行数',
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='乐府诗社诗词库'");
    }

    // poem_rounds 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'poem_rounds'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `poem_rounds` (
            `id` int NOT NULL AUTO_INCREMENT,
            `poem_id` int NOT NULL COMMENT '诗词ID',
            `poem_author` varchar(100) NULL COMMENT '作者(冗余)',
            `poem_title` varchar(200) NULL COMMENT '诗题(冗余)',
            `poem_content` text NULL COMMENT '完整诗词JSON(冗余)',
            `line_index` int NOT NULL COMMENT '选中行索引',
            `first_part` varchar(200) NOT NULL COMMENT '上句',
            `second_part` varchar(200) NOT NULL COMMENT '下句',
            `answer` varchar(400) NOT NULL COMMENT '正确答案(去空格逗号)',
            `scrambled` varchar(400) NOT NULL COMMENT '打乱后的题目',
            `is_answered` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已答对',
            `answered_by` varchar(100) NULL COMMENT '答对者姓名',
            `is_current` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=当前题 0=上一题',
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_current` (`is_current`),
            INDEX `idx_poem` (`poem_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='诗社猜诗轮次'");
    }

    // poem_answers 表
    $exists = Database::queryOne(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'poem_answers'"
    );
    if (!$exists) {
        Database::execute("CREATE TABLE `poem_answers` (
            `id` int NOT NULL AUTO_INCREMENT,
            `round_id` int NOT NULL,
            `char_id` int NOT NULL,
            `char_name` varchar(100) NULL,
            `answer_text` varchar(400) NOT NULL,
            `is_correct` tinyint(1) NOT NULL DEFAULT 0,
            `reward_type` varchar(20) NULL COMMENT 'daoxing/potential/literate',
            `reward_amount` int NULL,
            `created_at` datetime NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_round` (`round_id`),
            INDEX `idx_char` (`char_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='诗社答题记录'");
    }
}

/**
 * 诗词打乱算法 (移植自 LPC mixup)
 *
 * LPC 原始逻辑: 随机交换 1~2 组字符对的位置
 * LPC 使用字节级操作（GB2312 每个汉字2字节）
 * PHP 使用 UTF-8 mb_* 函数处理多字节字符
 *
 * @param string $str 原始诗句
 * @return string 打乱后的诗句
 */
function mixup(string $str): string {
    // 将字符串拆为字符数组（UTF-8安全）
    $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
    $len = count($chars);

    if ($len < 2) return $str;

    $iterations = mt_rand(1, 2);
    for ($i = 0; $i < $iterations; $i++) {
        $j = mt_rand(0, $len - 1);
        $k = mt_rand(0, $len - 1);

        if ($j === $k) {
            $k = ($k + 1) % $len;
        }

        // 确保 j < k
        if ($j > $k) {
            $temp = $k;
            $k = $j;
            $j = $temp;
        }

        // 交换位置 j 和 k 的字符
        $temp = $chars[$j];
        $chars[$j] = $chars[$k];
        $chars[$k] = $temp;
    }

    return implode('', $chars);
}

/**
 * 从诗词库中随机选一首诗并生成题目
 * 移植自 LPC new_poem + do_test 中的出题逻辑
 *
 * @return array|null 题目数据，无有效行返回 null
 */
function generateQuestion(): ?array {
    // 随机选一首诗（避免连续选同一首）
    $lastRound = Database::queryOne("SELECT poem_id FROM poem_rounds ORDER BY id DESC LIMIT 1");
    $lastPoemId = $lastRound ? (int)$lastRound['poem_id'] : 0;

    $maxAttempts = 10;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $poemId = mt_rand(1, POEM_COUNT);

        // 尝试从数据库读取
        $poem = Database::queryOne("SELECT * FROM poems WHERE id = ?", [$poemId]);
        if (!$poem) continue;

        $lines = json_decode($poem['content'], true);
        if (!$lines || !is_array($lines)) continue;

        // 查找有效行: 有 "  " 分隔，无特殊字符，上下句均 >2 字符
        $validLines = [];
        foreach ($lines as $idx => $line) {
            if (strpos($line, '  ') === false) continue;
            // 跳过含特殊标记的行 (LPC: ［ （ □)
            if (strpos($line, '［') !== false) continue;
            if (strpos($line, '（') !== false) continue;
            if (strpos($line, '□') !== false) continue;

            $parts = explode('  ', $line, 2);
            $first = trim($parts[0]);
            $second = trim($parts[1] ?? '');

            if (mb_strlen($first) > 2 && mb_strlen($second) > 2) {
                $validLines[] = [
                    'index' => $idx,
                    'first' => $first,
                    'second' => $second,
                ];
            }
        }

        if (empty($validLines)) continue;

        // 随机选一行
        $selected = $validLines[array_rand($validLines)];
        $first = $selected['first'];
        $second = $selected['second'];

        // 决定用哪部分作为题目 (移植自 LPC do_test 出题逻辑)
        // LPC strlen 是字节长度，GB2312 每字2字节，所以 >=14 即 >=7 字符
        // PHP mb_strlen 返回字符数，所以 >=7
        $quest = '';
        if (mb_strlen($first) >= 7 && mt_rand(0, 2) === 0) {
            $quest = $first;
        } elseif (mb_strlen($second) >= 7 && mt_rand(0, 1) === 0) {
            $quest = $second;
        } else {
            $quest = $first . $second;
        }

        // 答案 = 去掉逗号 (LPC: replace_string(quest,"，",""))
        $answer = str_replace(['，', ','], '', $quest);

        // 打乱 (LPC: quest=mixup(quest))
        $scrambled = mixup($quest);

        return [
            'poem_id' => (int)$poem['id'],
            'poem_author' => $poem['author'],
            'poem_title' => $poem['title'],
            'poem_content' => $poem['content'],
            'line_index' => $selected['index'],
            'first_part' => $first,
            'second_part' => $second,
            'answer' => $answer,
            'scrambled' => $scrambled,
        ];
    }

    return null;
}

/**
 * 创建新轮次（新题目）
 * 移植自 LPC do_test: 生成新题，旧题变为 last
 */
function createNewRound(): ?array {
    $question = generateQuestion();
    if (!$question) return null;

    // 旧题标记为非当前
    Database::execute("UPDATE poem_rounds SET is_current = 0 WHERE is_current = 1");

    // 插入新题
    Database::execute(
        "INSERT INTO poem_rounds (poem_id, poem_author, poem_title, poem_content,
                                   line_index, first_part, second_part, answer, scrambled,
                                   is_current, is_answered, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())",
        [
            $question['poem_id'],
            $question['poem_author'],
            $question['poem_title'],
            $question['poem_content'],
            $question['line_index'],
            $question['first_part'],
            $question['second_part'],
            $question['answer'],
            $question['scrambled'],
        ]
    );

    $id = Database::lastInsertId();
    return Database::queryOne("SELECT * FROM poem_rounds WHERE id = ?", [$id]);
}

/**
 * 懒推进状态机
 *
 * 移植自 LPC do_test 的 call_out("do_test",60) 机制:
 *   - 每题存活 60 秒
 *   - 到时自动生成新题
 *   - 旧题保留为"上一题"(is_current=0)，仍可作答
 */
function advanceRound(): void {
    $current = Database::queryOne("SELECT * FROM poem_rounds WHERE is_current = 1 ORDER BY id DESC LIMIT 1");

    if (!$current) {
        // 无题目，创建第一题
        createNewRound();
        return;
    }

    $elapsed = time() - strtotime($current['created_at']);
    if ($elapsed >= QUESTION_DURATION) {
        createNewRound();
    }
}

/**
 * 奖励机制 (移植自 LPC poem_reward1)
 *
 * 随机三选一:
 *   - 道行 +4~9 (LPC: dx=4+random(6))
 *   - 潜能 +3~6 (LPC: pot=3+random(4))
 *   - 读书识字 +4~9 (LPC: lite=4+random(6), improve_skill("literate",lite))
 *
 * @param int $charId
 * @param string $charName
 * @return array ['type' => string, 'amount' => int, 'message' => string]
 */
function grantReward(int $charId, string $charName): array {
    $rewardType = mt_rand(0, 2);

    switch ($rewardType) {
        case 0:
            // 道行 +4~9 (LPC: dx=4+random(6))
            $dx = 4 + mt_rand(0, 5);
            Database::execute("UPDATE characters SET daoxing = daoxing + ? WHERE id = ?", [$dx, $charId]);
            return [
                'type' => 'daoxing',
                'amount' => $dx,
                'message' => $charName . '的道行增加了！(+' . $dx . ')',
            ];

        case 1:
            // 潜能 +3~6 (LPC: pot=3+random(4))
            // LPC: 检查 potential+pot-learned_points <= 100 才加
            $pot = 3 + mt_rand(0, 3);
            $char = Database::queryOne("SELECT potential, learned_points FROM characters WHERE id = ?", [$charId]);
            if ($char && ($char['potential'] + $pot - $char['learned_points']) <= 100) {
                Database::execute("UPDATE characters SET potential = potential + ? WHERE id = ?", [$pot, $charId]);
                return [
                    'type' => 'potential',
                    'amount' => $pot,
                    'message' => $charName . '的潜能增加了！(+' . $pot . ')',
                ];
            } else {
                // 潜能超限，改为加道行作为补偿
                $dx = 4 + mt_rand(0, 5);
                Database::execute("UPDATE characters SET daoxing = daoxing + ? WHERE id = ?", [$dx, $charId]);
                return [
                    'type' => 'daoxing',
                    'amount' => $dx,
                    'message' => $charName . '的道行增加了！(+' . $dx . ')',
                ];
            }

        case 2:
            // 读书识字 +4~9 (LPC: lite=4+random(6), improve_skill("literate",lite))
            // 修正：使用 improveSkillOriginal 直接加经验，而非 improveSkill（升级函数）
            $lite = 4 + mt_rand(0, 5);
            $result = SkillManager::improveSkillOriginal($charId, 'literate', $lite, false);
            return [
                'type' => 'literate',
                'amount' => $lite,
                'message' => $charName . '的读书识字进步了！(+' . $lite . ')',
            ];
    }

    return ['type' => 'none', 'amount' => 0, 'message' => ''];
}

// ─── 主逻辑 ────────────────────────────────────────────

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// === 获取状态 ===
if ($action === 'status') {
    // 懒推进
    advanceRound();

    // 获取当前题
    $current = Database::queryOne("SELECT * FROM poem_rounds WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
    if (!$current) {
        echo json_encode(['success' => false, 'message' => '初始化中，请稍候'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 获取上一题
    $previous = Database::queryOne(
        "SELECT * FROM poem_rounds WHERE is_current = 0 ORDER BY id DESC LIMIT 1"
    );

    // 计算倒计时
    $elapsed = time() - strtotime($current['created_at']);
    $remaining = max(0, QUESTION_DURATION - $elapsed);

    // 获取完整诗词内容
    $poemContent = json_decode($current['poem_content'] ?? '[]', true) ?: [];

    // 构建响应
    $roundData = [
        'id' => (int)$current['id'],
        'scrambled' => $current['scrambled'],
        'isAnswered' => (bool)$current['is_answered'],
        'answeredBy' => $current['answered_by'],
        'remaining' => $remaining,
        'poemAuthor' => $current['poem_author'],
        'poemTitle' => $current['poem_title'],
        'poemContent' => $poemContent,
    ];

    // 如果当前题已答对，显示答案
    if ($current['is_answered']) {
        $roundData['answer'] = $current['answer'];
        $roundData['firstPart'] = $current['first_part'];
        $roundData['secondPart'] = $current['second_part'];
    }

    // 上一题信息（仍可作答）
    $previousData = null;
    if ($previous && !$previous['is_answered']) {
        $previousData = [
            'id' => (int)$previous['id'],
            'scrambled' => $previous['scrambled'],
            'isAnswered' => false,
        ];
    } elseif ($previous && $previous['is_answered']) {
        $previousData = [
            'id' => (int)$previous['id'],
            'scrambled' => $previous['scrambled'],
            'isAnswered' => true,
            'answeredBy' => $previous['answered_by'],
            'answer' => $previous['answer'],
        ];
    }

    // 获取当前玩家答错次数
    // LPC: 答对后 poem/wrong 重置为0，这里只统计最近一次答对之后的答错次数
    $lastCorrect = Database::queryOne(
        "SELECT created_at FROM poem_answers WHERE char_id = ? AND is_correct = 1 ORDER BY id DESC LIMIT 1",
        [$charId]
    );
    $wrongSince = $lastCorrect ? $lastCorrect['created_at'] : '1970-01-01 00:00:00';
    $wrongCount = Database::queryOne(
        "SELECT COUNT(*) as cnt FROM poem_answers WHERE char_id = ? AND is_correct = 0 AND created_at > ?",
        [$charId, $wrongSince]
    );
    $wrongAttempts = $wrongCount ? (int)$wrongCount['cnt'] : 0;

    $response = [
        'success' => true,
        'current' => $roundData,
        'previous' => $previousData,
        'wrongAttempts' => $wrongAttempts,
        'maxWrongAttempts' => MAX_WRONG_ATTEMPTS,
        'charName' => $char['name'],
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// === 答题 ===
if ($action === 'answer') {
    $answerText = trim($_POST['answer'] ?? '');
    $roundId = intval($_POST['round_id'] ?? 0);

    if (empty($answerText)) {
        echo json_encode(['success' => false, 'message' => '回答什么？'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 房间检查：必须在乐府诗社才能答题 (LPC: add_action 仅在 clubpoem 房间注册)
    if ($char['current_room'] !== 'city/clubpoem') {
        echo json_encode(['success' => false, 'message' => '你要去乐府诗社才能参与猜诗。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 茶博士NPC存在性检查 (LPC: present("cha boshi",this_object()) && living(ob))
    $teaWaiter = Database::queryOne(
        "SELECT id FROM npcs WHERE name = '茶博士' AND current_room = 'city/clubpoem' LIMIT 1"
    );
    if (!$teaWaiter) {
        echo json_encode(['success' => false, 'message' => '现在没有人裁判对错了．．．'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 答题消耗神识 (LPC: me->receive_damage("sen",5+random(15)))
    $senDamage = 5 + mt_rand(0, 15);
    Database::execute("UPDATE characters SET sen = sen - ? WHERE id = ?", [$senDamage, $charId]);

    // 懒推进
    advanceRound();

    // 查找题目（当前题或上一题）
    $round = Database::queryOne("SELECT * FROM poem_rounds WHERE id = ?", [$roundId]);
    if (!$round) {
        echo json_encode(['success' => false, 'message' => '题目不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 清理玩家输入 (LPC: replace_string(arg," ",""); replace_string(arg,",",""); replace_string(arg,"，",""))
    $cleanAnswer = str_replace([' ', ',', '，'], '', $answerText);

    // 检查是否已答对
    if ($round['is_answered']) {
        echo json_encode(['success' => false, 'message' => '别人已经回答过这句诗了。'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 比较答案 (LPC: arg==current && strlen(current)>2)
    $correctAnswer = $round['answer'];
    $isCorrect = ($cleanAnswer === $correctAnswer && mb_strlen($correctAnswer) > 2);

    $charName = $char['name'];

    if ($isCorrect) {
        // 答对了!
        Database::beginTransaction();
        try {
            // 标记题目为已答对
            Database::execute(
                "UPDATE poem_rounds SET is_answered = 1, answered_by = ? WHERE id = ? AND is_answered = 0",
                [$charName, $round['id']]
            );

            // 检查是否真的更新了（防止并发）
            $affected = Database::queryOne("SELECT is_answered, answered_by FROM poem_rounds WHERE id = ?", [$round['id']]);
            if (!$affected || !$affected['is_answered'] || $affected['answered_by'] !== $charName) {
                Database::rollBack();
                echo json_encode(['success' => false, 'message' => '别人已经回答过这句诗了。'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // 发放奖励
            $reward = grantReward($charId, $charName);

            // 记录答题
            Database::execute(
                "INSERT INTO poem_answers (round_id, char_id, char_name, answer_text, is_correct, reward_type, reward_amount, created_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, NOW())",
                [$round['id'], $charId, $charName, $answerText, $reward['type'], $reward['amount']]
            );

            // 统计总答对次数 (LPC: me->add("poem_answered",1))
            Database::execute("UPDATE characters SET poem_answered = poem_answered + 1 WHERE id = ?", [$charId]);

            Database::commit();
        } catch (Exception $e) {
            Database::rollBack();
            error_log('诗社答题失败: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => '系统错误，请重试'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'correct' => true,
            'message' => '茶博士点头道："' . $round['first_part'] . '  ' . $round['second_part'] . '"不错！不错！',
            'reward' => $reward,
            'answer' => $correctAnswer,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        // 答错了
        Database::execute(
            "INSERT INTO poem_answers (round_id, char_id, char_name, answer_text, is_correct, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())",
            [$round['id'], $charId, $charName, $answerText]
        );

        // 检查答错次数 (LPC: poem/wrong > 10 → poem_penalty)
        // LPC: 答对后 poem/wrong 重置为0，这里只统计最近一次答对之后的答错次数
        $lastCorrect = Database::queryOne(
            "SELECT created_at FROM poem_answers WHERE char_id = ? AND is_correct = 1 ORDER BY id DESC LIMIT 1",
            [$charId]
        );
        $wrongSince = $lastCorrect ? $lastCorrect['created_at'] : '1970-01-01 00:00:00';
        $wrongCount = Database::queryOne(
            "SELECT COUNT(*) as cnt FROM poem_answers WHERE char_id = ? AND is_correct = 0 AND created_at > ?",
            [$charId, $wrongSince]
        );
        $wrongAttempts = $wrongCount ? (int)$wrongCount['cnt'] : 0;

        $message = '茶博士摇了摇头：好象不对吧？';
        if ($wrongAttempts > MAX_WRONG_ATTEMPTS) {
            // 惩罚: 神识 -1 (LPC: me->set("sen",-1))
            Database::execute("UPDATE characters SET sen = -1 WHERE id = ?", [$charId]);
            $message = '茶博士摇头道：你今日答错太多了，歇歇吧。';
        }

        echo json_encode([
            'success' => true,
            'correct' => false,
            'message' => $message,
            'wrongAttempts' => $wrongAttempts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 未知操作
echo json_encode(['success' => false, 'message' => '无效的操作'], JSON_UNESCAPED_UNICODE);
