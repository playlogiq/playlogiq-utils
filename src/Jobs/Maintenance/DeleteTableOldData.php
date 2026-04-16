<?php

namespace PlaylogiqUtils\Jobs\Maintenance;

use PlaylogiqUtils\Jobs\BaseJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class DeleteTableOldData  extends BaseJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INTERNAL_JOB_ID = 86;

    public $tries = 1;
    public $uniqueFor = 60 * 60 * 23; // Almost one day
    protected $table;
    protected $days_max_age;
    protected $creationTimeColumn;
    protected $creationTimeColumnFormat;

    public function __construct($table, $days_max_age, $creationTimeColumn = "addedTime", $creationTimeColumnFormat = "UNIX") {

        $this->table = $table;
        $this->days_max_age = $days_max_age;
        $this->creationTimeColumn = $creationTimeColumn;
        $this->creationTimeColumnFormat = $creationTimeColumnFormat;

    }

    public function handle() {

        if ( empty($this->table) )
            return true;

        if($this->creationTimeColumnFormat == "UNIX"){
            $age_limit = strtotime('-' . $this->days_max_age . ' days');
        }else{
            $age_limit = Carbon::now()->subDays($this->days_max_age);
        }

        $query = DB::table($this->table);

        switch ($this->table) {
            case 'users_tokens':
                $query->where("revoked", 1)->where('expiration_time', '<', now()->subMonths(1));
                break;

            default:
                $query->where($this->creationTimeColumn, '<=', $age_limit);
                break;
        }

        $batch_size = 5000;
        // $max_loops = 1000;
        $curr_loop = 0;

        do {

            \Log::info('loop ' . $curr_loop . ' DeleteTableOldData query: ' . get_query_from_builder($query->limit($batch_size)));
            $res = $query->limit($batch_size)->delete();
            $curr_loop++;

        } while ($res > 0 /*&& $curr_loop <= $max_loops*/);
    }

    public function uniqueId()
    {
        return 'dtod_' . $this->table;
    }
}
