{{-- resources/views/admin/auth/login.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>Admin Login - ProHub</title>
  <link rel="stylesheet" href="{{ asset('css/app.css') }}">
  <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: linear-gradient(135deg, #1c5a7f 0%, #2c8cb0 50%, #764ba2 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: Inter, system-ui, sans-serif; padding: 20px; }
    .login-container { width: 100%; max-width: 420px; }
    .login-card { background: white; border-radius: 28px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); padding: 40px; }
    .login-card h1 { text-align: center; margin-bottom: 8px; color: #1c5a7f; font-size: 1.8rem; }
    .login-card .subtitle { text-align: center; color: #6b8498; margin-bottom: 32px; font-size: 0.9rem; }
    .form-group { margin-bottom: 20px; }
    .form-group label { display: block; margin-bottom: 8px; color: #1d4e6e; font-weight: 600; font-size: 0.85rem; }
    .form-group input { width: 100%; padding: 12px 18px; border: 1px solid #cfdfed; border-radius: 28px; font-size: 0.95rem; transition: border-color 0.2s; }
    .form-group input:focus { outline: none; border-color: #1c5a7f; }
    .remember-row { display: flex; align-items: center; gap: 8px; margin-bottom: 24px; }
    .remember-row input { width: auto; }
    .remember-row label { color: #5f7f9a; font-size: 0.85rem; margin: 0; }
    .btn-login { width: 100%; padding: 14px; background: linear-gradient(135deg, #1c5a7f, #2c8cb0); color: white; border: none; border-radius: 28px; font-size: 1rem; font-weight: 600; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; }
    .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(28,90,127,0.3); }
    .error-message { background: #fff3f0; color: #bc4e2c; padding: 12px 18px; border-radius: 16px; margin-bottom: 20px; font-size: 0.85rem; border: 1px solid #f0cfc0; }
    .logo-row { text-align: center; margin-bottom: 24px; }
    .logo-row span { font-size: 2.5rem; }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="logo-row"><span>📰</span></div>
      <h1>ProHub Admin</h1>
      <p class="subtitle">Sign in to your admin account</p>

      @if ($errors->any())
        <div class="error-message">{{ $errors->first() }}</div>
      @endif

      <form method="POST" action="{{ route('admin.login') }}">
        @csrf
        <div class="form-group">
          <label for="email">Email Address</label>
          <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus placeholder="admin@nearbypost.com">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required placeholder="••••••••">
        </div>
        <div class="remember-row">
          <input type="checkbox" id="remember" name="remember">
          <label for="remember">Remember me</label>
        </div>
        <button type="submit" class="btn-login">Sign In</button>
      </form>
    </div>
  </div>
</body>
</html>
