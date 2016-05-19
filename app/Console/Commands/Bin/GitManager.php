<?php

namespace App\Console\Commands\Bin;

class GitManager
{
    public $remote;

    public $tag;

    public $branch;

    public $directory;

    public $workTree;

    public $status;

    public $command;

    public $outputBuffer;

    public $amend = false;

    public function exec($option = 'CLEAR_PREVIOUS_OUTPUT')
    {
        if (isset($this->directory)) {
            $this->addFlag('--git-dir');
        }

        if (isset($this->workTree)) {
            $this->addFlag('--work-tree');
        }

        if ($option == 'CLEAR_PREVIOUS_OUTPUT') {
            $this->clearOutputBuffer();
        }
// echo "\nrunning: `".$this->command."`\n";
        exec($this->command, $this->outputBuffer);

        return $this->outputBuffer;
    }

    public function setRemote($remote)
    {
        $this->remote = $remote;
    }

    public function setBranch($branch)
    {
        $this->branch = $branch;
    }

    public function setDirectory($dir)
    {
        $this->directory = $dir;
    }

    public function setWorkTree($dir)
    {
        $this->workTree = $dir;
    }

    public function getStatus()
    {
        $this->command = 'git status --porcelain';

        return $this->exec();
    }

    public function setStatus($status)
    {
        $this->status = $status;
    }

    public function getCurrentBranch()
    {
        $this->command = 'git rev-parse --abbrev-ref HEAD';

        return $this->exec()[0];
    }

    public function getBranches()
    {
        $this->command = 'git branch';

        return $this->exec();
    }

    public function getRemotes()
    {
        $this->command = 'git remote -v';

        return $this->exec();
    }

    public function getLastCommit()
    {
        $this->command = 'git show --name-status';

        return $this->exec();
    }

    public function getCurrentCommitHash()
    {
        $this->command = 'git rev-parse --verify HEAD';

        return $this->exec()[0];
    }

    public function checkout($branch, $flag = null)
    {
        $this->command = 'git checkout '.$branch;

        if (!is_null($flag)) {
            $this->addFlag($flag);
        }

        return $this->exec();
    }

    public function addAll()
    {
        $this->command = 'git add --all';

        return $this->exec();
    }

    public function commit($message, $flag = null)
    {
        $this->command = 'git commit -am "'.$message.'"';

        if (!is_null($flag)) {
            $this->addFlag($flag);
        }

        return $this->exec();
    }

    public function getTags()
    {
        $this->command = 'git tag';

        return $this->exec();
    }

    public function setTag($tag)
    {
        $this->command = 'git tag '.$tag;

        return $this->exec();
    }

    public function setCommand($command)
    {
        $this->command = $command;
    }

    public function push($flag = null, $flag2 = null)
    {
        if (is_null($this->remote) || is_null($this->branch)) {
            throw new \Exception('Both gitRemote and gitBranch must be set '.
                'in order to execute a `git push.`');
        }

        $this->command = 'git push '.$this->remote.' '.$this->branch;

        if (!is_null($flag)) {
            $this->addFlag($flag);
        }

        if (!is_null($flag2)) {
            $this->addFlag($flag2);
        }

        return $this->exec();
    }

    public function pushWithKey($key, $flag = null, $flag2 = null)
    {
        if (is_null($this->remote) || is_null($this->branch)) {
            throw new \Exception('Both remote and branch must be set '.
                'to `git push.`');
        }

        $this->command = 'git push '.$this->remote.' '.$this->branch;

        $this->addDeployKey($key);

        if (!is_null($flag)) {
            $this->addFlag($flag);
        }

        if (!is_null($flag2)) {
            $this->addFlag($flag2);
        }

        return $this->exec();
    }

    public function updateCurrentTag($tag)
    {
        $this->command = 'git push '.$this->remote.' :refs/tags/'.$tag;

        if ($this->remote != 'origin') {
            if ($this->remote == 'jenkins') {
                $this->addDeployKey('jenkins');
            } else {
                $this->addDeployKey('aws');
            }
        }

        $this->exec();

        $this->command = 'git tag -f '.$tag;

        $this->exec();
    }

    public function addFlag($flag)
    {
        switch ($flag) {
            case '-f':
                $lookup = '/git\s\w*/';
                $insert = '-f';
                break;

            case '--amend':
                $lookup = '/$/';
                $insert = '--amend';
                break;

            case '--git-dir':
                $lookup = '/git/';
                if (is_null($this->directory)) {
                    return;
                }

                $insert = '--git-dir='.$this->directory;
                break;

            case '--work-tree':
                $lookup = '/git/';
                if (is_null($this->workTree)) {
                    return;
                }

                $insert = '--work-tree='.$this->workTree;
                break;

            case '--tags':
                $lookup = '/$/';
                $insert = '--tags';
                break;

            default:
                throw new \Exception("The flag provided is not accepted.");
        }

        preg_match(
            $lookup,
            $this->command,
            $insertPosition,
            PREG_OFFSET_CAPTURE
        );

        $this->command = substr_replace(
            $this->command,
            ' '.$insert,
            $insertPosition[0][1] + strlen($insertPosition[0][0]),
            0
        );
    }

    public function addDeployKey($type = 'aws')
    {
        $lookup = '/^/';

        if ($type == 'aws') {
            $key = env('DEPLOY_KEY', null);
            $insert = 'export GIT_SSH=~/bin/ssh-git && PKEY='.$key.' ';
        } elseif ($type == 'jenkins') {
            $key = env('JENKINS_KEY', null);
            $insert = 'export GIT_SSH=~/bin/ssh-git-jenkins && PKEY='.$key.
                ' ';
        }

        preg_match(
            $lookup,
            $this->command,
            $insertPosition,
            PREG_OFFSET_CAPTURE
        );

        $this->command = substr_replace(
            $this->command,
            ' '.$insert,
            $insertPosition[0][1] + strlen($insertPosition[0][0]),
            0
        );
    }

    protected function clearOutputBuffer()
    {
        $this->outputBuffer = [];
    }
}
