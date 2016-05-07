<?php

namespace App\Console\Commands;

/**
 * Much like BladeRunner (or actually not at all), this class descends into
 * project directories and runs our usual git management and deployment
 * commands, mainly the Deploy class found in our tools-laravel-microservice
 * submodule.
 */
class Project
{
    /**
     * The parent command that uses this class
     */
    protected $c;

    /**
     * List of projects found (by having composer.json in their directory)
     */
    public $projects;

    public $current;

    public $status;

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }


    }

    public function list()
    {
        $this->projects = ['APIs'];

        $listing = scandir($this->c->projectRoot);

        foreach ($listing as $item) {
            if (is_dir("{$this->c->projectRoot}$item") &&
                file_exists("{$this->c->projectRoot}{$item}/composer.json") &&
                !str_contains($item, 'tools-') // ignore tools
            ) {
                array_push($this->projects, $item);
            }
        }

        return $this->projects;
    }

    public function setCurrentProject($project)
    {
        $this->current = $project;
    }

    public function getStatus()
    {
        $status = [];

        if ($this->current != 'APIs') {
            return $this->getStatusWhole($this->current);
        } else {
            foreach ($this->projects as $project) {
                if (!str_contains($project, 'api-')) {
                    continue;
                }
                array_push($status, $this->getStatusWhole($project));
            }
        }

        $this->status = $status;

        return $status;
    }

    protected function getStatusWhole($project)
    {
        $dir = "cd {$this->c->projectRoot}{$project} && ";

        $branch = $this->getStatusPartial(
            $dir.'git branch | grep \*',
            function ($result) {
                return substr($result[0], 2);
            }
        );

        $version = $this->getStatusPartial(
            $dir.'git tag | tail -n1',
            function ($result) {
                return $result[0];
            }
        );

        $commitHash = $this->getStatusPartial(
            $dir.'git rev-parse --short HEAD',
            function ($result) {
                return $result[0];
            }
        );

        $status = $this->getStatusPartial(
            $dir.'git status',
            function ($result) {
                return strpos(
                    $result[0],
                    'working directory clean'
                ) == false ? 'CLEAN' : 'DIRTY';
            }
        );

        $result = [
            'project'     => $project,
            'dev'         => $branch,
            'version'     => $version,
            'commit hash' => $commitHash,
            'status'      => $status
        ];

        return $result;
    }

    protected function getStatusPartial($command, $callback)
    {
        exec($command, $result);

        try {
            return $callback($result);
        } catch (\Exception $e) {
            return '<null>';
        }
    }

    public function outWorkTree()
    {
        $this->c->out(
            "Currently working in [ \033[36m{$this->current}\033[33m ]",
            'comment'
        );
    }
}
