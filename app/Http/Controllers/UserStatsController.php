<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UserStatsController extends Controller
{
    /**
     * List observation statistics across users with optional filtering and pagination.
     */
    public function index(Request $request)
    {
        try {
            $perPage = (int)$request->query('per_page', 15);

            $query = DB::table('access_userroleaccount as ura')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id');

            if ($request->filled('user__username')) {
                $query->where('u.username', $request->query('user__username'));
            }

            if ($request->filled('search')) {
                $searchTerm = '%' . $request->query('search') . '%';
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('u.username', 'like', $searchTerm)
                      ->orWhere('u.first_name', 'like', $searchTerm)
                      ->orWhere('u.last_name', 'like', $searchTerm)
                      ->orWhere('r.title', 'like', $searchTerm);
                });
            }

            $accounts = $query->select([
                    'ura.id as user_role_account_id',
                    'ura.account_id',
                    'u.id as user_id',
                    'u.username',
                    'u.email',
                    'u.first_name',
                    'u.last_name',
                    'u.is_active',
                    'u.last_login',
                    'p.id as profile_id',
                    'p.created as profile_created',
                    'p.updated as profile_updated',
                    'p.designation',
                    'p.avatar_id',
                    'p.phone',
                    'p.dob',
                    'r.id as role_id',
                    'r.title as role_title',
                    'r.rank as role_rank',
                ])
                ->paginate($perPage);

            $uraIds = collect($accounts->items())->pluck('user_role_account_id')->toArray();

            $groupsMap = [];
            $statsMap = [];

            if (!empty($uraIds)) {
                $groupsMap = DB::table('access_group_users')
                    ->whereIn('userroleaccount_id', $uraIds)
                    ->select('userroleaccount_id', 'group_id')
                    ->get()
                    ->groupBy('userroleaccount_id')
                    ->map(fn ($items) => $items->pluck('group_id')->toArray())
                    ->toArray();

                $statsMap = $this->getBatchStats($uraIds);
            }

            $results = collect($accounts->items())->map(function ($item) use ($groupsMap, $statsMap) {
                $uraId = $item->user_role_account_id;
                $groups = $groupsMap[$uraId] ?? [];
                $stats = $statsMap[$uraId] ?? $this->defaultStats();

                return array_merge(
                    $this->formatUserRoleAccount($item, $groups),
                    $stats
                );
            })->toArray();

            return response()->json([
                'count'    => $accounts->total(),
                'next'     => $accounts->nextPageUrl(),
                'previous' => $accounts->previousPageUrl(),
                'results'  => $results,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve user statistics.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retrieve observation statistics for a single user by UserRoleAccount UUID or User ID.
     */
    public function show(int|string $id)
    {
        try {
            $query = DB::table('access_userroleaccount as ura')
                ->join('auth_user as u', 'ura.user_id', '=', 'u.id')
                ->leftJoin('common_profile as p', 'p.user_id', '=', 'u.id')
                ->join('access_role as r', 'ura.role_id', '=', 'r.id')
                ->select([
                    'ura.id as user_role_account_id',
                    'ura.account_id',
                    'u.id as user_id',
                    'u.username',
                    'u.email',
                    'u.first_name',
                    'u.last_name',
                    'u.is_active',
                    'u.last_login',
                    'p.id as profile_id',
                    'p.created as profile_created',
                    'p.updated as profile_updated',
                    'p.designation',
                    'p.avatar_id',
                    'p.phone',
                    'p.dob',
                    'r.id as role_id',
                    'r.title as role_title',
                    'r.rank as role_rank',
                ]);

            if (is_numeric($id)) {
                $query->where('u.id', (int)$id);
            } else {
                $query->where('ura.id', $id);
            }

            $account = $query->first();

            if (!$account) {
                return response()->json([
                    'detail' => 'Not found.'
                ], 404);
            }

            $uraId = $account->user_role_account_id;

            $groups = DB::table('access_group_users')
                ->where('userroleaccount_id', $uraId)
                ->pluck('group_id')
                ->toArray();

            $stats = $this->getUserStats($uraId);

            $data = array_merge(
                $this->formatUserRoleAccount($account, $groups),
                $stats
            );

            return response()->json($data, 200);

        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to retrieve user statistics.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Calculate observation and learning counts for a given user account.
     */
    private function getUserStats(string $uraId): array
    {
        $obsCounts = DB::table('observations_observation')
            ->where('is_published', true)
            ->where(function ($q) use ($uraId) {
                $q->where('observer_id', $uraId)
                  ->orWhere('observee_id', $uraId);
            })
            ->selectRaw("
                COUNT(CASE WHEN observer_id = ? THEN 1 END) as observation_count_by_user,
                COUNT(CASE WHEN observee_id = ? THEN 1 END) as observation_count_for_user,
                COUNT(*) as total_observation_count
            ", [$uraId, $uraId])
            ->first();

        $learningCounts = DB::table('observations_evidence as e')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->where('e.is_observers_learning', true)
            ->where('o.is_published', true)
            ->where(function ($q) use ($uraId) {
                $q->where('o.observer_id', $uraId)
                  ->orWhere('o.observee_id', $uraId);
            })
            ->selectRaw("
                COUNT(CASE WHEN o.observer_id = ? THEN 1 END) as observer_learning_count,
                COUNT(CASE WHEN o.observee_id = ? THEN 1 END) as observee_learning_count,
                COUNT(*) as total_learning_count
            ", [$uraId, $uraId])
            ->first();

        return [
            'observation_count_by_user'  => (int)($obsCounts->observation_count_by_user ?? 0),
            'observation_count_for_user' => (int)($obsCounts->observation_count_for_user ?? 0),
            'total_observation_count'    => (int)($obsCounts->total_observation_count ?? 0),
            'observer_learning_count'    => (int)($learningCounts->observer_learning_count ?? 0),
            'observee_learning_count'    => (int)($learningCounts->observee_learning_count ?? 0),
            'total_learning_count'       => (int)($learningCounts->total_learning_count ?? 0),
        ];
    }

    /**
     * Batch calculate statistics for a collection of user account IDs.
     */
    private function getBatchStats(array $uraIds): array
    {
        $stats = [];
        foreach ($uraIds as $id) {
            $stats[$id] = $this->defaultStats();
        }

        $obsByObserver = DB::table('observations_observation')
            ->whereIn('observer_id', $uraIds)
            ->where('is_published', true)
            ->select('observer_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('observer_id')
            ->pluck('cnt', 'observer_id');

        $obsByObservee = DB::table('observations_observation')
            ->whereIn('observee_id', $uraIds)
            ->where('is_published', true)
            ->select('observee_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('observee_id')
            ->pluck('cnt', 'observee_id');

        $totalObs = DB::table('observations_observation')
            ->where('is_published', true)
            ->where(function ($q) use ($uraIds) {
                $q->whereIn('observer_id', $uraIds)
                  ->orWhereIn('observee_id', $uraIds);
            })
            ->select('id', 'observer_id', 'observee_id')
            ->get();

        foreach ($totalObs as $obs) {
            if (isset($stats[$obs->observer_id])) {
                $stats[$obs->observer_id]['total_observation_count']++;
            }
            if ($obs->observee_id !== $obs->observer_id && isset($stats[$obs->observee_id])) {
                $stats[$obs->observee_id]['total_observation_count']++;
            }
        }

        foreach ($obsByObserver as $id => $cnt) {
            if (isset($stats[$id])) {
                $stats[$id]['observation_count_by_user'] = (int)$cnt;
            }
        }

        foreach ($obsByObservee as $id => $cnt) {
            if (isset($stats[$id])) {
                $stats[$id]['observation_count_for_user'] = (int)$cnt;
            }
        }

        $evidences = DB::table('observations_evidence as e')
            ->join('observations_observation as o', 'e.observation_id', '=', 'o.id')
            ->where('e.is_observers_learning', true)
            ->where('o.is_published', true)
            ->where(function ($q) use ($uraIds) {
                $q->whereIn('o.observer_id', $uraIds)
                  ->orWhereIn('o.observee_id', $uraIds);
            })
            ->select('e.id', 'o.observer_id', 'o.observee_id')
            ->get();

        foreach ($evidences as $ev) {
            $hasObserver = isset($stats[$ev->observer_id]);
            $hasObservee = isset($stats[$ev->observee_id]);

            if ($hasObserver) {
                $stats[$ev->observer_id]['observer_learning_count']++;
                $stats[$ev->observer_id]['total_learning_count']++;
            }
            if ($hasObservee && $ev->observee_id !== $ev->observer_id) {
                $stats[$ev->observee_id]['observee_learning_count']++;
                $stats[$ev->observee_id]['total_learning_count']++;
            } elseif ($hasObservee && $ev->observee_id === $ev->observer_id) {
                $stats[$ev->observee_id]['observee_learning_count']++;
            }
        }

        return $stats;
    }

    /**
     * Format user account profile details into standard API response shape.
     */
    private function formatUserRoleAccount(object $item, array $groups): array
    {
        return [
            'id' => $item->user_role_account_id,
            'profile' => [
                'id' => $item->profile_id,
                'avatar' => $item->avatar_id,
                'user' => [
                    'username' => $item->username,
                    'first_name' => $item->first_name,
                    'last_name' => $item->last_name,
                    'full_name' => trim($item->first_name . ' ' . $item->last_name),
                    'email' => $item->email,
                    'last_login' => $item->last_login ? Carbon::parse($item->last_login)->toJSON() : null,
                ],
                'created' => $item->profile_created ? Carbon::parse($item->profile_created)->toJSON() : null,
                'updated' => $item->profile_updated ? Carbon::parse($item->profile_updated)->toJSON() : null,
                'phone' => $item->phone,
                'designation' => $item->designation,
                'dob' => $item->dob ?: null,
            ],
            'groups' => $groups,
            'role_title' => $item->role_title,
            'role_rank' => (int)$item->role_rank,
            'role_id' => $item->role_id,
            'account' => $item->account_id,
        ];
    }

    /**
     * Default zeroed stats payload.
     */
    private function defaultStats(): array
    {
        return [
            'observation_count_by_user'  => 0,
            'observation_count_for_user' => 0,
            'total_observation_count'    => 0,
            'observer_learning_count'    => 0,
            'observee_learning_count'    => 0,
            'total_learning_count'       => 0,
        ];
    }
}
