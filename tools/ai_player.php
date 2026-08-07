<?php
/**
 * AI 玩家管理工具
 * 
 * 用法：
 *   php tools/ai_player.php list              - 列出所有 AI 玩家
 *   php tools/ai_player.php create <名称>     - 创建 AI 玩家（默认男性人族）
 *   php tools/ai_player.php create <名称> <性别> <种族>  - 创建指定属性的 AI 玩家
 *   php tools/ai_player.php mark <角色ID>     - 将现有角色标记为 AI 玩家
 *   php tools/ai_player.php unmark <角色ID>   - 取消 AI 玩家标记
 *   php tools/ai_player.php login             - 登录所有 AI 玩家
 *   php tools/ai_player.php logout            - 登出所有 AI 玩家
 *   php tools/ai_player.php tick              - 手动触发一次 AI tick
 *   php tools/ai_player.php tick debug        - 手动触发一次 AI tick，并开启详细 AI 日志
 *   php tools/ai_player.php status            - 查看 AI 玩家状态
 * 
 * 示例：
 *   C:\BtSoft\php\85\php.exe U:\xyj\tools\ai_player.php create 剑侠客
 *   C:\BtSoft\php\85\php.exe U:\xyj\tools\ai_player.php create 小龙女 female demon
 *   C:\BtSoft\php\85\php.exe U:\xyj\tools\ai_player.php mark 32
 *   C:\BtSoft\php\85\php.exe U:\xyj\tools\ai_player.php status
 */

$basePath = dirname(__DIR__);

require_once $basePath . '/config/game.php';
require_once $basePath . '/includes/db.php';
require_once $basePath . '/includes/functions.php';
require_once $basePath . '/models/Character.php';
require_once $basePath . '/helpers/AiPlayerHelper.php';
require_once $basePath . '/daemons/AiPlayerDaemon.php';

$command = $argv[1] ?? 'help';
$arg1 = $argv[2] ?? '';
$arg2 = $argv[3] ?? '';
$arg3 = $argv[4] ?? '';

function printHelp() {
    echo "AI 玩家管理工具\n";
    echo "========================================\n";
    echo "用法：\n";
    echo "  php ai_player.php list              - 列出所有 AI 玩家\n";
    echo "  php ai_player.php create <名称>     - 创建 AI 玩家\n";
    echo "  php ai_player.php create <名称> <性别> <种族>\n";
    echo "  php ai_player.php mark <角色ID>     - 标记为 AI 玩家\n";
    echo "  php ai_player.php unmark <角色ID>   - 取消标记\n";
    echo "  php ai_player.php login             - 登录所有 AI 玩家\n";
    echo "  php ai_player.php logout            - 登出所有 AI 玩家\n";
    echo "  php ai_player.php tick              - 手动触发 tick\n";
    echo "  php ai_player.php status            - 查看状态\n";
    echo "========================================\n";
}

switch ($command) {
    case 'list':
        $players = Database::queryAll(
            "SELECT id, name, race, gender, online, current_area, current_room, is_ai_player
             FROM characters WHERE is_ai_player = 1 ORDER BY id"
        );
        
        if (empty($players)) {
            echo "暂无 AI 玩家。\n";
            echo "使用 create 命令创建：php ai_player.php create <名称>\n";
        } else {
            echo "AI 玩家列表 (" . count($players) . " 个)：\n";
            echo str_pad("ID", 6) . str_pad("名称", 16) . str_pad("种族", 10) . str_pad("性别", 8) . str_pad("在线", 6) . "位置\n";
            echo str_repeat("-", 80) . "\n";
            foreach ($players as $p) {
                $online = $p['online'] ? '是' : '否';
                $location = ($p['current_area'] ?? '?') . '/' . ($p['current_room'] ?? '?');
                echo str_pad($p['id'], 6) . str_pad($p['name'], 16) . str_pad($p['race'], 10) . str_pad($p['gender'], 8) . str_pad($online, 6) . $location . "\n";
            }
        }
        break;
        
    case 'create':
        $name = $arg1;
        if (empty($name)) {
            echo "错误：请指定 AI 玩家名称。\n";
            echo "用法：php ai_player.php create <名称> [性别] [种族]\n";
            echo "性别：male(男) / female(女)，默认 male\n";
            echo "种族：human(人) / demon(魔) / god(仙) / monster(妖)，默认 human\n";
            break;
        }
        
        $gender = $arg2 ?: 'male';
        $race = $arg3 ?: 'human';
        
        if (!in_array($gender, ['male', 'female'])) {
            echo "错误：无效的性别 '{$gender}'，可选：male, female\n";
            break;
        }
        if (!in_array($race, ['human', 'demon', 'god', 'monster'])) {
            echo "错误：无效的种族 '{$race}'，可选：human, demon, god, monster\n";
            break;
        }
        
        $result = AiPlayerDaemon::createAndLogin($name, $gender, $race);
        
        if ($result['success']) {
            $genderName = ($gender === 'female') ? '女' : '男';
            echo "✓ AI 玩家创建成功！\n";
            echo "  角色ID: {$result['char_id']}\n";
            echo "  名称: {$name}\n";
            echo "  性别: {$genderName}\n";
            echo "  种族: {$race}\n";
            echo "  状态: 已上线\n";
            echo "\n";
            echo "现在可以通过定时任务驱动该 AI 玩家行动：\n";
            echo "  php tasks/AiPlayerTickTask.php\n";
        } else {
            echo "✗ 创建失败: {$result['message']}\n";
        }
        break;
        
    case 'mark':
        $charId = intval($arg1);
        if ($charId <= 0) {
            echo "错误：请指定有效的角色ID。\n";
            break;
        }
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            echo "错误：角色ID {$charId} 不存在。\n";
            break;
        }
        
        if (AiPlayerHelper::markAsAiPlayer($charId)) {
            echo "✓ 已将角色「{$char['name']}」(ID:{$charId}) 标记为 AI 玩家。\n";
        } else {
            echo "✗ 标记失败。\n";
        }
        break;
        
    case 'unmark':
        $charId = intval($arg1);
        if ($charId <= 0) {
            echo "错误：请指定有效的角色ID。\n";
            break;
        }
        
        $char = CharacterModel::find($charId);
        if (!$char) {
            echo "错误：角色ID {$charId} 不存在。\n";
            break;
        }
        
        if (AiPlayerHelper::unmarkAiPlayer($charId)) {
            echo "✓ 已取消角色「{$char['name']}」(ID:{$charId}) 的 AI 玩家标记。\n";
        } else {
            echo "✗ 取消标记失败。\n";
        }
        break;
        
    case 'login':
        $result = AiPlayerDaemon::loginAllAiPlayers();
        echo "登录所有 AI 玩家完成。\n";
        echo "总计: {$result['total']} 个\n";
        foreach ($result['results'] as $r) {
            $status = $r['success'] ? '✓' : '✗';
            echo "  {$status} {$r['name']} (ID:{$r['char_id']})\n";
        }
        break;
        
    case 'logout':
        $result = AiPlayerDaemon::logoutAllAiPlayers();
        echo "登出所有 AI 玩家完成。\n";
        echo "总计: {$result['total']} 个\n";
        break;
        
    case 'tick':
        $debugMode = ($arg1 === 'debug');
        if ($debugMode) {
            AiPlayerHelper::setDebug(true);
            echo "开启 AI 详细日志模式。\n";
        }
        $result = AiPlayerDaemon::runTick(5);
        echo "AI Tick 执行完成。\n";
        echo "在线AI玩家: {$result['total']} 个，本次处理: {$result['processed']} 个\n\n";
        foreach ($result['results'] as $r) {
            $status = ($r['success'] ?? false) ? '✓' : '✗';
            $name = $r['char_name'] ?? ("ID:" . ($r['char_id'] ?? '?'));
            $detail = $r['ai_detail'] ?? $r['message'] ?? '';
            echo "  {$status} {$name} -> {$detail}\n";
        }
        break;
        
    case 'status':
        $status = AiPlayerDaemon::getStatus();
        echo "AI 玩家状态\n";
        echo "========================================\n";
        echo "总AI玩家数: {$status['total']}\n";
        echo "在线AI玩家: {$status['online']}\n\n";
        
        if (!empty($status['players'])) {
            echo str_pad("ID", 6) . str_pad("名称", 16) . str_pad("在线", 6) . str_pad("HP", 12) . "位置\n";
            echo str_repeat("-", 80) . "\n";
            foreach ($status['players'] as $p) {
                $online = $p['online'] ? '✓' : '✗';
                $hp = ($p['kee'] ?? 0) . '/' . ($p['max_kee'] ?? 0);
                $location = ($p['current_area'] ?? '?') . '/' . ($p['current_room'] ?? '?');
                $lastAction = intval($p['ai_last_action'] ?? 0);
                $ago = $lastAction > 0 ? (time() - $lastAction) . '秒前' : '从未';
                echo str_pad($p['id'], 6) . str_pad($p['name'], 16) . str_pad($online, 6) . str_pad($hp, 12) . "{$location} (最后动作: {$ago})\n";
            }
        }
        break;
        
    default:
        printHelp();
        break;
}
