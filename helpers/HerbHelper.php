<?php
/**
 * 药草助手
 * 处理朱紫国制药任务相关的药草逻辑
 */

class HerbHelper {
    
    // 16种药草列表
    private static $herbList = [
        'yao_dahuang' => '大黄',
        'yao_badou' => '巴豆',
        'yao_baizhi' => '白芷',
        'yao_xionghuang' => '雄黄',
        'yao_cihuang' => '雌黄',
        'yao_chenpi' => '陈皮',
        'yao_longgu' => '龙骨',
        'yao_chuanbei' => '川贝',
        'yao_zijing' => '紫荆',
        'yao_cheqian' => '车前',
        'yao_yangxu' => '羊须',
        'yao_gouweicao' => '狗尾草',
        'yao_hanke' => '蚶壳',
        'yao_wangen' => '莞根',
        'yao_wuzhuhua' => '乌竹花',
        'yao_niuhuang' => '牛黄',
    ];
    
    // 制药配方：需要的三种药草 + 马尿
    private static $prescription = [
        'herbs' => ['yao_dahuang', 'yao_badou', 'yao_baizhi'], // 大黄、巴豆、白芷
        'liquid' => 'horse_urine', // 马尿
    ];
    
    /**
     * 获取随机药草
     * @return array ['item_id' => string, 'name' => string]
     */
    public static function getRandomHerb(): array {
        $keys = array_keys(self::$herbList);
        $randomKey = $keys[array_rand($keys)];
        return [
            'item_id' => $randomKey,
            'name' => self::$herbList[$randomKey],
        ];
    }
    
    /**
     * 获取所有药草列表
     * @return array
     */
    public static function getAllHerbs(): array {
        return self::$herbList;
    }
    
    /**
     * 获取制药配方
     * @return array
     */
    public static function getPrescription(): array {
        return self::$prescription;
    }
    
    /**
     * 检查是否是药草
     * @param string $itemId
     * @return bool
     */
    public static function isHerb(string $itemId): bool {
        return isset(self::$herbList[$itemId]);
    }
    
    /**
     * 获取药草名称
     * @param string $itemId
     * @return string|null
     */
    public static function getHerbName(string $itemId): ?string {
        return self::$herbList[$itemId] ?? null;
    }
    
    /**
     * 检查药盏中的物品是否符合制药配方
     * @param array $containerItems 容器内物品列表
     * @param string|null $liquidType 液体类型
     * @return array ['can_make' => bool, 'reason' => string, 'result' => string]
     */
    public static function checkPrescription(array $containerItems, ?string $liquidType = null): array {
        $prescription = self::$prescription;
        $requiredHerbs = $prescription['herbs'];
        $requiredLiquid = $prescription['liquid'];
        
        // 收集容器内的药草
        $foundHerbs = [];
        foreach ($containerItems as $item) {
            $itemId = $item['item_id'] ?? '';
            if (self::isHerb($itemId)) {
                $foundHerbs[] = $itemId;
            }
        }
        
        // 检查是否有所有需要的药草
        $missingHerbs = [];
        foreach ($requiredHerbs as $herb) {
            if (!in_array($herb, $foundHerbs)) {
                $missingHerbs[] = self::$herbList[$herb];
            }
        }
        
        // 检查液体（马尿）
        $hasLiquid = !empty($liquidType) && $liquidType === $requiredLiquid;
        
        if (!empty($missingHerbs) || !$hasLiquid) {
            $reason = '';
            if (!empty($missingHerbs)) {
                $reason .= '还缺少药草：' . implode('、', $missingHerbs) . '。';
            }
            if (!$hasLiquid) {
                $reason .= '还需要马尿作为药引。';
            }
            return [
                'can_make' => false,
                'reason' => $reason,
                'result' => '',
            ];
        }
        
        return [
            'can_make' => true,
            'reason' => '',
            'result' => '乌金丹',
        ];
    }
}
