/**
 * WebSocket实时消息客户端
 * 用于接收服务器的实时推送消息
 */

class GameWebSocketClient {
    constructor(serverUrl = null) {
        // 自动检测协议（http -> ws, https -> wss）
        if (!serverUrl) {
            const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            const host = window.location.hostname;
            serverUrl = `${protocol}//${host}:8080`;
        }
        
        this.serverUrl = serverUrl;
        this.ws = null;
        this.charId = null;
        this.reconnectInterval = 3000; // 重连间隔3秒
        this.maxReconnectAttempts = 10;
        this.reconnectAttempts = 0;
        this.messageHandlers = {};
        this.isConnected = false;
    }
    
    /**
     * 连接WebSocket服务器
     */
    connect(charId) {
        this.charId = charId;
        
        try {
            this.ws = new WebSocket(this.serverUrl);
            
            this.ws.onopen = () => {
                console.log('✅ WebSocket连接成功');
                this.isConnected = true;
                this.reconnectAttempts = 0;
                
                // 发送认证信息
                this.send({
                    type: 'auth',
                    char_id: charId
                });
                
                // 触发连接成功事件
                if (this.messageHandlers['connected']) {
                    this.messageHandlers['connected']();
                }
            };
            
            this.ws.onmessage = (event) => {
                const data = JSON.parse(event.data);
                this.handleMessage(data);
            };
            
            this.ws.onerror = (error) => {
                console.error('❌ WebSocket错误:', error);
            };
            
            this.ws.onclose = () => {
                console.log('⚠️  WebSocket连接关闭');
                this.isConnected = false;
                this.attemptReconnect();
            };
            
        } catch (e) {
            console.error('WebSocket连接失败:', e);
            this.attemptReconnect();
        }
    }
    
    /**
     * 尝试重新连接
     */
    attemptReconnect() {
        if (this.reconnectAttempts >= this.maxReconnectAttempts) {
            console.error('达到最大重连次数，停止重连');
            return;
        }
        
        this.reconnectAttempts++;
        console.log(`🔄 ${this.reconnectInterval / 1000}秒后尝试重连 (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
        
        setTimeout(() => {
            this.connect(this.charId);
        }, this.reconnectInterval);
    }
    
    /**
     * 发送消息
     */
    send(data) {
        if (this.ws && this.ws.readyState === WebSocket.OPEN) {
            this.ws.send(JSON.stringify(data));
        } else {
            console.warn('WebSocket未连接，消息发送失败');
        }
    }
    
    /**
     * 处理接收到的消息
     */
    handleMessage(data) {
        console.log('收到消息:', data);
        
        // 触发对应的处理器
        if (this.messageHandlers[data.type]) {
            this.messageHandlers[data.type](data);
        }
        
        // 通用处理器
        if (this.messageHandlers['*']) {
            this.messageHandlers['*'](data);
        }
    }
    
    /**
     * 注册消息处理器
     */
    on(messageType, handler) {
        this.messageHandlers[messageType] = handler;
    }
    
    /**
     * 断开连接
     */
    disconnect() {
        if (this.ws) {
            this.ws.close();
            this.ws = null;
        }
    }
    
    /**
     * 检查连接状态
     */
    isConnected() {
        return this.ws && this.ws.readyState === WebSocket.OPEN;
    }
}

// ==================== 使用示例 ====================

// 创建客户端实例
const wsClient = new GameWebSocketClient('ws://localhost:8080');

// 连接服务器（假设charId从页面获取）
const charId = window.currentCharId || 0;
if (charId > 0) {
    wsClient.connect(charId);
}

// 注册消息处理器
wsClient.on('room_message', (data) => {
    // 显示房间消息
    displayMessage(data.message);
});

wsClient.on('tell', (data) => {
    // 显示私聊消息
    displayMessage(`有人对你说道：${data.message}`);
});

wsClient.on('chat', (data) => {
    // 显示聊天消息
    displayMessage(`【聊天】${data.from}：${data.message}`);
});

// 辅助函数：显示消息
function displayMessage(message) {
    const output = document.getElementById('message-output');
    if (output) {
        const msgDiv = document.createElement('div');
        msgDiv.className = 'message';
        msgDiv.innerHTML = ansiToHtml(message);
        output.appendChild(msgDiv);
        output.scrollTop = output.scrollHeight;
    }
}

// ANSI转HTML（复用现有函数）
function ansiToHtml(text) {
    // TODO: 实现ANSI颜色代码转换
    return text;
}

// 导出供其他模块使用
window.GameWebSocketClient = GameWebSocketClient;
window.wsClient = wsClient;
