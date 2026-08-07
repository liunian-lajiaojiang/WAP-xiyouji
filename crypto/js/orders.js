/**
 * 订单管理模块
 */

// 标记开关存储键名
const MARKERS_STORAGE_KEY = 'chart_markers_visible';

class OrderManager {
    constructor() {
        this.currentTab = 'open';
        this.orders = { open: [], closed: [] };
        this.autoRefreshInterval = null;
        this.chartInstance = null;
        this.markersVisible = this.loadMarkersState();
    }

    // 从 localStorage 加载标记开关状态
    loadMarkersState() {
        try {
            const saved = localStorage.getItem(MARKERS_STORAGE_KEY);
            if (saved !== null) {
                return JSON.parse(saved);
            }
        } catch (e) {
            console.warn('无法从localStorage加载标记状态:', e);
        }
        return true; // 默认显示
    }

    // 保存标记开关状态到 localStorage
    saveMarkersState() {
        try {
            localStorage.setItem(MARKERS_STORAGE_KEY, JSON.stringify(this.markersVisible));
            console.log('标记状态已保存:', this.markersVisible);
        } catch (e) {
            console.warn('无法保存标记状态:', e);
        }
    }

    // 切换标记显示状态
    toggleMarkers() {
        this.markersVisible = !this.markersVisible;
        this.saveMarkersState();
        
        const btn = document.getElementById('toggle-markers');
        if (btn) {
            btn.classList.toggle('active', this.markersVisible);
            btn.style.opacity = this.markersVisible ? '1' : '0.5';
        }
        
        if (this.markersVisible) {
            this.updateChartMarkers();
        } else {
            this.clearChartMarkers();
        }
        
        return this.markersVisible;
    }

    // 清除图表标记
    clearChartMarkers() {
        if (!this.chartInstance) return;
        this.chartInstance.setTradeMarkers([]);
    }

    setChartInstance(chart) {
        this.chartInstance = chart;
    }
    
    updateChartMarkers() {
        if (!this.chartInstance) return;
        
        // 检查标记开关状态
        if (!this.markersVisible) {
            this.chartInstance.setTradeMarkers([]);
            return;
        }
        
        const markers = [];
        const allTrades = [...this.orders.open, ...this.orders.closed];
        
        allTrades.forEach(trade => {
            // 转换订单时间为秒级时间戳
            const openTime = Math.floor(new Date(trade.created_at).getTime() / 1000);
            
            // 添加开仓标记
            if (trade.open_price) {
                const isUp = trade.direction === 'up';
                markers.push({
                    time: openTime,
                    side: isUp ? 'BUY' : 'SELL',
                    price: parseFloat(trade.open_price),
                    text: isUp ? '开涨' : '开跌',
                    color: isUp ? '#0ECB81' : '#F6465D'
                });
            }
            
            // 添加平仓标记（如果已平仓）
            if (trade.close_price && trade.settled_at) {
                const closeTime = Math.floor(new Date(trade.settled_at).getTime() / 1000);
                const isWin = trade.status === 'win';
                const isUp = trade.direction === 'up';
                
                markers.push({
                    time: closeTime,
                    side: isUp ? 'SELL' : 'BUY',
                    price: parseFloat(trade.close_price),
                    text: isWin ? (isUp ? '平涨' : '平跌') : (isUp ? '平跌' : '平涨'),
                    color: isWin ? '#0ECB81' : '#F6465D'
                });
            }
        });
        
        // 按时间排序
        markers.sort((a, b) => a.time - b.time);
        this.chartInstance.setTradeMarkers(markers);
    }

    // 新的初始化方法，直接使用页面传过来的数据
    initWithData(openOrders, closedOrders) {
        console.log('Initializing with data:', openOrders, closedOrders);
        this.orders.open = openOrders || [];
        this.orders.closed = closedOrders || [];
        this.renderOrders();
        this.updateCounts();
        this.updateChartMarkers();
        this.startAutoRefresh();
    }

    async init() {
        await this.loadAllOrders();
        this.startAutoRefresh();
    }
    
    async loadAllOrders() {
        // 同时加载两个标签页的数据
        for (const tab of ['open', 'closed']) {
            try {
                const res = await fetch(`?ajax=orders&type=${tab}`);
                const data = await res.json();
                console.log(`Loaded ${tab} orders:`, data);
                this.orders[tab] = data.orders || data || [];
                this.orders[`${tab}Total`] = data.total || this.orders[tab].length;
            } catch (err) {
                console.error(`Error loading ${tab} orders:`, err);
                this.orders[tab] = [];
                this.orders[`${tab}Total`] = 0;
            }
        }
        
        this.updateCounts();
        this.renderOrders();
        this.updateChartMarkers();
    }

    switchTab(tab) {
        this.currentTab = tab;
        document.querySelectorAll('.orders-tab').forEach(el => {
            el.classList.toggle('active', el.dataset.tab === tab);
        });

        this.renderOrders();
    }

    async loadOrders() {
        const list = document.getElementById('orders-list');
        if (!list) return;

        list.innerHTML = '<div class="loading">加载中...</div>';
        await this.loadAllOrders();
    }

    updateCounts() {
        const openCount = this.orders.openTotal || this.orders.open.length;
        const closedCount = this.orders.closedTotal || this.orders.closed.length;

        const openEl = document.getElementById('open-count');
        const closedEl = document.getElementById('closed-count');

        if (openEl) openEl.textContent = `(${openCount > 10 ? '10+' : openCount})`;
        if (closedEl) closedEl.textContent = `(${closedCount > 10 ? '10+' : closedCount})`;
    }

    renderOrders() {
        const list = document.getElementById('orders-list');
        if (!list) {
            console.error('orders-list element not found');
            return;
        }

        const orders = this.orders[this.currentTab];
        console.log(`Rendering ${this.currentTab} orders:`, orders);

        if (!orders || orders.length === 0) {
            list.innerHTML = '<div class="empty">暂无记录</div>';
            return;
        }

        list.innerHTML = orders.map(order => this.renderOrderItem(order)).join('');
    }

    renderOrderItem(order) {
        const isUp = order.direction === 'up';
        const isWin = order.status === 'win';
        const isPending = order.status === 'pending';
        const profit = order.status === 'win'
            ? `+${(parseFloat(order.amount) + (parseFloat(order.profit) || 0)).toFixed(0)} G`
            : '0 G';
        const settleTime = order.settled_at ? this.formatDateTime(order.settled_at) : '';
        const openPrice = order.open_price ? parseFloat(order.open_price).toFixed(2) : '--';
        const closePrice = order.close_price ? parseFloat(order.close_price).toFixed(2) : '--';

        return `
            <article class="record">
                <div class="record-header">
                    <div class="symbol">
                        <span class="badge ${isUp ? 'up' : 'down'}">${isUp ? '↗' : '↘'}</span>
                        ${order.pair}USDT
                    </div>
                    <span class="profit-text ${isWin || isPending ? 'win' : ''}">${profit}</span>
                </div>
                <div class="details">
                    <div class="detail">
                        <span class="detail-label">数量(G)</span>
                        <span class="detail-value">${order.amount}</span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">开仓价</span>
                        <span class="detail-value">${openPrice}</span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">开仓时间</span>
                        <span class="detail-value">${this.formatDateTime(order.created_at)}</span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">奖金支付率</span>
                        <span class="detail-value bonus">80%</span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">${order.status === 'pending' ? '到期时间' : '平仓价'}</span>
                        <span class="detail-value">${order.status === 'pending' ? this.formatDateTime(order.expire_time) : closePrice}</span>
                    </div>
                    <div class="detail">
                        <span class="detail-label">${order.status === 'pending' ? '状态' : '平仓时间'}</span>
                        <span class="detail-value">${order.status === 'pending' ? '待结算' : settleTime}</span>
                    </div>
                </div>
            </article>
        `;
    }

    formatDateTime(dateStr) {
        if (!dateStr) return '--';
        const d = new Date(dateStr);
        const pad = n => String(n).padStart(2, '0');
        return `${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    startAutoRefresh(interval = 10000) {
        this.autoRefreshInterval = setInterval(() => this.loadAllOrders(), interval);
    }

    stopAutoRefresh() {
        if (this.autoRefreshInterval) {
            clearInterval(this.autoRefreshInterval);
        }
    }
}

// 导出全局订单管理器
window.orderManager = new OrderManager();
