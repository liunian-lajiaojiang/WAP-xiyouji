<?php
/**
 * 命令守护进程 - 命令分发和执行
 */
require_once __DIR__ . '/../helpers/WizardHelper.php';
require_once __DIR__ . '/../models/User.php';

class CommandDaemon {
    
    private static array $commandCache = [];
    
    /**
     * 执行命令
     */
    public static function execute(int $charId, string $command, string $param = ''): array {
        $char = CharacterModel::find($charId);
        
        if (!$char) {
            return ['success' => false, 'message' => '角色不存在'];
        }
        
        // 清理命令
        $command = strtolower(trim($command));
        $param = trim($param);
        
        // 权限检查：通过 WizardHelper 验证用户是否有权执行此命令
        $userId = intval($char['user_id']);
        if (!WizardHelper::canUseCommand($userId, $command)) {
            return ['success' => false, 'message' => '你没有权限执行此命令。'];
        }
        
        // 查找命令文件
        $commandFile = self::findCommand($command);
        
        if (!$commandFile) {
            return ['success' => false, 'message' => '未知命令: ' . $command];
        }
        
        // 执行命令
        try {
            require_once $commandFile;
            $functionName = 'cmd_' . str_replace('-', '_', $command);
            
            if (!function_exists($functionName)) {
                return ['success' => false, 'message' => '命令实现不存在'];
            }
            
            $result = $functionName($charId, $param);
            
            // 记录命令日志
            log_game('COMMAND', "角色 {$char['name']} 执行命令: $command $param");
            
            return $result;
        } catch (Exception $e) {
            error_log("命令执行错误 [$command]: " . $e->getMessage());
            return ['success' => false, 'message' => '命令执行失败'];
        }
    }
    
    /**
     * 查找命令文件
     */
    private static function findCommand(string $command): ?string {
        // 检查缓存
        if (isset(self::$commandCache[$command])) {
            return self::$commandCache[$command];
        }
        
        // 可能的命令文件路径
        $possiblePaths = [
            CMD_PATH . $command . '.php',
            CMD_PATH . str_replace('_', '-', $command) . '.php',
        ];
        
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                self::$commandCache[$command] = $path;
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * 获取可用命令列表
     * @param int $wizLevel 巫师等级，用于过滤可用命令
     */
    public static function getAvailableCommands(int $wizLevel = 0): array {
        $commands = [];
        
        if (!is_dir(CMD_PATH)) {
            return $commands;
        }
        
        $files = glob(CMD_PATH . '*.php');
        
        foreach ($files as $file) {
            $command = basename($file, '.php');
            // 检查命令是否在用户权限范围内
            if (WizardHelper::canUseCommandByLevel($wizLevel, $command)) {
                $commands[] = $command;
            }
        }
        
        sort($commands);
        return $commands;
    }
}

