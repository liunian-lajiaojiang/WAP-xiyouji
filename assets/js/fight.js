var fightData = window.fightData || {};

(function() {
    var saved = sessionStorage.getItem('perform_msg');
    if (saved) {
        sessionStorage.removeItem('perform_msg');
        var log = document.getElementById('combat-log');
        if (log) {
            var entry = document.createElement('div');
            entry.className = 'log-entry';
            entry.innerHTML = '<span>' + saved + '</span>';
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;
        }
    }
})();

function doPerform(param, linkElement) {
    if (linkElement && linkElement.getAttribute('data-disabled') === 'true') {
        var reason = linkElement.getAttribute('data-reason') || '不可用';
        addCombatLog(reason);
        return;
    }
    
    if (linkElement) {
        linkElement.style.pointerEvents = 'none';
        linkElement.style.opacity = '0.5';
    }
    
    fetch('action.php?action=perform&param=' + param + '&from=fight', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            if (data.message) {
                addCombatLog(data.message);
                sessionStorage.setItem('perform_msg', data.message);
            }
            
            if (data.target_hp_percent !== undefined) {
                updateTargetHpBar(data.target_hp_percent);
            }
            if (data.player_hp !== undefined) {
                updatePlayerHpBar(data.player_hp);
            }
            
            if (data.damage > 0) {
                showDamagePopup(data.damage, 'npc', 'target-hp-container');
            }
            if (data.player_damage > 0) {
                showDamagePopup(data.player_damage, 'player', 'player-hp-container');
            }
            
            if (data.killed) {
                setTimeout(function() {
                    window.location.href = data.redirect || 'room.php';
                }, 1000);
            }
        } else {
            if (data.message) {
                addCombatLog(data.message);
                sessionStorage.setItem('perform_msg', data.message);
            }
        }
    })
    .catch(function(error) {
        console.error('Perform error:', error);
        addCombatLog('招式发动失败');
    })
    .finally(function() {
        if (linkElement) {
            linkElement.style.pointerEvents = 'auto';
            linkElement.style.opacity = '1';
        }
    });
}

function addCombatLog(message) {
    var log = document.getElementById('combat-log');
    if (log) {
        var entry = document.createElement('div');
        entry.style.marginBottom = '2px';
        entry.style.lineHeight = '1.4';
        var cleanMsg = message.trim().replace(/\n/g, '<br>').replace(/<br>$/, '');
        entry.innerHTML = cleanMsg;
        log.appendChild(entry);
        log.scrollTop = log.scrollHeight;
    }
}

function updateTargetHpBar(hpPercent) {
    var hpFill = document.querySelector('#target-hp-container .hp-fill');
    if (hpFill) {
        hpFill.style.width = Math.max(0, hpPercent) + '%';
        hpFill.textContent = hpPercent + '%';
        
        hpFill.className = hpFill.className.replace(/hp-(high|medium|low)/g, '');
        if (hpPercent > 50) {
            hpFill.classList.add('hp-high');
        } else if (hpPercent > 25) {
            hpFill.classList.add('hp-medium');
        } else {
            hpFill.classList.add('hp-low');
        }
    }
}

function updatePlayerHpBar(currentHp) {
    var hpFill = document.querySelector('#player-hp-container .hp-fill');
    if (hpFill && hpFill.dataset.maxHp) {
        var maxHp = parseInt(hpFill.dataset.maxHp);
        var hpPercent = maxHp > 0 ? Math.round((currentHp / maxHp) * 100) : 0;
        hpFill.style.width = Math.max(0, hpPercent) + '%';
        hpFill.textContent = currentHp + '/' + maxHp + ' (' + hpPercent + '%)';
        
        hpFill.className = hpFill.className.replace(/hp-(high|medium|low)/g, '');
        if (hpPercent > 50) {
            hpFill.classList.add('hp-high');
        } else if (hpPercent > 25) {
            hpFill.classList.add('hp-medium');
        } else {
            hpFill.classList.add('hp-low');
        }
    }
}

function showDamagePopup(value, type, containerId) {
    if (value <= 0) return;
    
    var popup = document.createElement('div');
    popup.className = 'damage-popup ' + type;
    popup.textContent = '-' + value;
    
    var container = document.getElementById(containerId);
    if (!container) {
        popup.style.left = (type === 'player') ? '20%' : '70%';
        popup.style.top = (type === 'player') ? '60%' : '30%';
    } else {
        popup.style.position = 'absolute';
        popup.style.left = '50%';
        popup.style.top = '-10px';
        popup.style.transform = 'translateX(-50%)';
        container.style.position = 'relative';
        container.appendChild(popup);
        
        setTimeout(function() {
            if (popup.parentNode) {
                popup.parentNode.removeChild(popup);
            }
        }, 1500);
        return;
    }
    
    document.body.appendChild(popup);
    
    setTimeout(function() {
        if (popup.parentNode) {
            popup.parentNode.removeChild(popup);
        }
    }, 1500);
}

(function() {
    var playerDamage = fightData.playerDamage || 0;
    var targetDamage = fightData.targetDamage || 0;
    
    setTimeout(function() {
        if (targetDamage > 0) {
            showDamagePopup(targetDamage, 'target', 'target-hp-container');
        }
        if (playerDamage > 0) {
            showDamagePopup(playerDamage, 'player', 'player-hp-container');
        }
    }, 200);
})();

(function() {
    var combatActive = fightData.combatActive || false;
    if (!combatActive) return;
    
    var playerHp = fightData.playerHp || 0;
    var playerMaxHp = fightData.playerMaxHp || 0;
    var targetHp = fightData.targetHp || 0;
    var targetMaxHp = fightData.targetMaxHp || 0;
    
    var logArea = document.querySelector('.combat-log');
    if (logArea) {
        logArea.scrollTop = logArea.scrollHeight;
    }
    
    var savedMode = sessionStorage.getItem('combat_mode');
    var autoMode = (savedMode !== 'manual');
    var remaining = 5;
    var countdownTimer = null;
    var autoAttackTimer = null;
    var attackInProgress = false;
    
    var btnAuto = document.getElementById('btn-auto-attack');
    var btnManual = document.getElementById('btn-manual-attack');
    var countdownEl = document.getElementById('auto-attack-countdown');
    
    function updateUI() {
        if (autoMode) {
            btnAuto.textContent = '切为手动';
            btnAuto.style.background = '#1a5c1a';
            btnAuto.style.borderColor = '#2a8c2a';
            btnAuto.style.cursor = 'pointer';
            
            btnManual.style.background = '#444';
            btnManual.style.color = '#888';
            btnManual.style.borderColor = '#666';
            btnManual.style.cursor = 'not-allowed';
            btnManual.disabled = true;
        } else {
            btnAuto.textContent = '自动攻击';
            btnAuto.style.background = '#5c3a1a';
            btnAuto.style.borderColor = '#8c6a2a';
            btnAuto.style.cursor = 'pointer';
            
            btnManual.style.background = '#0f3460';
            btnManual.style.color = '#eee';
            btnManual.style.borderColor = '#533483';
            btnManual.style.cursor = 'pointer';
            btnManual.disabled = false;
        }
    }
    
    function startAutoCycle() {
        stopAutoCycle();
        remaining = 5;
        if (countdownEl) {
            countdownEl.textContent = '(' + remaining + ')';
            countdownEl.style.display = '';
        }
        
        countdownTimer = setInterval(function() {
            remaining--;
            if (countdownEl) {
                countdownEl.textContent = remaining > 0 ? '(' + remaining + ')' : '';
            }
            if (remaining <= 0) {
                clearInterval(countdownTimer);
                countdownTimer = null;
            }
        }, 1000);
        
        autoAttackTimer = setTimeout(function() {
            doAttack();
        }, 5000);
    }
    
    function stopAutoCycle() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
        if (autoAttackTimer) {
            clearTimeout(autoAttackTimer);
            autoAttackTimer = null;
        }
    }
    
    function doAttack() {
        if (attackInProgress) return;
        
        if (playerHp <= 0 || targetHp <= 0) {
            if (countdownEl) {
                countdownEl.textContent = '';
            }
            setTimeout(function() { window.location.reload(); }, 300);
            return;
        }
        
        attackInProgress = true;
        if (countdownEl) {
            countdownEl.textContent = '';
        }
        
        fetch('action.php?action=hit&from=fight', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.redirect) {
                npcTimerActive = false;
                stopAutoCycle();
                window.location.href = data.redirect;
            } else if (data.killed) {
                npcTimerActive = false;
                stopAutoCycle();
                window.location.reload();
            } else {
                npcTimerActive = false;
                stopAutoCycle();
                window.location.href = 'fight.php';
            }
        })
        .catch(function(err) {
            console.error('攻击请求失败:', err);
            npcTimerActive = false;
            stopAutoCycle();
            window.location.href = 'action.php?action=hit&from=fight';
        });
    }
    
    window.toggleAutoAttack = function() {
        autoMode = !autoMode;
        sessionStorage.setItem('combat_mode', autoMode ? 'auto' : 'manual');
        updateUI();
        
        if (autoMode) {
            startAutoCycle();
        } else {
            stopAutoCycle();
            if (countdownEl) {
                countdownEl.textContent = '';
            }
        }
    };
    
    window.manualAttack = function() {
        if (!autoMode) {
            doAttack();
        }
    };
    
    updateUI();
    if (autoMode) {
        startAutoCycle();
    } else {
        if (countdownEl) {
            countdownEl.textContent = '';
        }
    }
    
    document.addEventListener('click', function(e) {
        var target = e.target;
        while (target && target.tagName !== 'A' && target !== document) {
            target = target.parentElement;
        }
        if (target && target.tagName === 'A' && target.href) {
            stopAutoCycle();
            if (countdownEl) {
                countdownEl.textContent = '';
            }
        }
    });

    var npcTimerActive = true;

    function doNpcAttack() {
        if (!npcTimerActive) return;
        fetch('action.php?action=npc_attack&from=fight', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!npcTimerActive) return;
            if (!data || !data.success) return;

            if (data.killed) {
                npcTimerActive = false;
                stopAutoCycle();
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    window.location.reload();
                }
                return;
            }

            if (data.message) {
                var logArea = document.querySelector('.combat-log');
                if (logArea) {
                    var p = document.createElement('p');
                    p.innerHTML = data.message;
                    logArea.appendChild(p);
                    logArea.scrollTop = logArea.scrollHeight;
                }
            }

            if (data.player_hp !== undefined && data.player_hp !== null) {
                updatePlayerHpBar(parseInt(data.player_hp));
            }

            if (data.player_damage && data.player_damage > 0) {
                showDamagePopup(parseInt(data.player_damage), 'player', 'player-hp-container');
            }
        })
        .catch(function(err) {
        });
    }

    var npcTimerId = setTimeout(function npcLoop() {
        if (!npcTimerActive) return;
        doNpcAttack();
        npcTimerId = setTimeout(npcLoop, 5000);
    }, 2500);

    // ===== 战斗状态实时轮询（PVP投降/结束同步） =====
    var combatPollActive = true;
    var lastCombatPollId = 0;
    var pollDelay = 2000;           // 默认2秒
    var combatEnded = false;        // 防止重复跳转

    function pollCombatState() {
        if (!combatPollActive || combatEnded) return;
        
        fetch('chat.php?action=poll&last_id=' + lastCombatPollId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (!combatPollActive || combatEnded) return;

            // 战斗已经不在进行中 或 对手已投降/逃跑
            if (data.success && (data.in_combat === false || data.combat_ended === true)) {
                combatEnded = true;
                combatPollActive = false;
                stopAutoCycle();
                npcTimerActive = false;
                clearTimeout(npcTimerId);
                
                // 查找战斗结束消息
                var msg = '';
                if (data.messages && data.messages.length > 0) {
                    for (var i = 0; i < data.messages.length; i++) {
                        var m = data.messages[i];
                        if (m.msg_type === 'combat') {
                            msg = m.message;
                            break;
                        }
                    }
                }
                if (!msg) {
                    msg = data.combat_target_name ? 
                          (data.combat_target_name + '已向你投降，战斗结束。') : 
                          '战斗已结束。';
                }
                
                var redirectUrl = 'room.php';
                if (data.current_area) {
                    redirectUrl += '?area=' + encodeURIComponent(data.current_area);
                    if (data.current_room) {
                        redirectUrl += '&room=' + encodeURIComponent(data.current_room);
                    }
                }
                redirectUrl += (redirectUrl.indexOf('?') >= 0 ? '&' : '?') + 'msg=' + encodeURIComponent(msg);
                
                setTimeout(function() {
                    window.location.href = redirectUrl;
                }, 500);
                return;
            }

            // 更新 last_id 用于增量轮询
            if (data.last_id && data.last_id > lastCombatPollId) {
                lastCombatPollId = data.last_id;
            }

            // 继续轮询
            setTimeout(pollCombatState, pollDelay);
        })
        .catch(function(err) {
            if (combatPollActive && !combatEnded) {
                setTimeout(pollCombatState, 3000);
            }
        });
    }

    // 启动战斗状态轮询（首次延迟3秒，避免在页面刚加载时误判）
    setTimeout(pollCombatState, 3000);

    window.addEventListener('beforeunload', function() {
        combatPollActive = false;
        npcTimerActive = false;
        clearTimeout(npcTimerId);
    });
})();