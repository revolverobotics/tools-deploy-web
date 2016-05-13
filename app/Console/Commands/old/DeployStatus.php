<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Console\Commands\Deploy;

class DeployStatus extends Command
{
    use Deploy;

    protected $signature = "deploy:status {environment=all : Can be 'local', 'dev', 'production', or 'all'.}";

    protected $description = 'Check & compare status of local, dev, & production code';

    public function fire()
    {
        $this->environmentCheckStatus($this->argument('environment'));
    }
}
