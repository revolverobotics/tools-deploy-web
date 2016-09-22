<?php

namespace App\Console\Commands;

use SSH;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/*
    Base class for our deployment commands
 */
trait Deploy
{
    protected $services;

    protected $status;

    protected $statusHeaders = [' ', 'Service', 'Branch', 'Tag', 'Commit', 'Status'];

    protected $environments = ['local', 'dev', 'production'];

    protected $currentService;

    protected $currentEnvironment;

    protected $commands;

    protected $commandBuffer;

    protected $execOutput;

    protected $outputBuffer;

    protected $outputDelimiter = '--- break ---';

    private function environmentCheckStatus($whichEnvironment)
    {
        $this->clearScreen();

        $this->setCommands([
            'git branch | grep \*',
            'git tag | tail -n1',
            'git log -1',
            'git status'
        ]);

        $this->environmentLoop(
            $whichEnvironment,
            function () {
                $this->comment(PHP_EOL.PHP_EOL.'Repo status for all services on ' . $this->currentEnvironment . ':');
                $this->printServiceTable($this->currentEnvironment);
            },
            function () {
                $this->parseCheckStatusOutput();
            },
            "Checking repo status..."
        );
    }

    private function parseCheckStatusOutput()
    {
        $env = $this->currentEnvironment;
        $service = $this->currentService;

        $this->status[$env][$service][' '] = array_search($service, $this->services)+1;

        $this->status[$env][$service]['service'] = strtoupper($service);

        if (isset($this->execOutput[0])) {
            $this->status[$env][$service]['branch'] = str_replace(
                "---break---",
                "",
                $this->stripWhitespace(substr($this->execOutput[0], 2))
            );
        }

        if (isset($this->execOutput[1])) {
            $this->status[$env][$service]['tag'] = str_replace(
                "---break---",
                "",
                $this->stripWhitespace($this->execOutput[1])
            );
        }

        if (isset($this->execOutput[2])) {
            $this->status[$env][$service]['commit'] = $this->stripWhitespace(substr($this->execOutput[2], 7, 7));
        }

        if (isset($this->execOutput[3])) {
            if (strpos($this->execOutput[3], 'nothing to commit, working directory clean') === false) {
                $this->status[$env][$service]['status'] = 'DIRTY';
            } else {
                $this->status[$env][$service]['status'] = 'CLEAN';
            }
        }
    }

    private function printServiceTable($env = null)
    {
        if (is_null($env)) {
            $env = $this->currentEnvironment;
        }

        $this->table($this->statusHeaders, $this->status[$env]);
    }

    private function refreshServiceTable($env = null)
    {
        if (is_null($env)) {
            $env = $this->currentEnvironment;
        }

        $this->environmentCheckStatus($env);
    }

    /**
     * Runs a set of commands for the given environment
     * @param  [string]   $whichEnvironment Can be 'local', 'dev', 'production', or 'all'
     * @param  [function] $callbackEnv      Function to execute after every $this->environments iteration
     * @param  [function] $callbackService  Function to execute after every $this->service iteration
     * @return null
     *
     * The runCommandsForEnvironment chain runs a set of $this->commands for every $this->services
     * $callbackService will be run after runCommandsForService executes $this->commands for each $service
     *
     */
    private function environmentLoop($whichEnvironment, $callbackEnv, $callbackService, string $message = null)
    {
        $this->getServices();

        $this->setEnvironment($whichEnvironment);

        $this->initializeRemotes();

        foreach ($this->environments as $env) {
            $this->currentEnvironment = $env;

            $this->runCommandsForEnvironment($env, $callbackService, $message);

            $callbackEnv();
        }
    }

    private function setEnvironment(string $env)
    {
        $array = [];

        switch ($env) {
            case 'local':
                array_push($array, 'local');
                break;

            case 'dev':
                array_push($array, 'local', 'dev');
                break;

            case 'production':
                array_push($array, 'local', 'production');
                break;

            case 'all':
                array_push($array, 'local', 'dev', 'production');
                break;

            default:
                array_push($array, 'local');
        }

        $this->environments = $array;
    }

    private function getServices()
    {
        $this->services = explode(',', env('SERVICES'));
    }

    private function getServicePath()
    {
        $env = strtoupper($this->currentEnvironment);
        $service = strtoupper($this->currentService);

        $path = env('PATH_'.$env.'_'.$service, null);
        if (is_null($path)) {
            $path = env('PATH_'.$service);
        }

        if (is_null($path)) {
            throw new \Exception('Couldn\'t get path to service: '.
            $service);
        }

        return $path;
    }

    private function setCurrentService($service)
    {
        $this->currentService = $service;
    }

    private function initializeRemotes()
    {
        $connections = [];

        foreach ($this->environments as $env) {
            if ($env == 'local') {
                continue;
            }

            foreach ($this->services as $service) {
                $env = strtoupper($env);
                $service = strtoupper($service);

                $username = env('HOST_'.$env.'_USERNAME', null);

                if (is_null($username)) {
                    $username = env('HOST_USERNAME');
                }

                $path = env('PATH_'.$env.'_'.$service, null);

                if (is_null($path)) {
                    $path = env('PATH_'.$service);
                }

                $key = env($env.'_KEY', null);

                if (is_null($key)) {
                    $key = env('DEPLOY_KEY');
                }

                $connections[$env.'_'.$service] = [
                    'host'      => env('HOST_'.$env.'_'.$service),
                    'username'  => $username,
                    'password'  => '',
                    'key'       => $key,
                    'keyphrase' => '',
                    'root'      => $path,
                ];
            }
        }

        config(['remote' => ['connections' => $connections]]);
    }

    private function setCommands(array $commands)
    {
        $this->commands = $commands;
    }

    private function runCommandsForEnvironment($whichEnvironment, $callback, string $message = null)
    {
        if (is_null($message)) {
            $message = PHP_EOL . 'Running commands for ' . $whichEnvironment . ':';
        } else {
            $message = PHP_EOL . $message;
        }

        $this->line($message);

        $bar = $this->output->createProgressBar(count($this->services));

        foreach ($this->services as $service) {
            $this->runCommandsForService($service, $callback);

            $bar->advance();
        }

        $bar->finish();
    }

    private function runCommandsForService(
        $service,
        $callback = null,
        $liveOutput = false
    ) {
        $this->setCurrentService($service);

        $this->prepCommandsForExec();

        $this->execCommands($liveOutput);

        if (!is_null($callback)) {
            $callback();
        }
    }

    private function clearCommandBuffer()
    {
        $this->commandBuffer = [];
    }

    private function prepCommandsForExec()
    {
        $this->clearCommandBuffer();

        $serviceUpper = strtoupper($this->currentService);

        $servicePath = $this->getServicePath();

        /*
            all commands are run per service, so cd into the correct directory
            before executing them
        */
        array_push($this->commandBuffer, 'cd ' . $servicePath);

        foreach ($this->commands as $command) {
            if ($this->currentEnvironment != 'local' &&
                strpos($command, 'git ') !== false) {
                    $workTree = $this->getServicePath();

                    $lookup = 'git';
                    $insertPos = strpos($command, $lookup);
                    $command = substr_replace(
                        $command,
                        ' --git-dir='.env('GIT_PATH_'.$serviceUpper).
                        ' --work-tree='.$servicePath.' ',
                        $insertPos + strlen($lookup),
                        0
                    );
            }

            array_push(
                $this->commandBuffer,
                $command,
                'echo ' . $this->outputDelimiter
            );
        }
    }

    private function execCommands($liveOutput = false)
    {
        $counter = 0;
        $this->execOutput = [];

        $commands = $this->commandBuffer;

        if ($this->currentEnvironment == 'local') {
            $commandList = implode(" && ", $commands);
            $process = new Process($commandList);
            $process->run(
                function ($type, $buffer) use (&$counter, $liveOutput) {
                    $this->outputBuffer = $buffer;

                    if (gettype($liveOutput) == 'object') {
                        $liveOutput();
                    } elseif ($liveOutput === true) {
                        $this->info($this->filterOutputBreak($buffer));
                    } else {
                        $this->collectExecOutput($buffer, $counter);
                    }
                }
            );
        } else {
            $remote = strtoupper($this->currentEnvironment).
                      '_'.strtoupper($this->currentService);
            SSH::into($remote)->run($commands, function ($buffer) use (&$counter, $liveOutput) {
                $this->outputBuffer = $buffer;

                if (gettype($liveOutput) == 'object') {
                    $liveOutput();
                } elseif ($liveOutput === true) {
                    $this->info($this->filterOutputBreak($buffer));
                } else {
                    $this->collectExecOutput($buffer, $counter);
                }
            });
        }

        $this->clearCommandBuffer(); // done running, clear for next iteration
    }

    private function filterOutputBreak($line)
    {
        if (strpos($line, $this->outputDelimiter) !== false) {
            return '';
        }

        return $line;
    }

    private function collectExecOutput($buffer, &$counter)
    {
        if (!isset($this->execOutput[$counter])) {
            $this->execOutput[$counter] = $buffer;
        } else {
            $this->execOutput[$counter] .= $buffer;
        }

        if (strpos($buffer, $this->outputDelimiter) !== false) {
            $counter++;
        }
    }

    private function clearScreen()
    {
        echo "\033[2J";
    }

    private function strtoupperArray(array $array)
    {
        foreach ($array as &$value) {
            $value = strtoupper($value);
        }

        return $array;
    }

    public function stripWhitespace(string $string)
    {
        return preg_replace('/\s+/', '', $string);
    }
}
