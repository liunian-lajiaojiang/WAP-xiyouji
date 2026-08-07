<?php
/**
 * 木筏处理器
 * 
 * 实现木筏的动态传送机制：
 * 1. 触发后木筏漂过来（创建临时出口）
 * 2. 15秒后离岸（删除出口）
 * 3. 35秒后到达对岸（创建新出口）
 * 4. 55秒后再次离岸（重置状态）
 */

require_once __DIR__ . '/ActionHandler.php';
require_once __DIR__ . '/MessageDaemon.php';

class MufaHandler extends ActionHandler {
    
    /**
     * 执行木筏动作
     */
    public function execute(int $charId, array $action, array $params = []): array {
        try {
            $character = $this->getCharacter($charId);
            
            if (!$character) {
                return ['success' => false, 'message' => '角色不存在', 'data' => null];
            }
            
            // 解析配置
            $config = $this->parseConfig($action);
            $currentRoom = $character['current_room'];
            $actionName = $action['action_name'] ?? '';
            
            // 获取木筏状态（会自动更新时间线并广播）
            $mufaState = $this->getMufaState();
            
            // 根据当前房间和动作名称判断操作类型
            if ($currentRoom === 'changan/eastseashore' && $actionName === '上木筏') {
                // 东海之滨：上木筏
                return $this->handleBoardFromShore($charId, $character, $config, $mufaState, 'eastseashore');
            } elseif ($currentRoom === 'changan/aolaiws' && $actionName === '上木筏') {
                // 傲来国西海岸：上木筏
                return $this->handleBoardFromShore($charId, $character, $config, $mufaState, 'aolaiws');
            } elseif ($currentRoom === 'changan/mufa' && $actionName === '下木筏') {
                // 木筏房间：下木筏
                return $this->handleDisembark($charId, $character, $config, $mufaState);
            }
            
            return ['success' => false, 'message' => '无法执行木筏动作'];
            
        } catch (\Exception $e) {
            error_log("MufaHandler error: " . $e->getMessage());
            return ['success' => false, 'message' => '木筏功能执行失败', 'data' => null];
        }
    }
    
    /**
     * 处理从岸边（东海或傲来）上木筏
     */
    private function handleBoardFromShore(int $charId, array $character, array $config, array $mufaState, string $shoreType): array {
        // 检查木筏状态
        if ($shoreType === 'eastseashore') {
            // 东海之滨：木筏需要在at_shore状态
            if ($mufaState['status'] !== 'at_shore') {
                $messages = [
                    'sailing_away' => '你遥遥望去，发现木筏已经离岸，正在漂向大海深处...',
                    'at_dest' => '你揉了揉眼睛，发现木筏不在这里。',
                    'sailing_back' => '木筏还没到这里呢。'
                ];
                $msg = $messages[$mufaState['status']] ?? '木筏还没到这呢。';
                return ['success' => false, 'message' => $msg];
            }
            
            // 可以上船
            $successSelfMessage = $config['success_self_message'] ?? '你跳上木筏，奋力划向大海深处。';
            $broadcastTemplate = $config['success_broadcast_template'] ?? '{name}跳上木筏，奋力划向大海深处。';
            
            // 广播消息到东海之滨
            $broadcastMessage = str_replace('{name}', $character['name'], $broadcastTemplate);
            $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
            MessageDaemon::broadcastToRoom('changan/eastseashore', $broadcastMessage, intval($charId));
            
            // 移动到木筏房间
            Database::execute(
                'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
                ['changan', 'changan/mufa', $charId]
            );
            
            // 广播到达消息到木筏房间
            $arriveTemplate = $config['arrive_broadcast_template'] ?? '{name}从东海之滨上来了。';
            $arriveMessage = str_replace('{name}', $character['name'], $arriveTemplate);
            $arriveMessage = (defined('HIY') ? HIY : '') . $arriveMessage . (defined('NOR') ? NOR : '');
            MessageDaemon::broadcastToRoom('changan/mufa', $arriveMessage, intval($charId));
            
            $redirectUrl = room_url('changan', 'changan/mufa');
            
            return [
                'success' => true,
                'message' => $successSelfMessage,
                'redirect' => $redirectUrl
            ];
            
        } else {
            // 傲来国西海岸：木筏需要在at_dest状态
            if ($mufaState['status'] !== 'at_dest') {
                $messages = [
                    'at_shore' => '木筏还在海上呢，不在这里。',
                    'sailing_away' => '木筏正在漂向对岸，还没到呢。',
                    'sailing_back' => '木筏去哪了呢，大概还没到吧...'
                ];
                $msg = $messages[$mufaState['status']] ?? '木筏还没到这呢。';
                return ['success' => false, 'message' => $msg];
            }
            
            // 可以上船
            $successSelfMessage = $config['success_self_message'] ?? '你跳上木筏，奋力划向大海深处。';
            $broadcastTemplate = $config['success_broadcast_template'] ?? '{name}跳上木筏，奋力划向大海深处。';
            
            // 广播消息到傲来国西海岸
            $broadcastMessage = str_replace('{name}', $character['name'], $broadcastTemplate);
            $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
            MessageDaemon::broadcastToRoom('changan/aolaiws', $broadcastMessage, intval($charId));
            
            // 移动到木筏房间
            Database::execute(
                'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
                ['changan', 'changan/mufa', $charId]
            );
            
            // 广播到达消息到木筏房间
            $arriveTemplate = $config['arrive_broadcast_template'] ?? '{name}从傲来国西海岸上来了。';
            $arriveMessage = str_replace('{name}', $character['name'], $arriveTemplate);
            $arriveMessage = (defined('HIY') ? HIY : '') . $arriveMessage . (defined('NOR') ? NOR : '');
            MessageDaemon::broadcastToRoom('changan/mufa', $arriveMessage, intval($charId));
            
            $redirectUrl = room_url('changan', 'changan/mufa');
            
            return [
                'success' => true,
                'message' => $successSelfMessage,
                'redirect' => $redirectUrl
            ];
        }
    }
    
    /**
     * 处理下木筏
     */
    private function handleDisembark(int $charId, array $character, array $config, array $mufaState): array {
        // 检查木筏状态
        if ($mufaState['status'] !== 'at_dest' && $mufaState['status'] !== 'at_shore') {
            // 木筏在航行中，不能下船
            if ($mufaState['status'] === 'sailing_away') {
                return ['success' => false, 'message' => '木筏正在漂向傲来国，还没到岸呢。'];
            } elseif ($mufaState['status'] === 'sailing_back') {
                return ['success' => false, 'message' => '木筏正在返回东海，还没到岸呢。'];
            } else {
                return ['success' => false, 'message' => '周围是白茫茫一片大海，你已经远离任何陆地的视线...'];
            }
        }
        
        // 确定目标房间
        $targetRoom = '';
        $targetArea = 'changan';
        
        if ($mufaState['status'] === 'at_dest') {
            // 木筏在傲来国，下船到aolaiws
            $targetRoom = 'changan/aolaiws';
            $successSelfMessage = '你从木筏上下来，回到了傲来国西海岸。';
            $broadcastTemplate = '{name}从木筏上下来了。';
            $arriveTemplate = '{name}从海上回来了。';
        } else {
            // 木筏在东海，下船到eastseashore
            $targetRoom = 'changan/eastseashore';
            $successSelfMessage = '你从木筏上下来，回到了东海之滨。';
            $broadcastTemplate = '{name}从木筏上下来了。';
            $arriveTemplate = '{name}从海上回来了。';
        }
        
        // 广播消息到木筏房间
        $broadcastMessage = str_replace('{name}', $character['name'], $broadcastTemplate);
        $broadcastMessage = (defined('HIY') ? HIY : '') . $broadcastMessage . (defined('NOR') ? NOR : '');
        MessageDaemon::broadcastToRoom('changan/mufa', $broadcastMessage, intval($charId));
        
        // 移动到目标房间
        Database::execute(
            'UPDATE characters SET current_area = ?, current_room = ? WHERE id = ?',
            [$targetArea, $targetRoom, $charId]
        );
        
        // 广播到达消息到目标房间
        $arriveMessage = str_replace('{name}', $character['name'], $arriveTemplate);
        $arriveMessage = (defined('HIY') ? HIY : '') . $arriveMessage . (defined('NOR') ? NOR : '');
        MessageDaemon::broadcastToRoom($targetRoom, $arriveMessage, intval($charId));
        
        $redirectUrl = room_url($targetArea, $targetRoom);
        
        return [
            'success' => true,
            'message' => $successSelfMessage,
            'redirect' => $redirectUrl
        ];
    }
    
    /**
     * 获取木筏状态（并自动更新时间线）
     */
    private function getMufaState(): array {
        // 从缓存或数据库获取木筏状态
        $stateFile = __DIR__ . '/../data/mufa_state.json';
        
        if (file_exists($stateFile)) {
            $content = file_get_contents($stateFile);
            $state = json_decode($content, true);
            
            if ($state && isset($state['timestamp']) && isset($state['trigger_time'])) {
                // 自动更新时间线
                $now = time();
                $elapsed = $now - $state['trigger_time'];
                
                // 根据经过的时间自动更新状态
                if ($state['status'] === 'at_shore' && $elapsed >= 15) {
                    // 15秒后应该离岸
                    $this->updateMufaState('sailing_away', $state['trigger_time']);
                    $state['status'] = 'sailing_away';
                    error_log("[木筏] 状态变更: at_shore -> sailing_away");
                } elseif ($state['status'] === 'sailing_away' && $elapsed >= 35) {
                    // 35秒后应该到达对岸
                    $this->updateMufaState('at_dest', $state['trigger_time']);
                    $state['status'] = 'at_dest';
                    error_log("[木筏] 状态变更: sailing_away -> at_dest");
                } elseif ($state['status'] === 'at_dest' && $elapsed >= 55) {
                    // 55秒后应该再次离岸
                    $this->updateMufaState('sailing_back', $state['trigger_time']);
                    $state['status'] = 'sailing_back';
                    error_log("[木筏] 状态变更: at_dest -> sailing_back");
                } elseif ($state['status'] === 'sailing_back' && $elapsed >= 65) {
                    // 65秒后重置为初始状态
                    $this->updateMufaState('at_shore', null);
                    $state['status'] = 'at_shore';
                    $state['trigger_time'] = null;
                    error_log("[木筏] 状态变更: sailing_back -> at_shore (重置)");
                }
                
                return $state;
            }
        }
        
        // 默认状态
        return [
            'status' => 'at_shore',  // at_shore, sailing_away, at_dest, sailing_back
            'timestamp' => time(),
            'trigger_time' => null
        ];
    }
    
    /**
     * 获取木筏状态并向指定玩家发送时间线消息
     * 
     * @param int $charId 角色ID
     * @param string $currentRoom 当前房间
     * @return array 木筏状态
     */
    public function checkMufaStateForPlayer(int $charId, string $currentRoom): array {
        $oldState = $this->getMufaStateFromFile();
        $newState = $this->getMufaState(); // 这会触发状态更新
        
        // 如果状态发生了变化，向当前玩家发送消息
        if ($oldState['status'] !== $newState['status'] && $oldState['status'] !== null) {
            $messages = $this->getStateChangeMessages($oldState['status'], $newState['status']);
            
            // 根据当前房间发送对应的消息
            if (isset($messages[$currentRoom])) {
                foreach ($messages[$currentRoom] as $msg) {
                    MessageDaemon::queueMessageToSelf($charId, $msg, 'room');
                }
            }
        }
        
        return $newState;
    }
    
    /**
     * 直接从文件读取状态（不触发更新）
     */
    private function getMufaStateFromFile(): ?array {
        $stateFile = __DIR__ . '/../data/mufa_state.json';
        
        if (file_exists($stateFile)) {
            $content = file_get_contents($stateFile);
            return json_decode($content, true);
        }
        
        return null;
    }
    
    /**
     * 获取状态变化时的消息
     */
    private function getStateChangeMessages(string $oldStatus, string $newStatus): array {
        $messages = [];
        $hiy = defined('HIY') ? HIY : '';
        $nor = defined('NOR') ? NOR : '';
        
        if ($oldStatus === 'at_shore' && $newStatus === 'sailing_away') {
            $messages['changan/eastseashore'] = [$hiy . '一阵浪头打来，木筏缓缓漂去...' . $nor];
            $messages['changan/mufa'] = [$hiy . '周围是白茫茫一片大海，你已经远离任何陆地的视线...' . $nor];
        } elseif ($oldStatus === 'sailing_away' && $newStatus === 'at_dest') {
            $messages['changan/mufa'] = [$hiy . '木筏一沉，搁浅了。忽然竟是登陆之处，赶紧上去罢。' . $nor];
            $messages['changan/aolaiws'] = [$hiy . '木筏已经靠岸，可以上船了。' . $nor];
        } elseif ($oldStatus === 'at_dest' && $newStatus === 'sailing_back') {
            $messages['changan/mufa'] = [$hiy . '一阵浪头打来，木筏缓缓漂去...' . $nor];
            $messages['changan/aolaiws'] = [$hiy . '木筏缓缓离开岸边，向大海深处漂去...' . $nor];
        } elseif ($oldStatus === 'sailing_back' && $newStatus === 'at_shore') {
            $messages['changan/eastseashore'] = [$hiy . '一只木筏缓缓漂回岸边。' . $nor];
        }
        
        return $messages;
    }
    
    /**
     * 更新木筏状态
     */
    private function updateMufaState(string $status, ?int $triggerTime = null): void {
        $state = [
            'status' => $status,
            'timestamp' => time(),
            'trigger_time' => $triggerTime ?? time()
        ];
        
        $stateFile = __DIR__ . '/../data/mufa_state.json';
        $dataDir = dirname($stateFile);
        
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }
        
        file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE));
    }
    

}

