<?php
/**
 * 白虎岭区域事件处理器
 * 
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 * 
 * 核心机制：
 * 1. 3D动态迷宫生成（DFS算法）
 * 2. 主迷宫：2×5×5 = 50个房间（entrance → cave）
 * 3. 小迷宫：2×3×2 = 12个房间（jail → small_cave）
 * 4. 舍利子系统：随机生成，埋葬换取潜能
 * 5. 自动清理机制：防止内存泄漏
 * 
 * 注意：白虎岭不在28难列表中，是独立的探索玩法
 */

require_once __DIR__ . '/ActionHandler.php';

class BaihulingHandler extends ActionHandler
{
    // ==================== 常量定义 ====================

    /** 主迷宫维度 */
    private const MAIN_MAZE_I = 2;
    private const MAIN_MAZE_J = 5;
    private const MAIN_MAZE_K = 5;

    /** 小迷宫维度 */
    private const SMALL_MAZE_I = 2;
    private const SMALL_MAZE_J = 3;
    private const SMALL_MAZE_K = 2;

    /** 方向定义（6个方向） */
    private const DIRECTIONS = [
        'down'    => ['inc' => [-1, 0, 0], 'index' => 1],
        'up'      => ['inc' => [1, 0, 0],  'index' => 2],
        'west'    => ['inc' => [0, -1, 0], 'index' => 4],
        'east'    => ['inc' => [0, 1, 0],  'index' => 8],
        'south'   => ['inc' => [0, 0, -1], 'index' => 16],
        'north'   => ['inc' => [0, 0, 1],  'index' => 32],
    ];

    /** 相反方向映射 */
    private const OPPOSITE = [
        'down'  => 'up',
        'up'    => 'down',
        'west'  => 'east',
        'east'  => 'west',
        'south' => 'north',
        'north' => 'south',
    ];

    /** 房间模板 */
    private const CAVE_ROOM = 'qujing/baihuling/cave';
    private const SMALL_CAVE_ROOM = 'qujing/baihuling/small_cave';
    private const SMALL_EXIT_ROOM = 'qujing/baihuling/small_exit';

    /** 舍利子物品ID */
    private const SHELI_ITEM_ID = 'sheli_zi';

    // ==================== ActionHandler 入口 ====================

    /**
     * 执行白虎岭区域动作
     */
    public function execute(int $charId, array $action, array $params = []): array
    {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }

            $actionCmd = $action['action_cmd'] ?? '';

            switch ($actionCmd) {
                case 'enter':
                    return $this->handleEnterMaze($charId, $char, 'main');
                case 'zuan':
                    return $this->handleEnterMaze($charId, $char, 'small');
                case 'bury':
                    return $this->handleBuryShelizi($charId, $char, $params);
                default:
                    return ['success' => false, 'message' => '这里不能这样做。'];
            }
        } catch (\Exception $e) {
            error_log('BaihulingHandler Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            return ['success' => false, 'message' => '系统错误：' . $e->getMessage()];
        }
    }

    // ==================== 迷宫生成核心算法 ====================

    /**
     * 生成3D迷宫（DFS深度优先搜索）
     * 
     * 参考LPC：maze_generator.c do_setup() + search_path()
     * 
     * @param int $max_i X轴层数
     * @param int $max_j Y轴行数
     * @param int $max_k Z轴列数
     * @return array 三维迷宫数组 maze[i][j][k] = 位掩码
     */
    private static function generateMaze(int $max_i, int $max_j, int $max_k): array
    {
        // 初始化三维数组（含边界）
        $maze = [];
        for ($i = 0; $i <= $max_i + 1; $i++) {
            $maze[$i] = [];
            for ($j = 0; $j <= $max_j + 1; $j++) {
                $maze[$i][$j] = array_fill(0, $max_k + 2, 0);
            }
        }

        // 设置边界为-1（墙壁）
        for ($i = 0; $i <= $max_i + 1; $i++) {
            for ($j = 0; $j <= $max_j + 1; $j++) {
                $maze[$i][$j][0] = -1;
                $maze[$i][$j][$max_k + 1] = -1;
            }
        }
        for ($i = 0; $i <= $max_i + 1; $i++) {
            for ($k = 0; $k <= $max_k + 1; $k++) {
                $maze[$i][0][$k] = -1;
                $maze[$i][$max_j + 1][$k] = -1;
            }
        }
        for ($j = 0; $j <= $max_j + 1; $j++) {
            for ($k = 0; $k <= $max_k + 1; $k++) {
                $maze[0][$j][$k] = -1;
                $maze[$max_i + 1][$j][$k] = -1;
            }
        }

        // 从(1,1,1)开始DFS生成路径
        self::searchPath($maze, 1, 1, 1, $max_i, $max_j, $max_k);

        // 确保所有房间都连通
        for ($i = 1; $i <= $max_i; $i++) {
            for ($j = 1; $j <= $max_j; $j++) {
                for ($k = 1; $k <= $max_k; $k++) {
                    if ($maze[$i][$j][$k] == 0) {
                        // 寻找相邻已连通房间
                        $out = null;
                        foreach (self::DIRECTIONS as $dirName => $dirData) {
                            $i1 = $i + $dirData['inc'][0];
                            $j1 = $j + $dirData['inc'][1];
                            $k1 = $k + $dirData['inc'][2];
                            
                            if ($maze[$i1][$j1][$k1] <= 0) continue;
                            
                            $out = $dirName;
                            if (mt_rand(0, 1) == 0) break; // 随机选择
                        }
                        
                        if ($out === null) {
                            log_game('BAIHULING_ERROR', "迷宫生成失败：无法连通房间($i,$j,$k)");
                            return [];
                        }
                        
                        // 建立双向连接
                        $oppositeDir = self::OPPOSITE[$out];
                        $i1 = $i + self::DIRECTIONS[$out]['inc'][0];
                        $j1 = $j + self::DIRECTIONS[$out]['inc'][1];
                        $k1 = $k + self::DIRECTIONS[$out]['inc'][2];
                        
                        $maze[$i1][$j1][$k1] += self::DIRECTIONS[$oppositeDir]['index'];
                        $maze[$i][$j][$k] = self::DIRECTIONS[$out]['index'];
                        
                        // 继续扩展
                        self::searchPath($maze, $i, $j, $k, $max_i, $max_j, $max_k);
                    }
                }
            }
        }

        return $maze;
    }

    /**
     * 深度优先搜索生成路径
     * 
     * 参考LPC：maze_generator.c search_path()
     * 
     * @param array &$maze 引用传递的迷宫数组
     * @param int $i 当前X坐标
     * @param int $j 当前Y坐标
     * @param int $k 当前Z坐标
     * @param int $max_i 最大X
     * @param int $max_j 最大Y
     * @param int $max_k 最大Z
     */
    private static function searchPath(array &$maze, int $i, int $j, int $k, 
                                       int $max_i, int $max_j, int $max_k): void
    {
        $cont = true;
        
        while ($cont) {
            $out = null;
            
            // 优先在平面内搜索（减少上下移动）
            // LPC代码：for(m=5;m>=2;m--) 跳过down(0)和up(1)
            $directionsToTry = ['north', 'south', 'east', 'west'];
            
            foreach ($directionsToTry as $dirName) {
                // 减少上下移动概率（LPC：if(m==2 && random(10)>0) break）
                if ($out !== null && mt_rand(0, 9) > 0) {
                    break;
                }
                
                $dirData = self::DIRECTIONS[$dirName];
                $i1 = $i + $dirData['inc'][0];
                $j1 = $j + $dirData['inc'][1];
                $k1 = $k + $dirData['inc'][2];
                
                if ($maze[$i1][$j1][$k1] != 0) continue; // 已访问
                
                $out = $dirName;
                if (mt_rand(0, 1) == 0) break; // 随机分支
            }
            
            if ($out === null) {
                return; // 无路可走，回溯
            }
            
            $oppositeDir = self::OPPOSITE[$out];
            $i1 = $i + self::DIRECTIONS[$out]['inc'][0];
            $j1 = $j + self::DIRECTIONS[$out]['inc'][1];
            $k1 = $k + self::DIRECTIONS[$out]['inc'][2];
            
            // 建立双向通道
            $maze[$i][$j][$k] += self::DIRECTIONS[$out]['index'];
            $maze[$i1][$j1][$k1] = self::DIRECTIONS[$oppositeDir]['index'];
            
            // 移动到下一个房间继续搜索
            $i = $i1;
            $j = $j1;
            $k = $k1;
        }
    }

    /**
     * 根据迷宫数据生成房间出口映射
     * 
     * 参考LPC：maze_generator.c query_exit()
     * 
     * @param int $i X坐标
     * @param int $j Y坐标
     * @param int $k Z坐标
     * @param array $maze 迷宫数组
     * @param int $max_i 最大X
     * @param int $max_j 最大Y
     * @param int $max_k 最大Z
     * @return array 出口映射 ['north' => 'room_id', ...]
     */
    private static function queryExit(int $i, int $j, int $k, array $maze, 
                                      int $max_i, int $max_j, int $max_k): array
    {
        $exits = [];
        $m = $maze[$i + 1][$j + 1][$k + 1]; // +1因为有边界
        
        foreach (self::DIRECTIONS as $dirName => $dirData) {
            if (($m & $dirData['index']) > 0) {
                $i1 = $i + $dirData['inc'][0];
                $j1 = $j + $dirData['inc'][1];
                $k1 = $k + $dirData['inc'][2];
                
                // 检查是否在有效范围内
                if ($i1 >= 0 && $i1 < $max_i && 
                    $j1 >= 0 && $j1 < $max_j && 
                    $k1 >= 0 && $k1 < $max_k) {
                    $exits[$dirName] = "{$i1},{$j1},{$k1}";
                }
            }
        }
        
        return $exits;
    }

    /**
     * 公开版本的queryExit，供外部调用
     */
    public static function queryExitStatic(int $i, int $j, int $k, array $maze,
                                           int $max_i, int $max_j, int $max_k): array
    {
        return self::queryExit($i, $j, $k, $maze, $max_i, $max_j, $max_k);
    }

    /**
     * 生成迷宫房间的HTML内容
     */
    private function generateMazeRoomHtml(int $charId, array $mazeData, string $pos): string
    {
        return self::generateMazeRoomHtmlStatic($charId, $mazeData, $pos);
    }

    /**
     * 公开版本的generateMazeRoomHtml，供外部调用
     */
    public function generateMazeRoomHtmlPublic(int $charId, array $mazeData, string $pos): string
    {
        return self::generateMazeRoomHtmlStatic($charId, $mazeData, $pos);
    }

    /**
     * 静态版本的generateMazeRoomHtml
     * 输出格式与标准房间一致（纯文本+<br>换行）
     */
    private static function generateMazeRoomHtmlStatic(int $charId, array $mazeData, string $pos): string
    {
        $mazeLayout = $mazeData['layout'];
        $mazeType = $mazeData['type'];
        $dimensions = $mazeData['dimensions'];
        $exitRoom = $mazeData['exit_room'] ?? '';

        list($i, $j, $k) = explode(',', $pos);
        $i = intval($i);
        $j = intval($j);
        $k = intval($k);

        $max_i = $dimensions[0];
        $max_j = $dimensions[1];
        $max_k = $dimensions[2];

        $exits = self::queryExit($i, $j, $k, $mazeLayout, $max_i, $max_j, $max_k);

        // 方向映射（与标准房间一致）
        $directionMap = [
            'north' => '北↑', 'south' => '南↓', 'east' => '东→', 'west' => '西←',
            'northeast' => '东北↗', 'northwest' => '西北↖',
            'southeast' => '东南↘', 'southwest' => '西南↙',
            'up' => '上⇧', 'down' => '下⇩',
            'out' => '出去',
        ];

        // 房间描述
        $roomName = ($mazeType === 'main') ? '洞穴' : '小洞穴';
        $roomDesc = "这是一个阴暗潮湿的{$roomName}，四周石壁上长满了青苔。";

        // 检查是否是出口房间
        $isExit = ($pos === $exitRoom);
        if ($isExit) {
            if ($mazeType === 'main') {
                $roomDesc .= HTML_HIYEL . "\n你发现前方有一个洞口，似乎是出口！" . HTML_NOR . "\n";
                $exits['out'] = 'baihuling/entrance';
            } else {
                $roomDesc .= HTML_HIYEL . "\n你发现前面有一条石缝，钻过去应该能离开！" . HTML_NOR . "\n";
                $exits['out'] = 'baihuling/small_exit';
            }
        }

        // 舍利子生成（33%概率）
        $sheliziKey = 'baihuling_shelizi_' . $charId . '_' . $pos;
        if (!isset($_SESSION[$sheliziKey])) {
            if (mt_rand(1, 100) <= 33) {
                $count = mt_rand(1, 5);
                $_SESSION[$sheliziKey] = $count;
            } else {
                $_SESSION[$sheliziKey] = 0;
            }
        }
        $sheliziCount = intval($_SESSION[$sheliziKey]);
        $hasShelizi = ($sheliziCount > 0);
        if ($hasShelizi) {
            $roomDesc .= HTML_HIGRN . "\n你在角落里发现了{$sheliziCount}颗舍利子！" . HTML_NOR . "\n";
        }

        // === 构建HTML（与标准房间格式一致）===

        // 房间描述
        $html = nl2br($roomDesc);

        // 出口方向提示（帮助玩家找到出口）
        if (!$isExit && !empty($exitRoom)) {
            list($ei, $ej, $ek) = explode(',', $exitRoom);
            $di = intval($ei) - $i;
            $dj = intval($ej) - $j;
            $dk = intval($ek) - $k;
            $dist = abs($di) + abs($dj) + abs($dk);
            
            // 根据最大分量确定主方向
            $hintDir = '';
            if (abs($di) >= abs($dj) && abs($di) >= abs($dk)) {
                $hintDir = ($di > 0) ? '下方' : '上方';
            } elseif (abs($dj) >= abs($di) && abs($dj) >= abs($dk)) {
                $hintDir = ($dj > 0) ? '北方' : '南方';
            } else {
                $hintDir = ($dk > 0) ? '东方' : '西方';
            }
            
            if ($dist <= 3) {
                $html .= HTML_HIYEL . '<br>你感觉到一股微风从' . $hintDir . '吹来，出口应该就在附近！' . HTML_NOR;
            } elseif ($dist <= 8) {
                $html .= '<br>你隐约感觉到出口在' . $hintDir . '。';
            } else {
                $html .= '<br>你完全迷失在洞穴中，只能凭感觉向' . $hintDir . '探索。';
            }
        }

        // 消息区域
        $html .= '<br><span id="demo" style="display:none;"></span>';

        // 出口方向
        if (!empty($exits)) {
            $html .= '<br>明显的方向有：';
            foreach ($exits as $dir => $target) {
                $dirText = $directionMap[$dir] ?? $dir;
                if (strpos($target, ',') !== false) {
                    // 迷宫内部移动
                    $targetName = '洞穴';
                    $html .= '<br><a href="javascript:void(0);" onclick="moveInMaze(\'' . $target . '\')">' . $dirText . ' - ' . $targetName . '</a>';
                } else {
                    // 离开迷宫
                    $targetName = '白虎岭';
                    $html .= '<br><a href="room.php?area=qujing&room=' . urlencode($target) . '">' . $dirText . ' - ' . $targetName . '</a>';
                }
            }
        }

        // 物品（标准房间格式）
        $currentPos = "{$i},{$j},{$k}";
        if ($hasShelizi) {
            $html .= '<br>地上有：';
            $html .= '<a href="item.php?id=sheli_zi">舍利子</a>';
            if ($sheliziCount > 1) {
                $html .= ' x' . $sheliziCount;
            }
            $html .= ' [<a href="javascript:void(0);" onclick="getShelizi(\'' . $currentPos . '\')">拿</a>]';
        }

        return $html;
    }

    // ==================== 舍利子拾取机制 ====================

    /**
     * 处理拾取迷宫中的舍利子
     * 舍利子存储在session中，不走数据库room_items表
     */
    public static function handlePickShelizi(int $charId): array
    {
        try {
            $char = CharacterModel::find($charId);
            if (!$char) {
                return ['success' => false, 'message' => '角色不存在'];
            }

            $pos = $_GET['pos'] ?? $_SESSION['baihuling_current_pos_' . $charId] ?? '';
            if (empty($pos)) {
                return ['success' => false, 'message' => '位置无效'];
            }

            // 检查session中的舍利子数量
            $sheliziKey = 'baihuling_shelizi_' . $charId . '_' . $pos;
            $sheliziCount = intval($_SESSION[$sheliziKey] ?? 0);

            error_log("[SHELIZI_PICK] charId={$charId}, pos={$pos}, key={$sheliziKey}, count={$sheliziCount}");

            if ($sheliziCount <= 0) {
                return ['success' => false, 'message' => '这里没有舍利子'];
            }

            // 添加到背包
            require_once MODEL_PATH . 'Item.php';
            $added = ItemModel::addToInventory($charId, self::SHELI_ITEM_ID, $sheliziCount);

            error_log("[SHELIZI_PICK] addToInventory result: " . ($added ? 'true' : 'false'));

            if (!$added) {
                return ['success' => false, 'message' => '背包添加失败，请稍后再试。'];
            }

            // 清除session中的舍利子
            $_SESSION[$sheliziKey] = 0;

            // 生成消息
            $pickMsg = "你捡起{$sheliziCount}颗舍利子。\n";
            $broadcastMsg = "{$char['name']}捡起{$sheliziCount}颗舍利子。\n";

            log_game('SHELIZI_PICK', "{$char['name']} picked up {$sheliziCount} shelizi at pos={$pos}");

            return [
                'success' => true,
                'message' => $pickMsg,
                'broadcast_message' => $broadcastMsg,
            ];
        } catch (\Exception $e) {
            error_log("[SHELIZI_PICK ERROR] " . $e->getMessage());
            return ['success' => false, 'message' => '拾取失败: ' . $e->getMessage()];
        }
    }

    // ==================== 主迷宫入口逻辑 ====================

    /**
     * 处理进入迷宫（enter/zuan命令）
     * 
     * 参考LPC：entrance.c do_test() / jail.c do_test()
     * 
     * @param int $charId 角色ID
     * @param array $char 角色数据
     * @param string $mazeType 迷宫类型：'main' 或 'small'
     */
    public function handleEnterMaze(int $charId, array $char, string $mazeType): array
    {
        try {
            $roomId = $char['current_room'] ?? '';
            
            // 验证是否在正确的入口房间
            if ($mazeType === 'main' && $roomId !== 'qujing/baihuling/entrance') {
                return ['success' => false, 'message' => '这里没有洞口。'];
            }
            if ($mazeType === 'small' && $roomId !== 'qujing/baihuling/jail') {
                return ['success' => false, 'message' => '这里没有石缝。'];
            }

            error_log("[BAIHULING] 开始生成{$mazeType}迷宫");
            
            // 生成迷宫
            if ($mazeType === 'main') {
                $max_i = self::MAIN_MAZE_I;
                $max_j = self::MAIN_MAZE_J;
                $max_k = self::MAIN_MAZE_K;
            } else {
                $max_i = self::SMALL_MAZE_I;
                $max_j = self::SMALL_MAZE_J;
                $max_k = self::SMALL_MAZE_K;
            }

            $maze = self::generateMaze($max_i, $max_j, $max_k);
            error_log("[BAIHULING] 迷宫生成" . (empty($maze) ? '失败' : '成功'));
            
            if (empty($maze)) {
                return ['success' => false, 'message' => '迷宫生成失败，请稍后再试。'];
            }
            
            error_log("[BAIHULING] maze array size: " . count($maze) . "x" . count($maze[0]) . "x" . count($maze[0][0]));

            // 生成唯一迷宫ID
            $mazeId = 'baihuling_' . $mazeType . '_' . $charId . '_' . time();

            // 保存迷宫到Session
            $_SESSION['baihuling_maze_' . $charId] = [
                'maze_id' => $mazeId,
                'type' => $mazeType,
                'dimensions' => [$max_i, $max_j, $max_k],
                'layout' => $maze,
                'created_at' => time(),
            ];

            // 随机设置出口位置
            $exitI = mt_rand(0, $max_i - 1);
            $exitJ = ($mazeType === 'main') ? (1 + mt_rand(0, $max_j - 2)) : mt_rand(0, $max_j - 1);
            $exitK = mt_rand(0, $max_k - 1);
            
            $_SESSION['baihuling_maze_' . $charId]['exit_room'] = "{$exitI},{$exitJ},{$exitK}";

            // 如果是小迷宫，保存原始出生点
            if ($mazeType === 'small') {
                $_SESSION['baihuling_old_startroom_' . $charId] = $char['startroom'] ?? '';
                
                // 更新玩家出生点为地牢
                Database::execute(
                    "UPDATE characters SET startroom = ? WHERE id = ?",
                    ['qujing/baihuling/jail', $charId]
                );
            }

            // 进入消息
            if ($mazeType === 'main') {
                $enterMsg = HTML_HICYN . '你小心翼翼地走进洞口，探查里面的情况。' . HTML_NOR . "\n";
                $enterMsg .= HTML_HIYEL . '突然你脚下一滑，通体掉了进去！' . HTML_NOR . "\n";
                $enterMsg .= HTML_HIRED . '你摔了个四脚朝天！' . HTML_NOR;
            } else {
                $enterMsg = HTML_HICYN . '你顺着石缝钻进一个小洞里...' . HTML_NOR . "\n";
                $enterMsg .= HTML_HIYEL . '你一头栽到地上摔了下去。' . HTML_NOR;
            }

            // 移动到起点 [0,0,0]
            $startRoom = '0,0,0';
            
            // 在Session中保存当前位置和迷宫数据
            $_SESSION['baihuling_current_pos_' . $charId] = $startRoom;
            // 设置标志，告诉room.php需要显示迷宫内容
            $_SESSION['show_baihuling_maze'] = true;

            // 广播进入消息
            MessageDaemon::broadcastToRoom($roomId, 
                HTML_HICYN . $char['name'] . '进入了白虎岭迷宫。' . HTML_NOR,
                $charId, 'room');

            error_log("[BAIHULING] 准备返回redirect: room.php?area=qujing&room=baihuling/maze&pos={$startRoom}");
            
            return [
                'success' => true,
                'type' => 'move',
                'message' => $enterMsg,
                'leave_message' => '',
                'arrive_message' => '',
                'new_room' => null,
                'redirect' => "room.php?area=qujing&room=baihuling/maze&pos={$startRoom}",
            ];
        } catch (\Exception $e) {
            error_log('[BAIHULING ERROR] ' . $e->getMessage() . ' at line ' . $e->getLine());
            return ['success' => false, 'message' => '系统错误：' . $e->getMessage()];
        }
    }

    // ==================== 舍利子埋葬机制 ====================

    /**
     * 处理埋葬舍利子（bury命令）
     * 
     * 参考LPC：small_exit.c do_bury()
     * 
     * @param int $charId 角色ID
     * @param array $char 角色数据
     * @param array $params 参数（包含item_id）
     */
    public function handleBuryShelizi(int $charId, array $char, array $params): array
    {
        $roomId = $char['current_room'] ?? '';
        
        // 验证是否在出口房间
        if ($roomId !== 'qujing/baihuling/small_exit') {
            return ['success' => false, 'message' => '这里不能埋葬东西。'];
        }

        $itemId = $params['item_id'] ?? self::SHELI_ITEM_ID;

        if ($itemId !== self::SHELI_ITEM_ID) {
            return ['success' => false, 'message' => '你不能把这种东西埋在这里。'];
        }

        // 获取玩家背包中的舍利子数量
        $inventory = ItemModel::getCharacterItems($charId);
        $sheliCount = 0;
        foreach ($inventory as $item) {
            if ($item['item_id'] === self::SHELI_ITEM_ID) {
                $sheliCount = intval($item['quantity'] ?? 1);
                break;
            }
        }

        if ($sheliCount <= 0) {
            return ['success' => false, 'message' => '你身上没有舍利子。'];
        }

        // 计算潜能奖励：每个舍利子4点
        $potentialReward = 4 * $sheliCount;

        // 添加潜能
        Database::execute(
            "UPDATE characters SET potential = potential + ? WHERE id = ?",
            [$potentialReward, $charId]
        );

        // 移除舍利子
        ItemModel::removeFromInventory($charId, self::SHELI_ITEM_ID, $sheliCount);

        // 埋葬消息
        $chineseNum = self::chineseNumber($sheliCount);
        $buryMsg = HTML_HICYN . "你把身上的舍利子埋进了土里，把{$chineseNum}颗舍利子放进去后，小坑自动合上了..." . HTML_NOR . "\n";
        $buryMsg .= HTML_HIGRN . "哇噻，爽歪歪，你的潜能增加了{$potentialReward}点！" . HTML_NOR;

        // 记录日志
        log_game('SHELIZI_BURY', "{$char['name']} buried {$sheliCount} shelizi, got {$potentialReward} potential");

        // 恢复原始出生点
        $oldStartroom = $_SESSION['baihuling_old_startroom_' . $charId] ?? '';
        if (!empty($oldStartroom)) {
            Database::execute(
                "UPDATE characters SET startroom = ? WHERE id = ?",
                [$oldStartroom, $charId]
            );
            unset($_SESSION['baihuling_old_startroom_' . $charId]);
        }

        // 广播消息
        MessageDaemon::broadcastToRoom($roomId,
            HTML_HICYN . $char['name'] . '埋葬了舍利子，获得了潜能。' . HTML_NOR,
            $charId, 'room');

        return [
            'success' => true,
            'type' => 'message',
            'message' => $buryMsg,
        ];
    }

    // ==================== 辅助方法 ====================

    /**
     * 数字转中文
     * 
     * 参考LPC：chinese_number()
     */
    private static function chineseNumber(int $num): string
    {
        $digits = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
        $units = ['', '十', '百', '千', '万'];
        
        if ($num == 0) return '零';
        if ($num < 0) return '负' . self::chineseNumber(-$num);
        if ($num <= 10) return $digits[$num];
        
        $result = '';
        $unitIndex = 0;
        
        while ($num > 0) {
            $digit = $num % 10;
            if ($digit != 0) {
                $result = $digits[$digit] . $units[$unitIndex] . $result;
            } elseif ($unitIndex > 0 && $unitIndex < 4) {
                $result = $digits[0] . $result;
            }
            $num = intdiv($num, 10);
            $unitIndex++;
        }
        
        // 清理多余的零
        $result = str_replace('零零', '零', $result);
        $result = rtrim($result, '零');
        
        return $result ?: '零';
    }

    /**
     * 清理迷宫（销毁所有动态房间）
     * 
     * 参考LPC：entrance.c dest() / small_exit.c dest()
     * 
     * @param int $charId 角色ID
     * @param string $mazeType 迷宫类型
     */
    public static function cleanupMaze(int $charId, string $mazeType): void
    {
        require_once __DIR__ . '/../includes/db.php';
        require_once __DIR__ . '/MessageDaemon.php';

        $sessionKey = 'baihuling_maze_' . $charId;
        if (!isset($_SESSION[$sessionKey])) {
            return;
        }

        $mazeData = $_SESSION[$sessionKey];
        
        // 检查是否有玩家还在迷宫中（简化版：假设玩家已离开）
        // 在真实实现中，需要查询在线玩家位置
        
        // 清除Session
        unset($_SESSION[$sessionKey]);
        
        log_game('BAIHULING_CLEANUP', "清理{$mazeType}迷宫，角色ID={$charId}");
    }
}
