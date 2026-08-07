/**
 * Binance 深色主题图表库
 * 中国红涨绿跌配色 + MA/EMA + 最新价线 + 买卖标记
 * 适用于 Lightweight Charts v4.x
 */

// Binance 风格配色
const BG = '#0B0E11';
const GRID = '#1E2329';
const TEXT = '#848E9C';
const CROSS = '#2B3139';
const UP = '#F6465D';   // 红涨
const DOWN = '#0ECB81'; // 绿跌
const MA7_COLOR = '#F0B90B';   // Binance黄
const MA25_COLOR = '#EAECEF';  // 浅灰
const MA99_COLOR = '#A970FF';  // 紫
const EMA12_COLOR = '#4ECDC4'; // EMA12青绿色
const EMA26_COLOR = '#FF6B6B'; // EMA26红色

// 布林带颜色
const BOLL_MID_COLOR = '#A970FF';
const BOLL_UPPER_COLOR = '#A970FF';
const BOLL_LOWER_COLOR = '#A970FF';

/**
 * 创建币安风格图表
 * @param {Object} config
 * @param {HTMLElement} config.chartEl - 主图表容器
 * @param {HTMLElement} [config.volEl] - 成交量图容器（可选）
 * @param {Object} [config.options] - 额外配置
 * @returns {Object} 图表实例和工具函数
 */
window.binanceDarkCNTheme = function({ chartEl, volEl, options = {} }) {
    console.log('binanceDarkCNTheme 初始化开始');
    
    // 时间格式化函数
    function formatTime(timestamp) {
        let t = timestamp;
        if (t > 1e12) {
            t = t / 1000;
        }
        const date = new Date(t * 1000);
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        const seconds = String(date.getSeconds()).padStart(2, '0');
        return `${month}/${day} ${hours}:${minutes}:${seconds}`;
    }
    
    function formatDateShort(timestamp) {
        let t = timestamp;
        if (t > 1e12) {
            t = t / 1000;
        }
        const date = new Date(t * 1000);
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${month}/${day} ${hours}:${minutes}`;
    }
    
    // 基础图表配置
    const baseChartOptions = {
        layout: {
            background: { color: BG },
            textColor: TEXT,
            fontFamily: 'system-ui, -apple-system, Segoe UI, Roboto, Arial',
            fontSize: 12,
        },
        grid: {
            vertLines: { color: GRID },
            horzLines: { color: GRID },
        },
        crosshair: {
            mode: LightweightCharts.CrosshairMode.Normal,
            vertLine: {
                color: CROSS,
                width: 1,
                style: LightweightCharts.LineStyle.Solid,
                labelVisible: true,
                labelBackgroundColor: CROSS,
            },
            horzLine: {
                color: CROSS,
                width: 1,
                style: LightweightCharts.LineStyle.Solid,
                labelVisible: true,
                labelBackgroundColor: CROSS,
            },
        },
        rightPriceScale: {
            borderColor: GRID,
            textColor: TEXT,
            scaleMargins: { top: 0.05, bottom: 0.05 },
            autoScale: true,
            visible: true,
        },
        timeScale: {
            borderColor: GRID,
            timeVisible: true,
            secondsVisible: false,
            rightOffset: 6,
            barSpacing: 8,
        },
        handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: true },
        handleScale: { axisPressedMouseMove: true, mouseWheel: true, pinch: true },
        ...options.chartOptions,
    };
    
    console.log('创建图表...');
    // 创建主图表
    const chart = LightweightCharts.createChart(chartEl, baseChartOptions);
    console.log('图表创建成功');
    
    // 当前蜡烛图数据缓存
    let currentCandles = [];
    let lastPriceLine = null;
    
    console.log('创建蜡烛图系列...');
    // 创建蜡烛图系列（简化配置）
    const candleSeries = chart.addCandlestickSeries({
        upColor: UP,
        downColor: DOWN,
        borderUpColor: UP,
        borderDownColor: DOWN,
        wickUpColor: UP,
        wickDownColor: DOWN,
        priceLineVisible: false,
        lastValueVisible: false,
        ...options.candles,
    });
    console.log('蜡烛图系列创建成功');
    
    console.log('创建EMA系列...');
    // 创建 EMA 线系列
    let ema7Series, ema25Series, ema99Series;
    try {
        ema7Series = chart.addLineSeries({
            color: '#F0B90B',
            lineWidth: 2,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
            ...options.ema7,
        });
        
        ema25Series = chart.addLineSeries({
            color: '#EAECEF',
            lineWidth: 2,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
            ...options.ema25,
        });
        
        ema99Series = chart.addLineSeries({
            color: '#4ECDC4',
            lineWidth: 2,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
            ...options.ema99,
        });
        console.log('EMA系列创建成功');
    } catch (e) {
        console.error('创建EMA系列失败:', e);
    }
    
    console.log('创建布林带系列...');
    // 创建布林带系列
    let bollMidSeries, bollUpperSeries, bollLowerSeries;
    try {
        bollMidSeries = chart.addLineSeries({
            color: BOLL_MID_COLOR,
            lineWidth: 2,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
            ...options.bollMid,
        });
        
        bollUpperSeries = chart.addLineSeries({
            color: BOLL_UPPER_COLOR,
            lineWidth: 2,
            lineStyle: 1,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
            ...options.bollUpper,
        });
        
        bollLowerSeries = chart.addLineSeries({
            color: BOLL_LOWER_COLOR,
            lineWidth: 2,
            lineStyle: 1,
            priceLineVisible: false,
            lastValueVisible: false,
            crosshairMarkerVisible: false,
            ...options.bollLower,
        });
        console.log('布林带系列创建成功');
    } catch (e) {
        console.error('创建布林带系列失败:', e);
    }
    
    // 成交量图表暂时不处理
    let volChart = null;
    let volSeries = null;
    
    /**
     * 计算 EMA (指数移动平均线)
     */
    function calcEMA(candles, period) {
        if (!Array.isArray(candles) || candles.length === 0) return [];
        
        const out = [];
        const multiplier = 2 / (period + 1);
        let ema;
        
        for (let i = 0; i < candles.length; i++) {
            const c = candles[i];
            if (!c || typeof c.close === 'undefined' || c.close === null || isNaN(Number(c.close))) {
                continue;
            }
            
            const close = Number(c.close);
            
            if (i < period - 1) {
                // 跳过前 N-1 个
                continue;
            } else if (i === period - 1) {
                // 第N个用前N个的简单平均作为初始值
                let sum = 0;
                let count = 0;
                for (let j = 0; j <= i; j++) {
                    const cj = candles[j];
                    if (cj && typeof cj.close !== 'undefined' && cj.close !== null && !isNaN(Number(cj.close))) {
                        sum += Number(cj.close);
                        count++;
                    }
                }
                if (count > 0) {
                    ema = sum / count;
                    out.push({ time: Number(c.time), value: ema });
                }
            } else {
                // 后续使用EMA公式
                if (typeof ema !== 'undefined') {
                    ema = (close - ema) * multiplier + ema;
                    out.push({ time: Number(c.time), value: ema });
                }
            }
        }
        
        return out;
    }
    
    /**
     * 计算布林带
     */
    function calcBOLL(candles, period = 20, stdDev = 2) {
        if (!Array.isArray(candles) || candles.length === 0) return { upper: [], middle: [], lower: [] };
        
        const upper = [];
        const middle = [];
        const lower = [];
        
        for (let i = 0; i < candles.length; i++) {
            const c = candles[i];
            if (!c || typeof c.close === 'undefined' || c.close === null || isNaN(Number(c.close))) {
                continue;
            }
            
            if (i < period - 1) {
                // 数据不足时，跳过
                continue;
            } else {
                // 计算MA和标准差
                let sum = 0;
                let count = 0;
                for (let j = i - period + 1; j <= i; j++) {
                    const cj = candles[j];
                    if (cj && typeof cj.close !== 'undefined' && cj.close !== null && !isNaN(Number(cj.close))) {
                        sum += Number(cj.close);
                        count++;
                    }
                }
                
                if (count < period) {
                    continue;
                }
                
                const ma = sum / count;
                
                let squaredDiffSum = 0;
                for (let j = i - period + 1; j <= i; j++) {
                    const cj = candles[j];
                    if (cj && typeof cj.close !== 'undefined' && cj.close !== null && !isNaN(Number(cj.close))) {
                        squaredDiffSum += Math.pow(Number(cj.close) - ma, 2);
                    }
                }
                
                const std = Math.sqrt(squaredDiffSum / count);
                
                upper.push({ time: Number(c.time), value: ma + stdDev * std });
                middle.push({ time: Number(c.time), value: ma });
                lower.push({ time: Number(c.time), value: ma - stdDev * std });
            }
        }
        
        return { upper, middle, lower };
    }
    
    /**
     * 数据清洗：确保所有蜡烛图数据都是有效的
     */
    function cleanCandleData(candles) {
        if (!Array.isArray(candles)) {
            console.error('cleanCandleData: 不是数组', candles);
            return [];
        }
        
        console.log('cleanCandleData: 开始清洗', candles.length, '条数据');
        
        const cleaned = [];
        for (let i = 0; i < candles.length; i++) {
            const c = candles[i];
            
            // 调试：打印每条数据
            console.log('  原始数据 #' + i, c);
            
            // 必须有 time 字段
            if (!c || typeof c.time === 'undefined' || c.time === null) {
                console.warn('  跳过数据 #' + i + ': 缺少 time 字段');
                continue;
            }
            
            // 确保所有价格字段都是有效的数字
            const fields = ['open', 'high', 'low', 'close'];
            let valid = true;
            const cleanedCandle = {
                time: Number(c.time)
            };
            
            for (const field of fields) {
                if (typeof c[field] === 'undefined' || c[field] === null) {
                    console.warn('  跳过数据 #' + i + ': 缺少字段 ' + field);
                    valid = false;
                    break;
                }
                // 转换为数字
                const num = Number(c[field]);
                if (isNaN(num)) {
                    console.warn('  跳过数据 #' + i + ': 字段 ' + field + ' 不是有效的数字', c[field]);
                    valid = false;
                    break;
                }
                cleanedCandle[field] = num;
            }
            
            if (valid) {
                cleaned.push(cleanedCandle);
                console.log('  保留数据 #' + i, cleanedCandle);
            }
        }
        
        console.log('cleanCandleData: 清洗完成，剩余', cleaned.length, '条数据');
        return cleaned;
    }
    
    /**
     * 设置蜡烛图数据
     */
    function setCandleData(candles, volumes = null) {
        console.log('setCandleData 被调用，原始数据条数:', candles ? candles.length : 0);
        
        // 清洗数据
        const cleanedCandles = cleanCandleData(candles);
        console.log('清洗后的数据条数:', cleanedCandles.length);
        
        if (cleanedCandles.length === 0) {
            console.error('没有有效的蜡烛图数据可以显示');
            return;
        }
        
        currentCandles = cleanedCandles;
        
        try {
            // 设置蜡烛图数据
            console.log('正在设置蜡烛图数据...');
            candleSeries.setData(cleanedCandles);
            console.log('蜡烛图数据已设置');
        } catch (e) {
            console.error('设置蜡烛图数据失败:', e);
            return;
        }
        
        try {
            // 计算并设置指标数据
            const ema7Data = calcEMA(cleanedCandles, 7);
            console.log('EMA7 数据条数:', ema7Data.length);
            if (ema7Series) ema7Series.setData(ema7Data);
            
            const ema25Data = calcEMA(cleanedCandles, 25);
            if (ema25Series) ema25Series.setData(ema25Data);
            
            const ema99Data = calcEMA(cleanedCandles, 99);
            if (ema99Series) ema99Series.setData(ema99Data);
            
            const boll = calcBOLL(cleanedCandles, 20, 2);
            if (bollMidSeries) bollMidSeries.setData(boll.middle);
            if (bollUpperSeries) bollUpperSeries.setData(boll.upper);
            if (bollLowerSeries) bollLowerSeries.setData(boll.lower);
            console.log('所有指标数据已设置');
        } catch (e) {
            console.error('设置指标数据失败:', e);
        }
        
        try {
            // 暂时不强制设置可见范围，让图表自动调整
            // if (cleanedCandles.length >= 30) {
            //     chart.timeScale().fitContent();
            // }
            
            // 设置最新价线（暂时禁用，可能是问题所在）
            console.log('跳过设置最新价线');
            // const last = cleanedCandles[cleanedCandles.length - 1];
            // if (last) {
            //     setLastPriceLine(last.close, last.close >= last.open);
            // }
        } catch (e) {
            console.error('设置最新价线时出错:', e);
        }
    }
    
    /**
     * 更新最后一根蜡烛
     */
    function updateCandle(candle, volume = null) {
        // 清洗单根蜡烛数据
        if (!candle) {
            console.error('updateCandle: 无效的蜡烛数据');
            return;
        }
        
        // 验证单个蜡烛数据
        const fields = ['time', 'open', 'high', 'low', 'close'];
        for (const field of fields) {
            if (typeof candle[field] === 'undefined' || candle[field] === null) {
                console.error('updateCandle: 缺少字段', field);
                return;
            }
        }
        
        // 确保都是数字
        const cleanedCandle = {
            time: Number(candle.time),
            open: Number(candle.open),
            high: Number(candle.high),
            low: Number(candle.low),
            close: Number(candle.close),
        };
        
        // 更新或追加到缓存
        const lastTime = currentCandles.length > 0 ? currentCandles[currentCandles.length - 1].time : 0;
        const isNewBar = cleanedCandle.time !== lastTime;
        
        if (isNewBar) {
            currentCandles.push(cleanedCandle);
            if (currentCandles.length > 200) currentCandles = currentCandles.slice(-200);
        } else {
            currentCandles[currentCandles.length - 1] = cleanedCandle;
        }
        
        candleSeries.update(cleanedCandle);
        
        // 重新计算所有指标
        const ema7Data = calcEMA(currentCandles, 7);
        if (ema7Data.length > 0) ema7Series.update(ema7Data[ema7Data.length - 1]);
        
        const ema25Data = calcEMA(currentCandles, 25);
        if (ema25Data.length > 0) ema25Series.update(ema25Data[ema25Data.length - 1]);
        
        const ema99Data = calcEMA(currentCandles, 99);
        if (ema99Data.length > 0) ema99Series.update(ema99Data[ema99Data.length - 1]);
        
        const boll = calcBOLL(currentCandles, 20, 2);
        if (boll.middle.length > 0) {
            bollMidSeries.update(boll.middle[boll.middle.length - 1]);
            bollUpperSeries.update(boll.upper[boll.upper.length - 1]);
            bollLowerSeries.update(boll.lower[boll.lower.length - 1]);
        }
        
        setLastPriceLine(cleanedCandle.close, cleanedCandle.close >= cleanedCandle.open);
    }
    
    /**
     * 设置最新价水平线
     */
    function setLastPriceLine(price, isUp) {
        const color = isUp ? UP : DOWN;
        if (lastPriceLine) {
            candleSeries.removePriceLine(lastPriceLine);
        }
        lastPriceLine = candleSeries.createPriceLine({
            price,
            color,
            lineWidth: 1,
            lineStyle: 2,
            axisLabelVisible: true,
            title: '',
        });
    }
    
    /**
     * 设置买卖标记
     */
    function setTradeMarkers(trades) {
        if (!currentCandles || currentCandles.length === 0) {
            candleSeries.setMarkers([]);
            return;
        }
        
        const firstCandleTime = currentCandles[0].time;
        const lastCandleTime = currentCandles[currentCandles.length - 1].time;
        
        const markers = trades.map(t => {
            let markerTime = t.time;
            if (markerTime < firstCandleTime) markerTime = firstCandleTime;
            if (markerTime > lastCandleTime) markerTime = lastCandleTime;
            
            const isBuy = t.side === 'BUY';
            
            return {
                time: markerTime,
                position: isBuy ? 'belowBar' : 'aboveBar',
                color: isBuy ? UP : DOWN,
                shape: isBuy ? 'arrowUp' : 'arrowDown',
                text: t.text ?? (isBuy ? 'B' : 'S'),
                size: 1
            };
        });
        
        candleSeries.setMarkers(markers);
    }
    
    /**
     * 获取指定时间点的价格信息
     */
    function getPricesAtTime(targetTime) {
        if (currentCandles.length === 0) return null;
        
        let targetCandle = currentCandles[0];
        let minDiff = Math.abs(currentCandles[0].time - targetTime);
        for (const candle of currentCandles) {
            const diff = Math.abs(candle.time - targetTime);
            if (diff < minDiff) {
                minDiff = diff;
                targetCandle = candle;
            }
        }
        
        const targetIndex = currentCandles.findIndex(c => c.time === targetCandle.time);
        const candlesUpToTarget = currentCandles.slice(0, targetIndex + 1);
        
        const ema7 = candlesUpToTarget.length >= 7 ? calcEMA(candlesUpToTarget.slice(-15), 7).pop()?.value : null;
        const ema25 = candlesUpToTarget.length >= 25 ? calcEMA(candlesUpToTarget.slice(-40), 25).pop()?.value : null;
        const ema99 = candlesUpToTarget.length >= 99 ? calcEMA(candlesUpToTarget.slice(-150), 99).pop()?.value : null;
        
        const boll = candlesUpToTarget.length >= 20 ? calcBOLL(candlesUpToTarget.slice(-28), 20, 2) : { upper: [], middle: [], lower: [] };
        const bollUpper = boll.upper.length > 0 ? boll.upper[boll.upper.length - 1].value : null;
        const bollMid = boll.middle.length > 0 ? boll.middle[boll.middle.length - 1].value : null;
        const bollLower = boll.lower.length > 0 ? boll.lower[boll.lower.length - 1].value : null;
        
        return {
            close: targetCandle.close,
            ema7, ema25, ema99,
            bollUpper, bollMid, bollLower
        };
    }
    
    /**
     * 获取当前价格信息
     */
    function getCurrentPrices() {
        if (currentCandles.length === 0) return null;
        const last = currentCandles[currentCandles.length - 1];
        
        const ema7 = currentCandles.length >= 7 ? calcEMA(currentCandles.slice(-15), 7).pop()?.value : null;
        const ema25 = currentCandles.length >= 25 ? calcEMA(currentCandles.slice(-40), 25).pop()?.value : null;
        const ema99 = currentCandles.length >= 99 ? calcEMA(currentCandles.slice(-150), 99).pop()?.value : null;
        
        const boll = currentCandles.length >= 20 ? calcBOLL(currentCandles.slice(-28), 20, 2) : { upper: [], middle: [], lower: [] };
        const bollUpper = boll.upper.length > 0 ? boll.upper[boll.upper.length - 1].value : null;
        const bollMid = boll.middle.length > 0 ? boll.middle[boll.middle.length - 1].value : null;
        const bollLower = boll.lower.length > 0 ? boll.lower[boll.lower.length - 1].value : null;
        
        return {
            close: last.close,
            ema7, ema25, ema99,
            bollUpper, bollMid, bollLower
        };
    }
    
    /**
     * 调整图表尺寸
     */
    function resize(width, height) {
        chart.applyOptions({ width, height: height * 0.7 });
        if (volChart) {
            volChart.applyOptions({ width, height: height * 0.3 });
        }
    }
    
    // 添加十字线订阅
    chart.subscribeCrosshairMove(function(param) {
        if (param.time) {
            const data = param.seriesData.get(candleSeries);
            if (data) {
                const event = new CustomEvent('chartCrosshair', {
                    detail: {
                        time: param.time,
                        data: data,
                        prices: getPricesAtTime(param.time)
                    }
                });
                window.dispatchEvent(event);
            }
        } else {
            const event = new CustomEvent('chartCrosshair', {
                detail: {
                    prices: getCurrentPrices()
                }
            });
            window.dispatchEvent(event);
        }
    });
    
    // 监听鼠标点击获取十字线位置
    chart.subscribeClick(function(param) {
        if (param.time) {
            const data = param.seriesData.get(candleSeries);
            if (data) {
                const event = new CustomEvent('chartClick', {
                    detail: {
                        time: param.time,
                        data: data
                    }
                });
                window.dispatchEvent(event);
            }
        }
    });
    
    console.log('binanceDarkCNTheme 初始化完成');
    
    return {
        chart, candleSeries, volChart, volSeries,
        ema7Series, ema25Series, ema99Series,
        bollMidSeries, bollUpperSeries, bollLowerSeries,
        setLastPriceLine, setTradeMarkers,
        calcEMA, calcBOLL, setCandleData, updateCandle,
        getCurrentPrices, getPricesAtTime, resize,
        currentCandles,
        colors: { BG, GRID, TEXT, CROSS, UP, DOWN,
            EMA12_COLOR, EMA26_COLOR,
            BOLL_MID_COLOR, BOLL_UPPER_COLOR, BOLL_LOWER_COLOR },
    };
};

/**
 * 工具函数：将币安API K线数据转换为Lightweight Charts格式
 */
window.mapBinanceKlines = function(klines) {
    const candles = klines.map(k => ({
        time: Math.floor(Number(k[0]) / 1000),
        open: Number(k[1]),
        high: Number(k[2]),
        low: Number(k[3]),
        close: Number(k[4]),
    }));
    const volumes = klines.map(k => {
        const open = Number(k[1]);
        const close = Number(k[4]);
        return {
            time: Math.floor(Number(k[0]) / 1000),
            value: Number(k[5]),
            color: close >= open ? UP : DOWN,
        };
    });
    return { candles, volumes };
};