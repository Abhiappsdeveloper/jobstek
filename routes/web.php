<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ResumeMonitorController;
use App\Http\Controllers\HistoricalDataController;
use App\Http\Controllers\StatusCheckerController;

Route::get('/', function () {
    return view('welcome');
});

// Resume Monitor Routes
Route::get('/monitor', [ResumeMonitorController::class, 'login'])->name('monitor.login.show');
Route::post('/monitor', [ResumeMonitorController::class, 'login'])->name('monitor.login');
Route::get('/monitor/dashboard', [ResumeMonitorController::class, 'dashboard'])->name('resume.monitor');
Route::get('/monitor/historical', function() {
    if (!session('monitor_authenticated')) {
        return redirect()->route('monitor.login.show');
    }
    return view('resume-monitor.dashboard-historical');
})->name('monitor.historical');
Route::post('/monitor/logout', [ResumeMonitorController::class, 'logout'])->name('monitor.logout');
Route::post('/api/force-run', [ResumeMonitorController::class, 'forceRun'])->name('api.force-run');
Route::get('/api/live-data', [ResumeMonitorController::class, 'getLiveData'])->name('api.live-data');
Route::get('/api/downtime-list', [ResumeMonitorController::class, 'getDowntimeList'])->name('api.downtime-list');
Route::get('/api/get-error-count', [ResumeMonitorController::class, 'getErrorCount'])->name('api.get-error-count');
Route::get('/api/historical-data', [HistoricalDataController::class, 'getAllHistoricalData'])->name('api.historical-data');
Route::get('/api/heartbeat-data', [HistoricalDataController::class, 'getHeartbeatData'])->name('api.heartbeat-data');
