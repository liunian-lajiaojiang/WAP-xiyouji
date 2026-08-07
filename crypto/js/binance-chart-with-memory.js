/**
 * 带记忆功能版本 - 记住技术指标开关状态
 */

const UP = '#F6465D';
const DOWN = '#0ECB81';
const BG = '#0B0E11';
const GRID = '#1E2329';
const TEXT = '#848E9C';
const CROSS = '#2B3139';

// 保存键名
const STORAGE_KEY = 'chart_indicators_state';

window.binanceDarkCNTheme = function({ chartEl, volEl, options = {} }) {
    console.log('=== 带记忆功能版本 ===');
    
    let chart, candleSeries;
    let ema7Series, ema25Series, ema99Series;
    let bollMidSeries, bollUpperSeries, bollLowerSeries;
    
    // 所有数据
    let allCandles = [];
    
    // 指标显示状态 - 从localStorage读取或使用默认值
    let indicatorsVisible = loadIndicatorState();
    
    console.log('1. 创建图表...');
    chart = LightweightCharts.createChart(chartEl, {
        layout: {
            background: { color: BG },
            textColor: TEXT,
            fontFamily: 'system-ui, -apple-system, Segoe UI, Roboto, Arial',
            fontSize: 12
        },
        grid: {
            vertLines: { color: GRID },
            horzLines: { color: GRID }
        },
        crosshair: {
            mode: 1,
            vertLine: { 
                color: CROSS, 
                width: 1, 
                style: 0, 
                labelBackgroundColor: CROSS,
            },
            horzLine: { 
                color: CROSS, 
                width: 1, 
                style: 0, 
                labelBackgroundColor: CROSS,
            }
        },
        rightPriceScale: {
            borderColor: GRID,
            textColor: TEXT,
            scaleMargins: { top: 0.15, bottom: 0.1 }
        },
        timeScale: {
            borderColor: GRID,
            timeVisible: true,
            secondsVisible: false,
            rightOffset: 6,
            barSpacing: 8,
            fixLeftEdge: false,
            fixRightEdge: true
        },
        localization: {
            timeFormatter: (businessDayOrTimestamp) => {
                let timestamp;
                if (typeof businessDayOrTimestamp === 'number') {
                    timestamp = businessDayOrTimestamp;
                } else {
                    const date = new Date(Date.UTC(
                        businessDayOrTimestamp.year, 
                        businessDayOrTimestamp.month - 1, 
                        businessDayOrTimestamp.day
                    ));
                    timestamp = Math.floor(date.getTime() / 1000);
                }
                
                const date = new Date(timestamp * 1000);
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                
                return `${month}-${day} ${hours}:${minutes}`;
            },
            dateFormat: 'yyyy-MM-dd',
            locale: 'zh-CN'
        },
        handleScroll: { mouseWheel: true, pressedMouseMove: true, horzTouchDrag: true, vertTouchDrag: false },
        handleScale: { axisPressedMouseMove: true, mouseWheel: true, pinch: true },
        ...options.chartOptions
    });
    console.log('✅ 图表创建成功');
    
    console.log('2. 创建蜡烛图系列...');
    candleSeries = chart.addCandlestickSeries({
        upColor: UP,
        downColor: DOWN,
        borderUpColor: UP,
        borderDownColor: DOWN,
        wickUpColor: UP,
        wickDownColor: DOWN
    });
    console.log('✅ 蜡烛图系列创建成功');
    
    console.log('3. 创建 EMA 线...');
    ema7Series = chart.addLineSeries({ color: '#F0B90B', lineWidth: 2 });
    ema25Series = chart.addLineSeries({ color: '#EAECEF', lineWidth: 2 });
    ema99Series = chart.addLineSeries({ color: '#A970FF', lineWidth: 2 });
    console.log('✅ EMA 线创建成功');
    
    console.log('4. 创建布林带...');
    bollMidSeries = chart.addLineSeries({ color: '#F0B90B', lineWidth: 2 });
    bollUpperSeries = chart.addLineSeries({ color: '#787B86', lineWidth: 2, lineStyle: 1 });
    bollLowerSeries = chart.addLineSeries({ color: '#787B86', lineWidth: 2, lineStyle: 1 });
    console.log('✅ 布林带创建成功');
    
    // 从localStorage加载状态
    function loadIndicatorState() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved) {
                const parsed = JSON.parse(saved);
                console.log('✅ 从localStorage加载指标状态:', parsed);
                return parsed;
            }
        } catch (e) {
            console.warn('⚠️ 无法从localStorage加载指标状态:', e);
        }
        // 默认状态
        return {
            ema7: true,
            ema25: true,
            ema99: true,
            bollinger: true
        };
    }
    
    // 保存状态到localStorage
    function saveIndicatorState() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(indicatorsVisible));
            console.log('✅ 指标状态已保存到localStorage');
        } catch (e) {
            console.warn('⚠️ 无法保存指标状态到localStorage:', e);
        }
    }
    
    /**
     * 计算 EMA
     */
    function calcEMA(candles, period) {
        if (!Array.isArray(candles) || candles.length === 0) return [];
        
        const out = [];
        const multiplier = 2 / (period + 1);
        let ema;
        
        for (let i = 0; i < candles.length; i++) {
            const c = candles[i];
            if (!c) continue;
            const close = Number(c.close);
            if (isNaN(close)) continue;
            
            if (i < period - 1) {
                continue;
            } else if (i === period - 1) {
                let sum = 0;
                let count = 0;
                for (let j = 0; j <= i; j++) {
                    const cj = candles[j];
                    if (cj && !isNaN(Number(cj.close))) {
                        sum += Number(cj.close);
                        count++;
                    }
                }
                if (count > 0) {
                    ema = sum / count;
                    out.push({ time: Number(c.time), value: ema });
                }
            } else {
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
        if (!Array.isArray(candles) || candles.length === 0) {
            return { upper: [], middle: [], lower: [] };
        }
        
        const upper = [];
        const middle = [];
        const lower = [];
        
        for (let i = 0; i < candles.length; i++) {
            const c = candles[i];
            if (!c || !c.close) continue;
            
            if (i < period - 1) {
                continue;
            } else {
                let sum = 0;
                let count = 0;
                for (let j = i - period + 1; j <= i; j++) {
                    const cj = candles[j];
                    if (cj && cj.close) {
                        sum += Number(cj.close);
                        count++;
                    }
                }
                
                if (count < period) continue;
                
                const ma = sum / count;
                
                let squaredDiffSum = 0;
                for (let j = i - period + 1; j <= i; j++) {
                    const cj = candles[j];
                    if (cj && cj.close) {
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
     * 切换指标显示
     */
    function toggleIndicator(indicator) {
        if (!indicatorsVisible.hasOwnProperty(indicator)) {
            console.warn('未知指标:', indicator);
            return;
        }
        
        indicatorsVisible[indicator] = !indicatorsVisible[indicator];
        
        const ema7 = calcEMA(allCandles, 7);
        const ema25 = calcEMA(allCandles, 25);
        const ema99 = calcEMA(allCandles, 99);
        const boll = calcBOLL(allCandles, 20, 2);
        
        if (indicator === 'ema7') {
            indicatorsVisible.ema7 ? ema7Series.setData(ema7) : ema7Series.setData([]);
        } else if (indicator === 'ema25') {
            indicatorsVisible.ema25 ? ema25Series.setData(ema25) : ema25Series.setData([]);
        } else if (indicator === 'ema99') {
            indicatorsVisible.ema99 ? ema99Series.setData(ema99) : ema99Series.setData([]);
        } else if (indicator === 'bollinger') {
            if (indicatorsVisible.bollinger) {
                bollMidSeries.setData(boll.middle);
                bollUpperSeries.setData(boll.upper);
                bollLowerSeries.setData(boll.lower);
            } else {
                bollMidSeries.setData([]);
                bollUpperSeries.setData([]);
                bollLowerSeries.setData([]);
            }
        }
        
        // 保存状态
        saveIndicatorState();
        
        console.log(`指标 ${indicator} ${indicatorsVisible[indicator] ? '已显示' : '已隐藏'}`);
        
        return indicatorsVisible[indicator];
    }
    
    /**
     * 获取指标显示状态
     */
    function getIndicatorState() {
        return { ...indicatorsVisible };
    }
    
    /**
     * 设置蜡烛图数据
     */
    function setCandleData(candles, volumes = null) {
        console.log('5. 设置蜡烛图数据...');
        
        try {
            // 1. 清洗数据
            let cleaned = [];
            for (let i = 0; i < candles.length; i++) {
                const c = candles[i];
                if (!c) continue;
                
                let time = Number(c.time);
                
                if (time > 9999999999) {
                    time = Math.floor(time / 1000);
                }
                
                cleaned.push({
                    time: time,
                    open: Number(c.open),
                    high: Number(c.high),
                    low: Number(c.low),
                    close: Number(c.close)
                });
            }
            
            console.log('   原始数据条数:', cleaned.length);
            
            // 2. 按时间戳升序排序
            cleaned.sort((a, b) => a.time - b.time);
            
            console.log('   已按时间戳升序排序');
            
            allCandles = cleaned;
            
            candleSeries.setData(allCandles);
            console.log('   ✅ 蜡烛数据已设置');
            
            const ema7 = calcEMA(allCandles, 7);
            const ema25 = calcEMA(allCandles, 25);
            const ema99 = calcEMA(allCandles, 99);
            indicatorsVisible.ema7 && ema7Series.setData(ema7);
            indicatorsVisible.ema25 && ema25Series.setData(ema25);
            indicatorsVisible.ema99 && ema99Series.setData(ema99);
            console.log('   ✅ EMA 数据已设置');
            
            const boll = calcBOLL(allCandles, 20, 2);
            if (indicatorsVisible.bollinger) {
                bollMidSeries.setData(boll.middle);
                bollUpperSeries.setData(boll.upper);
                bollLowerSeries.setData(boll.lower);
            }
            console.log('   ✅ 布林带数据已设置');
            
            // 自动适配，只显示最新的35条
            const totalCount = allCandles.length;
            const startIndex = Math.max(0, totalCount - 35);
            
            if (totalCount > 0 && startIndex < totalCount) {
                const startTime = allCandles[startIndex].time;
                const endTime = allCandles[totalCount - 1].time;
                
                chart.timeScale().setVisibleRange({
                    from: startTime,
                    to: endTime
                });
            }
            
            console.log('   ✅ 已自动显示最新35条数据，可向左滑动查看全部历史');
        } catch (e) {
            console.error('   ❌ 设置数据失败:', e);
        }
    }
    
    /**
     * 获取当前价格
     */
    function getCurrentPrices() {
        if (allCandles.length === 0) return null;
        
        const last = allCandles[allCandles.length - 1];
        
        const ema7 = calcEMA(allCandles, 7);
        const ema25 = calcEMA(allCandles, 25);
        const ema99 = calcEMA(allCandles, 99);
        const boll = calcBOLL(allCandles, 20, 2);
        
        return {
            close: last.close,
            ema7: ema7.length > 0 ? ema7[ema7.length - 1].value : null,
            ema25: ema25.length > 0 ? ema25[ema25.length - 1].value : null,
            ema99: ema99.length > 0 ? ema99[ema99.length - 1].value : null,
            bollUpper: boll.upper.length > 0 ? boll.upper[boll.upper.length - 1].value : null,
            bollMid: boll.middle.length > 0 ? boll.middle[boll.middle.length - 1].value : null,
            bollLower: boll.lower.length > 0 ? boll.lower[boll.lower.length - 1].value : null
        };
    }
    
    /**
     * 更新单根蜡烛
     */
    function updateCandle(candle, volume = null) {
        if (!candle) return;
        
        let time = Number(candle.time);
        if (time > 9999999999) {
            time = Math.floor(time / 1000);
        }
        
        const cleaned = {
            time: time,
            open: Number(candle.open),
            high: Number(candle.high),
            low: Number(candle.low),
            close: Number(candle.close)
        };
        
        const lastTime = allCandles.length > 0 ? allCandles[allCandles.length - 1].time : 0;
        if (cleaned.time === lastTime) {
            allCandles[allCandles.length - 1] = cleaned;
        } else {
            allCandles.push(cleaned);
        }
        
        candleSeries.update(cleaned);
    }
    
    console.log('=== 带记忆功能版本初始化完成 ===');
    
    /**
     * 设置买卖标记
     */
    function setTradeMarkers(trades) {
        if (!allCandles || allCandles.length === 0) {
            candleSeries.setMarkers([]);
            return;
        }
        
        const firstCandleTime = allCandles[0].time;
        const lastCandleTime = allCandles[allCandles.length - 1].time;
        
        const markers = trades.map(t => {
            let markerTime = t.time;
            if (markerTime < firstCandleTime) markerTime = firstCandleTime;
            if (markerTime > lastCandleTime) markerTime = lastCandleTime;
            
            const isBuy = t.side === 'BUY';
            
            return {
                time: markerTime,
                position: isBuy ? 'belowBar' : 'aboveBar',
                color: t.color ?? (isBuy ? UP : DOWN),
                shape: isBuy ? 'arrowUp' : 'arrowDown',
                text: t.text ?? (isBuy ? 'B' : 'S'),
                size: 1
            };
        });
        
        candleSeries.setMarkers(markers);
    }
    
    return {
        chart,
        candleSeries,
        ema7Series,
        ema25Series,
        ema99Series,
        bollMidSeries,
        bollUpperSeries,
        bollLowerSeries,
        setCandleData,
        updateCandle,
        getCurrentPrices,
        toggleIndicator,
        getIndicatorState,
        setTradeMarkers
    };
};
