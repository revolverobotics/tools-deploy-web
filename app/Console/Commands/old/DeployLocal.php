<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

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
        $this->setEnvironment('local');

        $this->home();
    }

    private function newLinesUsingANSIEscapeSequences()
    {
        // http://stackoverflow.com/questions/5265288/update-command-line-output-i-e-for-progress

        // http://ascii-table.com/ansi-escape-sequences.php

        // overwrite new line
        for ($i=0; $i<=100; $i++):

            echo "\033[1A";
            $this->line('NEW line' . $i);
            sleep(1);

        endfor;
    }

    private function home()
    {
        $this->environmentCheckStatus('local');

        $this->mainMenu();
    }

    private function mainMenu()
    {
        $choices = [
            'Exit',
            'Manage a service'
        ];

        $this->menuChoice = $this->choice('What would you like to do?', $choices, 0);

        $choice = array_search($this->menuChoice, $choices);

        switch($choice):

            case 0:
                $this->info('Done.' . PHP_EOL);
                exit;

            case 1:
                $this->serviceMenu();
                break;

            default;
                $this->info('Done.' . PHP_EOL);
                exit;

        endswitch;
    }

    private function serviceMenu()
    {
        $this->clearScreen();

        $this->printServiceTable();

        $choices = $this->strtoupperArray($this->services);

        array_unshift($choices, 'Back');

        $this->menuChoice = $this->choice('Manage which service?', $choices, 0);

        $choice = array_search($this->menuChoice, $choices);

        switch($choice):

            case 0:
                $this->home();
                break;

            default;
                $this->setCurrentService(strtolower($this->menuChoice));
                exec('bash && pushd '.$this->getServicePath());
                exit;

        endswitch;
    }

    private function manageMenu($which)
    {
        $this->clearScreen();

        $this->setCurrentService(strtolower($which));

        $this->setCommands(['pwd', 'git status']);

        $this->runCommandsForService($which, null, function ()
        {
            $this->comment($this->filterOutputBreak($this->outputBuffer));
        });

        $choices = [
            'Current Branch -> `git status`',
            'Current Branch -> Switch to another branch',
            'Current Branch -> Push to GitHub',
            'Current Branch -> Run PHPUnit tests',
            'Current Branch -> Deploy to Dev Environment',
            'Current Branch -> Merge into master & Push to Github',
            ' master Branch -> Deploy to Dev Environment',
            ' master Branch -> Deploy to Production Environment',
            'Run a custom command',
        ];

        array_unshift($choices, 'Back');

        $this->menuChoice = $this->choice('[' . strtoupper($this->currentService) . '] Operation?', $choices, 0);

        $choice = array_search($this->menuChoice, $choices);

        switch($choice):

            case 0:
                $this->serviceMenu();
                break;

            case 1:
                $this->currentBranchRefresh();
                break;

            case 2:
                $this->currentBranchSwitch();
                break;

            case 3:
                $this->currentBranchPushToGithub();
                $this->confirm('Hit <ENTER> to return Service Management menu.');
                break;

            case 4:
                $this->currentBranchRunUnitTests();
                break;

            case 5:
                $this->currentBranchDeployToDev();
                $this->confirm('Hit <ENTER> to return Service Management menu.');
                break;

            case 6:
                $this->currentBranchMergeIntoMasterPushToGithub();
                $this->confirm('Hit <ENTER> to return Service Management menu.');
                break;

            case 7:
                $this->masterBranchDeployToDev();
                $this->confirm('Hit <ENTER> to return Service Management menu.');
                break;

            case 8:
                $this->masterBranchDeployToProduction();
                $this->confirm('Hit <ENTER> to return Service Management menu.');
                break;

            case 9:
                $this->customCommand();
                break;

            default:
                $this->serviceMenu();

        endswitch;

        $this->manageMenu($which);
    }

    private function currentBranchRefresh()
    {
        $this->setCommands([
            'git status'
        ]);

        $this->runCommandsForService($this->currentService, null, true);
    }

    private function currentBranchSwitch()
    {
        $this->setCommands([
            'git branch'
        ]);

        $choices = [];

        $this->runCommandsForService($this->currentService, function() use (&$choices)
        {
            $branches = explode("\n", $this->execOutput[0]);

            foreach($branches as $branch):

                $branch = $this->stripWhitespace($this->filterOutputBreak($branch));

                $branch = str_replace('*', '', $branch);

                if ($branch == "")
                    continue;

                array_push($choices, $branch);

            endforeach;
        });

        $this->line('Available branches:'.PHP_EOL);

        foreach($choices as $choice)
            $this->comment("\t" . $choice);

        $switchToBranch = $this->anticipate('Switch to which branch?', $choices);

        $this->setCommands([
            'git checkout ' . $switchToBranch
        ]);

        $this->runCommandsForService($this->currentService, null, true);

        $this->manageMenu($this->currentService);
    }

    private function currentBranchPushToGithub()
    {
        $this->setCommands([
            'pwd',
            'git add --all',
            'branch=$(git branch | sed -n -e \'s/^\* \(.*\)/\1/p\')',
            'git push origin $branch'
        ]);
        $this->runCommandsForService($this->currentService, null, true);
    }

    private function currentBranchRunUnitTests()
    {
        $this->setCommands([
            'vendor/phpunit/phpunit/phpunit'
        ]);

        $this->runCommandsForService($this->currentService, null, function ()
        {
            $this->line($this->filterOutputBreak($this->outputBuffer));
        });

        if ($this->confirm('Re-run unit tests? [y|N]'))
            $this->currentBranchRunUnitTests();
    }

    private function currentBranchDeployToDev()
    {
        $this->setCommands([
            'pwd',
            'branch=$(git branch | sed -n -e \'s/^\* \(.*\)/\1/p\')',
            'PKEY=' . env('DEPLOY_KEY') . ' git push dev $branch',
        ]);

        $this->runCommandsForService($this->currentService, null, true);
    }

    private function currentBranchMergeIntoMasterPushToGithub()
    {
        $this->setCommands([
            'pwd',
            'branch=$(git branch | sed -n -e \'s/^\* \(.*\)/\1/p\')',
            'git checkout master',
            'git merge $branch',
            'git push origin master',
            'git checkout $branch'
        ]);

        $this->runCommandsForService($this->currentService, null, true);
    }

    private function masterBranchDeployToDev()
    {
        $this->setCommands([
            'pwd',
            'branch=$(git branch | sed -n -e \'s/^\* \(.*\)/\1/p\')',
            'git checkout master',
            'PKEY=' . env('DEPLOY_KEY') . ' git push dev master',
            'git checkout $branch'
        ]);

        $this->runCommandsForService($this->currentService, null, true);
    }

    private function masterBranchDeployToProduction()
    {
        $this->setCommands([
            'pwd',
            'branch=$(git branch | sed -n -e \'s/^\* \(.*\)/\1/p\')',
            'git checkout master',
            'PKEY='.env('DEPLOY_KEY').' git push production master',
            'git checkout $branch'
        ]);

        $this->runCommandsForService($this->currentService, null, true);
    }

    // private function customCommand($command = null)
    // {
    //     exec('bash --init-file <(echo ". \"$HOME/.bashrc\";")');
    // }

    private function customCommand($command = null)
    {
        if (is_null($command))
            $command = $this->ask('Run command [exit]');

        if ($command == 'exit')
            return false;

        $this->setCommands([
            $command
        ]);

        $this->runCommandsForService($this->currentService, null, true);

        $command = $this->ask('Run another command [exit]');

        if ($command != 'exit')
            $this->customCommand($command);
    }
}
