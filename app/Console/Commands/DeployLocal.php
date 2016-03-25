<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Console\Commands\Deploy;

class DeployLocal extends Command
{
    use Deploy;

    protected $name = 'deploy:local';

    protected $description = 'Version control and code updation for local repos';

    protected $menu;

    protected $menuChoice;

    public function fire()
    {
        $this->environmentCheckStatus('local');

        $this->mainMenu();
    }

    private function mainMenu()
    {
        $choices = [
            'Exit',
            'Increment version tags',
            'Run unit tests',
            'Push code to GitHub'
        ];

        $this->menuChoice = $this->choice('What would you like to do?', $choices, 0);

        switch($this->menuChoice)
        {
            case 'Exit':
                $this->info('Done.' . PHP_EOL);
                break;

            case 'Increment version tags':
                $this->info('Incrementing version tags of all services...' . PHP_EOL);
                $this->incrementTags();
                break;

            case 'Run unit tests':
                $this->info('Running unit tests on all services...' . PHP_EOL);
                break;

            case 'Push code to GitHub':
                $this->info('Pushing all local code to GitHhub...' . PHP_EOL);
                break;

            default;
                $this->info('Done.' . PHP_EOL);
                break;
        }
    }

    private function incrementTags()
    {
        
    }

    private function refreshServiceInfo()
    {
        $this->getServiceInfo();
    }
}
