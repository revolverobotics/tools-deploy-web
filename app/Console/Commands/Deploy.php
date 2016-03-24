<?php

namespace App\Console\Commands;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Collective\Remote\RemoteFacade as SSH;

/*
    Base class for our deployment commands
 */
trait Deploy
{
    public $statusArray;

    private $outputBuffer;

    protected $scope = 'local';

    protected $services;

    public function enumerateServices()
    {
        $services = explode(',', env('SERVICES'));

        foreach($services as $service):

            $this->checkStatus($service);
            // array_push($this->statusArray, $status);

        endforeach;

        $tableHeaders = ['Service', 'Branch', 'Tag', 'Commit', 'Status'];

        $this->table($tableHeaders, $this->statusArray);
    }

    private function getServices()
    {
        if ($this->scope == 'local')
            $this->services = env('SERVICES');

        if ($this->scope == 'remote')
            SSH::into($remote)->run($commandArray);
    }

    private function checkStatus($service)
    {
        $service = strtoupper($service);
        $servicePath = env('PATH_' . $service);

        $this->statusArray[$service]['service'] = $service;
        // $this->statusArray[$service]['path'] = $servicePath;

        $statusArray = $this->statusArray;

        // get current branch
        $command = new Process('cd ' . $servicePath . ' && git branch | grep \*');
        $command->run(function($type, $buffer) use ($service, &$statusArray)
        {
            $statusArray[$service]['branch'] = preg_replace('/\s+/', '', substr($buffer, 2));
        });

        // get latest tag version
        $command = new Process('cd ' . $servicePath . ' && git tag | tail -n1');
        $command->run(function($type, $buffer) use ($service, &$statusArray)
        {
            $statusArray[$service]['tag'] = preg_replace('/\s+/', '', $buffer);
        });

        // get first 7 digits of commit hex
        $command = new Process('cd ' . $servicePath . ' && git log -1');
        $command->run(function($type, $buffer) use ($service, &$statusArray)
        {
            $statusArray[$service]['commit'] = preg_replace('/\s+/', '', substr($buffer, 7, 7));
        });

        // clean or dirty
        $command = new Process('cd ' . $servicePath . ' && git status');
        $command->run(function($type, $buffer) use ($service, &$statusArray)
        {
            if (strpos($buffer, 'nothing to commit, working directory clean') === false)
                $statusArray[$service]['status'] = 'DIRTY';

            else
                $statusArray[$service]['status'] = 'CLEAN';
        });

        $this->statusArray = $statusArray;
    }

    private function execLocal()
    {

    }

    private function execRemote()
    {

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
