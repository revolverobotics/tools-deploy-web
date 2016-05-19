<?php

namespace App\Console\Commands\Bin;

use Dotenv\Dotenv;
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
        ['b', 'submit project to Jenkins for build'],
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

        // check for env vars
        $this->chooseRemote();

        $this->c->out(
            'On branch [<cyan>'.$this->git->getCurrentBranch().'</cyan>]',
            'comment'
        );

        $this->makeCommit();

        $this->pushToRemote();
    }

    protected function getFlags()
    {
        $this->c->table(['Flag', 'Description'], $this->availableFlags);

        $this->flags =
            $this->c->ask('Which flags would you like to use?', '<none>');

        if ($this->flags == '<none>') {
            return;
        }

        foreach (str_split($this->flags) as $flag) {
            if (!in_array($flag, array_pluck($this->availableFlags, 0))) {
                $this->c->error(
                    "No flag `{$flag}` exists. It will not be used."
                );
            }
        }
    }

    protected function isFlagSet($flag)
    {
        if ($this->flags == '<none>') {
            return false;
        }

        if (!in_array($flag, str_split($this->flags))) {
            return false;
        }

        return true;
    }

    protected function loadEnvVars()
    {
        $dir = str_replace(
            "\\ ",
            " ",
            "{$this->c->projectRoot}/{$this->project}"
        );

        // Include our test variables (instead of specifying in phpunit-XX.xml)
        if (file_exists("{$dir}/.env")) {
            (new Dotenv($dir, '.env'))->load();
        }
    }

    protected function preFlightChecks()
    {
        // Check that _HOST entries exists for our remote servers
        $this->git->command = 'git remote';
        foreach ($this->git->exec() as $remote) {
            if ($remote == 'origin' || $remote == 'jenkins') {
                continue;
            }
        }

        foreach ($this->envVars as $var) {
            if (is_null(env($var, null))) {
                $this->c->outError('Missing env var: '.$var);
                throw new \Exception('Aborting.');
            }
        }

        if ($this->isFlagSet('b')
            && !env('DEPLOY_KEY_JENKINS', false)
        ) {
            throw new \Exception('Cannot push to Jenkins, no JENKINS_KEY '.
                'defined in .env file.');
        }

        // if ($this->c->argument('remote') != 'origin'
        //     && !env('DEPLOY_KEY', false)
        // ) {
        //     throw new \Exception('Cannot push to '.$this->git->remote.', no '.
        //         'DEPLOY_KEY defined in .env file.');
        // }
        //
        // if ($this->c->argument('remote') == 'jenkins') {
        //     throw new \Exception('Manual push to Jenkins server disabled.'.
        //         PHP_EOL.'Push to origin with the -b option to run a build.');
        // }
    }
}
