<?php

namespace App\Console\Commands\Bin;

use SSH;

use App\Console\Commands\Bin\DeployerDeployTrait;
use App\Console\Commands\Bin\DeployerRollbackTrait;

trait DeployerPushTrait
{
    use DeployerDeployTrait, DeployerRollbackTrait;

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
            exit;
        }
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
                    $this->git->commit('', '--amend');
                } else {
                    $this->c->error('User aborted commit on --amend');
                    exit;
                }
            } else {
                $commitMessage = $this->c->ask('Commit Message', '<abort>');

                if ($commitMessage == '<abort>') {
                    exit;
                }

                $this->git->commit($commitMessage);
            }
        }
    }

    protected function pushToRemote()
    {
        $this->c->outputSeparator();

        $choice = $this->c->ask('Which branch?', '<current>');

        if ($choice == '<current>') {
            $this->git->setBranch($this->git->getCurrentBranch());
        }

        if ($this->git->remote == 'production') {
            $warning = 'ARE YOU SURE YOU WANT TO PUSH TO PRODUCTION??';

            if ($this->c->confirm($warning)) {
                $this->c->out(
                    'Pushing branch [<cyan>'.$this->git->branch.'</cyan>] to '.
                    'production server, hold on to your butts...',
                    'info'
                );
            } else {
                $this->c->error('Push aborted.');
                exit;
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
            $this->checkEnvFiles();
            $this->getRollbackCommit();
            $this->putIntoMaintenanceMode();
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

        // if ($this->isOrigin() && $this->c->argument('version') != 'none') {
        //     $this->pushTags();
        // }

        $this->git->exec();

        if ($this->isFlagSet('b') && $this->isOrigin()) {
            $this->pushJenkins();
        } elseif ($this->isFlagSet('b') && $this->isRemoteServer()) {
            $this->c->error(
                'Can only build when pushing to origin, skipping.'
            );
        }

        if ($this->isRemoteServer()) {
            $this->runDeployCommands();
            $this->takeOutOfMaintenanceMode();
        }

        $this->c->outputSeparator();

        $this->c->out('Push completed.', 'comment');
        $this->c->out('');
    }

    protected function pushTags()
    {
        if ($this->git->branch != 'master' || !$this->isOrigin()) {
            $this->c->error(
                "Can only tag branch `master`. Skipping version increment."
            );
            $this->c->out('');
            return;
        }

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

    protected function pushJenkins()
    {
        $this->c->out(
            'Pushing to Jenkins server for CI build...',
            'comment',
            "\n "
        );

        // $this->c->out('');

        $this->git->setRemote('jenkins');

        // $this->git->updateCurrentTag($this->currentVersion);

        $this->c->out('');

        $this->git->command = 'git push jenkins '.$this->git->branch;

        // Always force
        $this->git->addFlag('-f');

        $this->git->addDeployKey('jenkins');

        // $this->git->addFlag('--tags');

        $this->git->exec();

        $this->c->out('Repo pushed to Jenkins, check dashboard '.
            'for build status.', 'comment', "\n ");
    }

    protected function isOrigin()
    {
        if ($this->git->remote == 'origin') {
            return true;
        }

        return false;
    }

    protected function isBuildServer()
    {
        if ($this->git->remote == 'jenkins') {
            return true;
        }

        return false;
    }

    protected function isRemoteServer()
    {
        if ($this->isOrigin() || $this->isBuildServer()) {
            return false;
        }

        return true;
    }
}
