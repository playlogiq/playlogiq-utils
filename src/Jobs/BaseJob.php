<?php

namespace PlaylogiqUtils\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class BaseJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $timeout = 10800;
    public $progress;
    public $should_be_killed = false;
    public $current;

    public function setProperty($key, $value) {

        // \Log::info(get_class($this) . ' set property: ' . $key . ': ' . $value);

        if ( env('QUEUE_CONNECTION', 'database') == 'redis' )
            return;

        $this->{$key} = $value;

        $payload = json_decode(DB::table('jobs')->where('id', $this->job->getJobId())->value('payload'), true);
        $command = unserialize($payload['data']['command']);
        $command->{$key} = $value;
        $payload['data']['command'] = serialize($command);
        DB::table('jobs')->where('id', $this->job->getJobId())->update(['payload' => json_encode($payload)]);

        return true;
    }

    public function getProperty($key = null, $default_value = null) {

        if ( env('QUEUE_CONNECTION', 'database') == 'redis' )
            return;

        $payload = json_decode(DB::table('jobs')->where('id', $this->job->getJobId())->value('payload'), true);
        $command = unserialize($payload['data']['command']);

        if ( !empty($key) && property_exists($command, $key) )
            return $command->{$key};

        if ( !empty($key) )
            return $default_value;

        return json_encode($command);
    }

    public function kill() {

        if ( env('QUEUE_CONNECTION', 'database') == 'redis' )
            return;

        $payload = json_decode(DB::table('jobs')->where('id', $this->job->getJobId())->value('payload'), true);
        $command = unserialize($payload['data']['command']);

        // $command = Arr::except($command, ['job', 'connection', 'queue', 'chainConnection', 'chainQueue', 'chainCatchCallbacks', 'delay', 'afterCommit', 'middleware', 'chained']);

        unset($command->job);
        unset($command->connection);
        unset($command->queue);
        unset($command->chainConnection);
        unset($command->chainQueue);
        unset($command->chainCatchCallbacks);
        unset($command->delay);
        unset($command->afterCommit);
        unset($command->middleware);
        unset($command->chained);

        throw new \Exception("Manually Killed Job: " . json_encode($command), 1);
    }
}
