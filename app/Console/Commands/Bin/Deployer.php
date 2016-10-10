<?php

namespace App\Console\Commands\Bin;

use SSH;
use App\Console\Commands\Bin\GitManager;
use App\Console\Commands\Bin\DeployerPushTrait;
use App\Console\Commands\Bin\DeployerDeployTrait;
use App\Console\Commands\Bin\DeployerRollbackTrait;

class Deployer
{
    use DeployerPushTrait;

    /**
     * The parent command that uses this class
     */
    protected $c;

    protected $project;

    protected $projectEnv = [];

    protected $flags;

    protected $availableFlags = [
        ['a', 'use --amend in git commit'],
        ['d', 'generate documentation on deployment using docs:generate'],
        ['f', 'force push the repository'],
        ['l', 'leave untracked files out of commit'],
        ['m', 'skip migrations on deployment']
    ];

    public $remote;

    public $branch;

    public $version;

    protected $git;

    protected $pushTime;

    protected $envVars = [
        'DEPLOY_KEY'
    ];

    protected $session; // SSH session to our remote

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }
    }

    public function push($project)
    {
        $this->project = $project;

        $dir = str_replace("\\ ", " ", "{$this->c->projectRoot}/{$project}");

        chdir($dir);

        $this->git = new GitManager;

        $this->pushTime = time();

        $this->getFlags();

        $this->loadEnvVars();

        $this->preFlightChecks();

        // Get remote info from git remote -v, ~/.ssh/config, and .env
        if (!$this->configRemotes()) {
            return;
        }

        if (!$this->chooseRemote()) {
            return;
        }

        $this->c->out(
            'On branch [<cyan>'.$this->git->getCurrentBranch().'</cyan>]',
            'comment'
        );

        if (!$this->makeCommit()) {
            return;
        }

        if (!$this->pushToRemote()) {
            return;
        }
    }
}
