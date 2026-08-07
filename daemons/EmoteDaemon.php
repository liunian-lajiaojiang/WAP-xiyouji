<?php
/**
 * Emote 表情动作系统守护进程
 * 处理玩家的表情动作命令
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../daemons/MessageDaemon.php';

class EmoteDaemon {
    
    /**
     * 执行 emote 命令
     * @param int $charId 角色ID
     * @param string $command emote 命令
     * @param string|null $targetName 目标名字（可选）
     * @return array 执行结果
     */
    public static function execute(int $charId, string $command, ?string $targetName = null): array {
        // 获取 emote 定义
        $emote = self::getEmote($command);
        
        if (!$emote) {
            return [
                'success' => false,
                'message' => "没有这个表情动作：$command"
            ];
        }
        
        // 获取角色信息
        require_once __DIR__ . '/../models/Character.php';
        $character = CharacterModel::getFullInfo($charId);
        
        if (!$character) {
            return [
                'success' => false,
                'message' => "角色不存在"
            ];
        }
        
        $charName = $character['name'];
        $roomId = $character['current_room'];

        // 判定是否"对自己使用"
        $isSelfTarget = $targetName && strcasecmp($targetName, $charName) === 0;

        // 解析目标：先查玩家，再查 NPC
        $targetId = null;
        $targetNpc = null;
        $targetData = null;
        if ($targetName && !$isSelfTarget) {
            $targetId = self::getTargetCharId($targetName);
            if ($targetId !== null) {
                $targetData = self::getTargetData($targetName);
            } else {
                $targetNpc = self::findNpcTarget($targetName, $roomId);
            }
        }

        // 确定消息模板
        if ($targetNpc) {
            $messages = self::getTargetMessages($emote, $charName, $targetNpc['name'] ?? $targetName, $character, $targetNpc);
        } elseif ($targetName && !$isSelfTarget) {
            $messages = self::getTargetMessages($emote, $charName, $targetName, $character, $targetData);
        } else {
            $messages = self::getSelfMessages($emote, $charName, $isSelfTarget, $character);
        }

        // 广播消息
        if ($targetNpc) {
            // NPC 目标
            $selfMsg = $messages['self'] ?? '';
            $othersMsg = $messages['others'] ?? '';
            if ($selfMsg) {
                MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'emote');
            }
            if ($othersMsg) {
                $onlinePlayers = Database::queryAll(
                    "SELECT id FROM characters WHERE online = 1 AND id != ?",
                    [$charId]
                );
                foreach ($onlinePlayers as $p) {
                    MessageDaemon::queueMessageToSelf($p['id'], $othersMsg, 'emote');
                }
            }
            self::reactNpc($targetNpc, $charName, $character, $charId);
            $returnMessage = $messages['self'] ?? '';
        } elseif ($targetName && !$isSelfTarget) {
            // 玩家目标
            $selfMsg = $messages['self'] ?? '';
            $targetMsg = $messages['target'] ?? '';
            $othersMsg = $messages['others'] ?? '';
            if ($selfMsg) {
                MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'emote');
            }
            if ($targetMsg && $targetId) {
                MessageDaemon::queueMessageToSelf($targetId, $targetMsg, 'emote');
            }
            if ($othersMsg) {
                $onlinePlayers = Database::queryAll(
                    "SELECT id FROM characters WHERE online = 1 AND id != ? AND id != ?",
                    [$charId, $targetId ?: 0]
                );
                foreach ($onlinePlayers as $p) {
                    MessageDaemon::queueMessageToSelf($p['id'], $othersMsg, 'emote');
                }
            }
            $returnMessage = $messages['self'] ?? '';
        } else {
            // 无目标 或 对自己使用
            $selfMsg = $messages[$charName] ?? '';
            $othersMsg = $messages['others'] ?? '';
            if ($selfMsg) {
                MessageDaemon::queueMessageToSelf($charId, $selfMsg, 'emote');
            }
            if ($othersMsg) {
                $onlinePlayers = Database::queryAll(
                    "SELECT id FROM characters WHERE online = 1 AND id != ?",
                    [$charId]
                );
                foreach ($onlinePlayers as $p) {
                    MessageDaemon::queueMessageToSelf($p['id'], $othersMsg, 'emote');
                }
            }
            $returnMessage = $messages[$charName] ?? '';
        }
        
        // 记录日志
        self::logEmote($charId, $command, $targetName ? self::getTargetCharId($targetName) : null, $roomId);
        
        return [
            'success' => true,
            'message' => $returnMessage,
            'skip_queue' => true  // 防止 action.php 重复保存消息
        ];
    }
    
    /**
     * 获取 emote 定义
     * @param string $command emote 命令
     * @return array|null emote 数据
     */
    private static function getEmote(string $command): ?array {
        try {
            $emote = Database::queryOne(
                "SELECT * FROM emotes WHERE command = ? AND is_active = 1",
                [$command]
            );
            
            return $emote ?: null;
        } catch (Exception $e) {
            error_log("获取 emote 失败: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * 获取无目标时的消息
     * @param array $emote emote 数据
     * @param string $charName 角色名字
     * @return array 消息数组 [char_id => message]
     */
    private static function getSelfMessages(array $emote, string $charName, bool $isSelfTarget, array $character): array {
        $messages = [];

        if ($isSelfTarget) {
            $myselfMsg = $emote['myself_self'] ?? '';
            $othersMsg = $emote['others_self'] ?? '';
        } else {
            $myselfMsg = $emote['myself'] ?? '';
            $othersMsg = $emote['others'] ?? '';
        }

        if ($myselfMsg) {
            $messages[$charName] = self::replaceVariables($myselfMsg, $charName, null, $character, null, true);
        }
        if ($othersMsg) {
            $messages['others'] = self::replaceVariables($othersMsg, $charName, null, $character, null);
        }

        return $messages;
    }
    
    /**
     * 获取有目标时的消息
     * @param array $emote emote 数据
     * @param string $charName 执行者名字
     * @param string $targetName 目标名字
     * @return array 消息数组
     */
    private static function getTargetMessages(array $emote, string $charName, string $targetName, array $character, ?array $targetData): array {
        $messages = [];

        $myselfMsg = $emote['myself_target'] ?? '';
        if ($myselfMsg) {
            $messages['self'] = self::replaceVariables($myselfMsg, $charName, $targetName, $character, $targetData, true);
        }

        $targetMsg = $emote['target'] ?? '';
        if ($targetMsg) {
            $messages['target'] = self::replaceVariables($targetMsg, $charName, $targetName, $character, $targetData, false, true);
        }

        $othersMsg = $emote['others_target'] ?? '';
        if ($othersMsg) {
            $messages['others'] = self::replaceVariables($othersMsg, $charName, $targetName, $character, $targetData);
        }

        return $messages;
    }
    
    /**
     * 替换消息中的变量
     * @param string $template 消息模板
     * @param string $charName 执行者名字
     * @param string|null $targetName 目标名字
     * @return string 替换后的消息
     */
    private static function replaceVariables(string $template, string $charName, ?string $targetName, array $actorData, ?array $targetData, bool $isActorView = false, bool $isTargetView = false): string {
        require_once __DIR__ . '/../helpers/RankHelper.php';

        $pronoun = function ($g) {
            return ($g === 'female' || $g === '女性') ? '她' : '他';
        };
        $charGender = $actorData['gender'] ?? 'male';
        $charP = $pronoun($charGender);
        $tgtP = $targetData ? $pronoun($targetData['gender'] ?? 'male') : '';

        $replacements = [
            // 自己视角下，执行者以"你"（第二人称）自称，符合中文 MUD 习惯
            '$N' => $isActorView ? '你' : $charName,
            // 对方视角下，目标以"你"自称
            '$n' => $isTargetView ? '你' : ($targetName ?? ''),
            '$P' => $isActorView ? '你' : $charP,
            '$p' => $isTargetView ? '你' : $tgtP,
            '$S' => RankHelper::querySelf($actorData),
            '$s' => RankHelper::querySelfRude($actorData),
            '$C' => RankHelper::queryRespect($actorData),
            '$c' => RankHelper::queryRude($actorData),
            '$R' => $targetData ? RankHelper::queryRespect($targetData) : ($targetName ?? '您'),
            '$r' => $targetData ? RankHelper::queryRude($targetData) : '您',
        ];

        return strtr($template, $replacements);
    }
    
    /**
     * 获取目标角色的 ID
     * @param string $targetName 目标名字
     * @return int|null 目标角色 ID
     */
    private static function getTargetCharId(string $targetName): ?int {
        try {
            $target = Database::queryOne(
                "SELECT id FROM characters WHERE name = ?",
                [$targetName]
            );
            
            return $target ? intval($target['id']) : null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * 获取目标玩家的完整数据（用于尊称/性别替换）
     * @param string $targetName 目标名字
     * @return array|null
     */
    private static function getTargetData(string $targetName): ?array {
        try {
            $t = Database::queryOne(
                "SELECT * FROM characters WHERE name = ?",
                [$targetName]
            );
            return $t ?: null;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * 将表情目标解析为 NPC（按名称）
     * @param string $name 目标名字
     * @param string $roomId 当前房间（预留：可加房间归属校验）
     * @return array|null
     */
    private static function findNpcTarget(string $name, string $roomId): ?array {
        require_once __DIR__ . '/../models/Npc.php';
        $npc = NpcModel::findByNpcId($name);
        return $npc ?: null;
    }

    /**
     * 触发 NPC 对表情的反应（relay_emote 等价物）
     * @param array $npc NPC 数据
     * @param string $charName 执行者名字
     * @param array $character 执行者完整数据
     * @param int $charId 执行者 ID
     */
    private static function reactNpc(array $npc, string $charName, array $character, int $charId): void {
        require_once __DIR__ . '/../helpers/RankHelper.php';
        $npcName = $npc['name'] ?? $charName;
        $npcRespect = RankHelper::queryRespect($npc);
        $actorRespect = RankHelper::queryRespect($character);
        $reaction = "{$npcRespect}{$npcName}看着{$actorRespect}。";

        try {
            $onlinePlayers = Database::queryAll(
                "SELECT id FROM characters WHERE online = 1 AND id != ?",
                [$charId]
            );
            foreach ($onlinePlayers as $p) {
                MessageDaemon::queueMessageToSelf($p['id'], $reaction, 'emote');
            }
        } catch (Exception $e) {
            error_log("NPC emote reaction failed: " . $e->getMessage());
        }
    }

    /**
     * 记录 emote 使用日志
     * @param int $charId 角色ID
     * @param string $command emote 命令
     * @param int|null $targetCharId 目标角色ID
     * @param string|null $roomId 房间ID
     */
    private static function logEmote(int $charId, string $command, ?int $targetCharId, ?string $roomId): void {
        try {
            Database::execute(
                "INSERT INTO emote_logs 
                (char_id, emote_command, target_char_id, room_id)
                VALUES (?, ?, ?, ?)",
                [$charId, $command, $targetCharId, $roomId]
            );
        } catch (Exception $e) {
            // 日志记录失败不影响主流程
            error_log("记录 emote 日志失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取所有可用的 emote 列表
     * @return array emote 列表
     */
    public static function getEmoteList(): array {
        try {
            $emotes = Database::queryAll(
                "SELECT command, description FROM emotes 
                 WHERE is_active = 1 
                 ORDER BY sort_order, command"
            );
            
            return $emotes ?: [];
        } catch (Exception $e) {
            error_log("获取 emote 列表失败: " . $e->getMessage());
            return [];
        }
    }
}

