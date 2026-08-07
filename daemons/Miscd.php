<?php
/**
 * 杂项守护进程 (Miscd)
 * 
 * 移植自 xyj2000-php 的 adm/daemons/miscd.c 和 daemon/Miscd.php
 * 核心功能：random_capture - 随机黄风捕获事件
 * 
 * 参考：
 * - U:/xyj2000/adm/daemons/miscd.c (LPC 原版)
 * - U:/xyj2000-php/daemon/Miscd.php (PHP 版)
 * - U:/xyj2000-php/src/Daemon/Miscd.php (PHP 重构版)
 * 
 * xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../models/CharacterModel.php';
require_once __DIR__ . '/MessageDaemon.php';

class Miscd
{
    /**
     * 默认触发几率（1/chance 的概率触发）
     */
    private const DEFAULT_CHANCE = 5000;

    /**
     * 囚洞房间路径
     */
    private const JAIL_ROOM = 'qujing/baihuling/jail';

    /**
     * 随机黄风捕获
     * 
     * 有一定几率（1/chance）将玩家传送到白虎岭囚洞。
     * 这是游戏中主要的随机惩罚事件，修道、诵经、练习等修炼行为
     * 都有几率触发。
     * 
     * 执行流程：
     * 1. 随机判定是否触发
     * 2. 发送黄风消息
     * 3. 保存当前位置到 session（用于后续返回）
     * 4. 更新角色位置到囚洞
     * 5. 发送落地消息
     * 
     * @param int $charId  角色ID
     * @param int $chance  触发几率基数（1/chance），越小越容易触发
     * @param bool $silent 是否静默模式（不显示他人可见的消息）
     * @return bool 是否触发了捕获
     */
    public static function randomCapture(int $charId, int $chance = self::DEFAULT_CHANCE, bool $silent = false): bool
    {
        // 随机判定
        if (mt_rand(0, $chance - 1) !== 0) {
            return false;
        }

        // 获取角色信息
        $char = CharacterModel::find($charId);
        if (!$char) {
            return false;
        }

        $charName = $char['name'] ?? '某人';
        $oldArea = $char['current_area'] ?? '';
        $oldRoom = $char['current_room'] ?? '';

        // 1. 发送黄风消息
        $messages = [];

        // 玩家自己看到的消息
        $messages[] = HTML_HIYEL . "\n忽然一阵黄风呼啸而来，你身不由己被卷了进去！" . HTML_NOR;

        // 如果非静默模式，广播给同房间玩家
        if (!$silent) {
            $oldRoomId = $oldArea . '/' . $oldRoom;
            MessageDaemon::broadcastToRoom(
                $oldRoomId,
                HTML_HIYEL . "\n忽然一阵黄风呼啸而来，{$charName}身不由己被卷了进去！" . HTML_NOR,
                $charId,
                'room'
            );
        }

        // 过场消息
        $messages[] = HTML_HICYN . "\n不知过了多久．．．" . HTML_NOR;

        // 2. 保存当前位置到 session（用于从舍利塔返回时使用）
        $_SESSION["old_place_{$charId}"] = $oldArea . '/' . $oldRoom;
        // 也保存原始出生点
        $_SESSION["old_startroom_{$charId}"] = $char['startroom'] ?? '';

        // 3. 更新角色位置到囚洞
        // current_room 存储完整路径 "qujing/baihuling/jail"
        // current_area 存储区域 "qujing"
        Database::execute(
            "UPDATE characters SET current_area = ?, current_room = ?, startroom = ? WHERE id = ?",
            ['qujing', self::JAIL_ROOM, self::JAIL_ROOM, $charId]
        );

        // 4. 清除修炼状态（打断当前的修炼）
        unset($_SESSION['pending_exercising']);
        unset($_SESSION['pending_meditating']);
        unset($_SESSION['pending_practicing']);
        unset($_SESSION['doing_xiudao']);
        unset($_SESSION['pending_chanting']);

        // 清除忙碌状态
        set_player_busy($charId, 0);

        // 落地消息
        $messages[] = HTML_HIYEL . "你被嘭地一声摔在地上！" . HTML_NOR;

        // 广播落地消息给囚洞中的其他人
        MessageDaemon::broadcastToRoom(
            self::JAIL_ROOM,
            HTML_HIYEL . "{$charName}被嘭地一声摔在地上！" . HTML_NOR,
            $charId,
            'room'
        );

        // 存储消息到 session，供 room.php 显示
        $_SESSION["capture_message_{$charId}"] = implode("\n", $messages);

        return true;
    }

    /**
     * 获取捕获消息并清除
     * 供 room.php 在渲染页面时调用
     * 
     * @param int $charId 角色ID
     * @return string|null 捕获消息，如果没有则返回 null
     */
    public static function getCaptureMessage(int $charId): ?string
    {
        $msg = $_SESSION["capture_message_{$charId}"] ?? null;
        unset($_SESSION["capture_message_{$charId}"]);
        return $msg;
    }

    /**
     * 恢复玩家的出生点
     * 在玩家从舍利塔离开时调用
     * 
     * @param int $charId 角色ID
     */
    public static function restoreStartroom(int $charId): void
    {
        $oldStartroom = $_SESSION["old_startroom_{$charId}"] ?? null;
        if ($oldStartroom) {
            Database::execute(
                "UPDATE characters SET startroom = ? WHERE id = ?",
                [$oldStartroom, $charId]
            );
            unset($_SESSION["old_startroom_{$charId}"]);
        }
    }

    /**
     * 获取旧位置（用于清风送回）
     * 
     * @param int $charId 角色ID
     * @return string|null 旧位置 "area/room"，没有则返回 null
     */
    public static function getOldPlace(int $charId): ?string
    {
        return $_SESSION["old_place_{$charId}"] ?? null;
    }

    /**
     * 清除旧位置记录
     * 
     * @param int $charId 角色ID
     */
    public static function clearOldPlace(int $charId): void
    {
        unset($_SESSION["old_place_{$charId}"]);
    }
}
