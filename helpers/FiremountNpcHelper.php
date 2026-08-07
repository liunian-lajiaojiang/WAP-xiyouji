<?php
require_once __DIR__ . '/../includes/db.php';

class FiremountNpcHelper {
    
    public static function handleNpcDialogue(int $charId, string $npcId, string $topic): ?array {
        require_once __DIR__ . '/../daemons/FiremountHandler.php';
        
        switch ($npcId) {
            case 'tudi-firemount':
                return self::handleTudi($charId, $topic);
                
            case 'cloud':
            case 'fog':
                return self::handleBrothers($charId, $npcId, $topic);
                
            case 'princess':
                return self::handlePrincess($charId, $topic);
                
            default:
                return null;
        }
    }
    
    public static function handleTudi(int $charId, string $topic): ?array {
        $topics = [
            '铁扇' => '土地公说道：火焰山的铁扇公主有一把芭蕉扇，能熄灭火焰。',
            '灭火' => '土地公说道：要熄灭火焰山，需借铁扇公主的芭蕉扇。',
            '芭蕉骨' => FiremountHandler::teachBoneLocation($charId),
            '铁树' => '土地公说道：翠云山的铁树林里藏有芭蕉骨，你可以去那里搜索收集。',
            'fan' => '土地公说道：铁扇公主有一把芭蕉扇，你可以去找她借。',
            '骨' => FiremountHandler::teachBoneLocation($charId),
        ];
        
        $lowerTopic = strtolower($topic);
        foreach ($topics as $key => $response) {
            if (stripos($lowerTopic, strtolower($key)) !== false || 
                stripos(strtolower($key), $lowerTopic) !== false) {
                return ['success' => true, 'message' => $response];
            }
        }
        
        return [
            'success' => true,
            'message' => '土地公说道：火焰山烈火熊熊，要过去需先借芭蕉扇才行。'
        ];
    }
    
    public static function handleBrothers(int $charId, string $npcId, string $topic): ?array {
        $npcName = $npcId === 'cloud' ? '云里雾' : '雾里云';
        
        $topics = [
            '铁扇公主' => self::handleIntroduceToPrincess($charId),
            '芭蕉扇' => self::handleIntroduceToPrincess($charId),
            '引见' => self::handleIntroduceToPrincess($charId),
            '公主' => self::handleIntroduceToPrincess($charId),
            '借扇' => self::handleIntroduceToPrincess($charId),
            '芭蕉骨' => self::checkBoneProgress($charId, $npcName),
            '骨' => self::checkBoneProgress($charId, $npcName),
        ];
        
        $lowerTopic = strtolower($topic);
        foreach ($topics as $key => $response) {
            if (stripos($lowerTopic, strtolower($key)) !== false || 
                stripos(strtolower($key), $lowerTopic) !== false) {
                return $response;
            }
        }
        
        return [
            'success' => true,
            'message' => "{$npcName}说道：铁扇公主住在翠云山上，我可以帮你引见。"
        ];
    }
    
    private static function handleIntroduceToPrincess(int $charId): array {
        require_once __DIR__ . '/../daemons/FiremountHandler.php';
        return FiremountHandler::introduceToPrincess($charId);
    }
    
    private static function checkBoneProgress(int $charId, string $npcName): array {
        require_once __DIR__ . '/../daemons/FiremountHandler.php';
        $count = FiremountHandler::getBoneCount($charId);
        
        if ($count >= 10) {
            return [
                'success' => true,
                'message' => "{$npcName}说道：你已经收集了{$count}根芭蕉骨，可以找铁扇公主换铁扇了！"
            ];
        }
        
        return [
            'success' => true,
            'message' => "{$npcName}说道：你才收集了{$count}根芭蕉骨，还需要" . (10 - $count) . "根。"
        ];
    }
    
    public static function handlePrincess(int $charId, string $topic): ?array {
        $topics = [
            '铁扇' => self::handleGetFan($charId),
            '芭蕉扇' => self::handleGetFan($charId),
            '扇' => self::handleGetFan($charId),
            '借扇' => self::handleGetFan($charId),
            '灭火' => self::handleGetFan($charId),
            '火焰山' => '铁扇公主说道：火焰山是我夫君牛魔王的地盘，你想灭火？',
        ];
        
        $lowerTopic = strtolower($topic);
        foreach ($topics as $key => $response) {
            if (stripos($lowerTopic, strtolower($key)) !== false || 
                stripos(strtolower($key), $lowerTopic) !== false) {
                return $response;
            }
        }
        
        return [
            'success' => true,
            'message' => '铁扇公主说道：我这芭蕉扇威力无穷，不是随便就能借的。'
        ];
    }
    
    private static function handleGetFan(int $charId): array {
        require_once __DIR__ . '/../daemons/FiremountHandler.php';
        return FiremountHandler::getIronFan($charId);
    }
    
    public static function getNpcInquiry(string $npcId): array {
        switch ($npcId) {
            case 'tudi-firemount':
                return [
                    '铁扇' => '土地公说道：火焰山的铁扇公主有一把芭蕉扇，能熄灭火焰。',
                    '灭火' => '土地公说道：要熄灭火焰山，需借铁扇公主的芭蕉扇。',
                    '芭蕉骨' => ['callable' => 'firemount_tudi_bone'],
                    '铁树' => '土地公说道：翠云山的铁树林里藏有芭蕉骨，你可以去那里搜索收集。',
                    'fan' => '土地公说道：铁扇公主有一把芭蕉扇，你可以去找她借。',
                    '骨' => ['callable' => 'firemount_tudi_bone'],
                ];
                
            case 'cloud':
            case 'fog':
                return [
                    '铁扇公主' => ['callable' => 'firemount_brother_introduce'],
                    '芭蕉扇' => ['callable' => 'firemount_brother_introduce'],
                    '引见' => ['callable' => 'firemount_brother_introduce'],
                    '公主' => ['callable' => 'firemount_brother_introduce'],
                    '借扇' => ['callable' => 'firemount_brother_introduce'],
                    '芭蕉骨' => ['callable' => 'firemount_brother_bone'],
                    '骨' => ['callable' => 'firemount_brother_bone'],
                ];
                
            case 'princess':
                return [
                    '铁扇' => ['callable' => 'firemount_princess_fan'],
                    '芭蕉扇' => ['callable' => 'firemount_princess_fan'],
                    '扇' => ['callable' => 'firemount_princess_fan'],
                    '借扇' => ['callable' => 'firemount_princess_fan'],
                    '灭火' => ['callable' => 'firemount_princess_fan'],
                    '火焰山' => '铁扇公主说道：火焰山是我夫君牛魔王的地盘，你想灭火？',
                ];
                
            default:
                return [];
        }
    }
}
