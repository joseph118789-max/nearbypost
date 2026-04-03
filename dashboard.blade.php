{{-- resources/views/admin/dashboard.blade.php --}}
@extends('layouts.admin')

@section('title', 'Admin Dashboard')

@section('sidebar')
    <div class="admin-logo">
        <h2>NearbyPost</h2>
    </div>
    <nav class="admin-nav">
        <a href="{{ route('admin.dashboard') }}" class="active">Dashboard</a>
        <a href="#">Users</a>
        <a href="#">Posts</a>
        <a href="#">Settings</a>
    </nav>
@endsection

@section('header')
    <div class="header-content">
        <h1>Dashboard</h1>
        <div class="user-info">
            <span>Welcome, {{ Auth::guard('admin')->user()->name }}</span>
            <form method="POST" action="{{ route('admin.logout') }}" style="display: inline;">
                @csrf
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>
    </div>
@endsection

@section('content')
    <div class="dashboard-cards">
        <div class="card">
            <h3>Total Users</h3>
            <p class="card-number">0</p>
        </div>
        <div class="card">
            <h3>Total Posts</h3>
            <p class="card-number">0</p>
        </div>
        <div class="card">
            <h3>Active Today</h3>
            <p class="card-number">0</p>
        </div>
    </div>
@endsection

@section('footer')
    <p>&copy; {{ date('Y') }} NearbyPost Admin. All rights reserved.</p>
@endsection

@push('styles')
<style>
    .admin-wrapper {
        display: flex;
        min-height: 100vh;
    }
    
    .admin-sidebar {
        width: 250px;
        background: #2c3e50;
        color: white;
        padding: 20px;
    }
    
    .admin-logo h2 {
        margin: 0 0 30px 0;
        color: #667eea;
    }
    
    .admin-nav a {
        display: block;
        color: white;
        padding: 12px 15px;
        text-decoration: none;
        border-radius: 6px;
        margin-bottom: 5px;
    }
    
    .admin-nav a:hover,
    .admin-nav a.active {
        background: #667eea;
    }
    
    .admin-main {
        flex: 1;
        display: flex;
        flex-direction: column;
    }
    
    .admin-header {
        background: white;
        padding: 20px 30px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .header-content h1 {
        margin: 0;
        font-size: 24px;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .btn-logout {
        padding: 8px 16px;
        background: #e74c3c;
        color: white;
        border: none;
        border-radius: 6px;
        cursor: pointer;
    }
    
    .admin-content {
        flex: 1;
        padding: 30px;
        background: #f5f5f5;
    }
    
    .dashboard-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .card {
        background: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .card h3 {
        margin: 0 0 10px 0;
        color: #666;
        font-size: 14px;
        text-transform: uppercase;
    }
    
    .card-number {
        font-size: 36px;
        font-weight: bold;
        color: #667eea;
        margin: 0;
    }
    
    .admin-footer {
        background: white;
        padding: 20px;
        text-align: center;
        border-top: 1px solid #eee;
    }
</style>
@endpush
