<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Requests\SubscriberRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

class SubscriberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        if ($request->filled('wa_group') && $request->wa_group !== 'all') {
            $query->where('wa_group', $request->wa_group);
        }
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }
        if ($request->filled('user_code') && $request->user_code !== 'all') {
            $query->where('user_code', 'like', '%' . $request->user_code . '%');
        }
        if ($request->filled('mobile')) {
            $query->where('mobile', 'like', '%' . $request->mobile . '%');
        }
        if ($request->filled('interest_sub') && $request->interest_sub !== 'all') {
            $query->where('interest_sub_cat', $request->interest_sub);
        }

        $perPage = (int) $request->get('per_page', 15);
        $items = $query->orderBy('join_date', 'desc')->paginate($perPage);

        return response()->json($items);
    }

    public function store(SubscriberRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (!isset($data['password'])) {
            $data['password'] = Hash::make('nearbypost_' . time());
        }
        if (!isset($data['join_date'])) {
            $data['join_date'] = now();
        }
        $item = User::create($data);
        return response()->json($item, 201);
    }

    public function show(int $id): JsonResponse
    {
        $item = User::findOrFail($id);
        return response()->json($item);
    }

    public function update(SubscriberRequest $request, int $id): JsonResponse
    {
        $item = User::findOrFail($id);
        $data = $request->validated();
        if (isset($data['password']) && $data['password']) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }
        $item->update($data);
        return response()->json($item);
    }

    public function destroy(int $id): JsonResponse
    {
        $item = User::findOrFail($id);
        $item->delete();
        return response()->json(['message' => 'Deleted'], 200);
    }
}
