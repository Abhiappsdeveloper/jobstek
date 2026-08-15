<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume Downloader - Historical Data Dashboard</title>
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

        .data-list {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            max-height: 300px;
            overflow-y: auto;
            font-size: 12px;
        }

        .data-item {
            padding: 5px;
            border-bottom: 1px solid #dee2e6;
            font-family: monospace;
            color: #333;
        }

        .data-item:last-child {
            border-bottom: none;
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

        table tr:hover {
            background: #f8f9fa;
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

        .loading {
            text-align: center;
            padding: 20px;
            color: #666;
        }

        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 10px auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <header>
            <div>
                <h1>📊 Historical Data Dashboard</h1>
                <div style="color: #666; font-size: 14px;">All Data from TXT Files</div>
            </div>
            <div class="header-actions">
                <button class="copy-btn" id="copy-btn" onclick="copyAllData()">📋 Export All Data</button>
                <form method="POST" action="{{ route('monitor.logout') }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="logout">Logout</button>
                </form>
            </div>
        </header>

        <!-- LOADING INDICATOR -->
        <div id="loading" class="card wide-card" style="text-align: center;">
            <div class="spinner"></div>
            <p>Loading historical data from all files...</p>
        </div>

        <!-- DATA CONTENT (Hidden until loaded) -->
        <div id="content" style="display: none;">
            <!-- SUMMARY CARDS -->
            <div class="grid">
                <div class="card">
                    <h3>📥 Downloaded Resumes</h3>
                    <div class="card-value" id="downloaded-count">0</div>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="badge badge-success">Tracked</span>
                    </div>
                </div>

                <div class="card">
                    <h3>🎯 Fetched Resume IDs</h3>
                    <div class="card-value" id="fetched-count">0</div>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="badge badge-success">Tracked</span>
                    </div>
                </div>

                <div class="card">
                    <h3>☁️ S3 URLs Captured</h3>
                    <div class="card-value" id="s3-count">0</div>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="badge badge-success">Tracked</span>
                    </div>
                </div>

                <div class="card">
                    <h3>📄 Pages Fetched</h3>
                    <div class="card-value" id="pages-count">0</div>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="badge badge-success">Tracked</span>
                    </div>
                </div>

                <div class="card">
                    <h3>⚠️ Total Errors</h3>
                    <div class="card-value" id="errors-count">0</div>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="badge badge-success">Tracked</span>
                    </div>
                </div>

                <div class="card">
                    <h3>📜 Total Log Entries</h3>
                    <div class="card-value" id="logs-count">0</div>
                    <div class="stat-row">
                        <span class="stat-label">Status:</span>
                        <span class="badge badge-success">Tracked</span>
                    </div>
                </div>
            </div>

            <!-- TIMELINE GRAPH -->
            <div class="card wide-card">
                <h3>📊 Errors by Time (All Data)</h3>
                <div class="chart-container">
                    <canvas id="errorsTimelineChart"></canvas>
                </div>
                <p style="color: #666; font-size: 12px; margin-top: 10px;">
                    Shows all errors recorded with their timestamps throughout the entire process.
                </p>
            </div>

            <!-- LOG ENTRIES GRAPH -->
            <div class="card wide-card">
                <h3>📊 Log Entries by Minute (All Data)</h3>
                <div class="chart-container">
                    <canvas id="logsTimelineChart"></canvas>
                </div>
                <p style="color: #666; font-size: 12px; margin-top: 10px;">
                    Shows the volume of log entries recorded each minute throughout the entire process.
                </p>
            </div>

            <!-- DOWNLOADED RESUMES TABLE -->
            <div class="card wide-card">
                <h3>📥 Downloaded Resumes (<span id="downloaded-table-count">0</span> items)</h3>
                <div class="data-list" id="downloaded-list"></div>
            </div>

            <!-- S3 URLS TABLE -->
            <div class="card wide-card">
                <h3>☁️ S3 URLs Captured (<span id="s3-table-count">0</span> items)</h3>
                <table id="s3-table">
                    <thead>
                        <tr>
                            <th>Resume ID</th>
                            <th>Filename</th>
                            <th>S3 URL</th>
                        </tr>
                    </thead>
                    <tbody id="s3-table-body"></tbody>
                </table>
            </div>

            <!-- RECENT ERRORS TABLE -->
            <div class="card wide-card">
                <h3>⚠️ Recent Errors (<span id="errors-table-count">0</span> latest)</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Error Message</th>
                        </tr>
                    </thead>
                    <tbody id="errors-table-body"></tbody>
                </table>
            </div>

            <!-- TIMELINE STATISTICS -->
            <div class="card wide-card">
                <h3>📅 Timeline Statistics</h3>
                <table>
                    <tr>
                        <td><strong>Process Started:</strong></td>
                        <td id="timeline-start">-</td>
                    </tr>
                    <tr>
                        <td><strong>Last Activity:</strong></td>
                        <td id="timeline-end">-</td>
                    </tr>
                    <tr>
                        <td><strong>Total Duration:</strong></td>
                        <td id="timeline-duration">-</td>
                    </tr>
                    <tr>
                        <td><strong>Unique Timestamps:</strong></td>
                        <td id="unique-timestamps">-</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <textarea id="data-export" style="display:none;"></textarea>

    <script>
        let allData = {};
        let errorsTimelineChart, logsTimelineChart;

        // Load all historical data
        function loadData() {
            fetch('{{ route("api.historical-data") }}')
                .then(r => r.json())
                .then(data => {
                    allData = data;
                    renderData();
                    initCharts();
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('content').style.display = 'block';
                })
                .catch(e => {
                    console.error('Error:', e);
                    document.getElementById('loading').innerHTML = '<p style="color: red;">Error loading data: ' + e + '</p>';
                });
        }

        // Render all data
        function renderData() {
            // Update counts
            document.getElementById('downloaded-count').textContent = allData.downloaded_resumes?.count || 0;
            document.getElementById('fetched-count').textContent = allData.fetched_resume_ids?.count || 0;
            document.getElementById('s3-count').textContent = allData.s3_urls?.count || 0;
            document.getElementById('pages-count').textContent = allData.pages_progress?.current_page || 0;
            document.getElementById('errors-count').textContent = allData.errors_timeline?.total_errors || 0;
            document.getElementById('logs-count').textContent = allData.log_timeline?.total_log_entries || 0;

            // Downloaded resumes list
            const downloadedList = document.getElementById('downloaded-list');
            downloadedList.innerHTML = '';
            document.getElementById('downloaded-table-count').textContent = allData.downloaded_resumes?.count || 0;
            (allData.downloaded_resumes?.items || []).slice(0, 50).forEach(item => {
                const div = document.createElement('div');
                div.className = 'data-item';
                div.textContent = '✓ ' + item;
                downloadedList.appendChild(div);
            });
            if ((allData.downloaded_resumes?.count || 0) > 50) {
                const div = document.createElement('div');
                div.className = 'data-item';
                div.style.fontWeight = 'bold';
                div.textContent = '... and ' + (allData.downloaded_resumes.count - 50) + ' more';
                downloadedList.appendChild(div);
            }

            // S3 URLs table
            const s3Body = document.getElementById('s3-table-body');
            s3Body.innerHTML = '';
            document.getElementById('s3-table-count').textContent = allData.s3_urls?.count || 0;
            (allData.s3_urls?.items || []).forEach(item => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td style="font-family: monospace; font-size: 11px;">${item.resume_id}</td>
                    <td>${item.filename}</td>
                    <td style="font-size: 11px; word-break: break-all;"><a href="${item.s3_url}" target="_blank" style="color: #667eea;">View</a></td>
                `;
                s3Body.appendChild(row);
            });

            // Recent errors
            const errorsBody = document.getElementById('errors-table-body');
            errorsBody.innerHTML = '';
            document.getElementById('errors-table-count').textContent = (allData.errors_timeline?.recent_errors || []).length;
            (allData.errors_timeline?.recent_errors || []).forEach(error => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${error.timestamp}</td>
                    <td style="font-size: 11px; color: #dc3545;">${error.message.substring(0, 100)}...</td>
                `;
                errorsBody.appendChild(row);
            });

            // Timeline stats
            const timestamps = allData.timestamps?.timestamps || [];
            if (timestamps.length > 0) {
                document.getElementById('timeline-start').textContent = timestamps[0];
                document.getElementById('timeline-end').textContent = timestamps[timestamps.length - 1];
                document.getElementById('unique-timestamps').textContent = timestamps.length;

                // Calculate duration
                const start = new Date(timestamps[0]);
                const end = new Date(timestamps[timestamps.length - 1]);
                const duration = Math.round((end - start) / 60000); // minutes
                document.getElementById('timeline-duration').textContent = duration + ' minutes (' + Math.round(duration / 60) + 'h ' + (duration % 60) + 'm)';
            }
        }

        // Initialize charts
        function initCharts() {
            const errorsData = allData.errors_timeline?.errors_by_time || {};
            const logsData = allData.log_timeline?.logs_by_minute || {};

            const timestamps = Object.keys(errorsData).sort();
            const errorCounts = timestamps.map(ts => errorsData[ts]);
            const logCounts = timestamps.map(ts => logsData[ts] || 0);

            // Errors timeline chart
            const errorsCtx = document.getElementById('errorsTimelineChart')?.getContext('2d');
            if (errorsCtx) {
                errorsTimelineChart = new Chart(errorsCtx, {
                    type: 'line',
                    data: {
                        labels: timestamps,
                        datasets: [{
                            label: 'Errors',
                            data: errorCounts,
                            borderColor: '#dc3545',
                            backgroundColor: 'rgba(220, 53, 69, 0.1)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 3
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: true } },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }

            // Logs timeline chart
            const logsCtx = document.getElementById('logsTimelineChart')?.getContext('2d');
            if (logsCtx) {
                logsTimelineChart = new Chart(logsCtx, {
                    type: 'bar',
                    data: {
                        labels: timestamps,
                        datasets: [{
                            label: 'Log Entries/Minute',
                            data: logCounts,
                            backgroundColor: 'rgba(102, 126, 234, 0.7)',
                            borderColor: '#667eea',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: true } },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            }
        }

        // Copy all data
        function copyAllData() {
            const report = generateReport();
            navigator.clipboard.writeText(report).then(() => {
                const btn = document.getElementById('copy-btn');
                btn.textContent = '✓ Exported!';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = '📋 Export All Data';
                    btn.classList.remove('copied');
                }, 2000);
            });
        }

        // Generate report
        function generateReport() {
            return `HISTORICAL DATA REPORT
Generated: ${new Date().toLocaleString()}

=== SUMMARY ===
Downloaded Resumes: ${allData.downloaded_resumes?.count || 0}
Fetched Resume IDs: ${allData.fetched_resume_ids?.count || 0}
S3 URLs: ${allData.s3_urls?.count || 0}
Pages Fetched: ${allData.pages_progress?.current_page || 0}
Total Errors: ${allData.errors_timeline?.total_errors || 0}
Total Log Entries: ${allData.log_timeline?.total_log_entries || 0}

=== TIMELINE ===
Start: ${allData.timestamps?.first_timestamp || '-'}
End: ${allData.timestamps?.last_timestamp || '-'}
Duration: ${document.getElementById('timeline-duration').textContent}

=== DOWNLOADED RESUMES (First 100) ===
${(allData.downloaded_resumes?.items || []).slice(0, 100).join('\n')}

=== S3 URLS (First 50) ===
${(allData.s3_urls?.items || []).slice(0, 50).map(u => u.resume_id + ' | ' + u.filename + ' | ' + u.s3_url).join('\n')}

=== RECENT ERRORS ===
${(allData.errors_timeline?.recent_errors || []).map(e => e.timestamp + ' | ' + e.message).join('\n')}

Report generated: ${new Date().toLocaleString()}`;
        }

        // Load on page load
        document.addEventListener('DOMContentLoaded', loadData);
    </script>
</body>
</html>
