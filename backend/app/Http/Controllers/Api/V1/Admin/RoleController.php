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

        $logs = $query->paginate(30);

        // Resolve "to whom" — subject names for user-targeted entries.
        $userIds = $logs->getCollection()
            ->filter(fn ($log) => $log->subject_type === \App\Models\User::class && $log->subject_id)
            ->pluck('subject_id')->unique();
        $names = \App\Models\User::whereIn('id', $userIds)->pluck('name', 'id');

        $logs->getCollection()->transform(function ($log) use ($names) {
            $log->setAttribute(
                'subject_name',
                $log->subject_type === \App\Models\User::class ? ($names[$log->subject_id] ?? null) : null,
            );

            return $log;
        });

        return response()->json($logs);
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
