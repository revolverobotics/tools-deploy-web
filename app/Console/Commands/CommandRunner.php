<?php

namespace App\Console\Commands;

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

    /**
    * List of common operations we want to perform for our API services
    */
    public $apiCommands = [
        'Synchronize Submodules',
        'Push new [master] Version'
    ];

    /**
     * List of commands that we can run in our projects
     */
    public $commands = [
        '<go back>',
        'Run a custom command',
        'php artisan push',
        'php artisan pull',
        'git reset --hard',
        'git checkout',
        'git branch',
        'git tag',
        'git log -5 --color=always',
        'git submodule update'
    ];

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }
    }

    public function listCommands()
    {
        return $this->commands;
    }

    public function execute($command, $directory, $verbose = true)
    {
        if ($command == 'Run a custom command') {
            $command = $this->c->ask('What command?');
        }

        if ($verbose == true) {
            $this->c->out("\nRunning {$command}...", 'comment');
        }

        exec("cd {$directory} && {$command}", $lines);

        array_unshift($lines, "");
        array_push($lines, "");

        foreach ($lines as $line) {
            $this->c->out($line, 'info');
        }

        if ($verbose == true) {
            $this->c->out("Done.\n");
        }
    }
}
