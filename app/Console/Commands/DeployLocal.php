<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Console\Commands\Deploy;

use SSH;

class DeployLocal extends Command
{
    use Deploy;

    protected $name = 'deploy:local';

    protected $description = 'Check status of local code, version tag, run unit tests, and push to GitHub';

    public function fire()
    {
        // $this->enumerateServices();

        // config(['remote' => [
        //     'connections' => [
        //         'test' => [
        //             'host'      => env('HOST_DEV_FRONTEND'),
        //             'username'  => env('HOST_USERNAME'),
        //             'password'  => '',
        //             'key'       => env('DEPLOY_KEY'),
        //             'keyphrase' => '',
        //             'root'      => env('PATH_FRONTEND'),
        //         ]
        //     ]
        // ]]);
        // print_r(config('remote'));
        // config('remote.connections', [
        //     'test' => [
        //         'host'      => env('HOST_DEV_FRONTEND'),
        //         'username'  => env('HOST_USERNAME'),
        //         'password'  => '',
        //         'key'       => env('DEPLOY_KEY'),
        //         'keyphrase' => '',
        //         'root'      => env('PATH_FRONTEND'),
        //     ]
        // ]);

        // SSH::into('test')->run(['cd ' . env('PATH_FRONTEND'), 'git --git-dir='.env('GIT_PATH_FRONTEND').' --work-tree=. status']);
    }
}
