<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume Downloader - Monitor Dashboard</title>
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
            max-width: 1400px;
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
        }

        header h1 {
            color: #667eea;
            font-size: 28px;
        }

        header .time {
            color: #666;
            font-size: 14px;
        }

        .logout {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
        }

        .logout:hover {
            background: #764ba2;
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

        .card-status {
            font-size: 12px;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
            margin-top: 10px;
        }

        .status-healthy {
            background: #d4edda;
            color: #155724;
        }

        .status-warning {
            background: #fff3cd;
            color: #856404;
        }

        .status-danger {
            background: #f8d7da;
            color: #721c24;
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

        .badge-warning {
            background: #ffc107;
            color: black;
        }

        .badge-danger {
            background: #dc3545;
            color: white;
        }

        .wide-card {
            grid-column: 1 / -1;
        }

        .progress-bar {
            background: #e9ecef;
            border-radius: 5px;
            height: 30px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            background: linear-gradient(90deg, #667eea, #764ba2);
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 12px;
        }

        .error-list {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            max-height: 200px;
            overflow-y: auto;
            font-size: 12px;
        }

        .error-item {
            color: #dc3545;
            padding: 5px;
            border-bottom: 1px solid #dee2e6;
            font-family: monospace;
        }

        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 15px;
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

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .refresh-icon {
            display: inline-block;
            animation: spin 2s linear infinite;
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

            header {
                flex-direction: column;
                gap: 15px;
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
                <h1>📊 Resume Downloader Monitor</h1>
                <div class="time">Last updated: <span id="update-time">{{ now()->format('Y-m-d H:i:s') }}</span></div>
            </div>
            <div>
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

        <!-- MAIN GRID -->
        <div class="grid">
            <!-- CRON STATUS -->
            <div class="card">
                <h3>🔄 Cron Status</h3>
                <div class="card-value">
                    @if ($cronHealthy)
                        <span class="badge badge-success">✓ HEALTHY</span>
                    @else
                        <span class="badge badge-danger">✗ FAILED</span>
                    @endif
                </div>
                <div class="stat-row">
                    <span class="stat-label">Heartbeat Age:</span>
                    <span class="stat-value">{{ $heartbeatAge }}</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Last Run:</span>
                    <span class="stat-value">{{ $lastHeartbeat }}</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Frequency:</span>
                    <span class="stat-value">Every 1 minute</span>
                </div>
            </div>

            <!-- DOWNLOAD PROGRESS -->
            <div class="card">
                <h3>📥 Downloads</h3>
                <div class="card-value">{{ $totalDownloaded }}</div>
                <p style="color: #666; font-size: 12px;">resumes downloaded</p>
                <div class="stat-row">
                    <span class="stat-label">Total Size:</span>
                    <span class="stat-value">{{ $totalSize }}</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Download Rate:</span>
                    <span class="stat-value">~2.6/min</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: {{ min(($totalDownloaded / 5000) * 100, 100) }}%;">
                        {{ min(($totalDownloaded / 5000) * 100, 100) }}%
                    </div>
                </div>
            </div>

            <!-- PENDING DOWNLOADS -->
            <div class="card">
                <h3>⏳ Pending</h3>
                <div class="card-value">{{ $pending }}</div>
                <p style="color: #666; font-size: 12px;">waiting to download</p>
                <div class="stat-row">
                    <span class="stat-label">Est. Time:</span>
                    <span class="stat-value">~{{ $estimatedHours }}h {{ $estimatedMins }}m</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Fetched IDs:</span>
                    <span class="stat-value">{{ $totalFetched }}</span>
                </div>
            </div>

            <!-- PAGES FETCHED -->
            <div class="card">
                <h3>📄 Pages Fetched</h3>
                <div class="card-value">{{ $pagesFetched }}</div>
                <p style="color: #666; font-size: 12px;">pages scanned</p>
                <div class="stat-row">
                    <span class="stat-label">Fetch Rate:</span>
                    <span class="stat-value">~5.5/min</span>
                </div>
                <div class="stat-row">
                    <span class="stat-label">Est. Total:</span>
                    <span class="stat-value">1000-10000</span>
                </div>
            </div>

            <!-- S3 TRACKING -->
            <div class="card">
                <h3>☁️ S3 URLs</h3>
                <div class="card-value">{{ $s3Urls }}</div>
                <p style="color: #666; font-size: 12px;">urls captured</p>
                <div class="stat-row">
                    <span class="stat-label">Status:</span>
                    <span class="badge badge-success">Tracking Active</span>
                </div>
            </div>

            <!-- RUNNING PROCESSES -->
            <div class="card">
                <h3>⚙️ Processes</h3>
                <div class="card-value">{{ $runningProcesses }}</div>
                <p style="color: #666; font-size: 12px;">python processes active</p>
                <div class="stat-row">
                    <span class="stat-label">Status:</span>
                    <span class="badge {{ $runningProcesses > 0 ? 'badge-success' : 'badge-warning' }}">
                        {{ $runningProcesses > 0 ? 'Running' : 'Idle' }}
                    </span>
                </div>
            </div>

            <!-- ERROR TRACKING (WIDE) -->
            <div class="card wide-card">
                <h3>⚠️ Errors Today</h3>
                <div class="card-value">{{ $errorCount }}</div>

                @if ($errorCount > 0)
                    <div class="error-list">
                        @forelse ($recentErrors as $error)
                            <div class="error-item">{{ $error }}</div>
                        @empty
                            <div style="color: #999;">No detailed errors captured</div>
                        @endforelse
                    </div>
                @else
                    <p style="color: #28a745; padding: 10px; background: #d4edda; border-radius: 5px;">
                        ✓ No errors today
                    </p>
                @endif
            </div>

            <!-- SYSTEM PERFORMANCE (WIDE) -->
            <div class="card wide-card">
                <h3>🖥️ System Performance</h3>
                <table>
                    <tr>
                        <td><strong>Server Load:</strong></td>
                        <td>
                            {{ $load[0] }} (1min), {{ $load[1] }} (5min), {{ $load[2] }} (15min)
                            <span class="badge {{ $load[0] > 30 ? 'badge-danger' : ($load[0] > 20 ? 'badge-warning' : 'badge-success') }}">
                                {{ $load[0] > 30 ? 'HIGH' : ($load[0] > 20 ? 'MEDIUM' : 'NORMAL') }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Disk Usage:</strong></td>
                        <td>
                            {{ $diskPercent }}% used ({{ $diskFree }} free)
                            <span class="badge {{ $diskPercent > 85 ? 'badge-danger' : ($diskPercent > 70 ? 'badge-warning' : 'badge-success') }}">
                                {{ $diskPercent > 85 ? 'CRITICAL' : ($diskPercent > 70 ? 'WARN' : 'OK') }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Uptime:</strong></td>
                        <td>{{ $uptime }}</td>
                    </tr>
                </table>
            </div>

            <!-- ACTIONS (WIDE) -->
            <div class="card wide-card">
                <h3>⚡ Quick Actions</h3>
                <div class="button-group">
                    <button class="btn btn-primary" onclick="forceRun()">Force Run Script</button>
                    <a href="{{ route('home') }}" class="btn btn-secondary">View API Stats</a>
                    <button class="btn btn-secondary" onclick="location.reload()">Refresh</button>
                </div>
            </div>

            <!-- TIMELINE (WIDE) -->
            <div class="card wide-card">
                <h3>📅 Timeline & Estimates</h3>
                <table>
                    <tr>
                        <th>Metric</th>
                        <th>Value</th>
                        <th>Notes</th>
                    </tr>
                    <tr>
                        <td>Downloads Remaining</td>
                        <td><strong>{{ $pending }}</strong> resumes</td>
                        <td>At 2.6/min = ~{{ $estimatedHours }}h {{ $estimatedMins }}m</td>
                    </tr>
                    <tr>
                        <td>Total Downloaded</td>
                        <td><strong>{{ $totalDownloaded }}</strong> resumes</td>
                        <td>Size: {{ $totalSize }}</td>
                    </tr>
                    <tr>
                        <td>Pages Fetched</td>
                        <td><strong>{{ $pagesFetched }}</strong> pages</td>
                        <td>Estimated 1000-10000 total pages</td>
                    </tr>
                    <tr>
                        <td>Est. Completion</td>
                        <td><strong>2-4 days</strong></td>
                        <td>Running 24/7 continuously</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- FOOTER -->
        <footer>
            <p>Resume Downloader Monitor • Last synced: <span id="sync-time">just now</span> • Status: Monitoring Active</p>
        </footer>
    </div>

    <script>
        // Auto-refresh data every 30 seconds
        setInterval(function() {
            fetch('{{ route("api.live-data") }}')
                .then(r => r.json())
                .then(data => {
                    document.getElementById('update-time').textContent = data.timestamp;
                    document.getElementById('sync-time').textContent = 'just now';
                })
                .catch(e => console.log('Sync error:', e));
        }, 30000);

        function forceRun() {
            if (confirm('Run the downloader script now?')) {
                fetch('{{ route("api.force-run") }}', { method: 'POST' })
                    .then(r => r.json())
                    .then(data => {
                        alert('✓ Script started in background');
                        setTimeout(() => location.reload(), 2000);
                    })
                    .catch(e => alert('Error: ' + e));
            }
        }

        // Reload every 60 seconds
        setTimeout(() => location.reload(), 60000);
    </script>
</body>
</html>
