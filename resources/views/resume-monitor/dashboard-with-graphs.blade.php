<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume Downloader - Monitor with Graphs</title>
    <!-- Chart.js for graphs -->
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
            max-width: 1600px;
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

        header .time {
            color: #666;
            font-size: 14px;
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

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .card h3 {
            color: #667eea;
            font-size: 14px;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }

        .card-value {
            font-size: 32px;
            font-weight: bold;
            color: #333;
            margin: 10px 0;
        }

        .card-timestamp {
            font-size: 11px;
            color: #999;
            background: #f5f5f5;
            padding: 5px 8px;
            border-radius: 3px;
            margin-top: 10px;
            display: inline-block;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-right: 5px;
        }

        .badge-success {
            background: #28a745;
            color: white;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: black;
        }

        .wide-card {
            grid-column: 1 / -1;
        }

        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }

        .stat-row:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #666;
        }

        .stat-value {
            font-weight: 600;
            color: #333;
        }

        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 15px;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #764ba2;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 13px;
        }

        table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #dee2e6;
            color: #667eea;
            font-weight: 600;
        }

        table td {
            padding: 12px;
            border-bottom: 1px solid #dee2e6;
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

            header {
                flex-direction: column;
                gap: 15px;
            }

            header h1 {
                font-size: 20px;
            }

            .chart-container {
                height: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <header>
            <div>
                <h1>📊 Resume Downloader - Live Monitoring</h1>
                <div class="time">Last updated: <span id="update-time">{{ now()->format('Y-m-d H:i:s') }}</span></div>
            </div>
            <div class="header-actions">
                <button class="copy-btn" id="copy-btn" onclick="copyAllData()">📋 Copy All Data</button>
                <form method="POST" action="{{ route('monitor.logout') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="logout">Logout</button>
                </form>
            </div>
        </header>

        <!-- STATUS ALERTS -->
        @if (!$cronHealthy)
            <div class="alert alert-danger">
                ⚠️ <strong>CRON NOT RUNNING!</strong> Heartbeat age: {{ $heartbeatAge }}
                <button class="btn btn-primary" onclick="forceRun()" style="margin-left: 10px;">Force Run Now</button>
            </div>
        @endif

        <!-- KEY METRICS -->
        <div class="grid">
            <div class="card">
                <h3>📥 Downloaded (Live)</h3>
                <div class="card-value" id="live-downloaded">{{ $totalDownloaded }}</div>
                <div class="card-timestamp">{{ now()->format('Y-m-d H:i:s') }}</div>
            </div>

            <div class="card">
                <h3>📄 Pages (Live)</h3>
                <div class="card-value" id="live-pages">{{ $pagesFetched }}</div>
                <div class="card-timestamp">{{ now()->format('Y-m-d H:i:s') }}</div>
            </div>

            <div class="card">
                <h3>⏳ Pending (Live)</h3>
                <div class="card-value" id="live-pending">{{ $pending }}</div>
                <div class="card-timestamp">{{ now()->format('Y-m-d H:i:s') }}</div>
            </div>

            <div class="card">
                <h3>🔄 Cron Status</h3>
                <div class="card-value">
                    <span class="badge {{ $cronHealthy ? 'badge-success' : 'badge-danger' }}">
                        {{ $cronHealthy ? '✓ HEALTHY' : '✗ FAILED' }}
                    </span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Heartbeat:</span>
                    <span class="stat-value">{{ $heartbeatAge }}</span>
                </div>
                <div class="card-timestamp">{{ now()->format('Y-m-d H:i:s') }}</div>
            </div>
        </div>

        <!-- DOWNLOAD TREND GRAPH -->
        <div class="card wide-card">
            <h3>📊 Download Progress Over Time (Last 60 Minutes)</h3>
            <div class="chart-container">
                <canvas id="downloadChart"></canvas>
            </div>
            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                Shows how many resumes have been downloaded over the past hour. Each point represents one minute of data.
            </p>
        </div>

        <!-- PAGES FETCHED TREND GRAPH -->
        <div class="card wide-card">
            <h3>📄 Pages Fetched Over Time (Last 60 Minutes)</h3>
            <div class="chart-container">
                <canvas id="pagesChart"></canvas>
            </div>
            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                Shows page fetching progress over time. Helps identify fetch rate consistency.
            </p>
        </div>

        <!-- DOWNLOAD RATE PER MINUTE GRAPH -->
        <div class="card wide-card">
            <h3>⚡ Download Rate Per Minute (Last 60 Minutes)</h3>
            <div class="chart-container">
                <canvas id="downloadRateChart"></canvas>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <div style="background: #f5f5f5; padding: 12px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px;">Avg Downloads/Min</div>
                    <div style="font-size: 24px; font-weight: bold; color: #667eea;" id="avg-download-rate">0</div>
                </div>
                <div style="background: #f5f5f5; padding: 12px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px;">Max/Min Gap</div>
                    <div style="font-size: 24px; font-weight: bold; color: #28a745;" id="download-rate-gap">0</div>
                </div>
                <div style="background: #f5f5f5; padding: 12px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px;">Current Rate</div>
                    <div style="font-size: 24px; font-weight: bold; color: #ffc107;" id="current-download-rate">0/min</div>
                </div>
            </div>
            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                Shows how many resumes are downloaded each minute. Higher rate = faster downloads. Gaps show inconsistencies.
            </p>
        </div>

        <!-- PAGES RATE PER MINUTE GRAPH -->
        <div class="card wide-card">
            <h3>⚡ Pages Fetched Rate Per Minute (Last 60 Minutes)</h3>
            <div class="chart-container">
                <canvas id="pagesRateChart"></canvas>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 15px;">
                <div style="background: #f5f5f5; padding: 12px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px;">Avg Pages/Min</div>
                    <div style="font-size: 24px; font-weight: bold; color: #667eea;" id="avg-pages-rate">0</div>
                </div>
                <div style="background: #f5f5f5; padding: 12px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px;">Max/Min Gap</div>
                    <div style="font-size: 24px; font-weight: bold; color: #28a745;" id="pages-rate-gap">0</div>
                </div>
                <div style="background: #f5f5f5; padding: 12px; border-radius: 5px;">
                    <div style="color: #666; font-size: 12px;">Current Rate</div>
                    <div style="font-size: 24px; font-weight: bold; color: #ffc107;" id="current-pages-rate">0/min</div>
                </div>
            </div>
            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                Shows how many pages are fetched each minute. Helps identify fetch efficiency and bottlenecks.
            </p>
        </div>

        <!-- SERVER LOAD TREND GRAPH -->
        <div class="card wide-card">
            <h3>🖥️ Server Load Over Time (Last 60 Minutes)</h3>
            <div class="chart-container">
                <canvas id="loadChart"></canvas>
            </div>
            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                Shows server CPU load (1-minute average). Helps identify peak times and resource constraints.
            </p>
        </div>

        <!-- ERRORS TREND GRAPH -->
        <div class="card wide-card">
            <h3>⚠️ Errors Over Time (Last 60 Minutes)</h3>
            <div class="chart-container">
                <canvas id="errorsChart"></canvas>
            </div>
            <p style="color: #666; font-size: 12px; margin-top: 10px;">
                Shows error count trends. Helps identify when issues started and if they're resolved.
            </p>
        </div>

        <!-- STATISTICS TABLE -->
        <div class="card wide-card">
            <h3>📈 Current Statistics</h3>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td><strong>Total Downloaded</strong></td>
                    <td>{{ $totalDownloaded }} resumes</td>
                    <td><span class="badge badge-success">✓ Active</span></td>
                </tr>
                <tr>
                    <td><strong>Total Size</strong></td>
                    <td>{{ $totalSize }}</td>
                    <td></td>
                </tr>
                <tr>
                    <td><strong>Pages Fetched</strong></td>
                    <td>{{ $pagesFetched }} / ~1000-10000</td>
                    <td><span class="badge badge-warning">In Progress</span></td>
                </tr>
                <tr>
                    <td><strong>Pending Downloads</strong></td>
                    <td>{{ $pending }} resumes</td>
                    <td>Est. {{ $estimatedHours }}h {{ $estimatedMins }}m</td>
                </tr>
                <tr>
                    <td><strong>Server Load</strong></td>
                    <td>{{ $load[0] }} (1min)</td>
                    <td><span class="badge {{ $load[0] > 30 ? 'badge-danger' : 'badge-success' }}">
                        {{ $load[0] > 30 ? 'HIGH' : 'NORMAL' }}
                    </span></td>
                </tr>
                <tr>
                    <td><strong>Errors Today</strong></td>
                    <td>{{ $errorCount }}</td>
                    <td><span class="badge {{ $errorCount > 50 ? 'badge-danger' : 'badge-success' }}">
                        {{ $errorCount > 50 ? 'ALERT' : 'OK' }}
                    </span></td>
                </tr>
            </table>
        </div>

        <!-- ACTIONS -->
        <div class="card wide-card">
            <h3>⚡ Actions</h3>
            <div class="button-group">
                <button class="btn btn-primary" onclick="forceRun()">Force Run Script</button>
                <button class="btn btn-primary" onclick="location.reload()">Refresh Dashboard</button>
            </div>
        </div>
    </div>

    <!-- Hidden textarea for copy -->
    <textarea id="data-export" style="display:none;"></textarea>

    <script>
        // Store data points for graphs
        let downloadHistory = [];
        let pageHistory = [];
        let loadHistory = [];
        let errorHistory = [];
        let downloadRateHistory = [];  // Downloads per minute
        let pageRateHistory = [];      // Pages per minute
        let timeLabels = [];

        // Chart instances
        let downloadChart, pagesChart, loadChart, errorsChart, downloadRateChart, pagesRateChart;

        // Initialize charts
        function initCharts() {
            // DOWNLOAD CHART
            const downloadCtx = document.getElementById('downloadChart').getContext('2d');
            downloadChart = new Chart(downloadCtx, {
                type: 'line',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Downloaded Resumes',
                        data: downloadHistory,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#667eea',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#666' }
                        },
                        x: {
                            ticks: { color: '#666' }
                        }
                    }
                }
            });

            // PAGES CHART
            const pagesCtx = document.getElementById('pagesChart').getContext('2d');
            pagesChart = new Chart(pagesCtx, {
                type: 'line',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Pages Fetched',
                        data: pageHistory,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#28a745',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#666' }
                        },
                        x: {
                            ticks: { color: '#666' }
                        }
                    }
                }
            });

            // LOAD CHART
            const loadCtx = document.getElementById('loadChart').getContext('2d');
            loadChart = new Chart(loadCtx, {
                type: 'line',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Server Load (1min avg)',
                        data: loadHistory,
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#ffc107',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#666' }
                        },
                        x: {
                            ticks: { color: '#666' }
                        }
                    }
                }
            });

            // ERRORS CHART
            const errorsCtx = document.getElementById('errorsChart').getContext('2d');
            errorsChart = new Chart(errorsCtx, {
                type: 'line',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Errors Count',
                        data: errorHistory,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#dc3545',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#666' }
                        },
                        x: {
                            ticks: { color: '#666' }
                        }
                    }
                }
            });

            // DOWNLOAD RATE CHART (Per Minute)
            const downloadRateCtx = document.getElementById('downloadRateChart').getContext('2d');
            downloadRateChart = new Chart(downloadRateCtx, {
                type: 'bar',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Downloads/Minute',
                        data: downloadRateHistory,
                        backgroundColor: 'rgba(102, 126, 234, 0.7)',
                        borderColor: '#667eea',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#666' }
                        },
                        x: {
                            ticks: { color: '#666' }
                        }
                    }
                }
            });

            // PAGES RATE CHART (Per Minute)
            const pagesRateCtx = document.getElementById('pagesRateChart').getContext('2d');
            pagesRateChart = new Chart(pagesRateCtx, {
                type: 'bar',
                data: {
                    labels: timeLabels,
                    datasets: [{
                        label: 'Pages/Minute',
                        data: pageRateHistory,
                        backgroundColor: 'rgba(40, 167, 69, 0.7)',
                        borderColor: '#28a745',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { color: '#666' }
                        },
                        x: {
                            ticks: { color: '#666' }
                        }
                    }
                }
            });
        }

        // Calculate rate per minute from history
        function calculateRates() {
            downloadRateHistory = [];
            pageRateHistory = [];

            for (let i = 1; i < downloadHistory.length; i++) {
                const downloadDelta = downloadHistory[i] - downloadHistory[i-1];
                const pageDelta = pageHistory[i] - pageHistory[i-1];

                downloadRateHistory.push(Math.max(0, downloadDelta));
                pageRateHistory.push(Math.max(0, pageDelta));
            }

            // Add zero for first point (no previous data)
            if (downloadRateHistory.length < downloadHistory.length) {
                downloadRateHistory.unshift(0);
                pageRateHistory.unshift(0);
            }

            // Update statistics
            updateRateStatistics();
        }

        // Update rate statistics
        function updateRateStatistics() {
            // Downloads per minute stats
            if (downloadRateHistory.length > 0) {
                const avgDownloadRate = (downloadRateHistory.reduce((a, b) => a + b, 0) / downloadRateHistory.length).toFixed(2);
                const maxDownloadRate = Math.max(...downloadRateHistory);
                const minDownloadRate = Math.min(...downloadRateHistory);
                const currentDownloadRate = downloadRateHistory[downloadRateHistory.length - 1];
                const downloadGap = (maxDownloadRate - minDownloadRate).toFixed(2);

                document.getElementById('avg-download-rate').textContent = avgDownloadRate + '/min';
                document.getElementById('download-rate-gap').textContent = downloadGap + ' gap';
                document.getElementById('current-download-rate').textContent = currentDownloadRate + '/min';
            }

            // Pages per minute stats
            if (pageRateHistory.length > 0) {
                const avgPagesRate = (pageRateHistory.reduce((a, b) => a + b, 0) / pageRateHistory.length).toFixed(2);
                const maxPagesRate = Math.max(...pageRateHistory);
                const minPagesRate = Math.min(...pageRateHistory);
                const currentPagesRate = pageRateHistory[pageRateHistory.length - 1];
                const pagesGap = (maxPagesRate - minPagesRate).toFixed(2);

                document.getElementById('avg-pages-rate').textContent = avgPagesRate + '/min';
                document.getElementById('pages-rate-gap').textContent = pagesGap + ' gap';
                document.getElementById('current-pages-rate').textContent = currentPagesRate + '/min';
            }
        }

        // Update data from API
        function updateData() {
            fetch('{{ route("api.live-data") }}')
                .then(r => r.json())
                .then(data => {
                    const now = new Date();
                    const timeLabel = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });

                    // Add new data points
                    downloadHistory.push(data.downloaded || {{ $totalDownloaded }});
                    pageHistory.push(data.pages || {{ $pagesFetched }});
                    loadHistory.push(Math.round(data.load[0] * 10) / 10);
                    timeLabels.push(timeLabel);

                    // Fetch error count from endpoint
                    fetch('/api/get-error-count')
                        .then(r => r.json())
                        .then(edata => {
                            errorHistory.push(edata.error_count || 0);

                            // Keep only last 60 data points
                            if (downloadHistory.length > 60) {
                                downloadHistory.shift();
                                pageHistory.shift();
                                loadHistory.shift();
                                errorHistory.shift();
                                timeLabels.shift();
                            }

                            // Update live values
                            document.getElementById('live-downloaded').textContent = data.downloaded || {{ $totalDownloaded }};
                            document.getElementById('live-pages').textContent = data.pages || {{ $pagesFetched }};
                            document.getElementById('live-pending').textContent = (data.fetched || {{ $totalFetched }}) - (data.downloaded || {{ $totalDownloaded }});
                            document.getElementById('update-time').textContent = new Date().toLocaleString();

                            // Calculate rates
                            calculateRates();

                            // Update charts
                            downloadChart.update();
                            pagesChart.update();
                            loadChart.update();
                            errorsChart.update();
                            downloadRateChart.update();
                            pagesRateChart.update();
                        });
                })
                .catch(e => console.log('Error fetching data:', e));
        }

        // Copy function
        function copyAllData() {
            const timestamp = new Date().toLocaleString();
            const data = `RESUME DOWNLOADER MONITOR REPORT
Generated: ${timestamp}

=== LIVE METRICS ===
Downloaded: ${document.getElementById('live-downloaded').textContent} resumes
Pages: ${document.getElementById('live-pages').textContent}
Pending: ${document.getElementById('live-pending').textContent}

=== DOWNLOAD RATE STATISTICS ===
Average Rate: ${document.getElementById('avg-download-rate').textContent}
Current Rate: ${document.getElementById('current-download-rate').textContent}
Max/Min Gap: ${document.getElementById('download-rate-gap').textContent}

=== PAGES RATE STATISTICS ===
Average Rate: ${document.getElementById('avg-pages-rate').textContent}
Current Rate: ${document.getElementById('current-pages-rate').textContent}
Max/Min Gap: ${document.getElementById('pages-rate-gap').textContent}

=== DOWNLOAD HISTORY (Last 60 min) ===
${downloadHistory.join(', ')}

=== DOWNLOAD RATE HISTORY (Per Minute) ===
${downloadRateHistory.join(', ')}

=== PAGES HISTORY (Last 60 min) ===
${pageHistory.join(', ')}

=== PAGES RATE HISTORY (Per Minute) ===
${pageRateHistory.join(', ')}

=== SERVER LOAD HISTORY (Last 60 min) ===
${loadHistory.join(', ')}

=== ERRORS HISTORY (Last 60 min) ===
${errorHistory.join(', ')}

Report generated at: ${timestamp}`;

            navigator.clipboard.writeText(data).then(() => {
                const btn = document.getElementById('copy-btn');
                btn.textContent = '✓ Copied!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = '📋 Copy All Data';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }

        function forceRun() {
            if (confirm('Run the downloader script now?')) {
                fetch('{{ route("api.force-run") }}', { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        alert('✓ Script started');
                        setTimeout(() => location.reload(), 2000);
                    });
            }
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', () => {
            initCharts();
            updateData();

            // Update every 30 seconds
            setInterval(updateData, 30000);

            // Reload every 5 minutes
            setTimeout(() => location.reload(), 300000);
        });
    </script>
</body>
</html>
