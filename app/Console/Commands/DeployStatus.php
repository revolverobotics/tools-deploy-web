<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

use SSH;

class DeployStatus extends Command
{
    protected $name = 'deploy:status';

    protected $description = 'Check & compare status of local, dev, & production code';

    protected $services;

    protected $status;

    protected $statusHeaders = ['Service', 'Branch', 'Tag', 'Commit', 'Status'];

    protected $environments = ['local', 'dev', 'production'];

    protected $currentService;

    protected $currentEnvironment;

    protected $commands;

    protected $execOutput;

    public function __construct()
    {
        parent::__construct();
        $this->services = explode(',', env('SERVICES'));
    }

    public function fire()
    {
        $this->initializeRemotes();

        $this->enumerateServices();
    }

    private function initializeRemotes()
    {
        $connections = [];

        foreach ($this->environments as $env):

            if ($env == 'local')
                continue;

            foreach($this->services as $service):

                $env = strtoupper($env);
                $service = strtoupper($service);

                $connections[$env.'_'.$service] = [
                    'host'      => env('HOST_'.$env.'_'.$service),
                    'username'  => env('HOST_USERNAME'),
                    'password'  => '',
                    'key'       => env('DEPLOY_KEY'),
                    'keyphrase' => '',
                    'root'      => env('PATH_'.$service),
                ];

            endforeach;

        endforeach;

        config(['remote' => [
            'connections' => $connections
        ]]);
    }

    private function enumerateServices()
    {
        foreach($this->environments as $env):

            $this->currentEnvironment = $env;

            $this->line(PHP_EOL . 'Checking ' . $env . ':');
            $bar = $this->output->createProgressBar(count($this->services));

            foreach($this->services as $service):

                $this->currentService = $service;

                $this->checkStatus();

                $bar->advance();

            endforeach;

            $bar->finish();

            $this->line(PHP_EOL.PHP_EOL.'Repo status for ' . $env . ':');

            $this->table($this->statusHeaders, $this->status[$env]);

        endforeach;
    }

    private function checkStatus()
    {
        $servicePath = env('PATH_' . strtoupper($this->currentService));

        $this->commands = [
            'cd ' . $servicePath,
            'git branch | grep \*',
            'git tag | tail -n1',
            'git log -1',
            'git status'
        ];

        $this->execCommands();
    }

    private function execCommands()
    {
        $this->prepCommandsForExec();

        $this->execLocal();

        $this->execRemote();

        $this->parseExecOutput();
    }

    private function prepCommandsForExec()
    {
        $moddedCommands = [];

        $serviceUpper = strtoupper($this->currentService);

        foreach ($this->commands as &$command):

            if ($this->currentEnvironment != 'local' && strpos($command, 'git') !== false):

                $lookup = 'git';
                $insertPos = strpos($command, $lookup);
                $command = substr_replace(
                    $command,
                    ' --git-dir=' . env('GIT_PATH_' . $serviceUpper) . ' --work-tree=.',
                    $insertPos + strlen($lookup),
                    0
                );

            endif;

            array_push($moddedCommands, $command, 'echo --- break ---');

        endforeach;

        $this->commands = $moddedCommands;
    }

    private function execLocal()
    {
        if ($this->currentEnvironment != 'local')
            return false;

        $counter = 0;
        $this->execOutput = [];

        $commandList = implode(" && ", $this->commands);
        $process = new Process($commandList);
        $process->run(function($type, $buffer) use (&$counter)
        {
            if (!isset($this->execOutput[$counter]))
                $this->execOutput[$counter] = $buffer.PHP_EOL;

            else
                $this->execOutput[$counter] .= $buffer.PHP_EOL;

            if (strpos($buffer, '--- break ---') !== false)
                $counter++;
        });
    }

    private function execRemote()
    {
        if ($this->currentEnvironment == 'local')
            return false;

        $counter = 0;
        $this->execOutput = [];

        $env = strtoupper($this->currentEnvironment);
        $service = strtoupper($this->currentService);
        $remote = $env . '_' . $service;

        SSH::into($remote)->run($this->commands, function($buffer) use (&$counter)
        {
            if (!isset($this->execOutput[$counter]))
                $this->execOutput[$counter] = $buffer.PHP_EOL;

            else
                $this->execOutput[$counter] .= $buffer.PHP_EOL;

            if (strpos($buffer, '--- break ---') !== false)
                $counter++;
        });
    }

    private function parseExecOutput()
    {
        $env = $this->currentEnvironment;
        $service = $this->currentService;

        $this->status[$env][$service]['service'] = strtoupper($service);

        $this->status[$env][$service]['branch'] = str_replace("---break---", "", preg_replace('/\s+/', '', substr($this->execOutput[1], 2)));

        $this->status[$env][$service]['tag'] = str_replace("---break---", "", preg_replace('/\s+/', '', $this->execOutput[2]));

        $this->status[$env][$service]['commit'] = preg_replace('/\s+/', '', substr($this->execOutput[3], 7, 7));

        if (strpos($this->execOutput[4], 'nothing to commit, working directory clean') === false)
            $this->status[$env][$service]['status'] = 'DIRTY';

        else
            $this->status[$env][$service]['status'] = 'CLEAN';
    }

    private function printOutput($output=null)
    {
        if (is_null($output))
            $output = $this->outputBuffer;

        foreach($output as $line)
        {
            $this->info($line);
        }
    }
}
