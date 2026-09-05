@extends('layouts.admin')
@section('title', 'Admin')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .admins table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e2edf6; border-radius:14px; overflow:hidden; font-size:0.88rem; }
  .admins th, .admins td { padding:10px 12px; text-align:left; border-bottom:1px solid #eef3f7; }
  .admins th { background:#f6f9fc; color:#5f7f9a; font-weight:600; font-size:0.74rem; text-transform:uppercase; letter-spacing:0.05em; }
  .admins .card { background:#fff; border:1px solid #e2edf6; border-radius:14px; padding:16px 18px; margin-top:18px; max-width:520px; }
  .admins .card label { display:block; font-size:0.8rem; color:#5f7f9a; margin:10px 0 4px; }
  .admins .card input { width:100%; padding:8px 12px; border:1px solid #cfe0ec; border-radius:10px; font:inherit; box-sizing:border-box; }
  .admins button { font:inherit; font-size:0.82rem; padding:6px 14px; border-radius:999px; border:1px solid #cfe0ec; background:#fff; color:#1c5a7f; cursor:pointer; }
  .admins button.primary { background:#1c5a7f; border-color:#1c5a7f; color:#fff; margin-top:12px; }
  .admins form.inline { display:inline; }
  .flash { background:#e0f5e9; color:#1f7840; padding:10px 16px; border-radius:12px; margin-bottom:14px; font-size:0.88rem; }
  .errors { background:#fde8e6; color:#9b2c1f; padding:10px 16px; border-radius:12px; margin-bottom:14px; font-size:0.88rem; }
</style>
@endpush
@section('content')
<div class="srcpage admins">
  @include('admin.brain._nav')
  <h1>Admin</h1>
  <p class="lede">The people who can sign in here. Each has the whole desk. You cannot remove your own access, and the last admin cannot be removed.</p>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="errors">{{ $errors->first() }}</div>@endif
  <table>
    <thead><tr><th>Name</th><th>Email</th><th>Since</th><th></th></tr></thead>
    <tbody>
    @foreach($admins as $a)
      <tr>
        <td><b>{{ $a->name }}</b>@if($a->id === $me) <span style="color:#5f7f9a;font-size:0.8rem">(you)</span>@endif</td>
        <td>{{ $a->email }}</td>
        <td>{{ $a->created_at?->format('j M Y') }}</td>
        <td style="text-align:right">
          @if($a->id !== $me)
            <form method="post" action="{{ route('admin.admins.destroy', ['id' => $a->id]) }}" class="inline" onsubmit="return confirm('Remove {{ $a->name }}\'s access?')">@csrf @method('DELETE')<button type="submit">Remove</button></form>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <div class="card">
    <b>Add an admin</b>
    <form method="post" action="{{ route('admin.admins.store') }}">
      @csrf
      <label for="a-name">Name</label><input id="a-name" name="name" required minlength="2" maxlength="100" value="{{ old('name') }}">
      <label for="a-email">Email (their sign-in)</label><input id="a-email" type="email" name="email" required value="{{ old('email') }}">
      <label for="a-pass">Password (at least 8 characters; they should change it after first sign-in)</label><input id="a-pass" type="password" name="password" required minlength="8" autocomplete="new-password">
      <label for="a-pass2">Password again</label><input id="a-pass2" type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
      <button type="submit" class="primary">Add admin</button>
    </form>
  </div>
</div>
@endsection
