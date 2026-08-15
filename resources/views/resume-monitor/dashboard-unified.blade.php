<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume Downloader - Complete Monitor</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1800px;
            margin: 0 auto;
        }

        header {
            background: white;
            padding: 20px 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header h1 {
            color: #667eea;
            font-size: 28px;
        }

        .header-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .logout, .copy-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .logout:hover, .copy-btn:hover {
            background: #764ba2;
        }

        .copy-btn.copied {
            background: #28a745;
        }

        .tabs {
            display: flex;
            gap: 10px;
            background: white;
            padding: 10px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 10px 20px;
            border: none;
            background: #f0f0f0;
            color: #333;
            cursor: pointer;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s;
        }

        .tab-btn.active {
            background: #667eea;
            color: white;
        }

        .tab-btn:hover {
            background: #667eea;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
            row-gap: 25px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            overflow: visible;
        }

        .card h3 {
            color: #667eea;
            font-size: 13px;
            text-transform: uppercase;
            margin-bottom: 15px;
            letter-spacing: 1px;
            font-weight: 600;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .card-value {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }

        .card-stat {
            font-size: 12px;
            color: #666;
            margin-top: 8px;
        }

        .wide-card {
            grid-column: 1 / -1;
            margin-top: 10px;
        }

        .chart-container {
            position: relative;
            height: 280px;
            margin: 20px 0;
            padding: 10px 0;
        }

        .data-list {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            max-height: 250px;
            overflow-y: auto;
            font-size: 11px;
        }

        .data-item {
            padding: 4px;
            border-bottom: 1px solid #dee2e6;
            font-family: monospace;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 10px;
        }

        table th {
            background: #f8f9fa;
            padding: 8px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            color: #667eea;
            font-weight: 600;
        }

        table td {
            padding: 8px;
            border-bottom: 1px solid #dee2e6;
        }

        .badge {
            display: inline-block;
            padding: 3px 6px;
            border-radius: 3px;
            font-size: 10px;
            margin-right: 3px;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: black;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }

        footer {
            text-align: center;
            color: white;
            margin-top: 30px;
            font-size: 12px;
        }

        @media (max-width: 768px) {
            .grid {
                grid-template-columns: 1fr;
            }
            header h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <header>
            <div>
                <h1>📊 Complete Resume Monitor</h1>
                <div style="color: #666; font-size: 13px;">Live Data • Historical Data • Graphs • Statistics</div>
            </div>
            <div class="header-actions">
                <button class="copy-btn" id="copy-btn" onclick="copyAllData()">📋 Export All</button>
                <form method="POST" action="{{ route('monitor.logout') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="logout">Logout</button>
                </form>
            </div>
        </header>

        <!-- CRITICAL STATUS ALERT -->
        <div id="status-alert" style="display:none; background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; border-left: 5px solid red; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h3 style="color: #dc3545; margin-bottom: 10px;">🚨 SCRIPT STATUS ALERT</h3>
                    <div id="status-message" style="color: #666; font-size: 14px;"></div>
                    <div id="status-details" style="color: #999; font-size: 12px; margin-top: 10px;"></div>
                </div>
                <div style="text-align: right;">
                    <div id="status-badge" style="font-size: 24px; font-weight: bold; margin-bottom: 10px;"></div>
                    <button class="copy-btn" onclick="runDiagnostics()" style="background: #dc3545; margin-bottom: 10px;">🔧 Run Diagnostics</button>
                    <button class="copy-btn" onclick="forceRestart()" style="background: #28a745;">▶️ Force Restart</button>
                </div>
            </div>
            <div id="recommendations" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #eee;"></div>
        </div>

        <!-- TABS -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('live')">🔴 LIVE DATA</button>
            <button class="tab-btn" onclick="switchTab('graphs')">📊 GRAPHS</button>
            <button class="tab-btn" onclick="switchTab('historical')">📜 ALL DATA</button>
            <button class="tab-btn" onclick="switchTab('stats')">📈 STATISTICS</button>
        </div>

        <!-- TAB 1: LIVE DATA -->
        <div id="live" class="tab-content active">
            <div class="grid">
                <div class="card">
                    <h3>📥 Downloaded</h3>
                    <div class="card-value" id="live-downloaded">-</div>
                    <div class="card-stat">resumes</div>
                </div>
                <div class="card">
                    <h3>📄 Pages</h3>
                    <div class="card-value" id="live-pages">-</div>
                    <div class="card-stat">fetched</div>
                </div>
                <div class="card">
                    <h3>⏳ Pending</h3>
                    <div class="card-value" id="live-pending">-</div>
                    <div class="card-stat">remaining</div>
                </div>
                <div class="card">
                    <h3>⚠️ Errors</h3>
                    <div class="card-value" id="live-errors">-</div>
                    <div class="card-stat">today</div>
                </div>
                <div class="card">
                    <h3>🖥️ Load</h3>
                    <div class="card-value" id="live-load">-</div>
                    <div class="card-stat">average</div>
                </div>
                <div class="card">
                    <h3>🔄 Cron</h3>
                    <div class="card-value"><span class="badge badge-success" id="cron-status">✓</span></div>
                    <div class="card-stat" id="cron-age">-</div>
                </div>

                <div class="card wide-card">
                    <h3>📋 Current Timestamp</h3>
                    <div class="card-stat" id="current-time" style="font-size: 16px; font-weight: bold; color: #333;"></div>
                </div>

                <div class="card">
                    <h3>❤️ Heartbeat Runs</h3>
                    <div class="card-value" id="heartbeat-count">-</div>
                    <div class="card-stat">last 60 minutes</div>
                </div>

                <div class="card">
                    <h3>📊 Avg Runs/Min</h3>
                    <div class="card-value" id="heartbeat-avg">-</div>
                    <div class="card-stat">per minute</div>
                </div>

                <div class="card">
                    <h3>⏱️ Last Heartbeat</h3>
                    <div class="card-stat" id="heartbeat-last" style="font-size: 14px; font-weight: bold;"></div>
                </div>

                <div class="card wide-card">
                    <h3>❤️ Heartbeat Over Time (Last 60 Minutes)</h3>
                    <div class="chart-container">
                        <canvas id="heartbeatChart"></canvas>
                    </div>
                </div>

                <div class="card wide-card">
                    <h3>📋 All Heartbeats (Last 60 min)</h3>
                    <div class="data-list" id="heartbeat-list"></div>
                </div>
            </div>
        </div>

        <!-- TAB 2: GRAPHS -->
        <div id="graphs" class="tab-content">
            <div class="card wide-card">
                <h3>📥 Downloads Over Time</h3>
                <div class="chart-container">
                    <canvas id="downloadChart"></canvas>
                </div>
            </div>

            <div class="card wide-card">
                <h3>📄 Pages Over Time</h3>
                <div class="chart-container">
                    <canvas id="pagesChart"></canvas>
                </div>
            </div>

            <div class="card wide-card">
                <h3>⚡ Download Rate Per Minute</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 15px;">
                    <div style="background: #f5f5f5; padding: 10px; border-radius: 5px;">
                        <div style="color: #666; font-size: 11px;">Avg Rate</div>
                        <div style="font-size: 20px; font-weight: bold; color: #667eea;" id="avg-rate">-</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 10px; border-radius: 5px;">
                        <div style="color: #666; font-size: 11px;">Current</div>
                        <div style="font-size: 20px; font-weight: bold; color: #28a745;" id="current-rate">-</div>
                    </div>
                    <div style="background: #f5f5f5; padding: 10px; border-radius: 5px;">
                        <div style="color: #666; font-size: 11px;">Variation</div>
                        <div style="font-size: 20px; font-weight: bold; color: #ffc107;" id="rate-gap">-</div>
                    </div>
                </div>
                <div class="chart-container">
                    <canvas id="rateChart"></canvas>
                </div>
            </div>

            <div class="card wide-card">
                <h3>🖥️ Server Load Over Time</h3>
                <div class="chart-container">
                    <canvas id="loadChart"></canvas>
                </div>
            </div>

            <div class="card wide-card">
                <h3>⚠️ Errors Over Time</h3>
                <div class="chart-container">
                    <canvas id="errorsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- TAB 3: HISTORICAL DATA -->
        <div id="historical" class="tab-content">
            <div class="grid">
                <div class="card">
                    <h3>📥 Total Downloaded</h3>
                    <div class="card-value" id="hist-downloaded">-</div>
                </div>
                <div class="card">
                    <h3>🎯 Total Fetched</h3>
                    <div class="card-value" id="hist-fetched">-</div>
                </div>
                <div class="card">
                    <h3>☁️ S3 URLs</h3>
                    <div class="card-value" id="hist-s3">-</div>
                </div>
                <div class="card">
                    <h3>📊 Total Errors</h3>
                    <div class="card-value" id="hist-errors">-</div>
                </div>
            </div>

            <div class="card wide-card">
                <h3>📥 Downloaded Resumes (Last 50)</h3>
                <div class="data-list" id="downloaded-list"></div>
            </div>

            <div class="card wide-card">
                <h3>☁️ S3 URLs (Last 50)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Resume ID</th>
                            <th>Filename</th>
                            <th>S3 Link</th>
                        </tr>
                    </thead>
                    <tbody id="s3-table-body"></tbody>
                </table>
            </div>

            <div class="card wide-card">
                <h3>⚠️ Recent Errors (Last 20)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody id="errors-table-body"></tbody>
                </table>
            </div>
        </div>

        <!-- TAB 4: STATISTICS -->
        <div id="stats" class="tab-content">
            <div class="card wide-card">
                <h3>📅 Timeline Information</h3>
                <table style="width: 100%; margin-top: 0;">
                    <tr>
                        <td><strong>Process Start:</strong></td>
                        <td id="stat-start">-</td>
                    </tr>
                    <tr>
                        <td><strong>Last Activity:</strong></td>
                        <td id="stat-end">-</td>
                    </tr>
                    <tr>
                        <td><strong>Total Duration:</strong></td>
                        <td id="stat-duration">-</td>
                    </tr>
                    <tr>
                        <td><strong>Unique Timestamps:</strong></td>
                        <td id="stat-timestamps">-</td>
                    </tr>
                </table>
            </div>

            <div class="card wide-card">
                <h3>📊 Performance Metrics</h3>
                <div class="grid">
                    <div class="card">
                        <h3>↓ Avg Download Rate</h3>
                        <div class="card-value" id="stat-avg-rate">-</div>
                        <div class="card-stat">/minute</div>
                    </div>
                    <div class="card">
                        <h3>↓ Max Download Rate</h3>
                        <div class="card-value" id="stat-max-rate">-</div>
                        <div class="card-stat">/minute</div>
                    </div>
                    <div class="card">
                        <h3>↑ Avg Page Rate</h3>
                        <div class="card-value" id="stat-avg-pages">-</div>
                        <div class="card-stat">/minute</div>
                    </div>
                    <div class="card">
                        <h3>📈 Total Size</h3>
                        <div class="card-value" id="stat-size">-</div>
                        <div class="card-stat">MB</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <textarea id="data-export" style="display:none;"></textarea>

    <script>
        let allData = {};
        let liveData = {};
        let historyData = {};
        let heartbeatData = {};
        let errorData = {};
        let downloadData = {};
        let pageData = {};
        let s3Data = {};
        let loadData = {};
        let charts = {};

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            updateClock();
            loadAllData();
            loadHeartbeatData();
            loadPerMinuteData();
            setInterval(updateClock, 1000);
            setInterval(loadAllData, 30000);
            setInterval(loadHeartbeatData, 30000);
            setInterval(loadPerMinuteData, 30000);
        });

        function updateClock() {
            document.getElementById('current-time').textContent = new Date().toLocaleString();
        }

        // Load all data
        function loadAllData() {
            Promise.all([
                fetch('{{ route("api.live-data") }}').then(r => r.json()),
                fetch('{{ route("api.historical-data") }}').then(r => r.json())
            ])
            .then(([live, hist]) => {
                liveData = live;
                historyData = hist;
                updateAllUI();
            })
            .catch(e => console.error('Error:', e));
        }

        // Update all UI
        function updateAllUI() {
            updateLiveTab();
            updateGraphsTab();
            updateHistoricalTab();
            updateStatsTab();
        }

        // Update Live Tab
        function updateLiveTab() {
            const fetched = liveData.fetched || 0;
            const downloaded = liveData.downloaded || 0;

            document.getElementById('live-downloaded').textContent = downloaded;
            document.getElementById('live-pages').textContent = liveData.pages || 0;
            document.getElementById('live-pending').textContent = Math.max(0, fetched - downloaded);
            document.getElementById('live-errors').textContent = liveData.errors || 0;
            document.getElementById('live-load').textContent = (liveData.load && liveData.load[0].toFixed(1)) || '-';
            document.getElementById('cron-age').textContent = liveData.healthy ? '✓ Healthy' : '✗ Failed';
        }

        // Update Graphs Tab
        function updateGraphsTab() {
            if (!charts.downloadChart) {
                initializeCharts();
            }
            updateCharts();
        }

        // Initialize Charts
        function initializeCharts() {
            const commonOptions = {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true } },
                scales: { y: { beginAtZero: true } }
            };

            // Download Chart
            const downloadCtx = document.getElementById('downloadChart')?.getContext('2d');
            if (downloadCtx) {
                charts.downloadChart = new Chart(downloadCtx, {
                    type: 'line',
                    data: { labels: [], datasets: [{ label: 'Downloads', data: [], borderColor: '#667eea', backgroundColor: 'rgba(102, 126, 234, 0.1)', fill: true, tension: 0.3 }] },
                    options: commonOptions
                });
            }

            // Pages Chart
            const pagesCtx = document.getElementById('pagesChart')?.getContext('2d');
            if (pagesCtx) {
                charts.pagesChart = new Chart(pagesCtx, {
                    type: 'line',
                    data: { labels: [], datasets: [{ label: 'Pages', data: [], borderColor: '#28a745', backgroundColor: 'rgba(40, 167, 69, 0.1)', fill: true, tension: 0.3 }] },
                    options: commonOptions
                });
            }

            // Rate Chart
            const rateCtx = document.getElementById('rateChart')?.getContext('2d');
            if (rateCtx) {
                charts.rateChart = new Chart(rateCtx, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label: 'Per Minute', data: [], backgroundColor: 'rgba(102, 126, 234, 0.7)', borderColor: '#667eea' }] },
                    options: commonOptions
                });
            }

            // Load Chart
            const loadCtx = document.getElementById('loadChart')?.getContext('2d');
            if (loadCtx) {
                charts.loadChart = new Chart(loadCtx, {
                    type: 'line',
                    data: { labels: [], datasets: [{ label: 'Load', data: [], borderColor: '#ffc107', backgroundColor: 'rgba(255, 193, 7, 0.1)', fill: true, tension: 0.3 }] },
                    options: commonOptions
                });
            }

            // Errors Chart
            const errorsCtx = document.getElementById('errorsChart')?.getContext('2d');
            if (errorsCtx) {
                charts.errorsChart = new Chart(errorsCtx, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label: 'Errors', data: [], backgroundColor: 'rgba(220, 53, 69, 0.7)', borderColor: '#dc3545' }] },
                    options: commonOptions
                });
            }

            // Heartbeat Chart
            const heartbeatCtx = document.getElementById('heartbeatChart')?.getContext('2d');
            if (heartbeatCtx) {
                charts.heartbeatChart = new Chart(heartbeatCtx, {
                    type: 'bar',
                    data: { labels: [], datasets: [{ label: 'Cron Executions', data: [], backgroundColor: 'rgba(220, 20, 60, 0.7)', borderColor: '#dc143c', borderWidth: 2 }] },
                    options: commonOptions
                });
            }
        }

        // Load heartbeat data
        function loadHeartbeatData() {
            fetch('{{ route("api.heartbeat-data") }}')
                .then(r => r.json())
                .then(data => {
                    heartbeatData = data;
                    updateHeartbeatUI();
                    updateHeartbeatChart();
                })
                .catch(e => console.error('Error loading heartbeat:', e));
        }

        // Load per-minute data for last 60 minutes
        function loadPerMinuteData() {
            Promise.all([
                fetch('{{ route("api.errors-60min") }}').then(r => r.json()),
                fetch('{{ route("api.downloads-60min") }}').then(r => r.json()),
                fetch('{{ route("api.pages-60min") }}').then(r => r.json()),
                fetch('{{ route("api.s3-60min") }}').then(r => r.json()),
                fetch('{{ route("api.load-60min") }}').then(r => r.json())
            ])
            .then(([errors, downloads, pages, s3, load]) => {
                errorData = errors;
                downloadData = downloads;
                pageData = pages;
                s3Data = s3;
                loadData = load;
                updateCharts();
            })
            .catch(e => console.error('Error loading per-minute data:', e));
        }

        // Update heartbeat UI
        function updateHeartbeatUI() {
            document.getElementById('heartbeat-count').textContent = heartbeatData.total_heartbeats || 0;
            document.getElementById('heartbeat-avg').textContent = heartbeatData.avg_per_minute || '0.00';

            if (heartbeatData.timestamps?.last) {
                document.getElementById('heartbeat-last').textContent = heartbeatData.timestamps.last;
            }

            // Heartbeat list
            const list = document.getElementById('heartbeat-list');
            list.innerHTML = '';
            (heartbeatData.heartbeats_last_60min || []).forEach((hb, idx) => {
                const div = document.createElement('div');
                div.className = 'data-item';
                div.textContent = (idx + 1) + '. ✓ ' + hb;
                list.appendChild(div);
            });
        }

        // Update heartbeat chart
        function updateHeartbeatChart() {
            if (charts.heartbeatChart && heartbeatData.heartbeats_by_minute) {
                const times = Object.keys(heartbeatData.heartbeats_by_minute);
                const counts = Object.values(heartbeatData.heartbeats_by_minute);

                charts.heartbeatChart.data.labels = times;
                charts.heartbeatChart.data.datasets[0].data = counts;
                charts.heartbeatChart.update();
            }
        }

        // Update Charts with real per-minute data
        function updateCharts() {
            // Get real data from API responses
            const downloadTimes = Object.keys(downloadData.downloads_by_minute || {});
            const downloadValues = Object.values(downloadData.downloads_by_minute || {});

            const pageTimes = Object.keys(pageData.pages_by_minute || {});
            const pageValues = Object.values(pageData.pages_by_minute || {});

            const errorTimes = Object.keys(errorData.errors_by_minute || {});
            const errorValues = errorTimes.map(t => errorData.errors_by_minute[t]?.total || 0);

            const loadTimes = Object.keys(loadData.load_by_minute || {});
            const loadValues = Object.values(loadData.load_by_minute || {});

            const rateTimes = downloadTimes;
            const rateValues = downloadValues.map(v => Math.round(v / 1 * 100) / 100); // Per minute rate

            if (charts.downloadChart) {
                charts.downloadChart.data.labels = downloadTimes;
                charts.downloadChart.data.datasets[0].data = downloadValues;
                charts.downloadChart.update();
            }

            if (charts.pagesChart) {
                charts.pagesChart.data.labels = pageTimes;
                charts.pagesChart.data.datasets[0].data = pageValues;
                charts.pagesChart.update();
            }

            if (charts.rateChart) {
                charts.rateChart.data.labels = rateTimes;
                charts.rateChart.data.datasets[0].data = rateValues;
                charts.rateChart.update();
            }

            if (charts.loadChart) {
                charts.loadChart.data.labels = loadTimes;
                charts.loadChart.data.datasets[0].data = loadValues;
                charts.loadChart.update();
            }

            if (charts.errorsChart) {
                charts.errorsChart.data.labels = errorTimes;
                charts.errorsChart.data.datasets[0].data = errorValues;
                charts.errorsChart.update();
            }

            // Update rate statistics
            const avgRate = downloadData.avg_per_minute || 0;
            const maxRate = downloadValues.length > 0 ? Math.max(...downloadValues) : 0;
            const currentRate = downloadValues.length > 0 ? downloadValues[downloadValues.length - 1] : 0;
            const rateGap = Math.abs(avgRate - currentRate).toFixed(2);

            document.getElementById('avg-rate').textContent = avgRate.toFixed(2);
            document.getElementById('current-rate').textContent = currentRate.toFixed(2);
            document.getElementById('rate-gap').textContent = rateGap;
        }

        // Update Historical Tab
        function updateHistoricalTab() {
            document.getElementById('hist-downloaded').textContent = historyData.downloaded_resumes?.count || 0;
            document.getElementById('hist-fetched').textContent = historyData.fetched_resume_ids?.count || 0;
            document.getElementById('hist-s3').textContent = historyData.s3_urls?.count || 0;
            document.getElementById('hist-errors').textContent = historyData.errors_timeline?.total_errors || 0;

            // Downloaded list
            const list = document.getElementById('downloaded-list');
            list.innerHTML = '';
            (historyData.downloaded_resumes?.items || []).slice(0, 50).forEach(item => {
                const div = document.createElement('div');
                div.className = 'data-item';
                div.textContent = '✓ ' + item;
                list.appendChild(div);
            });

            // S3 table
            const s3Body = document.getElementById('s3-table-body');
            s3Body.innerHTML = '';
            (historyData.s3_urls?.items || []).slice(0, 30).forEach(item => {
                const row = document.createElement('tr');
                row.innerHTML = `<td style="font-size:10px;">${item.resume_id}</td><td>${item.filename}</td><td><a href="${item.s3_url}" target="_blank" style="color:#667eea;">Link</a></td>`;
                s3Body.appendChild(row);
            });

            // Errors table
            const errorsBody = document.getElementById('errors-table-body');
            errorsBody.innerHTML = '';
            (historyData.errors_timeline?.recent_errors || []).forEach(error => {
                const row = document.createElement('tr');
                row.innerHTML = `<td>${error.timestamp}</td><td style="font-size:10px;">${error.message.substring(0, 80)}...</td>`;
                errorsBody.appendChild(row);
            });
        }

        // Update Stats Tab
        function updateStatsTab() {
            const ts = historyData.timestamps || {};
            document.getElementById('stat-start').textContent = ts.first_timestamp || '-';
            document.getElementById('stat-end').textContent = ts.last_timestamp || '-';
            document.getElementById('stat-timestamps').textContent = ts.unique_timestamps || '-';
            document.getElementById('stat-duration').textContent = 'Calculating...';

            // Calculate from real data
            const avgDownloadRate = downloadData.avg_per_minute || 0;
            const maxDownloadRate = downloadData.downloads_by_minute ? Math.max(...Object.values(downloadData.downloads_by_minute)) : 0;
            const avgPageRate = pageData.avg_per_minute || 0;

            // Get total size from HTML or default
            const totalSize = historyData.current?.total_size || '19.48';

            document.getElementById('stat-avg-rate').textContent = avgDownloadRate.toFixed(2);
            document.getElementById('stat-max-rate').textContent = maxDownloadRate.toFixed(2);
            document.getElementById('stat-avg-pages').textContent = avgPageRate.toFixed(2);
            document.getElementById('stat-size').textContent = totalSize;
        }

        // Tab Switching
        function switchTab(tab) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            document.getElementById(tab).classList.add('active');
            event.target.classList.add('active');
        }

        // Copy All Data - COMPLETE with all metrics
        function copyAllData() {
            const errorTypes = errorData.errors_by_type || {};
            const s3By60 = s3Data.s3_by_minute || {};

            const report = `COMPLETE RESUME MONITOR REPORT
Generated: ${new Date().toLocaleString()}

=== LIVE METRICS (Current) ===
Downloaded: ${document.getElementById('live-downloaded').textContent} resumes
Pages Fetched: ${document.getElementById('live-pages').textContent} pages
Pending: ${document.getElementById('live-pending').textContent} resumes
Errors Today: ${document.getElementById('live-errors').textContent}
Server Load: ${document.getElementById('live-load').textContent}
Current Time: ${document.getElementById('current-time').textContent}

=== CRON/HEARTBEAT STATUS ===
Heartbeat Status: ${document.getElementById('cron-status').textContent}
Last Heartbeat: ${document.getElementById('heartbeat-last').textContent}
Heartbeat Runs (60 min): ${document.getElementById('heartbeat-count').textContent}
Avg Runs/Min: ${document.getElementById('heartbeat-avg').textContent}

=== HISTORICAL DATA (All Time) ===
Total Downloaded: ${document.getElementById('hist-downloaded').textContent}
Total Fetched: ${document.getElementById('hist-fetched').textContent}
S3 URLs Generated: ${document.getElementById('hist-s3').textContent}
Total Errors: ${document.getElementById('hist-errors').textContent}

=== LAST 60 MINUTES BREAKDOWN ===
Downloads (60 min): ${downloadData.total_downloads_60min || 0}
Pages Fetched (60 min): ${pageData.total_pages_60min || 0}
S3 Uploads (60 min): ${s3Data.total_s3_60min || 0}
Errors (60 min): ${errorData.total_errors_60min || 0}

=== ERROR BREAKDOWN (Last 60 Min) ===
Page Fetch Errors: ${errorTypes.page_fetch || 0}
S3 Upload Errors: ${errorTypes.s3_upload || 0}
Download Errors: ${errorTypes.download || 0}
Other Errors: ${errorTypes.other || 0}

=== TIMELINE ===
Process Start: ${document.getElementById('stat-start').textContent}
Last Activity: ${document.getElementById('stat-end').textContent}

=== PERFORMANCE METRICS ===
Avg Download Rate: ${document.getElementById('stat-avg-rate').textContent}/min
Max Download Rate: ${document.getElementById('stat-max-rate').textContent}/min
Avg Page Rate: ${document.getElementById('stat-avg-pages').textContent}/min
Total Size: ${document.getElementById('stat-size').textContent} MB

=== MINUTE-BY-MINUTE SUMMARY (Last 60 Min) ===
Minutes with Downloads: ${Object.keys(downloadData.downloads_by_minute || {}).length}
Minutes with Pages: ${Object.keys(pageData.pages_by_minute || {}).length}
Minutes with S3: ${Object.keys(s3By60).length}
Minutes with Errors: ${errorData.minutes_with_errors || 0}

Report generated: ${new Date().toLocaleString()}`;

            navigator.clipboard.writeText(report).then(() => {
                const btn = document.getElementById('copy-btn');
                btn.textContent = '✓ Exported!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = '📋 Export All';
                    btn.classList.remove('copied');
                }, 2000);
            }).catch(err => {
                alert('Failed to copy: ' + err);
            });
        }
    </script>
</body>
</html>
