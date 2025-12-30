<?php
/**
 * ============================================
 * PROGRESSIVE LOADING VIEW
 * ============================================
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current user info
require_once dirname(__DIR__, 2) . '/middleware/AuthMiddleware.php';
$currentUser = AuthMiddleware::getCurrentUser();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đang phân tích bất thường...</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; }
        
        .progress-container {
            max-width: 900px;
            margin: 50px auto;
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .loading-spinner {
            text-align: center;
            margin: 30px 0;
        }
        
        .spinner-border {
            width: 5rem;
            height: 5rem;
            border-width: 0.4rem;
        }
        
        .progress {
            height: 35px;
            font-size: 1.1rem;
            font-weight: 600;
            margin: 30px 0;
        }
        
        .stats-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 15px;
            margin: 20px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 15px;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            display: block;
        }
        
        .stat-label {
            font-size: 0.95rem;
            opacity: 0.9;
        }
        
        .log-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin: 10px 0;
            border-radius: 8px;
            font-size: 0.9rem;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .log-entry {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .log-entry:last-child {
            border-bottom: none;
        }
        
        .log-time {
            color: #6c757d;
            font-size: 0.85rem;
            margin-right: 10px;
        }
        
        .complete-message {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            margin: 20px 0;
            display: none;
        }
        
        .complete-message.show {
            display: block;
            animation: slideDown 0.5s;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .btn-view-full {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 15px 40px;
            border-radius: 25px;
            font-weight: 600;
            font-size: 1.1rem;
            margin-top: 20px;
        }
        
        .btn-view-full:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
            color: white;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="progress-container">
        <h2 class="text-center mb-4">
            <i class="fas fa-chart-line me-2"></i>
            Đang Phân Tích Dữ Liệu Bất Thường
        </h2>
        
        <div class="loading-spinner">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Đang tải...</span>
            </div>
            <p class="mt-3 text-muted" id="statusMessage">Đang khởi tạo...</p>
        </div>
        
        <!-- Progress Bar -->
        <div class="progress">
            <div class="progress-bar progress-bar-striped progress-bar-animated" 
                 role="progressbar" 
                 id="progressBar"
                 style="width: 0%"
                 aria-valuenow="0" 
                 aria-valuemin="0" 
                 aria-valuemax="100">0%</div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-box">
            <div class="row">
                <div class="col-md-3 stat-item">
                    <span class="stat-number" id="processedCount">0</span>
                    <span class="stat-label">Đã xử lý</span>
                </div>
                <div class="col-md-3 stat-item">
                    <span class="stat-number" id="totalCount">0</span>
                    <span class="stat-label">Tổng KH</span>
                </div>
                <div class="col-md-3 stat-item">
                    <span class="stat-number" id="foundCount">0</span>
                    <span class="stat-label">Tìm thấy BT</span>
                </div>
                <div class="col-md-3 stat-item">
                    <span class="stat-number" id="batchNumber">0</span>
                    <span class="stat-label">Batch</span>
                </div>
            </div>
        </div>
        
        <!-- Activity Log -->
        <div class="log-box" id="logBox">
            <strong><i class="fas fa-list me-2"></i>Nhật ký xử lý:</strong>
            <div id="logEntries" class="mt-2"></div>
        </div>
        
        <!-- Complete Message -->
        <div class="complete-message" id="completeMessage">
            <h4><i class="fas fa-check-circle me-2"></i>Hoàn tất phân tích!</h4>
            <p class="mb-0" id="completeText"></p>
            <button class="btn btn-view-full" onclick="viewFullReport()">
                <i class="fas fa-table me-2"></i>Xem Báo Cáo Đầy Đủ
            </button>
        </div>
    </div>
</div>

<script>
const filters = <?= json_encode($filters) ?>;
const cacheKey = '<?= $cacheKey ?>';
let isCompleted = false;
let connectionAttempts = 0;
const MAX_RETRIES = 3;
let currentEventSource = null;

// Start progressive calculation
window.addEventListener('load', function() {
    startProgressiveCalculation();
});

function startProgressiveCalculation() {
    addLog('Bắt đầu phân tích...');
    
    // 🔧 FIX 1: Reset state
    connectionAttempts++;
    isCompleted = false;
    
    // Create FormData
    const formData = new FormData();
    formData.append('years', JSON.stringify(filters.years));
    formData.append('months', JSON.stringify(filters.months));
    formData.append('ma_tinh_tp', filters.ma_tinh_tp);
    formData.append('gkhl_status', filters.gkhl_status);
    
    // 🔧 FIX 2: Use fetch with better error handling
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 300000); // 5 min timeout
    
    fetch('anomaly.php?action=ajax_progressive', {
        method: 'POST',
        body: formData,
        signal: controller.signal
    })
    .then(response => {
        clearTimeout(timeoutId);
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let currentEvent = '';
        let lastHeartbeat = Date.now();
        
        // 🔧 FIX 3: Heartbeat monitor
        const heartbeatCheck = setInterval(() => {
            const timeSinceLastHeartbeat = Date.now() - lastHeartbeat;
            if (timeSinceLastHeartbeat > 30000 && !isCompleted) { // 30s no response
                clearInterval(heartbeatCheck);
                handleError({ message: 'Mất kết nối (timeout)' });
            }
        }, 5000);
        
        function processStream({ done, value }) {
            if (done) {
                clearInterval(heartbeatCheck);
                if (!isCompleted) {
                    handleError({ message: 'Kết nối bị ngắt trước khi hoàn tất' });
                }
                return;
            }
            
            // Update heartbeat
            lastHeartbeat = Date.now();
            
            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop(); // Keep incomplete line
            
            for (const line of lines) {
                if (line.startsWith('event:')) {
                    currentEvent = line.substring(7).trim();
                } else if (line.startsWith('data:')) {
                    try {
                        const data = JSON.parse(line.substring(6));
                        handleSSEMessage(currentEvent || 'message', data);
                        currentEvent = '';
                    } catch (e) {
                        console.error('Parse error:', e, line);
                    }
                }
            }
            
            return reader.read().then(processStream);
        }
        
        return reader.read().then(processStream);
    })
    .catch(error => {
        clearTimeout(timeoutId);
        console.error('Stream error:', error);
        
        // 🔧 FIX 4: Retry logic
        if (connectionAttempts < MAX_RETRIES && !isCompleted) {
            addLog(`Lỗi kết nối, đang thử lại (${connectionAttempts}/${MAX_RETRIES})...`, 'warning');
            setTimeout(() => {
                startProgressiveCalculation();
            }, 2000); // Wait 2s before retry
        } else {
            handleError({ 
                message: `Lỗi kết nối: ${error.message}`,
                suggestion: 'Vui lòng thử lại hoặc chọn ít dữ liệu hơn'
            });
        }
    });
}

function handleSSEMessage(eventType, data) {
    console.log('Event:', eventType, data);
    
    switch(eventType) {
        case 'heartbeat':
            // Just update last activity, no UI change
            console.log('Heartbeat:', data);
            break;
            
        case 'init':
            handleInit(data);
            break;
            
        case 'progress':
            handleProgress(data);
            break;
            
        case 'warning':
            addLog('⚠️ ' + data.message, 'warning');
            break;
            
        case 'partial_complete':
            handlePartialComplete(data);
            break;
            
        case 'complete':
            handleComplete(data);
            break;
            
        case 'error':
            handleError(data);
            break;
    }
}

function handleInit(data) {
    document.getElementById('totalCount').textContent = data.total_customers.toLocaleString();
    document.getElementById('statusMessage').textContent = data.message;
    addLog(data.message);
}

function handleProgress(data) {
    // Update progress bar
    const progressBar = document.getElementById('progressBar');
    progressBar.style.width = data.progress + '%';
    progressBar.textContent = data.progress + '%';
    progressBar.setAttribute('aria-valuenow', data.progress);
    
    // Update stats
    document.getElementById('processedCount').textContent = data.processed.toLocaleString();
    document.getElementById('foundCount').textContent = data.found_anomalies.toLocaleString();
    document.getElementById('batchNumber').textContent = data.batch_number;
    document.getElementById('statusMessage').textContent = data.message;
    
    // Add log
    addLog(`Batch ${data.batch_number}: ${data.message} (${data.batch_duration}ms)`);
}

function handlePartialComplete(data) {
    addLog('✅ Đã có đủ dữ liệu để hiển thị!', 'success');
}

function handleComplete(data) {
    isCompleted = true;
    
    // Update progress to 100%
    const progressBar = document.getElementById('progressBar');
    progressBar.style.width = '100%';
    progressBar.textContent = '100%';
    progressBar.classList.remove('progress-bar-animated');
    progressBar.classList.add('bg-success');
    
    // Hide spinner
    document.querySelector('.loading-spinner').style.display = 'none';
    
    // Show complete message
    const completeMsg = document.getElementById('completeMessage');
    const completeText = document.getElementById('completeText');
    completeText.textContent = data.message || 'Phân tích hoàn tất!';
    completeMsg.classList.add('show');
    
    addLog('🎉 ' + (data.message || 'Hoàn tất!'), 'success');
}

function handleError(data) {
    isCompleted = true;
    
    const errorMsg = data.message || 'Có lỗi xảy ra';
    const suggestion = data.suggestion || 'Vui lòng thử lại';
    
    addLog('❌ Lỗi: ' + errorMsg, 'error');
    if (suggestion) {
        addLog('💡 ' + suggestion, 'info');
    }

function addLog(message, type = 'info') {
    const logEntries = document.getElementById('logEntries');
    const time = new Date().toLocaleTimeString();
    
    const icon = type === 'success' ? 'fa-check-circle text-success' :
                 type === 'error' ? 'fa-exclamation-circle text-danger' :
                 'fa-info-circle text-primary';
    
    const entry = document.createElement('div');
    entry.className = 'log-entry';
    entry.innerHTML = `
        <span class="log-time">${time}</span>
        <i class="fas ${icon} me-1"></i>
        ${message}
    `;
    
    logEntries.insertBefore(entry, logEntries.firstChild);
    
    // Keep only last 20 logs
    while (logEntries.children.length > 20) {
        logEntries.removeChild(logEntries.lastChild);
    }
}

function viewFullReport() {
    // Redirect to main report with same filters
    const params = new URLSearchParams();
    filters.years.forEach(y => params.append('years[]', y));
    filters.months.forEach(m => params.append('months[]', m));
    if (filters.ma_tinh_tp) params.append('ma_tinh_tp', filters.ma_tinh_tp);
    if (filters.gkhl_status) params.append('gkhl_status', filters.gkhl_status);
    
    window.location.href = 'anomaly.php?' + params.toString();
}

const progressBar = document.getElementById('progressBar');
    progressBar.classList.remove('progress-bar-animated');
    progressBar.classList.add('bg-danger');
    
    document.getElementById('statusMessage').innerHTML = 
        `<span class="text-danger">
            <i class="fas fa-exclamation-triangle me-2"></i>
            ${errorMsg}
        </span>`;
    
    // 🔧 FIX 5: Show retry button
    const completeMsg = document.getElementById('completeMessage');
    completeMsg.innerHTML = `
        <h4 class="text-danger">
            <i class="fas fa-exclamation-circle me-2"></i>
            ${errorMsg}
        </h4>
        <p>${suggestion}</p>
        <button class="btn btn-warning" onclick="location.reload()">
            <i class="fas fa-redo me-2"></i>Thử Lại
        </button>
        <button class="btn btn-secondary ms-2" onclick="history.back()">
            <i class="fas fa-arrow-left me-2"></i>Quay Lại
        </button>
    `;
    completeMsg.classList.add('show');
}

// 🔧 FIX 6: Add visibility change detection
document.addEventListener('visibilitychange', function() {
    if (document.hidden && !isCompleted) {
        console.warn('Tab hidden - connection may be affected');
    }
});

// Start on load
window.addEventListener('load', function() {
    startProgressiveCalculation();
});
</script>

</body>
</html>