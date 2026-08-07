<?php

class SystemBroadcast {
    
    private static function ensureMessageDaemon(): void {
        require_once DAEMON_PATH . 'MessageDaemon.php';
    }

    public static function newCharacter(string $name, string $race): void {
        $raceNames = [
            'human' => '人族',
            'xian' => '仙族',
            'mo' => '魔族',
            'yao' => '妖族'
        ];
        
        $raceName = $raceNames[$race] ?? $race;
        
        $message = HTML_HIRED . '[系统]' . HIG . " {$name}（{$raceName}）降临到了这个世界！" . HTML_NOR;
        
        self::ensureMessageDaemon();
        MessageDaemon::broadcastToAll($message, 0, 'system');
    }
    
    public static function announce(string $message): void {
        self::ensureMessageDaemon();
        MessageDaemon::broadcastToAll($message, 0, 'system');
    }
    
    public static function deathRumor(string $message): void {
        $fullMessage = HTML_HIMAG . '【传闻】' . HTML_NOR . HTML_HIYEL . " {$message}" . HTML_NOR;
        
        self::ensureMessageDaemon();
        MessageDaemon::broadcastToAll($fullMessage, 0, 'rumor');
    }
    
    public static function playerLogin(string $name, string $id): void {
        $message = HTML_HIYEL . '[系统]' . HTML_NOR . " {$name}（{$id}）连线进入。";
        
        self::ensureMessageDaemon();
        MessageDaemon::broadcastToAll($message, 0, 'system');
    }
}
