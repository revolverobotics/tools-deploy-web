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

        $this->configRemotes();

        // check for env vars
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
            (new Dotenv($dir, '.env'))->overload();
        }
    }

    protected function preFlightChecks()
    {
        // Check that _HOST entries exists for our remote servers
        $this->git->command = 'git remote';
        foreach ($this->git->exec() as $remote) {
            if ($remote == 'origin') {
                continue;
            }
        }

        foreach ($this->envVars as $var) {
            if (is_null(env($var, null))) {
                $this->c->outError('Missing env var: '.$var);
                throw new \Exception('Aborting.');
            }
        }
    }

    protected function configRemotes()
    {
        $dir = str_replace(
            "\\ ",
            " ",
            "{$this->c->projectRoot}/{$this->project}"
        );

        chdir($dir);

        // Add project's remotes into the remote config for SSH
        exec('git remote -v', $lines);

        foreach ($lines as $line) {
            $remote = explode("\t", explode(":", $line)[0]);
            $remoteName = $remote[0];
            $address = $remote[1];

            // Check if address is IP or Hostname from ~/.ssh/config
            if (preg_match("/(?:[0-9]{1,3}\.){3}[0-9]{1,3}/", $address) === 0) {
                $address = exec("ssh -G test-service-devices |".
                     " awk '/^hostname / { print $2 }'");
            }

            if (preg_match("/(?:[0-9]{1,3}\.){3}[0-9]{1,3}/", $address) === 0) {
                throw new \Exception(
                    'Couldn\'t get an IP address for the given host. Aborting.'
                );
            }

            $deployKey = env('DEPLOY_KEY', null);

            if (is_null($deployKey)) {
                $this->c->error("`DEPLOY_KEY` is not defined, skipping.");
                return false;
            }

            $deployPath = env('REMOTE_WORKTREE', null);

            if (is_null($deployPath)) {
                $this->c->error(
                    "`REMOTE_WORKTREE` in {$status['project']}".
                    " is not defined, skipping."
                );
                return false;
            }

            $gitDir = env('REMOTE_GITDIR');

            if (is_null($gitDir)) {
                $this->c->error(
                    "`REMOTE_GITDIR` in {$status['project']}".
                    " is not defined, skipping."
                );
                return false;
            }

            $connections[$remoteName] = [
                'host'      => $address,
                'username'  => env('DEPLOY_USERNAME', 'web'),
                'password'  => '',
                'key'       => $deployKey,
                'keyphrase' => '',
                'root'      => $deployPath,
            ];

            config(['remote' => ['connections' => $connections]]);
        }
    }
}
