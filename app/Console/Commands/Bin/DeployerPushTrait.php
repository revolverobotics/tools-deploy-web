<?php

namespace App\Console\Commands\Bin;

use SSH;
use Dotenv\Dotenv;
use App\Console\Commands\Bin\DeployerDeployTrait;
use App\Console\Commands\Bin\DeployerRollbackTrait;

trait DeployerPushTrait
{
    use DeployerDeployTrait, DeployerRollbackTrait;

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
                $this->c->outError(
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
        foreach ($this->git->getRemotes() as $line) {
            $remote = explode("\t", explode(":", $line)[0]);
            $remoteName = $remote[0];
            $address = $remote[1];

            if (!str_contains($line, "(push)") || $remoteName == 'origin') {
                continue;
            }

            // Check if address is IP or Hostname from ~/.ssh/config
            if (preg_match("/(?:[0-9]{1,3}\.){3}[0-9]{1,3}/", $address) === 0) {
                $address = exec("ssh -G ".$remote[1]." |".
                     " awk '/^hostname / { print $2 }'");
            }

            if (preg_match("/(?:[0-9]{1,3}\.){3}[0-9]{1,3}/", $address) === 0) {
                $this->c->outError('Couldn\'t get an IP address'.
                                ' for the given host. Aborting.');
                return false;
            }

            $deployKey = env('DEPLOY_KEY', null);

            if (is_null($deployKey)) {
                $this->c->outError("`DEPLOY_KEY` is not defined, skipping.");
                return false;
            }

            $deployPath = env('REMOTE_WORKTREE', null);

            if (is_null($deployPath)) {
                $this->c->outError(
                    "`REMOTE_WORKTREE` in {$status['project']}".
                    " is not defined, skipping."
                );
                return false;
            }

            $gitDir = env('REMOTE_GITDIR');

            if (is_null($gitDir)) {
                $this->c->outError(
                    "`REMOTE_GITDIR` in {$status['project']}".
                    " is not defined, skipping."
                );
                return false;
            }

            $connections[$remoteName] = [
                'host'      => $address,
                'hostname' => $remote[1],
                'username'  => env('DEPLOY_USER', 'web'),
                'password'  => '',
                'key'       => $deployKey,
                'keyphrase' => '',
                'root'      => $deployPath,
                'timeout'   => 600 // need long timeout
            ];

            config(['remote' => ['connections' => $connections]]);
        }
        return true;
    }

    protected function chooseRemote()
    {
        // Discover which remotes we have in the repo
            $this->c->out('Remotes in this repo:', 'comment', ' ');

            $remotes = [];

        foreach ($this->git->getRemotes() as $remote) {
            $firstSpace = strpos($remote, "\t");
            $remoteName = substr($remote, 0, $firstSpace);

            if ($remoteName == 'upstream') {
                continue;
            }

            if (!in_array($remoteName, $remotes)) {
                array_push($remotes, $remoteName);
                $this->c->out($remoteName, 'line', "\t");
            }
        }

        $this->git->setRemote($this->c->anticipate(
            'Push to which remote?',
            array_merge(['<abort>'], $remotes),
            '<abort>'
        ));

        if ($this->git->remote == '<abort>') {
            return false;
        }

        if ($this->git->remote != 'origin') {
            try {
                $this->c->out("Establishing SSH connection with remote...\n");
                $this->session = SSH::into($this->git->remote);
            } catch (\Exception $e) {
                return false;
            }
        }

        return true;
    }

    protected function makeCommit()
    {
        if (is_null($this->git->status)) {
            $this->git->setStatus($this->git->getStatus());
        }

        if (count($this->git->status) < 1) {
            $this->c->out('Working branch is clean.', 'line', "\n ");
        } else {
            $this->c->out('Your last commit:', 'line', "\n ");
            $this->c->out('');
            $this->c->out($this->git->getLastCommit(), 'info', "\t");
            $this->c->out('');
            $this->c->out('New Commit:');
            $this->c->out('');
            $this->c->out($this->git->status, 'line', "\t");

            $untrackedFiles = false;
            $search_text = '??';
            array_filter($this->git->status, function ($el) use (
                $search_text,
                &$untrackedFiles
            ) {
                if (strpos($el, $search_text) !== false) {
                    $untrackedFiles = true;
                }
            });

            if ($untrackedFiles) {
                if (!$this->isFlagSet('l')) {
                    $this->git->addAll();
                } else {
                    $this->c->out(
                        'Leaving behind untracked files...',
                        'info',
                        ' '
                    );
                }
            }

            if ($this->isFlagSet('a')) {
                $this->git->amend = true;

                if ($this->c->confirm('Are you sure you want to amend the last '.
                    'commit? (potentially destructive) [y|N]')
                ) {
                    $this->git->commit('', '--amend --no-edit');
                } else {
                    $this->c->outError('User aborted commit on --amend');
                    return false;
                }
            } else {
                $commitMessage = $this->c->ask('Commit Message', '<abort>');

                if ($commitMessage == '<abort>') {
                    return false;
                }

                $this->git->commit($commitMessage);
            }
        }

        return true;
    }

    protected function pushToRemote()
    {
        $this->c->outputSeparator();

        $choice = $this->c->ask('Which branch?', '<current>');

        if ($choice == '<current>') {
            $choice = $this->git->getCurrentBranch();
        }

        $this->git->setBranch($choice);

        if ($this->git->remote == 'production') {
            $warning = 'ARE YOU SURE YOU WANT TO PUSH TO PRODUCTION??';

            if ($this->c->confirm($warning)) {
                $this->c->out(
                    'Pushing branch [<cyan>'.$this->git->branch.'</cyan>] to '.
                    'production server, hold on to your butts...',
                    'info'
                );
            } else {
                $this->c->outError('Push aborted.');
                return false;
            }
        } else {
            $this->c->out(
                'Pushing branch [<cyan>'.$this->git->branch.
                '</cyan>] to remote [<cyan>'.$this->git->remote.
                '</cyan>]',
                'comment'
            );
        }

        if ($this->isRemoteServer()) {
            if (!$this->checkRemoteDeployKey()) {
                return false;
            }
            if (!$this->checkRepoAndWorkTree()) {
                return false;
            }
            if (!$this->checkEnvFiles()) {
                return false;
            }

            $this->getRollbackCommit();

            if (!$this->putIntoMaintenanceMode()) {
                return false;
            }
        }

        $this->c->out('');

        $this->git->command = 'git push '.$this->git->remote.' '.
            $this->git->branch;

        if ($this->isFlagSet('f') || $this->git->amend) {
            $this->git->addFlag('-f');
        }

        if ($this->isRemoteServer()) {
            $this->git->addDeployKey('aws');
        }

        // if ( $this->c->argument('version') != 'none') {
        //     $this->pushTags();
        // }

        $this->c->out(
            "Pushing repo to ".
            "[<cyan>{$this->git->remote}</cyan>]...\n",
            'comment',
            "\n\n "
        );

        $this->git->exec();

        if ($this->isRemoteServer()) {
            $this->runDeployCommands();
            $this->takeOutOfMaintenanceMode();
        }

        $this->c->outputSeparator();

        $this->c->out('Push completed.', 'comment');
        $this->c->out('');

        return true;
    }

    protected function pushTags()
    {
        if ($this->newVersion != false
            && $this->newVersion != $this->currentVersion
        ) {
            $this->git->setTag($this->newVersion);

            $this->c->out(
                'Tagging with new version: '.$this->newVersion,
                'line'
            );
        } else {
            $this->c->out(
                'Updating current version tag: '.$this->currentVersion,
                'line'
            );
            $this->c->out('');
            $this->git->updateCurrentTag($this->currentVersion);
            $this->c->out('');
        }

        $this->git->addFlag('--tags');
    }

    protected function isOrigin()
    {
        if ($this->git->remote == 'origin') {
            return true;
        }

        return false;
    }

    protected function isRemoteServer()
    {
        if ($this->isOrigin()) {
            return false;
        }

        return true;
    }
}
