<?php

namespace App\Console\Commands;

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

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }
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

    public function startLogViewer($project)
    {
        $dir = str_replace("\\ ", " ", $this->c->projectRoot.$project);

        try {
            $this->c->process = new Process('php artisan tail --ansi');
            $this->c->process
                ->setWorkingDirectory($dir);
            $this->c->process->setTimeout(null);
            $this->c->process->run(function ($type, $buffer) {
                $this->c->out($buffer);
            });
        } catch (\Symfony\Component\Process\Exception\RuntimeException $e) {
            $this->c->out("Exiting log viewer...\n", 'comment');
        }
    }
}
