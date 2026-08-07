<?php
/**
 * 房间导航辅助类 - BFS 路径查找 + 逐步移动
 *
 * AI 玩家通过此辅助类实现"逐步走向目标"的行为，模拟真实玩家。
 * 不再一次性 moveCharacter 跳转到目标房间，而是一步步走。
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Room.php';
require_once __DIR__ . '/AiPlayerHelper.php';

class RoomNavHelper {

    /**
     * BFS 查找从当前房间到目标房间的方向路径
     * @return array|null 方向数组（如 ['east','north']）；找不到返回 null
     */
    public static function findPath(string $fromRoom, string $toRoom): ?array {
        $fromNormalized = self::normalizeRoomId($fromRoom);
        $toNormalized   = self::normalizeRoomId($toRoom);

        if ($fromNormalized === $toNormalized) return [];

        $queue = [['room' => $fromNormalized, 'path' => []]];
        $visited = [$fromNormalized => true];

        $maxDepth = 60;
        $processedCount = 0;
        $maxNodes = 3000;

        while (!empty($queue) && $processedCount < $maxNodes) {
            $current = array_shift($queue);
            $currentRoom = $current['room'];
            $currentPath = $current['path'];
            $processedCount++;

            if (count($currentPath) > $maxDepth) continue;

            $exits = self::getRoomExits($currentRoom);
            if (empty($exits)) continue;

            foreach ($exits as $exit) {
                $targetRoom = $exit['target_room_full'] ?? '';
                if (empty($targetRoom)) continue;

                $targetNormalized = self::normalizeRoomId($targetRoom);

                if (isset($visited[$targetNormalized])) continue;
                $visited[$targetNormalized] = true;

                $newPath = array_merge($currentPath, [$exit['direction']]);

                if ($targetNormalized === $toNormalized) {
                    return $newPath;
                }

                $queue[] = ['room' => $targetNormalized, 'path' => $newPath];
            }
        }

        return null;
    }

    /**
     * 房间动作 → 目标房间映射表（movement 类型的 room_actions）
     * key: "房间ID|动作命令", value: ['target_room' => '目标完整路径', 'use_cmd_go' => true]
     * 这些 room_actions 是真实游戏中的特殊出口（如聚见亭跨出栏杆），
     * 但在 room_exits 表中没有记录，需要硬编码映射以支持 BFS 路径查找。
     */
    private static $ACTION_EXIT_MAP = [
        'ourhome/xiaoting|out'     => ['target_room' => 'dntg/hgs/entrance', 'target_area' => 'dntg', 'use_cmd_go' => true],
        'death/new-out6|go north'   => ['target_room' => 'death/gateway', 'target_area' => 'death', 'use_cmd_go' => true],
        'death/guidaomen|go out'    => ['target_room' => 'death/gate', 'target_area' => 'death', 'use_cmd_go' => true],
    ];

    /**
     * 获取房间的所有出口（返回完整目标路径）
     * 包括 room_exits 表中的标准出口和 room_actions 表中的 movement 类型动作
     */
    private static function getRoomExits(string $roomId): array {
        $roomId = self::normalizeRoomId($roomId);

        $room = Database::queryOne(
            "SELECT id, area, room_id FROM rooms WHERE room_id = ? LIMIT 1",
            [$roomId]
        );

        if (!$room) {
            $parts = explode('/', $roomId);
            if (count($parts) >= 2) {
                $area = $parts[0];
                $shortId = implode('/', array_slice($parts, 1));
                $room = Database::queryOne(
                    "SELECT id, area, room_id FROM rooms WHERE area = ? AND room_id = ? LIMIT 1",
                    [$area, $shortId]
                );
            }
        }

        if (!$room) return [];

        $roomDbId = intval($room['id']);
        $fromArea = $room['area'] ?? '';
        $fullRoomId = $room['room_id'] ?? $roomId;

        $exits = RoomModel::getExits($roomDbId);

        $result = [];
        foreach ($exits as $exit) {
            $targetArea = $exit['target_area'] ?? '';
            $targetRoom = $exit['target_room'] ?? '';

            $targetRoom = self::normalizeRoomId($targetRoom);

            // target_area 为空时，尝试从 target_room 路径推断区域
            // 例如 target_room="changan/eastseashore" → 推断 area="changan"
            if (empty($targetArea) && strpos($targetRoom, '/') !== false) {
                $inferredArea = explode('/', $targetRoom)[0];
                if (!empty($inferredArea) && $inferredArea !== $fromArea) {
                    $targetArea = $inferredArea;
                }
            }

            // 如果 target_room 不以 target_area/ 开头，则补全前缀
            // 兼容 room_exits 表中 "hgs/houshan1"（含子目录但缺 area 前缀）的情况
            if (!empty($targetArea) && strpos($targetRoom, $targetArea . '/') !== 0) {
                $targetRoom = $targetArea . '/' . $targetRoom;
            } elseif (empty($targetArea)) {
                // target_area 确实为空且无法推断 → 默认为当前区域
                $targetArea = $fromArea;
                if (strpos($targetRoom, $targetArea . '/') !== 0) {
                    $targetRoom = $targetArea . '/' . $targetRoom;
                }
            }

            $result[] = [
                'direction'        => $exit['direction'],
                'target_room'      => $exit['target_room'],
                'target_room_full' => $targetRoom,
                'target_area'      => $targetArea,
                'is_action'        => false,
            ];
        }

        // ★ 添加 room_actions 中的 movement 类型动作作为虚拟出口
        // 这些是真实游戏中的特殊出口（如聚见亭跨出栏杆(out)、木筏），不在 room_exits 表中
        // 两层解析：
        //   1) 硬编码 ACTION_EXIT_MAP：已知的特殊房间动作，通过 cmd_go 分发
        //   2) 动态 JSON config：从 room_actions.config 读取 target_area/target_room，通过 ActionRouter 分发
        $movementActions = Database::queryAll(
            "SELECT action_cmd, room_id, config, handler_class, action_name FROM room_actions WHERE action_type = 'movement' AND enabled = 1",
            []
        );
        foreach ($movementActions as $action) {
            $actionRoomId = self::normalizeRoomId($action['room_id'] ?? '');
            if ($actionRoomId !== $fullRoomId) continue;

            $actionCmd = $action['action_cmd'] ?? '';
            $mapKey = "{$actionRoomId}|{$actionCmd}";

            if (isset(self::$ACTION_EXIT_MAP[$mapKey])) {
                // 硬编码映射：已知的特殊房间动作，通过 cmd_go 分发
                $mapped = self::$ACTION_EXIT_MAP[$mapKey];
                $targetRoom = self::normalizeRoomId($mapped['target_room']);
                $result[] = [
                    'direction'        => $actionCmd,
                    'target_room'      => $mapped['target_room'],
                    'target_room_full' => $targetRoom,
                    'target_area'      => $mapped['target_area'] ?? explode('/', $targetRoom)[0],
                    'is_action'        => true,
                    'use_cmd_go'       => $mapped['use_cmd_go'] ?? true,
                    'use_action_router'=> false,
                ];
            } else {
                // 动态解析：从 config JSON 读取目标信息，通过 ActionRouter 分发
                $config = $action['config'] ?? '';
                if (is_string($config)) {
                    $config = json_decode($config, true);
                }
                if (is_array($config) && !empty($config['target_room'])) {
                    $targetRoom = self::normalizeRoomId($config['target_room']);
                    $result[] = [
                        'direction'        => $actionCmd,
                        'target_room'      => $config['target_room'],
                        'target_room_full' => $targetRoom,
                        'target_area'      => $config['target_area'] ?? explode('/', $targetRoom)[0],
                        'is_action'        => true,
                        'use_cmd_go'       => false,
                        'use_action_router'=> true,
                        'action_name'      => $action['action_name'] ?? '',
                        'action_cmd'       => $actionCmd,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 归一化房间 ID
     */
    private static function normalizeRoomId(string $roomId): string {
        $roomId = trim(str_replace('\\', '/', $roomId));
        $roomId = preg_replace('#^/d/#', '', $roomId);
        $roomId = trim($roomId, '/');
        return $roomId;
    }

    /**
     * AI 朝目标房间走一步
     *
     * @param int $charId AI 角色 ID
     * @param array $char  角色数据
     * @param string $targetRoom 目标房间（kaifeng/tianpeng 格式）
     * @return string|null 方向字符串（north/south 等）；已在目标返回 null；找不到路径返回 null 让调用方随机走
     */
    public static function stepTowards(int $charId, array $char, string $targetRoom): ?string {
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;
        $currentRoom = self::normalizeRoomId($fullRoom);

        $targetRoom = self::normalizeRoomId($targetRoom);

        // 已经在目标
        if ($currentRoom === $targetRoom) {
            return null;
        }

        $currentAreaTop = explode('/', $fullRoom)[0] ?? '';
        $targetAreaTop  = explode('/', $targetRoom)[0] ?? '';

        // 跨区域：先尝试找当前区域内最近的通往外区域的出口（含本地BFS兜底）
        if ($currentAreaTop !== $targetAreaTop) {
            $crossDir = self::stepToDifferentArea($charId, $char, $targetAreaTop);
            if ($crossDir !== null) {
                // stepToDifferentArea 已调用 doStep 移动，直接返回方向字符串
                return $crossDir;
            }
            // 当前房间无直达目标区域的出口 → 继续走 BFS（支持经中转区域的多跳路径）
        }

        // BFS 走第一步（支持跨区域路径，如 dntg → changan → city）
        // stepTowards 负责调用 doStep 执行实际移动，返回方向字符串供调用方记录日志
        $path = self::findPath($fullRoom, $targetRoom);
        if ($path === null || empty($path)) {
            return null;
        }

        $firstStepDir = $path[0];
        // doStep 已移动角色，返回方向字符串
        self::doStep($charId, $char, $firstStepDir);
        return $firstStepDir;
    }

    /**
     * 跨区域移动：找当前房间中通往目标区域的出口
     * @return string|null 方向字符串（已在 doStep 中移动）；找不到返回 null
     */
    private static function stepToDifferentArea(int $charId, array $char, string $targetArea): ?string {
        $currentArea = $char['current_area'] ?? '';
        $currentRoom = $char['current_room'] ?? '';
        $fullRoom = (strpos($currentRoom, '/') !== false) ? $currentRoom : $currentArea . '/' . $currentRoom;

        // 第一步：检查当前房间是否有直接通往目标区域的出口（最快路径）
        $exits = self::getRoomExits($fullRoom);
        foreach ($exits as $exit) {
            $exitTargetArea = $exit['target_area'] ?? '';
            if ($exitTargetArea === $targetArea) {
                self::doStep($charId, $char, $exit['direction']);
                return $exit['direction'];
            }
        }

        // 第二步：没有直接出口 → 本地 BFS 寻找当前区域内最近的通往外区域的出口
        // 优先寻找通往目标区域的出口，其次才是任意不同区域的出口
        $currentRoomNormalized = self::normalizeRoomId($fullRoom);
        $queue = [['room' => $currentRoomNormalized, 'firstStep' => null]];
        $visited = [$currentRoomNormalized => true];
        $localMaxNodes = 500;

        // 当前房间有通往任意外区域的出口 → 优先选目标区域，其次用任意出口
        $fallbackDir = null;
        foreach ($exits as $exit) {
            $exitTargetArea = $exit['target_area'] ?? '';
            if ($exitTargetArea !== $currentArea && !empty($exitTargetArea)) {
                if ($exitTargetArea === $targetArea) {
                    self::doStep($charId, $char, $exit['direction']);
                    return $exit['direction'];
                }
                if ($fallbackDir === null) {
                    $fallbackDir = $exit['direction'];
                }
            }
        }

        // 本地 BFS：在当前区域内搜索外区域出口，优先匹配目标区域
        $processedCount = 0;
        $bfsFallbackDir = null;  // BFS 中找到的非目标区域出口（兜底）
        $bfsFallbackStep = null;
        while (!empty($queue) && $processedCount < $localMaxNodes) {
            $current = array_shift($queue);
            $curRoom = $current['room'];
            $firstStep = $current['firstStep'];
            $processedCount++;

            $curExits = self::getRoomExits($curRoom);
            foreach ($curExits as $exit) {
                $targetRoom = $exit['target_room_full'] ?? '';
                if (empty($targetRoom)) continue;

                $targetNormalized = self::normalizeRoomId($targetRoom);
                if (isset($visited[$targetNormalized])) continue;
                $visited[$targetNormalized] = true;

                $exitTargetArea = $exit['target_area'] ?? '';
                // 找到了通往外区域的出口
                if ($exitTargetArea !== $currentArea && !empty($exitTargetArea)) {
                    $stepDir = $firstStep ?? $exit['direction'];
                    // 优先：通往目标区域的出口 → 直接返回
                    if ($exitTargetArea === $targetArea) {
                        self::doStep($charId, $char, $stepDir);
                        return $stepDir;
                    }
                    // 兜底：通往其他区域的出口 → 记住但继续搜索
                    if ($bfsFallbackDir === null) {
                        $bfsFallbackDir = $stepDir;
                    }
                }

                // 继续在当前区域内探索
                if ($firstStep === null) {
                    $queue[] = ['room' => $targetNormalized, 'firstStep' => $exit['direction']];
                } else {
                    $queue[] = ['room' => $targetNormalized, 'firstStep' => $firstStep];
                }
            }
        }

        // BFS 没找到目标区域出口，但有其他跨区域出口 → 用兜底（至少能离开当前区域）
        if ($bfsFallbackDir !== null) {
            self::doStep($charId, $char, $bfsFallbackDir);
            return $bfsFallbackDir;
        }

        // 当前房间兜底
        if ($fallbackDir !== null) {
            self::doStep($charId, $char, $fallbackDir);
            return $fallbackDir;
        }

        // 第三步：找不到任何跨区域出口（如在完全孤立的子区域中）
        return null;
    }

    /**
     * 执行单步移动
     * - 常规出口：调用 AiPlayerHelper::moveCharacter 一步
     * - room_actions 出口：双路径分发
     *   use_cmd_go=true：调用 cmd_go（如聚见亭跨出栏杆(out)、地府穿墙）
     *   use_action_router=true：调用 ActionRouter::handleCustomAction（如木筏上/下、坐木筏）
     */
    private static function doStep(int $charId, array $char, string $direction): array {
        $area = $char['current_area'] ?? 'city';
        $roomId = $char['current_room'] ?? 'city/kezhan';

        if (strpos($roomId, '/') === false) {
            $roomId = $area . '/' . $roomId;
        }

        $room = RoomModel::load($area, $roomId);
        if (!$room) {
            return ['success' => false, 'message' => 'AI房间不存在', 'action' => 'move'];
        }

        // 获取所有出口（包括 room_actions 虚拟出口）
        $allExits = self::getRoomExits($roomId);
        $validExits = [];
        $actionExit = null;
        foreach ($allExits as $exit) {
            if (!empty($exit['direction']) && !empty($exit['target_room_full']) && !empty($exit['target_area'])) {
                $validExits[$exit['direction']] = $exit;
                if (!empty($exit['is_action'])) {
                    $actionExit = $exit; // 记住 action 出口，优先使用
                }
            }
        }

        if (empty($validExits)) {
            return ['success' => false, 'message' => 'AI无出口', 'action' => 'move'];
        }

        // 优先用 direction，如果不存在则返回失败（不再随机选出口，避免走反方向导致振荡）
        if (!isset($validExits[$direction])) {
            return ['success' => false, 'message' => "方向{$direction}无出口", 'action' => 'move'];
        }
        $exitData = $validExits[$direction];

        // ★ 如果是 room_actions 出口，双路径分发：
        //   use_cmd_go=true（硬编码映射）→ cmd_go 执行（触发游戏内置特殊逻辑如 out/穿墙）
        //   use_action_router=true（动态 config）→ ActionRouter 执行（触发 handler_class 如 MufaHandler）
        if (!empty($exitData['is_action'])) {
            if (!empty($exitData['use_action_router'])) {
                // 通过 ActionRouter 分发（适用于有 handler_class 的动作，如木筏、潜水）
                require_once __DIR__ . '/../daemons/ActionRouter.php';
                $actionCmd = $exitData['action_cmd'] ?? $exitData['direction'];
                $actionResult = ActionRouter::handleCustomAction($charId, $actionCmd, '');
                if ($actionResult['success'] ?? false) {
                    return [
                        'success'   => true,
                        'message'   => "执行动作: {$actionCmd}",
                        'action'    => 'move',
                        'ai_detail' => "action_router: {$actionCmd} → {$exitData['target_room_full']}",
                    ];
                } else {
                    return ['success' => false, 'message' => $actionResult['message'] ?? '无法执行', 'action' => 'move'];
                }
            }

            // 通过 cmd_go 执行（适用于在 go.php 中有硬编码处理的房间动作）
            if (!empty($exitData['use_cmd_go'])) {
                require_once __DIR__ . '/../commands/go.php';
                $goResult = cmd_go($charId, $exitData['direction']);
                if ($goResult['success'] ?? false) {
                    return [
                        'success'   => true,
                        'message'   => "执行自定义动作: {$exitData['direction']}",
                        'action'    => 'move',
                        'ai_detail' => "custom_action: {$exitData['direction']} → {$exitData['target_room_full']}",
                    ];
                } else {
                    return ['success' => false, 'message' => $goResult['message'] ?? '无法执行', 'action' => 'move'];
                }
            }

            // 未指定分发方式，跳过
            return ['success' => false, 'message' => '未知动作分发方式', 'action' => 'move'];
        }

        $targetArea   = $exitData['target_area'] ?? $area;
        $targetRoomId = $exitData['target_room'] ?? '';

        // 确保 target_room 是完整的 area/sub/room 格式
        if (strpos($targetRoomId, $targetArea . '/') !== 0) {
            $targetRoomId = $targetArea . '/' . $targetRoomId;
        }

        $oldRoom = $char['current_room'] ?? '';

        // 调用 moveCharacter 移动一步（保持跟随者跟随逻辑）
        AiPlayerHelper::moveCharacter($charId, $targetArea, $targetRoomId, $char['name'] ?? '', $oldRoom);

        return [
            'success'   => true,
            'message'   => "向{$direction}移动",
            'action'    => 'move',
            'ai_detail' => "向{$direction}移动 → {$targetRoomId}",
        ];
    }
}
