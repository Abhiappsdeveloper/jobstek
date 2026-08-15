<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MonitorAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if user is authenticated in session
        if (session('monitor_authenticated')) {
            return $next($request);
        }

        // If trying to login
        if ($request->isMethod('post') && $request->path() === 'monitor/login') {
            $password = $request->input('password', '');

            // Change this password in production!
            if ($password === env('MONITOR_PASSWORD', 'monitor@2026')) {
                session(['monitor_authenticated' => true]);
                return redirect()->route('resume.monitor');
            }

            return back()->withErrors(['password' => 'Invalid password']);
        }

        // Not authenticated, show login
        if ($request->path() === 'monitor') {
            return view('resume-monitor.login');
        }

        return redirect()->route('monitor.login.show');
    }
}
