<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/** Who can sign in here (owner, 4 Sep 2026). An admin cannot remove themself. */
class AdminsController extends Controller
{
    public function index(): View
    {
        return view('admin.admins', ['admins' => Admin::query()->orderBy('id')->get(), 'me' => Auth::guard('admin')->id()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:100'],
            'email'    => ['required', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        Admin::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);

        return back()->with('status', $data['name'] . ' can sign in now.');
    }

    public function destroy(int $id): RedirectResponse
    {
        if ($id === (int) Auth::guard('admin')->id()) {
            return back()->with('status', 'You cannot remove your own access.');
        }

        if (Admin::query()->count() <= 1) {
            return back()->with('status', 'The last admin stays.');
        }

        Admin::query()->where('id', $id)->delete();

        return back()->with('status', 'Removed.');
    }
}
