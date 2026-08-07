<?php
/**
 * 巫师权限辅助类
 * 参考 xyj2000-php 重构，xyj、xyj2000、xyj2000-php 是三个完全独立的项目，xyj 不能引用另外两个项目的文件
 */

require_once __DIR__ . '/../includes/db.php';

class WizardHelper {
    
    // 巫师等级常量（参考原始项目）
    const LEVEL_PLAYER = 0;       // 普通玩家
    const LEVEL_ELDER = 1;        // 长老（高级玩家/准巫师）
    const LEVEL_IMMORTAL = 2;     // 神仙（初级巫师权限）
    const LEVEL_APPRENTICE = 3;   // 学徒巫师
    const LEVEL_WIZARD = 4;       // 正式巫师
    const LEVEL_ARCH = 5;         // 大巫师/建筑巫师
    const LEVEL_ADMIN = 6;        // 系统管理员
    
    // 等级名称映射
    const LEVEL_NAMES = [
        self::LEVEL_PLAYER => '玩家',
        self::LEVEL_ELDER => '长老',
        self::LEVEL_IMMORTAL => '神仙',
        self::LEVEL_APPRENTICE => '学徒巫师',
        self::LEVEL_WIZARD => '巫师',
        self::LEVEL_ARCH => '大巫师',
        self::LEVEL_ADMIN => '管理员'
    ];
    
    // 等级英文名称映射（用于显示）
    const LEVEL_TITLES = [
        self::LEVEL_PLAYER => '(player)',
        self::LEVEL_ELDER => '(elder)',
        self::LEVEL_IMMORTAL => '(immortal)',
        self::LEVEL_APPRENTICE => '(apprentice)',
        self::LEVEL_WIZARD => '(wizard)',
        self::LEVEL_ARCH => '(arch)',
        self::LEVEL_ADMIN => '(admin)'
    ];
    
    /**
     * 命令目录权限映射
     * 高等级巫师可以访问低等级的命令目录
     */
    const COMMAND_PATHS = [
        self::LEVEL_ADMIN => ['adm', 'arch', 'wiz', 'imm', 'eld', 'usr', 'std'],
        self::LEVEL_ARCH => ['arch', 'wiz', 'imm', 'eld', 'usr', 'std'],
        self::LEVEL_WIZARD => ['wiz', 'imm', 'eld', 'usr', 'std'],
        self::LEVEL_APPRENTICE => ['wiz', 'imm', 'eld', 'usr', 'std'],
        self::LEVEL_IMMORTAL => ['imm', 'eld', 'usr', 'std'],
        self::LEVEL_ELDER => ['eld', 'usr', 'std'],
        self::LEVEL_PLAYER => ['usr', 'std']
    ];
    
    /**
     * 命令分类定义
     * 每个命令对应其所需的最低巫师等级
     */
    const COMMAND_LEVELS = [
        // admin 统一管理命令（immortal+可进入，具体子命令有各自权限）
        'admin' => self::LEVEL_IMMORTAL,
        
        // admin 专用命令
        'dump' => self::LEVEL_ADMIN,
        'dumpprog' => self::LEVEL_ADMIN,
        'profile' => self::LEVEL_ADMIN,
        
        // arch 级别命令
        'cleanup' => self::LEVEL_ARCH,
        'purge' => self::LEVEL_ARCH,
        'purgehouse' => self::LEVEL_ARCH,
        'reclaim' => self::LEVEL_ARCH,
        'setskill' => self::LEVEL_ARCH,
        'shutdown' => self::LEVEL_ARCH,
        'transfer' => self::LEVEL_ARCH,
        'transferlog' => self::LEVEL_ARCH,
        'transferuser' => self::LEVEL_ARCH,
        'wizlock' => self::LEVEL_ARCH,
        
        // wizard 级别命令
        'approve' => self::LEVEL_WIZARD,
        'ban' => self::LEVEL_WIZARD,
        'block' => self::LEVEL_WIZARD,
        'call' => self::LEVEL_WIZARD,
        'clone' => self::LEVEL_WIZARD,
        'config' => self::LEVEL_WIZARD,
        'cost' => self::LEVEL_WIZARD,
        'data' => self::LEVEL_WIZARD,
        'dest' => self::LEVEL_WIZARD,
        'ff' => self::LEVEL_WIZARD,
        'full' => self::LEVEL_WIZARD,
        'home' => self::LEVEL_WIZARD,
        'info' => self::LEVEL_WIZARD,
        'rehash' => self::LEVEL_WIZARD,
        'search' => self::LEVEL_WIZARD,
        'snoop' => self::LEVEL_WIZARD,
        'tail' => self::LEVEL_WIZARD,
        'tojail' => self::LEVEL_WIZARD,
        'toguest' => self::LEVEL_WIZARD,
        'update' => self::LEVEL_WIZARD,
        
        // immortal 级别命令
        'smash' => self::LEVEL_IMMORTAL,
        'summon' => self::LEVEL_IMMORTAL,
        'whois' => self::LEVEL_IMMORTAL,
        'sameip' => self::LEVEL_IMMORTAL,
        
        // elder 级别命令
        'goto' => self::LEVEL_ELDER,
        'where' => self::LEVEL_ELDER,
        'who2' => self::LEVEL_ELDER,
        
        // 玩家命令（无需巫师权限）
        'go' => self::LEVEL_PLAYER,
        'look' => self::LEVEL_PLAYER,
        'say' => self::LEVEL_PLAYER,
        'chat' => self::LEVEL_PLAYER,
        'tell' => self::LEVEL_PLAYER,
        'reply' => self::LEVEL_PLAYER,
        'quit' => self::LEVEL_PLAYER,
        'help' => self::LEVEL_PLAYER,
        'score' => self::LEVEL_PLAYER,
        'inventory' => self::LEVEL_PLAYER,
        'get' => self::LEVEL_PLAYER,
        'drop' => self::LEVEL_PLAYER,
        'use' => self::LEVEL_PLAYER,
        'eat' => self::LEVEL_PLAYER,
        'drink' => self::LEVEL_PLAYER,
        'wear' => self::LEVEL_PLAYER,
        'remove' => self::LEVEL_PLAYER,
        'wield' => self::LEVEL_PLAYER,
        'unwield' => self::LEVEL_PLAYER,
        'kill' => self::LEVEL_PLAYER,
        'fight' => self::LEVEL_PLAYER,
        'cast' => self::LEVEL_PLAYER,
        'skills' => self::LEVEL_PLAYER,
        'spells' => self::LEVEL_PLAYER,
        'practice' => self::LEVEL_PLAYER,
        'study' => self::LEVEL_PLAYER,
        'fly' => self::LEVEL_PLAYER,
        'flyto' => self::LEVEL_PLAYER,
        'pray' => self::LEVEL_PLAYER,
        'team' => self::LEVEL_PLAYER,
        'follow' => self::LEVEL_PLAYER,
        'leave' => self::LEVEL_PLAYER,
        'accept' => self::LEVEL_PLAYER,
        'reject' => self::LEVEL_PLAYER,
        'quest' => self::LEVEL_PLAYER,
        'save' => self::LEVEL_PLAYER,
        'title' => self::LEVEL_PLAYER,
        'nick' => self::LEVEL_PLAYER,
        'describe' => self::LEVEL_PLAYER,
        'story' => self::LEVEL_PLAYER,
        'post' => self::LEVEL_PLAYER,
        'read' => self::LEVEL_PLAYER,
        'list' => self::LEVEL_PLAYER,
        'buy' => self::LEVEL_PLAYER,
        'sell' => self::LEVEL_PLAYER,
        'value' => self::LEVEL_PLAYER,
        'repair' => self::LEVEL_PLAYER,
        'identify' => self::LEVEL_PLAYER,
        'decompose' => self::LEVEL_PLAYER,
        'enchase' => self::LEVEL_PLAYER,
        'combine' => self::LEVEL_PLAYER,
        'refine' => self::LEVEL_PLAYER,
        'upgrade' => self::LEVEL_PLAYER,
        'enchant' => self::LEVEL_PLAYER,
        'imbue' => self::LEVEL_PLAYER,
        'resist' => self::LEVEL_PLAYER,
        'steal' => self::LEVEL_PLAYER,
        'pick' => self::LEVEL_PLAYER,
        'lock' => self::LEVEL_PLAYER,
        'unlock' => self::LEVEL_PLAYER,
        'open' => self::LEVEL_PLAYER,
        'close' => self::LEVEL_PLAYER,
        'push' => self::LEVEL_PLAYER,
        'pull' => self::LEVEL_PLAYER,
        'turn' => self::LEVEL_PLAYER,
        'sit' => self::LEVEL_PLAYER,
        'stand' => self::LEVEL_PLAYER,
        'sleep' => self::LEVEL_PLAYER,
        'wake' => self::LEVEL_PLAYER,
        'rest' => self::LEVEL_PLAYER,
        'exercise' => self::LEVEL_PLAYER,
        'meditate' => self::LEVEL_PLAYER,
        'force' => self::LEVEL_PLAYER,
        'perform' => self::LEVEL_PLAYER,
        'invoke' => self::LEVEL_PLAYER,
        'exert' => self::LEVEL_PLAYER,
        'enable' => self::LEVEL_PLAYER,
        'disable' => self::LEVEL_PLAYER,
        'prepare' => self::LEVEL_PLAYER,
        'unprepare' => self::LEVEL_PLAYER,
        'abandon' => self::LEVEL_PLAYER,
        'learn' => self::LEVEL_PLAYER,
        'teach' => self::LEVEL_PLAYER,
        'train' => self::LEVEL_PLAYER,
        'whisper' => self::LEVEL_PLAYER,
        'emote' => self::LEVEL_PLAYER,
        'semote' => self::LEVEL_PLAYER,
        'channel' => self::LEVEL_PLAYER,
        'alias' => self::LEVEL_PLAYER,
        'unalias' => self::LEVEL_PLAYER,
        'set' => self::LEVEL_PLAYER,
        'unset' => self::LEVEL_PLAYER,
        'prompt' => self::LEVEL_PLAYER,
        'brief' => self::LEVEL_PLAYER,
        'verbose' => self::LEVEL_PLAYER,
        'map' => self::LEVEL_PLAYER,
        'time' => self::LEVEL_PLAYER,
        'date' => self::LEVEL_PLAYER,
        'uptime' => self::LEVEL_PLAYER,
        'version' => self::LEVEL_PLAYER,
        'finger' => self::LEVEL_PLAYER,
        'who' => self::LEVEL_PLAYER,
        'wizlist' => self::LEVEL_PLAYER,
        'news' => self::LEVEL_PLAYER,
        'rules' => self::LEVEL_PLAYER,
        'bug' => self::LEVEL_PLAYER,
        'typo' => self::LEVEL_PLAYER,
        'idea' => self::LEVEL_PLAYER,
        'admire' => self::LEVEL_PLAYER,
        'applaud' => self::LEVEL_PLAYER,
        'beg' => self::LEVEL_PLAYER,
        'bite' => self::LEVEL_PLAYER,
        'blush' => self::LEVEL_PLAYER,
        'bow' => self::LEVEL_PLAYER,
        'burp' => self::LEVEL_PLAYER,
        'bye' => self::LEVEL_PLAYER,
        'cackle' => self::LEVEL_PLAYER,
        'chuckle' => self::LEVEL_PLAYER,
        'clap' => self::LEVEL_PLAYER,
        'comfort' => self::LEVEL_PLAYER,
        'cringe' => self::LEVEL_PLAYER,
        'cry' => self::LEVEL_PLAYER,
        'curse' => self::LEVEL_PLAYER,
        'curtsey' => self::LEVEL_PLAYER,
        'dance' => self::LEVEL_PLAYER,
        'daydream' => self::LEVEL_PLAYER,
        'disagree' => self::LEVEL_PLAYER,
        'dream' => self::LEVEL_PLAYER,
        'faint' => self::LEVEL_PLAYER,
        'frown' => self::LEVEL_PLAYER,
        'gasp' => self::LEVEL_PLAYER,
        'giggle' => self::LEVEL_PLAYER,
        'grin' => self::LEVEL_PLAYER,
        'groan' => self::LEVEL_PLAYER,
        'grovel' => self::LEVEL_PLAYER,
        'grumble' => self::LEVEL_PLAYER,
        'handshake' => self::LEVEL_PLAYER,
        'happy' => self::LEVEL_PLAYER,
        'hug' => self::LEVEL_PLAYER,
        'ignore' => self::LEVEL_PLAYER,
        'knife' => self::LEVEL_PLAYER,
        'kiss' => self::LEVEL_PLAYER,
        'laugh' => self::LEVEL_PLAYER,
        'lean' => self::LEVEL_PLAYER,
        'lick' => self::LEVEL_PLAYER,
        'love' => self::LEVEL_PLAYER,
        'massage' => self::LEVEL_PLAYER,
        'moan' => self::LEVEL_PLAYER,
        'nicker' => self::LEVEL_PLAYER,
        'nod' => self::LEVEL_PLAYER,
        'nuzzle' => self::LEVEL_PLAYER,
        'pat' => self::LEVEL_PLAYER,
        'pinch' => self::LEVEL_PLAYER,
        'poke' => self::LEVEL_PLAYER,
        'ponder' => self::LEVEL_PLAYER,
        'pout' => self::LEVEL_PLAYER,
        'pray' => self::LEVEL_PLAYER,
        'puke' => self::LEVEL_PLAYER,
        'punch' => self::LEVEL_PLAYER,
        'rofl' => self::LEVEL_PLAYER,
        'rose' => self::LEVEL_PLAYER,
        'ruffle' => self::LEVEL_PLAYER,
        'scratch' => self::LEVEL_PLAYER,
        'shake' => self::LEVEL_PLAYER,
        'shiver' => self::LEVEL_PLAYER,
        'shrug' => self::LEVEL_PLAYER,
        'sigh' => self::LEVEL_PLAYER,
        'slap' => self::LEVEL_PLAYER,
        'smile' => self::LEVEL_PLAYER,
        'smirk' => self::LEVEL_PLAYER,
        'snap' => self::LEVEL_PLAYER,
        'snarl' => self::LEVEL_PLAYER,
        'sneer' => self::LEVEL_PLAYER,
        'sneeze' => self::LEVEL_PLAYER,
        'snicker' => self::LEVEL_PLAYER,
        'sniff' => self::LEVEL_PLAYER,
        'snore' => self::LEVEL_PLAYER,
        'spank' => self::LEVEL_PLAYER,
        'squeeze' => self::LEVEL_PLAYER,
        'stare' => self::LEVEL_PLAYER,
        'thank' => self::LEVEL_PLAYER,
        'think' => self::LEVEL_PLAYER,
        'tickle' => self::LEVEL_PLAYER,
        'tongue' => self::LEVEL_PLAYER,
        'wave' => self::LEVEL_PLAYER,
        'whistle' => self::LEVEL_PLAYER,
        'wiggle' => self::LEVEL_PLAYER,
        'wink' => self::LEVEL_PLAYER,
        'yawn' => self::LEVEL_PLAYER,
        'zap' => self::LEVEL_PLAYER
    ];
    
    /**
     * 获取用户巫师等级
     * @param int $userId 用户ID
     * @return int 巫师等级
     */
    public static function getWizardLevel(int $userId): int {
        $user = Database::queryOne("SELECT wizard_level FROM users WHERE id = ?", [$userId]);
        return $user ? intval($user['wizard_level']) : self::LEVEL_PLAYER;
    }
    
    /**
     * 检查用户是否为巫师
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isWizard(int $userId): bool {
        return self::getWizardLevel($userId) >= self::LEVEL_IMMORTAL;
    }
    
    /**
     * 检查用户是否为管理员
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isAdmin(int $userId): bool {
        return self::getWizardLevel($userId) >= self::LEVEL_ADMIN;
    }
    
    /**
     * 检查用户是否为大巫师
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isArch(int $userId): bool {
        return self::getWizardLevel($userId) >= self::LEVEL_ARCH;
    }
    
    /**
     * 检查用户是否有权执行指定命令
     * @param int $userId 用户ID
     * @param string $command 命令名称
     * @return bool
     */
    public static function canUseCommand(int $userId, string $command): bool {
        $userLevel = self::getWizardLevel($userId);
        
        // 如果命令未定义，默认需要玩家权限
        if (!isset(self::COMMAND_LEVELS[$command])) {
            return true;
        }
        
        $requiredLevel = self::COMMAND_LEVELS[$command];
        return $userLevel >= $requiredLevel;
    }
    
    /**
     * 获取用户可用的命令列表
     * @param int $userId 用户ID
     * @return array
     */
    public static function getAvailableCommands(int $userId): array {
        $userLevel = self::getWizardLevel($userId);
        $commands = [];
        
        foreach (self::COMMAND_LEVELS as $command => $requiredLevel) {
            if ($userLevel >= $requiredLevel) {
                $commands[] = $command;
            }
        }
        
        return $commands;
    }
    
    /**
     * 获取用户可访问的命令目录
     * @param int $userId 用户ID
     * @return array
     */
    public static function getCommandPaths(int $userId): array {
        $userLevel = self::getWizardLevel($userId);
        
        // 找到最接近的等级
        foreach (self::COMMAND_PATHS as $level => $paths) {
            if ($userLevel >= $level) {
                return $paths;
            }
        }
        
        return self::COMMAND_PATHS[self::LEVEL_PLAYER];
    }
    
    /**
     * 获取等级名称
     * @param int $level 等级数值
     * @return string
     */
    public static function getLevelName(int $level): string {
        return self::LEVEL_NAMES[$level] ?? '未知';
    }
    
    /**
     * 获取等级英文标题
     * @param int $level 等级数值
     * @return string
     */
    public static function getLevelTitle(int $level): string {
        return self::LEVEL_TITLES[$level] ?? '(unknown)';
    }
    
    /**
     * 设置用户巫师等级
     * @param int $userId 用户ID
     * @param int $level 新等级
     * @param int $operatorId 操作者ID
     * @return bool
     */
    public static function setWizardLevel(int $userId, int $level, int $operatorId): bool {
        // 检查操作者权限
        $operatorLevel = self::getWizardLevel($operatorId);
        $targetLevel = self::getWizardLevel($userId);
        
        // 只能设置比自己等级低的用户
        if ($targetLevel >= $operatorLevel) {
            return false;
        }
        
        // 只能设置比自己等级低的等级
        if ($level >= $operatorLevel) {
            return false;
        }
        
        // 等级范围检查
        if ($level < self::LEVEL_PLAYER || $level > self::LEVEL_ADMIN) {
            return false;
        }
        
        $result = Database::execute("UPDATE users SET wizard_level = ? WHERE id = ?", [$level, $userId]);
        return $result > 0;
    }
    
    /**
     * 检查操作者是否有权限对目标用户执行操作
     * @param int $operatorId 操作者ID
     * @param int $targetId 目标用户ID
     * @return bool
     */
    public static function canOperateOn(int $operatorId, int $targetId): bool {
        $operatorLevel = self::getWizardLevel($operatorId);
        $targetLevel = self::getWizardLevel($targetId);
        
        // 只能操作等级比自己低的用户
        return $operatorLevel > $targetLevel;
    }
    
    /**
     * 检查是否可以snoop目标
     * admin可以snoop任何人，其他巫师只能snoop等级低于自己的人
     * @param int $operatorId 操作者ID
     * @param int $targetId 目标用户ID
     * @return bool
     */
    public static function canSnoop(int $operatorId, int $targetId): bool {
        $operatorLevel = self::getWizardLevel($operatorId);
        $targetLevel = self::getWizardLevel($targetId);
        
        // admin可以snoop任何人
        if ($operatorLevel >= self::LEVEL_ADMIN) {
            return true;
        }
        
        // 其他巫师只能snoop等级低于自己的人
        return $operatorLevel > $targetLevel;
    }
    
    /**
     * 检查是否可以更新目标对象
     * 不能更新比自己等级高的巫师的对象
     * @param int $operatorId 操作者ID
     * @param int $objectOwnerId 对象所有者ID
     * @return bool
     */
    public static function canUpdateObject(int $operatorId, int $objectOwnerId): bool {
        $operatorLevel = self::getWizardLevel($operatorId);
        $ownerLevel = self::getWizardLevel($objectOwnerId);
        
        return $operatorLevel >= $ownerLevel;
    }
    
    /**
     * 按给定等级检查命令权限（不查数据库，供 CommandDaemon 等使用）
     * @param int $level 巫师等级数值
     * @param string $command 命令名称
     * @return bool
     */
    public static function canUseCommandByLevel(int $level, string $command): bool {
        // 如果命令未定义等级要求，默认允许
        if (!isset(self::COMMAND_LEVELS[$command])) {
            return true;
        }
        return $level >= self::COMMAND_LEVELS[$command];
    }
}
