<?php
/**
 * 房产业务逻辑类
 * 处理房产购买、房间/床铺定制、物品存取、访客管理、婴儿管理等功能
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../models/Item.php';

class HomeHelper {

    /**
     * 检查角色是否已有房产（作为owner或spouse）
     *
     * @param int $charId 角色ID
     * @return bool
     */
    public static function hasHome($charId): bool {
        $sql = 'SELECT COUNT(*) as cnt FROM player_homes WHERE owner_id = ? OR spouse_id = ?';
        $result = Database::queryOne($sql, [$charId, $charId]);
        return $result && $result['cnt'] > 0;
    }

    /**
     * 购买房产，创建记录
     * 不处理金币扣除（金币扣除在命令层处理）
     *
     * @param int $charId 角色ID（房主）
     * @param int $spouseId 配偶角色ID
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function buyHome($charId, $spouseId): array {
        try {
            $check = self::canBuyHome($charId);
            if (!$check['can']) {
                return ['success' => false, 'message' => $check['message']];
            }

            if (self::hasHome($spouseId)) {
                return ['success' => false, 'message' => '你的配偶已经拥有房产了！'];
            }

            $sql = 'INSERT INTO player_homes (owner_id, spouse_id, room_name, room_desc, bed_name, bed_desc, max_items, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())';
            Database::execute($sql, [$charId, $spouseId, '温馨小屋', '一间布置温馨的小屋', '木床', '一张普通的木床', 20]);

            return ['success' => true, 'message' => '恭喜你们购置了房产，拥有了自己的小家！'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '购房失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取房产信息（角色作为owner或spouse均可）
     *
     * @param int $charId 角色ID
     * @return array|null 房产信息数组，无房产则返回null
     */
    public static function getHome($charId): ?array {
        $sql = 'SELECT * FROM player_homes WHERE owner_id = ? OR spouse_id = ? LIMIT 1';
        $result = Database::queryOne($sql, [$charId, $charId]);
        return $result ?: null;
    }

    /**
     * 修改房间名称/描述
     *
     * @param int $homeId 房产ID
     * @param string|null $name 新房间名称，null表示不修改
     * @param string|null $desc 新房间描述，null表示不修改
     * @return bool
     */
    public static function updateRoom($homeId, $name = null, $desc = null): bool {
        try {
            $sets = [];
            $params = [];

            if ($name !== null) {
                $sets[] = 'room_name = ?';
                $params[] = $name;
            }
            if ($desc !== null) {
                $sets[] = 'room_desc = ?';
                $params[] = $desc;
            }

            if (empty($sets)) {
                return false;
            }

            $params[] = $homeId;
            $sql = 'UPDATE player_homes SET ' . implode(', ', $sets) . ' WHERE id = ?';
            Database::execute($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 修改床铺名称/描述
     *
     * @param int $homeId 房产ID
     * @param string|null $name 新床铺名称，null表示不修改
     * @param string|null $desc 新床铺描述，null表示不修改
     * @return bool
     */
    public static function updateBed($homeId, $name = null, $desc = null): bool {
        try {
            $sets = [];
            $params = [];

            if ($name !== null) {
                $sets[] = 'bed_name = ?';
                $params[] = $name;
            }
            if ($desc !== null) {
                $sets[] = 'bed_desc = ?';
                $params[] = $desc;
            }

            if (empty($sets)) {
                return false;
            }

            $params[] = $homeId;
            $sql = 'UPDATE player_homes SET ' . implode(', ', $sets) . ' WHERE id = ?';
            Database::execute($sql, $params);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 存放物品到房产
     *
     * @param int $homeId 房产ID
     * @param int $itemId 物品ID（来自character_inventory）
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function storeItem($homeId, $itemId): array {
        try {
            // 先获取物品信息
            $sql = 'SELECT ci.*, i.name FROM character_inventory ci LEFT JOIN items i ON ci.item_id = i.item_id AND ci.category = i.category WHERE ci.id = ?';
            $inventoryItem = Database::queryOne($sql, [$itemId]);
            if (!$inventoryItem) {
                return ['success' => false, 'message' => '物品不存在！'];
            }

            // 检查当前物品数量
            $sql = 'SELECT quantity FROM home_items WHERE home_id = ? AND item_id = ?';
            $existingItem = Database::queryOne($sql, [$homeId, $inventoryItem['item_id']]);

            if ($existingItem) {
                // 物品已存在，更新数量
                $sql = 'UPDATE home_items SET quantity = quantity + ? WHERE home_id = ? AND item_id = ?';
                Database::execute($sql, [$inventoryItem['quantity'], $homeId, $inventoryItem['item_id']]);
            } else {
                // 物品不存在，插入新记录
                $sql = 'INSERT INTO home_items (home_id, item_id, quantity) VALUES (?, ?, ?)';
                Database::execute($sql, [$homeId, $inventoryItem['item_id'], $inventoryItem['quantity']]);
            }

            // 从背包中删除物品
            $sql = 'DELETE FROM character_inventory WHERE id = ?';
            Database::execute($sql, [$itemId]);

            return ['success' => true, 'message' => '物品已存放到家中！'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '存放物品失败：' . $e->getMessage()];
        }
    }

    /**
     * 从房产取出物品到背包
     *
     * @param int $homeId 房产ID
     * @param int $charId 角色ID
     * @param int $homeItemId 房产物品ID
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function retrieveItem($homeId, $charId, $homeItemId): array {
        try {
            // 获取房产物品信息
            $sql = 'SELECT hi.*, i.name FROM home_items hi LEFT JOIN items i ON hi.item_id = i.item_id AND hi.category = i.category WHERE hi.id = ? AND hi.home_id = ?';
            $homeItem = Database::queryOne($sql, [$homeItemId, $homeId]);
            if (!$homeItem) {
                return ['success' => false, 'message' => '物品不存在！'];
            }

            // 检查背包中是否已有相同物品
            // 使用统一的 addToInventory，自动处理液体容器不堆叠
            ItemModel::addToInventory($charId, $homeItem['item_id'], $homeItem['quantity']);

            // 从房产中删除物品
            $sql = 'DELETE FROM home_items WHERE id = ?';
            Database::execute($sql, [$homeItemId]);

            return ['success' => true, 'message' => '物品已取出！'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '取出物品失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取房产存放的物品列表
     *
     * @param int $homeId 房产ID
     * @return array
     */
    public static function getStoredItems($homeId): array {
        $sql = 'SELECT hi.*, i.name FROM home_items hi LEFT JOIN items i ON hi.item_id = i.item_id AND hi.category = i.category WHERE hi.home_id = ?';
        return Database::queryAll($sql, [$homeId]) ?: [];
    }

    /**
     * 邀请访客
     *
     * @param int $homeId 房产ID
     * @param int $guestId 访客角色ID
     * @param int $hostId 房主角色ID（用于发送消息）
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function inviteGuest($homeId, $guestId, $hostId = null): array {
        try {
            $sql = 'SELECT COUNT(*) as cnt FROM home_guests WHERE home_id = ? AND guest_char_id = ?';
            $result = Database::queryOne($sql, [$homeId, $guestId]);
            if ($result && $result['cnt'] > 0) {
                return ['success' => false, 'message' => '该访客已在邀请列表中！'];
            }

            $sql = 'INSERT INTO home_guests (home_id, guest_char_id, invited_at, status) VALUES (?, ?, NOW(), "invited")';
            Database::execute($sql, [$homeId, $guestId]);

            // 发送邀请消息给被邀请的玩家
            if ($hostId) {
                $host = CharacterModel::getFullInfo($hostId);
                $guest = CharacterModel::getFullInfo($guestId);
                if ($host && $guest) {
                    require_once DAEMON_PATH . 'MessageDaemon.php';
                    $msg = "<span style='color: #FF69B4;'>\n【邀请】\n" 
                        . $host['name'] . "邀请你去他家做客！\n"
                        . "请访问 <a href='http://127.0.0.1/functions/home.php?home_id=" . $homeId . "' style='color:#FFD700;'>点击这里</a> 前往\n</span>";
                    MessageDaemon::sendPrivateMessage($guestId, $msg, $hostId);
                }
            }

            return ['success' => true, 'message' => '邀请成功！'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '邀请访客失败：' . $e->getMessage()];
        }
    }

    /**
     * 移除访客
     *
     * @param int $homeId 房产ID
     * @param int $guestId 访客角色ID
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function removeGuest($homeId, $guestId): array {
        try {
            $sql = 'DELETE FROM home_guests WHERE home_id = ? AND guest_char_id = ?';
            Database::execute($sql, [$homeId, $guestId]);

            return ['success' => true, 'message' => '已移除访客！'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '移除访客失败：' . $e->getMessage()];
        }
    }

    /**
     * 检查是否为访客
     *
     * @param int $homeId 房产ID
     * @param int $charId 角色ID
     * @return bool
     */
    public static function isGuest($homeId, $charId): bool {
        $sql = 'SELECT COUNT(*) as cnt FROM home_guests WHERE home_id = ? AND guest_char_id = ?';
        $result = Database::queryOne($sql, [$homeId, $charId]);
        return $result && $result['cnt'] > 0;
    }

    /**
     * 获取访客列表（包含角色名称）
     *
     * @param int $homeId 房产ID
     * @return array
     */
    public static function getGuests($homeId): array {
        $sql = 'SELECT hg.*, gc.name FROM home_guests hg LEFT JOIN characters gc ON hg.guest_char_id = gc.id WHERE hg.home_id = ?';
        return Database::queryAll($sql, [$homeId]) ?: [];
    }

    /**
     * 喂养孩子
     *
     * @param int $homeId 房产ID
     * @param string $babyName 孩子名字
     * @param int $charId 喂养者角色ID
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function feedBaby($homeId, $babyName, $charId): array {
        try {
            $baby = Database::queryOne(
                'SELECT * FROM home_babies WHERE home_id = ? AND name = ?',
                [$homeId, $babyName]
            );
            
            if (!$baby) {
                return ['success' => false, 'message' => '找不到名为"' . $babyName . '"的孩子！'];
            }

            $hunger = intval($baby['hunger'] ?? 0);
            $age = intval($baby['age'] ?? 0);

            if ($hunger < 100) {
                $hunger += 20;
                if ($hunger > 100) $hunger = 100;
                
                Database::execute(
                    'UPDATE home_babies SET hunger = ? WHERE id = ?',
                    [$hunger, $baby['id']]
                );
                
                $message = '你喂了' . $babyName . '一些食物，小家伙吃得很开心！';
                
                if ($hunger >= 100 && $age < 18) {
                    $age++;
                    Database::execute(
                        'UPDATE home_babies SET age = ? WHERE id = ?',
                        [$age, $baby['id']]
                    );
                    $message .= ' ' . $babyName . '长大了一岁！';
                    
                    if ($age >= 18) {
                        $message .= ' ' . $babyName . '已经成年了！';
                    }
                }
                
                return ['success' => true, 'message' => $message];
            } else {
                return ['success' => false, 'message' => $babyName . '现在不饿，不需要喂食。'];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => '喂养失败：' . $e->getMessage()];
        }
    }

    /**
     * 生育婴儿
     *
     * @param int $homeId 房产ID
     * @param string $name 婴儿名称
     * @param string $gender 性别 ('male' 或 'female')
     * @return array ['success'=>bool, 'message'=>string]
     */
    public static function addBaby($homeId, $name, $gender): array {
        try {
            $sql = 'SELECT COUNT(*) as cnt FROM home_babies WHERE home_id = ?';
            $result = Database::queryOne($sql, [$homeId]);
            if ($result && $result['cnt'] >= 3) {
                return ['success' => false, 'message' => '最多只能生育3个孩子！'];
            }

            $sql = 'INSERT INTO home_babies (home_id, name, gender, born_at, hunger, age) VALUES (?, ?, ?, NOW(), 0, 0)';
            Database::execute($sql, [$homeId, $name, $gender]);

            return ['success' => true, 'message' => '恭喜！孩子已出生！'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => '生育失败：' . $e->getMessage()];
        }
    }

    /**
     * 获取婴儿列表
     *
     * @param int $homeId 房产ID
     * @return array
     */
    public static function getBabies($homeId): array {
        $sql = 'SELECT * FROM home_babies WHERE home_id = ? ORDER BY born_at';
        return Database::queryAll($sql, [$homeId]) ?: [];
    }

    /**
     * 验证购房条件
     *
     * @param int $charId 角色ID
     * @return array ['can'=>bool, 'message'=>string]
     */
    public static function canBuyHome($charId): array {
        // 检查角色信息
        require_once __DIR__ . '/../models/Character.php';
        $char = CharacterModel::find($charId);
        if (!$char) {
            return ['can' => false, 'message' => '角色不存在！'];
        }

        // 检查是否已婚
        if (empty($char['couple_id'])) {
            return ['can' => false, 'message' => '你还没有结婚，无法购房！'];
        }

        // 检查是否已有房产
        if (self::hasHome($charId)) {
            return ['can' => false, 'message' => '你已经拥有房产了！'];
        }

        return ['can' => true, 'message' => '可以购房！'];
    }

    /**
     * 获取访客可以访问的房产
     *
     * @param int $guestCharId 访客角色ID
     * @return array|null
     */
    public static function getVisitableHome($guestCharId): ?array {
        try {
            $sql = 'SELECT h.* FROM home_guests g 
                    LEFT JOIN player_homes h ON g.home_id = h.id 
                    WHERE g.guest_char_id = ? AND g.status = "invited"';
            $result = Database::queryOne($sql, [$guestCharId]);
            return $result ?: null;
        } catch (Exception $e) {
            error_log("[HomeHelper] getVisitableHome error: " . $e->getMessage());
            return null;
        }
    }
}
