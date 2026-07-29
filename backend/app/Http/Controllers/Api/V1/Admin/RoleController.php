<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function roles(): JsonResponse
    {
        return response()->json([
            'data' => Role::withCount('users')->with('permissions:id,slug,name,module')->get(),
        ]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json([
            'data' => Permission::orderBy('module')->get()->groupBy('module'),
        ]);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $query = \App\Models\AuditLog::with('actor:id,uuid,name,email')->latest();

        if ($action = $request->query('action')) {
            $query->where('action', 'like', $action . '%');
        }

        return response()->json($query->paginate(30));
    }

    public function loginHistories(Request $request): JsonResponse
    {
        $query = LoginHistory::with('user:id,uuid,name,email')->latest('logged_in_at');

        if ($q = $request->query('q')) {
            $query->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%")
                ->orWhere('name', 'like', "%{$q}%"));
        }

        return response()->json($query->paginate(30));
    }
}
