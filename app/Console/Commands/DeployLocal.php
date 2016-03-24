<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Console\Commands\Deploy;

use SSH;

class DeployLocal extends Command
{
    use Deploy;

    protected $name = 'deploy:local';

    protected $description = '';

    public function fire()
    {
        // 1. Increment version tag
        // 2. Verify .env & .env.testing files
        // 3. Run unit tests
        // 4. Push to GitHub if all passed
    }
}
