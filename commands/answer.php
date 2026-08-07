<?php
/**
 * 答诗命令 (answer)
 * 移植自 LPC: d/city/clubpoem.c do_answer()
 *
 * 用法: answer <原句>
 * 必须在乐府诗社(city/clubpoem)房间且茶博士在场时使用
 *
 * 游戏流程:
 *   茶博士每60秒在墙上写一句打乱的诗句
 *   玩家用 answer 命令回答原句
 *   答对获得道行/潜能/读书识字奖励，答错消耗神识
 */

require_once HELPER_PATH . 'SkillManager.php';

function cmd_answer(int $charId, string $param = ''): array {
    $char = CharacterModel::find($charId);

    if (!$char) {
        return ['success' => false, 'message' => '角色不存在'];
    }

    if (empty($param)) {
        return ['success' => false, 'message' => '回答什么？'];
    }

    // 房间检查：必须在乐府诗社 (LPC: add_action 仅在 clubpoem 房间注册)
    if ($char['current_room'] !== 'city/clubpoem') {
        return ['success' => false, 'message' => '你要去乐府诗社才能参与猜诗。'];
    }

    // 茶博士NPC存在性检查 (LPC: present("cha boshi",this_object()) && living(ob))
    $teaWaiter = Database::queryOne(
        "SELECT id FROM npcs WHERE name = '茶博士' AND current_room = 'city/clubpoem' LIMIT 1"
    );
    if (!$teaWaiter) {
        return ['success' => false, 'message' => '现在没有人裁判对错了．．． ：（'];
    }

    // 答题消耗神识 (LPC: me->receive_damage("sen",5+random(15)))
    $senDamage = 5 + mt_rand(0, 15);
    Database::execute("UPDATE characters SET sen = sen - ? WHERE id = ?", [$senDamage, $charId]);

    // 懒推进：检查是否需要生成新题
    $current = Database::queryOne("SELECT * FROM poem_rounds WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
    if (!$current) {
        // 无题目，手动创建第一题
        $lastRound = Database::queryOne("SELECT poem_id FROM poem_rounds ORDER BY id DESC LIMIT 1");
        $lastPoemId = $lastRound ? (int)$lastRound['poem_id'] : 0;
        $maxAttempts = 10;
        $question = null;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $poemId = mt_rand(1, 319);
            $poem = Database::queryOne("SELECT * FROM poems WHERE id = ?", [$poemId]);
            if (!$poem) continue;
            $lines = json_decode($poem['content'], true);
            if (!$lines || !is_array($lines)) continue;
            $validLines = [];
            foreach ($lines as $idx => $line) {
                if (strpos($line, '  ') === false) continue;
                if (strpos($line, '［') !== false) continue;
                if (strpos($line, '（') !== false) continue;
                if (strpos($line, '□') !== false) continue;
                $parts = explode('  ', $line, 2);
                $first = trim($parts[0]);
                $second = trim($parts[1] ?? '');
                if (mb_strlen($first) > 2 && mb_strlen($second) > 2) {
                    $validLines[] = ['index' => $idx, 'first' => $first, 'second' => $second];
                }
            }
            if (empty($validLines)) continue;
            $selected = $validLines[array_rand($validLines)];
            $first = $selected['first'];
            $second = $selected['second'];
            $quest = '';
            if (mb_strlen($first) >= 7 && mt_rand(0, 2) === 0) {
                $quest = $first;
            } elseif (mb_strlen($second) >= 7 && mt_rand(0, 1) === 0) {
                $quest = $second;
            } else {
                $quest = $first . $second;
            }
            $answer = str_replace(['，', ','], '', $quest);
            // 打乱 (mixup)
            $chars = preg_split('//u', $quest, -1, PREG_SPLIT_NO_EMPTY);
            $len = count($chars);
            if ($len >= 2) {
                $iterations = mt_rand(1, 2);
                for ($i = 0; $i < $iterations; $i++) {
                    $j = mt_rand(0, $len - 1);
                    $k = mt_rand(0, $len - 1);
                    if ($j === $k) $k = ($k + 1) % $len;
                    if ($j > $k) { $temp = $k; $k = $j; $j = $temp; }
                    $temp = $chars[$j];
                    $chars[$j] = $chars[$k];
                    $chars[$k] = $temp;
                }
            }
            $scrambled = implode('', $chars);
            $question = [
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
            break;
        }
        if ($question) {
            Database::execute("UPDATE poem_rounds SET is_current = 0 WHERE is_current = 1");
            Database::execute(
                "INSERT INTO poem_rounds (poem_id, poem_author, poem_title, poem_content,
                                           line_index, first_part, second_part, answer, scrambled,
                                           is_current, is_answered, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())",
                [$question['poem_id'], $question['poem_author'], $question['poem_title'],
                 $question['poem_content'], $question['line_index'], $question['first_part'],
                 $question['second_part'], $question['answer'], $question['scrambled']]
            );
            $current = Database::queryOne("SELECT * FROM poem_rounds WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
        }
    } else {
        // 检查是否超时
        $elapsed = time() - strtotime($current['created_at']);
        if ($elapsed >= 60) {
            // 生成新题
            Database::execute("UPDATE poem_rounds SET is_current = 0 WHERE is_current = 1");
            $lastPoemId = (int)$current['poem_id'];
            $maxAttempts = 10;
            $question = null;
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $poemId = mt_rand(1, 319);
                $poem = Database::queryOne("SELECT * FROM poems WHERE id = ?", [$poemId]);
                if (!$poem) continue;
                $lines = json_decode($poem['content'], true);
                if (!$lines || !is_array($lines)) continue;
                $validLines = [];
                foreach ($lines as $idx => $line) {
                    if (strpos($line, '  ') === false) continue;
                    if (strpos($line, '［') !== false) continue;
                    if (strpos($line, '（') !== false) continue;
                    if (strpos($line, '□') !== false) continue;
                    $parts = explode('  ', $line, 2);
                    $first = trim($parts[0]);
                    $second = trim($parts[1] ?? '');
                    if (mb_strlen($first) > 2 && mb_strlen($second) > 2) {
                        $validLines[] = ['index' => $idx, 'first' => $first, 'second' => $second];
                    }
                }
                if (empty($validLines)) continue;
                $selected = $validLines[array_rand($validLines)];
                $first = $selected['first'];
                $second = $selected['second'];
                $quest = '';
                if (mb_strlen($first) >= 7 && mt_rand(0, 2) === 0) {
                    $quest = $first;
                } elseif (mb_strlen($second) >= 7 && mt_rand(0, 1) === 0) {
                    $quest = $second;
                } else {
                    $quest = $first . $second;
                }
                $answer = str_replace(['，', ','], '', $quest);
                $chars = preg_split('//u', $quest, -1, PREG_SPLIT_NO_EMPTY);
                $len = count($chars);
                if ($len >= 2) {
                    $iterations = mt_rand(1, 2);
                    for ($i = 0; $i < $iterations; $i++) {
                        $j = mt_rand(0, $len - 1);
                        $k = mt_rand(0, $len - 1);
                        if ($j === $k) $k = ($k + 1) % $len;
                        if ($j > $k) { $temp = $k; $k = $j; $j = $temp; }
                        $temp = $chars[$j];
                        $chars[$j] = $chars[$k];
                        $chars[$k] = $temp;
                    }
                }
                $scrambled = implode('', $chars);
                $question = [
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
                break;
            }
            if ($question) {
                Database::execute(
                    "INSERT INTO poem_rounds (poem_id, poem_author, poem_title, poem_content,
                                               line_index, first_part, second_part, answer, scrambled,
                                               is_current, is_answered, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW())",
                    [$question['poem_id'], $question['poem_author'], $question['poem_title'],
                     $question['poem_content'], $question['line_index'], $question['first_part'],
                     $question['second_part'], $question['answer'], $question['scrambled']]
                );
                $current = Database::queryOne("SELECT * FROM poem_rounds WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
            }
        }
    }

    if (!$current) {
        return ['success' => false, 'message' => '暂时没有题目，请稍候。'];
    }

    // 获取上一题
    $previous = Database::queryOne("SELECT * FROM poem_rounds WHERE is_current = 0 ORDER BY id DESC LIMIT 1");

    // 清理玩家输入 (LPC: replace_string(arg," ",""); replace_string(arg,",",""); replace_string(arg,"，",""))
    $cleanAnswer = str_replace([' ', ',', '，'], '', $param);

    $charName = $char['name'];

    // 随机说话方式 (LPC: "说道" 或 "答道")
    $sayVerb = mt_rand(0, 1) === 0 ? '说道' : '答道';

    // 先尝试匹配当前题
    $matchedRound = null;
    if (!$current['is_answered']) {
        $correctAnswer = $current['answer'];
        if ($cleanAnswer === $correctAnswer && mb_strlen($correctAnswer) > 2) {
            $matchedRound = $current;
        }
    }

    // 如果当前题没匹配，尝试上一题 (LPC: arg==last)
    if (!$matchedRound && $previous && !$previous['is_answered']) {
        $correctAnswer = $previous['answer'];
        if ($cleanAnswer === $correctAnswer && mb_strlen($correctAnswer) > 2) {
            $matchedRound = $previous;
        }
    }

    if ($matchedRound) {
        // 答对了!
        Database::beginTransaction();
        try {
            // 标记题目为已答对
            Database::execute(
                "UPDATE poem_rounds SET is_answered = 1, answered_by = ? WHERE id = ? AND is_answered = 0",
                [$charName, $matchedRound['id']]
            );

            // 检查是否真的更新了（防止并发）
            $affected = Database::queryOne("SELECT is_answered, answered_by FROM poem_rounds WHERE id = ?", [$matchedRound['id']]);
            if (!$affected || !$affected['is_answered'] || $affected['answered_by'] !== $charName) {
                Database::rollBack();
                return ['success' => false, 'message' => '别人已经回答过这句诗了。', 'skip_queue' => true];
            }

            // 发放奖励 (移植自 LPC poem_reward1)
            $rewardType = mt_rand(0, 2);
            $rewardMsg = '';
            $rewardTypeStr = '';
            $rewardAmount = 0;

            switch ($rewardType) {
                case 0:
                    $dx = 4 + mt_rand(0, 5);
                    Database::execute("UPDATE characters SET daoxing = daoxing + ? WHERE id = ?", [$dx, $charId]);
                    $rewardTypeStr = 'daoxing';
                    $rewardAmount = $dx;
                    $rewardMsg = HTML_HIYEL . '你的道行增加了！(+' . $dx . ')' . HTML_NOR;
                    break;
                case 1:
                    $pot = 3 + mt_rand(0, 3);
                    $charFresh = Database::queryOne("SELECT potential, learned_points FROM characters WHERE id = ?", [$charId]);
                    if ($charFresh && ($charFresh['potential'] + $pot - $charFresh['learned_points']) <= 100) {
                        Database::execute("UPDATE characters SET potential = potential + ? WHERE id = ?", [$pot, $charId]);
                        $rewardTypeStr = 'potential';
                        $rewardAmount = $pot;
                        $rewardMsg = HTML_HIBLU . '你的潜能增加了！(+' . $pot . ')' . HTML_NOR;
                    } else {
                        $dx = 4 + mt_rand(0, 5);
                        Database::execute("UPDATE characters SET daoxing = daoxing + ? WHERE id = ?", [$dx, $charId]);
                        $rewardTypeStr = 'daoxing';
                        $rewardAmount = $dx;
                        $rewardMsg = HTML_HIYEL . '你的道行增加了！(+' . $dx . ')' . HTML_NOR;
                    }
                    break;
                case 2:
                    $lite = 4 + mt_rand(0, 5);
                    SkillManager::improveSkillOriginal($charId, 'literate', $lite, false);
                    $rewardTypeStr = 'literate';
                    $rewardAmount = $lite;
                    $rewardMsg = HTML_HIGRN . '你的读书识字进步了！(+' . $lite . ')' . HTML_NOR;
                    break;
            }

            // 记录答题
            Database::execute(
                "INSERT INTO poem_answers (round_id, char_id, char_name, answer_text, is_correct, reward_type, reward_amount, created_at)
                 VALUES (?, ?, ?, ?, 1, ?, ?, NOW())",
                [$matchedRound['id'], $charId, $charName, $param, $rewardTypeStr, $rewardAmount]
            );

            // 统计总答对次数 (LPC: me->add("poem_answered",1))
            Database::execute("UPDATE characters SET poem_answered = poem_answered + 1 WHERE id = ?", [$charId]);

            Database::commit();
        } catch (Exception $e) {
            Database::rollBack();
            error_log('诗社答题失败: ' . $e->getMessage());
            return ['success' => false, 'message' => '系统错误，请重试'];
        }

        $output = [];
        $output[] = HTML_HICYN . $charName . HTML_NOR . $sayVerb . '：' . $param . '？';
        $output[] = '茶博士点头道："' . $matchedRound['first_part'] . '  ' . $matchedRound['second_part'] . '"不错！不错！';
        $output[] = $rewardMsg;

        return [
            'success' => true,
            'type' => 'poem_answer',
            'output' => implode("\n", $output),
            'skip_queue' => true,
        ];
    } else {
        // 答错了
        // 确定目标题目（当前题或上一题）
        $targetRound = $current;
        if ($current['is_answered'] && $previous && !$previous['is_answered']) {
            $targetRound = $previous;
        }

        Database::execute(
            "INSERT INTO poem_answers (round_id, char_id, char_name, answer_text, is_correct, created_at)
             VALUES (?, ?, ?, ?, 0, NOW())",
            [$targetRound['id'], $charId, $charName, $param]
        );

        // 检查答错次数 (LPC: poem/wrong > 10 → poem_penalty)
        // 答对后重置计数器，只统计最近一次答对之后的答错次数
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

        $output = [];
        $output[] = HTML_HICYN . $charName . HTML_NOR . $sayVerb . '：' . $param . '？';
        $output[] = '茶博士摇了摇头：好象不对吧？';

        if ($wrongAttempts > 10) {
            // 惩罚: 神识 -1 (LPC: me->set("sen",-1))
            Database::execute("UPDATE characters SET sen = -1 WHERE id = ?", [$charId]);
            $output[] = HTML_HIRED . '茶博士摇头道：你今日答错太多了，歇歇吧。' . HTML_NOR;
        }

        return [
            'success' => true,
            'type' => 'poem_answer',
            'output' => implode("\n", $output),
            'skip_queue' => true,
        ];
    }
}
