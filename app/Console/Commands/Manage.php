<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class Manage extends Command
{
    use OutputTrait;

    protected $name = 'manage';

    protected $description = 'Manage all Laravel projects on local dev.';

    public $projectRoot;

    /**
     * root, project, exit
     */
    protected $state = 'root';

    /**
    * Project class
    */
    public $project;

    /**
     * Command class
     */
    public $commandRunner;

    /**
     * Symfony process
     */
    public $process;

    protected $projectAPICommands = [
        'Git Status',
        'Git Command',
        'Artisan Push',
        'Pull Latest Submodules',
        'Run Unit Tests',
        'Build',
        'Deploy',
        'Compare With Remote',
    ];

    protected $projectCommands = [
        'Git Status',
        'Git Command',
        'Artisan Push',
        'Pull Latest Submodule',
        'Push Submodule',
        'Start Log Viewer',
        'Run Unit Tests',
        'Build',
        'Deploy',
        'Compare With Remote',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->projectRoot = env('PROJECT_ROOT', '/var/www');

        $this->project = new Project($this);
        $this->commandRunner = new CommandRunner($this);

        // Don't kill our script on ctrl+c:
        declare(ticks = 1); // might be able to delete this line, try it later.
        pcntl_signal(SIGINT, function () {
        });
    }

    public function fire()
    {
        while ($this->state == 'root') {
            $this->manageLoop();
        }

        $this->comment("Exiting...\n");
        exit;
    }

    protected function manageLoop()
    {
        // First allow user to select what project to manage
        $this->menuProject();

        while ($this->state == 'project') {
            $this->menuCommand();
        }
    }

    protected function menuProject()
    {
        $choices = $this->project->list();
        array_unshift($choices, '<exit>');

        $choice = $this->choice(
            'Which project do you want to manage?',
            $choices,
            0
        );

        if ($choice == '<exit>') {
            $this->state = 'exit';
            return false;
        }

        $this->project->setCurrentProject($choice);

        $this->state = 'project';

        return true;
    }

    protected function menuCommand()
    {
        $this->project->outWorkTree();

        $this->project->getStatus();

        $tableHeaders = [
            'Project','Branch','Version','Status','Commit','Origin'
        ];

        $this->table($tableHeaders, $this->project->status);

        if ($this->project->current == 'APIs') {
            $choices = $this->projectAPICommands;
        } else {
            $choices = $this->projectCommands;
        }

        array_unshift($choices, 'Custom Command');
        array_unshift($choices, '<go back>');

        $choice = $this->choice(
            'What do you want to do?',
            $choices,
            0
        );

        if ($choice == '<go back>') {
            $this->state = 'root';
            return;
        }

        $this->commandRunner->executor($choice);
    }
}
