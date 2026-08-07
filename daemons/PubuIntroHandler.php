<?php
/**
 * 瀑布前进入剧情处理器 (PubuIntroHandler)
 *
 * 玩家首次进入瀑布前(dntg/hgs/pubu)时，播放花果山猴子发现瀑布的经典剧情。
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 同session内只播放一次。
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/MessageDaemon.php';

class PubuIntroHandler {

    private const ROOM_ID = 'dntg/hgs/pubu';
    private const SESSION_KEY = 'pubu_intro_shown';

    /**
     * 检查并播放进入剧情
     * 在 room.php 房间加载时调用
     */
    public static function checkAndPlay(int $charId, string $roomId): void {
        // 只在瀑布前房间触发
        if ($roomId !== self::ROOM_ID) {
            return;
        }

        // 同session内只播放一次
        if (!empty($_SESSION[self::SESSION_KEY])) {
            return;
        }

        $_SESSION[self::SESSION_KEY] = true;

        // 剧情消息序列（还原西游记原著 + 原始LPC appearing）
        // 逐条广播到房间，所有人可见
        $messages = [
            HTML_HICYN . '树丛中蹿出一只猴子。' . HTML_NOR,
            HTML_HICYN . '小猴子向瀑布下张望了一下。' . HTML_NOR,
            HTML_HICYN . '猴子们交头结耳道："不知这瀑布下是番什么风景。"' . HTML_NOR,
            HTML_HICYN . '众猴拍手称扬道："好水！好水！原来此处远通山脚之下，直接大海之波。"' . HTML_NOR,
            HTML_HICYN . '猴子们道："那一个有本事的，钻进去寻个源头出来，不伤身体者，我等即拜他为王。"' . HTML_NOR,
        ];

        foreach ($messages as $msg) {
            MessageDaemon::broadcastToRoom(self::ROOM_ID, $msg, 0, 'room');
        }
    }
}
