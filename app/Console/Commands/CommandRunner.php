<?php

namespace App\Console\Commands;

use Cache;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Much like BladeRunner (or actually not at all), this class descends into
 * project directories and runs our usual git management and deployment
 * commands, mainly the Deploy class found in our tools-laravel-microservice
 * submoduel.
 */
class CommandRunner
{
    /**
     * The parent command that uses this class
     */
    protected $c;

    protected $gitCommands = [
        'Git Status',
        'Git Log',
        'Git Diff',
        'Git Checkout',
        'Git Reset',
        'Git Pull',
        'Git Push',
    ];

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }
    }

    public function executor($command)
    {
        $method = camel_case($command);

        if (!method_exists($this, $method)) {
            $this->c->out('No method exists for that command yet.', 'error');
            return;
        }

        $this->$method();
    }

    protected function runFromStatuses($callback)
    {
        $statuses = $this->c->project->status;

        // Operate on api-frontend last, since it depends on all the backends
        if ($this->c->project->current == 'APIs') {
            $first = array_shift($statuses);
            array_push($statuses, $first);
        }

        foreach ($statuses as $status) {
            $this->c->outputHeading(
                "Working in [ <cyan>{$status['project']}</cyan> ]"
            );

            $callback($status);
        }
    }

    protected function startProcess($process, $project)
    {
        $dir = str_replace("\\ ", " ", "{$this->c->projectRoot}/{$project}");

        $this->c->out(
            "Running `<green>{$process}</green>` in [ <cyan>$dir</cyan> ]...\n",
            'info'
        );

        $this->c->process = new Process($process);
        $this->c->process
            ->setWorkingDirectory($dir);
        $this->c->process->setTimeout(null);
        $this->c->process->run(function ($type, $buffer) {
            $this->c->out($buffer, 'info');
        });

        $this->c->out("Done.\n\n");
    }

    protected function customCommand()
    {
        $commandArray = Cache::get('custom-commands');

        if (is_null($commandArray)) {
            $commandArray = [];
        }

        $this->c->out('Command history:');
        foreach ($commandArray as $commandHistory) {
            $this->c->out($commandHistory);
        }

        $command = $this->c->anticipate(
            'What would you like to run?',
            array_merge(['<go back>'], $commandArray),
            '<go back>'
        );

        if ($command == '<go back>') {
            return;
        }

        if (!in_array($command, $commandArray)) {
            // Store command for later:
            if (count($commandArray) > 10) {
                array_shift($commandArray);
            }
            array_push($commandArray, $command);
            Cache::forever('custom-commands', $commandArray);
        }

        $this->runFromStatuses(function ($status) use ($command) {
            chdir("{$this->c->projectRoot}/{$status['project']}");
            passthru($command);
        });

        $this->c->out("Done.\n", 'comment');
    }

    protected function gitCommand()
    {
        // This method is pretty much like executor() except that we
        // add a sub-menu for the git commands.
        $choices = $this->gitCommands;
        array_unshift($choices, '<go back>');

        $command =
            $this->c->choice('Which git command?', $choices, 0);

        if ($command == '<go back>') {
            return;
        }

        $method = camel_case($command);

        if (!method_exists($this, $method)) {
            $this->c->out('No method exists for that command yet.', 'error');
            return;
        }

        $this->$method();

        // Loop unless we choose <go back>
        $this->gitCommand();
    }

    protected function gitEnforceClean()
    {
        if (!$this->c->confirm('Enforce CLEAN status on all work trees?')) {
            return;
        }

        // Before performing batch operations, let's make sure that all
        // work trees to be operated on are clean.

        $statuses = $this->c->project->status;

        $this->runFromStatuses(function ($status) {
            if ($status['status'] != 'CLEAN') {
                $this->c->out(
                    "Working branch for [ <cyan>{$status['project']}</cyan> ] ".
                        "is dirty. Please fix before continuing.",
                    'error'
                );
                return false;
            }
        });
    }

    protected function gitStatus()
    {
        $this->runFromStatuses(function ($status) {
            $this->startProcess(
                "git status",
                $status['project']
            );
        });
    }

    protected function gitLog()
    {
        $this->runFromStatuses(function ($status) {
            $this->startProcess(
                "git log -3 --color=always",
                $status['project']
            );
        });
    }

    protected function gitDiff()
    {
        $this->runFromStatuses(function ($status) {
            $this->startProcess(
                "git diff --color=always",
                $status['project']
            );
        });
    }

    protected function gitCheckout()
    {
        $branch = $this->c->ask('Checkout to which branch?');

        $this->runFromStatuses(function ($status) use ($branch) {
            $this->startProcess(
                "git checkout {$branch}",
                $status['project']
            );
        });
    }

    protected function gitReset()
    {
        $which =
            $this->c->ask('Reset to which spec? (e.g., --hard or 4a9cb2f)');

        $this->runFromStatuses(function ($status) use ($which) {
            $this->startProcess(
                "git reset {$which}",
                $status['project']
            );
        });
    }

    protected function gitPull()
    {
        $branch = $this->c->ask("Pull which branch?", '(current)');

        $this->runFromStatuses(function ($status) use ($branch) {
            if ($branch == '(current)') {
                $branch = $status['branch'];
            }

            $this->startProcess(
                "git pull origin {$branch}",
                $status['project']
            );
        });
    }

    protected function artisanPush()
    {
        $root = $this->c->projectRoot;

        $flags = $this->c->ask("Enter any flags you wish to use: ", 'none');

        if ($flags == 'none') {
            $flags = "";
        }

        $this->runFromStatuses(function ($status) use ($root, $flags) {
            $dir = "cd {$root}/{$status['project']} && ";
            passthru($dir."php artisan push --ansi $flags");
        });
    }

    protected function pullLatestSubmodules()
    {
        // For now our only submodule is app/Submodules/ToolsLaravelMicroservice

        $this->runFromStatuses(function ($status) {
            $this->startProcess(
                'git pull origin master -f',
                $status['project']."/app/Submodules/ToolsLaravelMicroservice"
            );
        });
    }

    protected function pushSubmodule()
    {
        // For now our only submodule is app/Submodules/ToolsLaravelMicroservice

        $project = head($this->c->project->status)['project'];

        $message = $this->c->ask("Commit message?");

        $commands =
            "git add --all && ".
            "git commit -am \"{$message}\" && ".
            "git checkout master && ".
            "git merge HEAD@{1} && ".
            "git push origin master";

        $this->startProcess(
            $commands,
            $project."/app/Submodules/ToolsLaravelMicroservice"
        );
    }

    protected function startLogViewer()
    {
        $project = head($this->c->project->status)['project'];

        try {
            $this->startProcess('php artisan tail --ansi', $project);
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            $this->c->out("Exiting log viewer...\n", 'comment');
        }
    }

    protected function runUnitTests()
    {
        $root = $this->c->projectRoot;

        $this->runFromStatuses(function ($status) use ($root) {
            $dir = "cd {$root}/{$status['project']} && ";

            passthru($dir."vendor/phpunit/phpunit/phpunit --no-coverage");

            $this->c->out("\nDone.\n\n", 'line');
        });
    }

    protected function build()
    {
        $root = $this->c->projectRoot;

        $this->runFromStatuses(function ($status) use ($root) {
            $dir = "cd {$root}/{$status['project']} && ";
            passthru($dir."php artisan push origin --ansi -b");
        });
    }
}
