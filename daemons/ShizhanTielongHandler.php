<?php
/**
 * 石栈道铁笼处理器
 * 
 * 还原原始LPC: /d/westway/tielong.c 的 break 机制
 * 
 * 核心逻辑：
 * 1. 玩家在铁笼中使用 break 命令尝试扳开铁笼
 * 2. 破坏力 = force_factor × 5 + str（内力系数×5 + 力量）
 * 3. 每次尝试消耗 30 气血 + 当前内力系数
 * 4. 破坏值累计超过 3000 时，铁笼打开，出口指向山洞内(lu1)
 * 
 * 使用 character_temp_states 存储破坏进度：
 * - shizhan_tielong_break_{charId}: 累计破坏值
 */

require_once __DIR__ . '/ActionHandler.php';

class ShizhanTielongHandler extends ActionHandler {
    
    const BREAK_THRESHOLD = 3000;
    const DAMAGE_PER_ATTEMPT = 30;
    const TARGET_ROOM = 'westway/lu1';
    
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在'];
            }
            
            $currentRoom = $character['current_room'];
            
            // 检查是否在铁笼中
            if ($currentRoom !== 'westway/tielong') {
                return ['success' => false, 'message' => '这里没有铁笼可以扳开。'];
            }
            
            // 检查铁笼是否已打开
            require_once __DIR__ . '/../helpers/TempStateHelper.php';
            $isOpen = TempStateHelper::get($charId, 'shizhan_tielong_open');
            if (is_array($isOpen)) {
                $isOpen = !empty($isOpen['_value']);
            }
            if ($isOpen) {
                return ['success' => false, 'message' => '铁笼已经打开了，你已经可以出去了。'];
            }
            
            // 获取角色属性
            $forceFactor = intval($character['force_factor'] ?? 0);
            $str = intval($character['str'] ?? 0);
            
            // 计算破坏力：内力系数 × 5 + 力量
            $damage = $forceFactor * 5 + $str;
            
            // 获取当前累计破坏值
            $breakProgressData = TempStateHelper::get($charId, 'shizhan_tielong_break');
            if (is_array($breakProgressData)) {
                $breakProgress = intval($breakProgressData['_value'] ?? 0);
            } else {
                $breakProgress = intval($breakProgressData ?? 0);
            }
            
            // 累加破坏值
            $breakProgress += $damage;
            TempStateHelper::set($charId, 'shizhan_tielong_break', $breakProgress);
            
            // 消耗气血（每次30）
            require_once __DIR__ . '/../includes/db.php';
            Database::execute(
                'UPDATE characters SET kee = GREATEST(1, kee - ?) WHERE id = ?',
                [self::DAMAGE_PER_ATTEMPT, $charId]
            );
            
            // 消耗内力（当前内力系数）
            Database::execute(
                'UPDATE characters SET `force` = GREATEST(0, `force` - ?) WHERE id = ?',
                [$forceFactor, $charId]
            );
            
            // 根据破坏力显示不同效果消息
            $effectMessage = '';
            if ($damage > 300) {
                $effectMessage = '只听见一声巨响，铁条被你扳弯了！';
            } elseif ($damage > 200) {
                $effectMessage = '铁条被扳弯了一些，看来你需要继续努力。';
            } elseif ($damage > 100) {
                $effectMessage = '铁条被扳得弯了一些。';
            } else {
                $effectMessage = '铁条被扳得没有什么反应...';
            }
            
            // 检查是否打开铁笼
            if ($breakProgress >= self::BREAK_THRESHOLD) {
                // 铁笼打开
                TempStateHelper::set($charId, 'shizhan_tielong_open', 1);
                
                // 发送成功消息
                $successMessage = HTML_HIGRN . '在你的努力下铁笼终于被扳开了！' . HTML_NOR . "\n";
                $successMessage .= HTML_HICYN . '你可以从铁笼中出来了。' . HTML_NOR;
                
                // 广播消息
                $broadcastMessage = HTML_HIYEL . "{$character['name']}用力扳开了铁笼，铁条发出刺耳的声响。" . HTML_NOR;
                $this->broadcastToRoom($currentRoom, $broadcastMessage, $charId);
                
                // 清除破坏进度
                TempStateHelper::remove($charId, 'shizhan_tielong_break');
                
                return [
                    'success' => true,
                    'message' => $successMessage
                ];
            }
            
            // 未打开，显示进度消息
            $progressPercent = min(100, round(($breakProgress / self::BREAK_THRESHOLD) * 100));
            $remaining = self::BREAK_THRESHOLD - $breakProgress;
            
            $message = HTML_YEL . "你开始用力扳了起来，双手一上一下地用力摇着铁条...\n" . HTML_NOR;
            $message .= HTML_CYN . $effectMessage . "\n" . HTML_NOR;
            $message .= HTML_HICYN . "铁笼破坏进度: {$progressPercent}% (还需 {$remaining} 点破坏值)\n" . HTML_NOR;
            $message .= HTML_HIRED . "消耗了 " . self::DAMAGE_PER_ATTEMPT . " 气血和 {$forceFactor} 内力\n" . HTML_NOR;
            
            // 广播消息
            $broadcastMessage = HTML_HIYEL . "{$character['name']}开始用力扳铁笼，双手一上一下地用力摇着铁条...\n" . HTML_NOR;
            $this->broadcastToRoom($currentRoom, $broadcastMessage, $charId);
            
            return [
                'success' => true,
                'message' => $message
            ];
            
        } catch (\Exception $e) {
            error_log("ShizhanTielongHandler error: " . $e->getMessage() . " at line " . $e->getLine());
            return ['success' => false, 'message' => '扳铁笼失败，请稍后再试。'];
        }
    }
}