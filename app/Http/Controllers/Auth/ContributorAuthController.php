<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FeedQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Signing in, for the two kinds of people who sign in here.
 *
 * They have nothing to do with each other. An administrator runs the site; a
 * contributor writes for it. They authenticate against different tables through
 * different guards, and neither can reach the other's pages. The one shared
 * thing is this door, because a reader who clicks "Login" should not have to
 * already know which of the two they are.
 */
class ContributorAuthController extends Controller
{
    public function __construct(private FeedQuery $feed)
    {
    }

    /** The two doors, named plainly. */
    public function choose(): View
    {
        return view('pages.auth.choose', $this->chrome(__('site.login')));
    }

    public function showLogin(): View
    {
        return view('pages.auth.login', $this->chrome(__('site.contributor_login')));
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        // Throttled per address and per email, so neither a single machine nor
        // a spread of them can grind through passwords for one account.
        $key = 'login:' . sha1(mb_strtolower($credentials['email']) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages([
                'email' => __('site.login_throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (!Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 600);

            // One message for both a wrong password and an unknown address, so
            // the form cannot be used to find out who has an account.
            throw ValidationException::withMessages(['email' => __('site.login_failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('contribute.index'));
    }

    public function showRegister(): View
    {
        return view('pages.auth.register', $this->chrome(__('site.contributor_register')));
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'min:2', 'max:120'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => mb_strtolower($data['email']),
            'password'  => Hash::make($data['password']),
            'status'    => 'active',
            'join_date' => now(),
        ]);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('contribute.create')->with('status', __('site.welcome_contributor'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    /**
     * The furniture every public page needs, so these sit inside the site
     * rather than looking like a different application.
     */
    private function chrome(string $title): array
    {
        return [
            'tab'        => null,
            'pageTitle'  => $title,
            'categories' => $this->feed->categories(),
        ];
    }
}
