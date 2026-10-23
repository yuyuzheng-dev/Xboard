<?php

namespace App\Http\Controllers\V1\User;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ComissionLogResource;
use App\Http\Resources\InviteCodeResource;
use App\Models\CommissionLog;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Utils\Helper;
use Illuminate\Http\Request;

class InviteController extends Controller
{
    public function save(Request $request)
    {
        $userId = (int) $request->user()->id;

        // Serialize invite generation per user so parallel requests cannot bypass the configured limit.
        $created = DB::transaction(function () use ($userId): bool {
            $user = User::whereKey($userId)->lockForUpdate()->first();
            if (!$user) {
                throw new ApiException(__('The user does not exist'));
            }

            $limit = (int) admin_setting('invite_gen_limit', 5);
            if (InviteCode::where('user_id', $userId)->where('status', InviteCode::STATUS_UNUSED)->count() >= $limit) {
                return false;
            }

            // The database unique index is the final concurrency guard. insertOrIgnore lets a collision
            // retry safely on MySQL, PostgreSQL, SQLite and SQL Server without aborting the transaction.
            for ($attempt = 0; $attempt < 16; $attempt++) {
                $code = Helper::randomChar(8);
                $inserted = DB::table('v2_invite_code')->insertOrIgnore([
                    'user_id' => $userId,
                    'code' => $code,
                    'status' => InviteCode::STATUS_UNUSED,
                    'pv' => 0,
                    'created_at' => time(),
                    'updated_at' => time(),
                ]);
                if ($inserted > 0) {
                    return true;
                }
            }

            throw new ApiException(__('Invite code generation failed, please try again'));
        });

        if (!$created) {
            return $this->fail([400, __('The maximum number of creations has been reached')]);
        }
        return $this->success(true);
    }

    public function details(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        $builder = CommissionLog::where('invite_user_id', $request->user()->id)
            ->where('get_amount', '>', 0)
            ->orderBy('created_at', 'DESC');
        $total = $builder->count();
        $details = $builder->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => ComissionLogResource::collection($details),
            'total' => $total
        ]);
    }

    public function fetch(Request $request)
    {
        $commission_rate = admin_setting('invite_commission', 10);
        $user = User::find($request->user()->id)
                ->load(['codes' => fn($query) => $query->where('status', 0)]);
        if ($user->commission_rate) {
            $commission_rate = $user->commission_rate;
        }
        $uncheck_commission_balance = (int)Order::where('status', 3)
            ->where('commission_status', 0)
            ->where('invite_user_id', $user->id)
            ->sum('commission_balance');
        if (admin_setting('commission_distribution_enable', 0)) {
            $uncheck_commission_balance = $uncheck_commission_balance * (admin_setting('commission_distribution_l1') / 100);
        }
        $stat = [
            //已注册用户数
            (int)User::where('invite_user_id', $user->id)->count(),
            //有效的佣金
            (int)CommissionLog::where('invite_user_id', $user->id)
                ->sum('get_amount'),
            //确认中的佣金
            $uncheck_commission_balance,
            //佣金比例
            (int)$commission_rate,
            //可用佣金
            (int)$user->commission_balance
        ];
        $data = [
            'codes' => InviteCodeResource::collection($user->codes),
            'stat' => $stat
        ];
        return $this->success($data);
    }
}
