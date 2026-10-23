<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\InviteCode;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Console\Command;

class ClearUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clear:user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清理用户';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $builder = User::where('plan_id', NULL)
            ->where('transfer_enable', 0)
            ->where('expired_at', 0)
            ->where('last_login_at', NULL);

        $count = 0;
        DB::transaction(function () use ($builder, &$count) {
            $ids = (clone $builder)->lockForUpdate()->pluck('id')->all();
            $count = count($ids);
            if ($count === 0) {
                return;
            }

            // Do not leave usable orphan invite codes when bulk-deleting inactive users.
            InviteCode::whereIn('user_id', $ids)->delete();
            User::whereIn('id', $ids)->delete();
        });

        if ($count > 0) {
            $this->info("已删除{$count}位没有任何数据的用户");
        }
    }
}
