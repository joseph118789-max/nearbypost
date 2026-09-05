<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        // The admin opens on the Resource centre (owner, 4 Sep 2026). The old
        // dashboard keeps its Subscribers and Intel tabs, reached by ?tab=.
        // Subscribers and Intel Analytics were removed too (owner, 4 Sep): "the panel displays only the Resource centre".
        return redirect()->route('admin.brain.index');
    }
}
