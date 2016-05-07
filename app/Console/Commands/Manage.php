<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

use App\Console\Commands\Deploy;

class Manage extends Command
{
    use OutputTrait;

    protected $name = 'manage';

    protected $description = 'Manage all Laravel projects on local dev.';

    public $projectRoot = '/var/www/';

    protected $project;

    protected $commander;

    public function __construct()
    {
        parent::__construct();

        $this->project = new Project($this);
        $this->commander = new CommandRunner($this);
    }

    public function fire()
    {
        $this->projectLoop();
    }

    public function signalHandler($signal)
    {

    }

    protected function projectLoop()
    {
        $this->project->list();
        // $project = $this->choice(
        //     'Which project do you want to manage?',
        //     array_merge(['<exit>'], $this->project->list()),
        //     0
        // );
        //
        // if ($project == '<exit>') {
        //     $this->comment("Exiting...\n");
        //     exit;
        // }

        // $this->project->setCurrentProject($project);
        $this->project->setCurrentProject('APIs');

        $this->project->getStatus();

        if ($this->project->current == 'APIs') {
            $this->menuAPILoop();
        } else {
            $this->out(
                'No code for managing individual projects yet.',
                'error'
            );
            exit;
        }
    }

    protected function menuAPILoop()
    {
        $this->clearScreen();

        $this->project->outWorkTree();

        $tableHeaders = ['Project', 'Branch', 'Version', 'Commit', 'Status'];

        $this->table($tableHeaders, $this->project->status);

        $command = $this->choice(
            'What do you want to do?',
            [
                '<go back>',
                'Git Pull',
                'Git Push',
                'Synchronize Submodules',
                'Start Log Viewer',
                'Run Unit Tests',
                'Build',
                'Deploy to Dev',
                'Deploy to Production'
            ],
            0
        );
    }

    protected function startLogViewer($project)
    {
        try {
            declare(ticks = 1);
            pcntl_signal(SIGINT, function () {
            }); // don't kill the entire script on ctrl+c
            $process = new Process('php artisan tail --ansi');
            $process->setWorkingDirectory("{$this->projectRoot}.{$project}");
            $process->setTimeout(null);
            $process->start();
            $process->wait(function ($type, $buffer) use ($process) {
                $this->out($buffer);
            });
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            $process->stop();
        }
    }
}
