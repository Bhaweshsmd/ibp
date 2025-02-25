<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use App\Models\MaintenanceSetting;
use Carbon\Carbon;

class CheckAdmin
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'nocache, no-store, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Sun, 02 Jan 2021 00:00:00 GMT');


      if (Session::get('user_name')) {
            return $response;
        } else {
            return redirect(url('/'));
        }
    }
}
