<?php

namespace App\Console\Commands\Bin;

use SSH;
use App\Console\Commands\Bin\GitManager;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

trait DeployerDeployTrait
{
    protected $dbCredentials;

    protected $dbBackup;

    protected $dbMigrations = false;

    protected function checkRemoteDeployKey()
    {
        $host     = config("remote.connections.{$this->git->remote}.host");
        $hostname = config("remote.connections.{$this->git->remote}.hostname");
        $user     = config("remote.connections.{$this->git->remote}.username");

        if ($host == "" || is_null($host)) {
            $this->c->outError(
                "Couldn't find a deploy key on {$hostname} ({$host}). ".
                "You may not be able to update the remote repo or its".
                " submodules."
            );
            if (!$this->c->confirm("Continue anyway?")) {
                exit;
            }
        }

        // Check for GitHub deploy key
        $key = str_replace("-", "_", $hostname);
        $this->c->out(
            "Checking for deploy key {$key} on {$host}",
            'info',
            "\n . "
        );

        $this->session->run(["ls -la ~/.ssh/{$key}"], function ($a) {
            $a;
        });

        if ($this->session->status() > 0) {
            if ($this->c->confirm(
                "Remote [<cyan>{$host}</cyan>] has no deploy key.  Create one?"
            )) {
                $this->session->run([
                    "ssh-keygen -t rsa -b 4096 -C ".
                    "\"software@revolverobotics.com\" -f ~/.ssh/{$key} -N ''"
                ], function ($lines) {
                    $this->c->out($lines);
                });

                $this->c->out('Generated public key:');

                $this->session->run([
                    "cat ~/.ssh/{$key}.pub"
                ], function ($lines) {
                    $this->c->out($lines);
                });

                $this->c->out('Adding key to ~/.ssh/config...');

                $this->session->run(["
                    touch ~/.ssh/config
                    cat > ~/.ssh/config <<EOF
Host github.com
  User                   git
  StrictHostKeyChecking  no
  IdentityFile           /home/{$user}/.ssh/{$key}
EOF
                    chmod 600 ~/.ssh/config
                "]);

                $this->c->out("\nContents of ~/.ssh/config:");

                $this->session->run(["cat ~/.ssh/config"], function ($lines) {
                    $this->c->out($lines);
                });

                if (!$this->c->confirm(
                    "Please copy and paste the public key into the repo's".
                    " allowed deploy keys before continuing.  Also verify".
                    " that ~/.ssh/config is correct."
                )) {
                    exit;
                }
            } else {
                if (!$this->c->confirm(
                    "Continue push without remote deploy key?"
                )) {
                    exit;
                }
            }
        } else {
            $this->c->out(
                'Deploy key for pulling from origin found.',
                'line',
                ' ✓ '
            );
        }

        return true;
    }

    protected function checkRepoAndWorkTree()
    {
        $host     = config("remote.connections.{$this->git->remote}.host");
        $hostname = config("remote.connections.{$this->git->remote}.hostname");
        $user     = config("remote.connections.{$this->git->remote}.username");

        // Check for git repo
        $this->c->out(
            "Checking for bare repo at {$host}:".env('REMOTE_GITDIR'),
            'info',
            "\n . "
        );

        $this->session->run(['ls -la '.env('REMOTE_GITDIR')], function ($a) {
            $a;
        });

        if ($this->session->status() > 0) {
            $this->c->outError("No bare repo found on remote {$host}");
            if ($this->c->confirm('Create one?')) {
                $this->session->run([
                    'mkdir '.env('REMOTE_GITDIR'),
                    'cd '.env('REMOTE_GITDIR'),
                    'git init --bare',
                ], function ($lines) {
                    $this->c->out($lines);
                });
            }
        } else {
            $this->c->out('Bare repo OK.', 'line', ' ✓ ');
        }

        // Check for work tree
        $this->c->out(
            "Checking for work tree at {$host}:".env('REMOTE_WORKTREE'),
            'info',
            "\n . "
        );

        $this->session->run(['ls -la '.env('REMOTE_WORKTREE')], function ($a) {
            $a;
        });

        if ($this->session->status() > 0) {
            $this->c->outError("No work tree found on remote {$host}");
            if ($this->c->confirm('Create it?')) {
                $this->session->run([
                    'mkdir -p '.env('REMOTE_WORKTREE'),
                ], function ($lines) {
                    $this->c->out($lines);
                });
            }
        } else {
            $this->c->out('Work tree OK.', 'line', ' ✓ ');
        }

        $this->c->out(
            'Removing any post-deploy hooks, this '.
            'script handles post-deploy actions',
            'info',
            "\n . "
        );

        $this->session->run(['rm '.env('REMOTE_GITDIR').'/hooks/*'], function ($a) {
            $a;
        });

        $this->c->out('Done.', 'line', ' ✓ ');

        return true;
    }

    protected function checkEnvFiles()
    {
        $this->c->out(
            "Beginning server deployment on ".
            "[<cyan>{$this->git->remote}</cyan>]...\n",
            'comment',
            "\n\n "
        );

        if (!$this->checkEnvFile('.env')) {
            return false;
        }

        if (!$this->checkEnvFile('.env.testing')) {
            return false;
        }

        return true;
    }

    protected function checkEnvFile($which)
    {
        $dir = str_replace(
            "\\ ",
            " ",
            "{$this->c->projectRoot}/{$this->project}"
        );

        $this->c->out(
            "Checking <white>{$which}</white> file ".
                "diff between local & remote...",
            'info',
            ' . '
        );

        // First check for file existence:
        $this->session
            ->run(['ls -la '.env('REMOTE_WORKTREE').'/'.$which], function ($a) {
                $a;
            });

        if ($this->session->status() > 0) {
            $this->c->outError(
                "Couldn't find {$which} on remote: {$this->git->remote}"
            );
            if (!$this->c->confirm('Continue?')) {
                return false;
            }
        }

        // Now check that files have matching variables:
        $localVars = [];
        $remoteVars = [];

        $localProcess = new Process('cat '.$which);
        $localProcess->setWorkingDirectory($dir);
        $localProcess->run(function ($type, $buffer) use (&$localVars) {
            $lines = explode("\n", $buffer);
            foreach ($lines as $line) {
                $splitLine = explode("=", $line);
                if (count($splitLine) > 1) {
                    array_push($localVars, $splitLine[0]);
                }
            }
        });

        $this->session->run(
            [
                'cd '.env('REMOTE_WORKTREE'),
                'cat '.$which
            ],
            function ($contents) use (&$remoteVars) {
                $lines = explode("\n", $contents);
                foreach ($lines as $line) {
                    $splitLine = explode("=", $line);
                    if (count($splitLine) > 1) {
                        array_push($remoteVars, $splitLine[0]);
                    }
                }
            }
        );


        $diff = array_diff($localVars, $remoteVars);

        if (count($diff) > 0) {
            $tabulated = [];

            if (!function_exists('App\Console\Commands\Bin\addToTabulated')) {
                function addToTabulated($array, &$tabulated, $index, $max)
                {
                    for ($i = 0; $i < $max; $i++) {
                        if (!isset($array[$i])) {
                            $array[$i] = '';
                        }
                    }

                    foreach ($array as $key => $value) {
                        if (!isset($tabulated[$key])) {
                            $tabulated[$key] = [];
                        }
                        $tabulated[$key][$index] = $value;
                    }
                }

                $max = max([count($localVars), count($remoteVars)]);
                addToTabulated($localVars, $tabulated, 0, $max);
                addToTabulated($remoteVars, $tabulated, 1, $max);
                addToTabulated($diff, $tabulated, 2, $max);

                $this->c->table(
                    ["Local {$which} vars", "Remote {$which} vars", "Diff"],
                    $tabulated
                );

                if (!$this->c->confirm(
                    "Local {$which} doesn't match remote. Continue?",
                    false
                )) {
                    return false;
                }
            }
        }

        $this->c->out(
            "{$which} match (or skip) confirmed by user\n",
            'line',
            ' ✓ '
        );

        return true;
    }

    protected function putIntoMaintenanceMode()
    {
        $this->c->out(
            'Placing remote app into maintenance mode...',
            'info',
            "\n . "
        );

        // First check for file existence:
        $this->session
            ->run(['cd '.env('REMOTE_WORKTREE'), 'php artisan down'], function ($a) {
                $a;
            });

        if ($this->session->status() > 0) {
            $this->c->outError('Couldn\'t put remote app into '.
                'maintenance mode.');
            if (!$this->c->confirm('Continue with push?')) {
                return false;
            }
        } else {
            $this->c->out('App placed into maintenance mode.', 'line', ' ✓ ');
        }

        return true;
    }

    protected function runDeployCommands()
    {
        $this->verifyCommitAndUpdateDependencies();

        $this->checkForMigrations();

        try {
            if ($this->dbMigrations) {
                $this->getDbCredentials();
                $this->backupDatabase();
                $this->runMigrations();
            } else {
                $this->runUnitTests();
            }
        } catch (\Exception $e) {
            $this->runRollbackCommands();

            try {
                $this->runUnitTests();
            } catch (\Exception $e) {
                $this->c->outError('Unit tests failed after performing a '.
                    'rollback.  We might be in some deep doo-doo.');
                return false;
            }
        }

        if ($this->isFlagSet('d')) {
            $this->generateDocumentation();
        }

        return true;
    }

    protected function verifyCommitAndUpdateDependencies()
    {
        $this->c->out(
            "Verifying repo and running post-deploy commands...\n",
            'comment',
            "\n\n "
        );

        $gitPaths = '--git-dir='.env('REMOTE_GITDIR').
                    ' --work-tree='.env('REMOTE_WORKTREE');

        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'echo -e "\n\tResetting work tree to last commit..."',
            'git '.$gitPaths.' reset --hard',
            'echo -e "\n\tChecking out work tree to pushed branch..."',
            'git '.$gitPaths.' checkout '.$this->git->branch,
            'echo -e "\n\tUpdating submodules..."',
            'git '.$gitPaths.' submodule foreach git reset --hard',
            'git '.$gitPaths.' submodule init',
            'git '.$gitPaths.' submodule update',
            'echo -e "\n\tUpdating compuser..."',
            'composer --ansi self-update',
            'composer --ansi update',
            'composer --ansi install',
            'composer --ansi update'
        ];

        $count = 0;

        $this->session->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $this->c->out(trim($line), 'line', "  ");
        });

        $this->c->out('Done.', 'line', "\n ✓ ");

        return true;
    }

    protected function checkForMigrations()
    {
        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan migrate:status',
        ];

        $this->c->out('Checking for migrations...', 'info', "\n . ");

        $migrationStatus = "";


        $this->session->run($commandArray, function ($line) use (
            &$migrationStatus
        ) {
            $migrationStatus .= $line;
        });

        $this->c->out($migrationStatus, 'line', "\n");

        $migrationStatus = explode("\n", $migrationStatus);

        foreach ($migrationStatus as $migration) {
            $status = substr($migration, 0, 8);
            if (strpos($status, 'N') !== false) {
                $this->dbMigrations = true;
            }
        }

        if ($this->dbMigrations) {
            $this->c->out('Pending migrations found.', 'comment', ' ✓ ');
        } else {
            $this->c->out('No pending migrations found.', 'comment', ' ✓ ');
        }

        return true;
    }

    protected function runUnitTests()
    {
        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'vendor/phpunit/phpunit/phpunit --no-coverage'
        ];

        $this->c->out("Running unit tests...\n", 'comment', "\n\n ");

        $this->session->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);
            // $this->c->out('Backing up database...', 'info', "\n . ");

            $this->c->out($line, 'line', "\n");

            if (strpos($line, 'FAILURES!') !== false) {
                $this->c->outError('Unit tests failed.');
                return false;
            }
        });

        $this->c->out('Done.', 'line', ' ✓ ');
    }

    protected function getDbCredentials()
    {
        $dbCredentials = [
            'DB_HOST'     => null,
            'DB_DATABASE' => null,
            'DB_USERNAME' => null,
            'DB_PASSWORD' => null
        ];

        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'cat .env'
        ];

        $this->session->run($commandArray, function ($line) use (
            &$dbCredentials
        ) {
            $line = rtrim($line);

            $this->c->out(
                'Fetching DB credentials for backup',
                'info',
                "\n . "
            );

            $vars = explode("\n", $line);

            foreach ($vars as $var) {
                $env = explode('=', $var);
                if (array_key_exists($env[0], $dbCredentials)) {
                    $dbCredentials[$env[0]] = $env[1];
                }
            }

            foreach ($dbCredentials as $env) {
                if (is_null($env)) {
                    $this->c->out('Couldn\'t get all SQL '.
                        'credentials. Check remote .env file.', 'error');
                    throw new \Exception('Error.');
                }
            }

            $this->c->out('Credentials obtained.', 'line', ' ✓ ');
        });

        $this->dbCredentials = $dbCredentials;
    }

    protected function backupDatabase()
    {
        $dbCredentials = $this->dbCredentials;

        $this->dbBackup = $dbCredentials['DB_DATABASE'].
            '_'.$this->pushTime.'.sql';

        $commandArray = [
            "mysqldump -u {$dbCredentials['DB_USERNAME']} ".
            "--password={$dbCredentials['DB_PASSWORD']} ".
            "-h {$dbCredentials['DB_HOST']} ".
            "{$dbCredentials['DB_DATABASE']} ".
            "> /var/tmp/{$this->dbBackup}",
            'ls -l /var/tmp/ | grep "\.sql"'
        ];

        $this->c->out('Backing up database...', 'info', "\n . ");

        $count = 0;

        $this->session->run($commandArray, function ($line) use (
            &$count
        ) {
            $line = rtrim($line);
            // $this->c->out($line, 'line', "\n");
            $count++;
            if (strpos($line, $this->dbBackup) !== false) {
                $this->c->out(
                    'Backup verified and is located at: /var/tmp/'.$this->dbBackup,
                    'line',
                    ' ✓ '
                );
            } else {
                $this->c->outError(
                    'Backup couldn\'t be found at /var/tmp/'.$this->dbBackup
                );

                throw new \Exception(
                    'Backup could not be at /var/tmp/'.$this->dbBackup
                );
            }
        });

        if ($count < 1) {
            $this->c->outError('No output from backup. It may have failed.');
        }

        $this->scpBackup();
    }

    protected function scpBackup()
    {
        $this->c->out('Downloading backup to local machine...', 'info', "\n . ");

        $server = strtoupper($this->git->remote);

        passthru(
            'scp -i '.env('DEPLOY_KEY').
            ' '.env('DEPLOY_USER').'@'.env($server.'_HOST').
            ':/var/tmp/'.$this->dbBackup.' /var/tmp/.'
        );

        exec('ls -l /var/tmp | grep "\.sql"', $localBackupList);
        // $this->c->out($localBackupList, 'line', "\n");

        $backupFound = false;

        foreach ($localBackupList as $line) {
            if (strpos($line, $this->dbBackup) !== false) {
                $backupFound = true;
            }
        }

        if ($backupFound) {
            $this->c->out(
                'Backup verified and is located at: /var/tmp/'.$this->dbBackup,
                'line',
                ' ✓ '
            );
        } else {
            $this->c->outError(
                'Backup couldn\'t be found at /var/tmp/'.$this->dbBackup
            );

            throw new \Exception(
                'Backup could not be at /var/tmp/'.$this->dbBackup
            );
        }
    }

    protected function runMigrations()
    {
        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan migrate --force'
        ];

        if (!$this->c->confirm('Do you want to run the pending migrations?')) {
            return false;
        }

        $count = 0;

        $this->c->out('Running database migrations...', 'info', "\n . ");

        try {
            $this->session->run(
                $commandArray,
                function ($line) use (&$count) {
                    $this->c->out(trim($line));
                    if (strpos($line, 'SQLSTATE') !== false) {
                        throw new \Exception('Failure.');
                    }
                }
            );
        } catch (\Exception $e) {
            $this->c->outError('Exceptions found when running migrations.');
            exit;
        }

        return true;
    }

    protected function generateDocumentation()
    {
        $this->c->out('Generating API Documentation...', 'info', "\n . ");

        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan docs:generate'
        ];

        $this->session->run($commandArray, function ($a) {
            $a;
        });

        $this->c->out('Done.', 'line', "\n ✓ ");

        return true;
    }

    protected function takeOutOfMaintenanceMode()
    {
        $this->c->outputSeparator();

        if (!$this->c->confirm('Do you want to make the app live?')) {
            return false;
        }

        $this->c->out(
            'Taking app out of maintenance mode...',
            'comment'
        );
        $this->c->out('');

        $commandArray = [
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan up',
        ];

        $this->session->run($commandArray, function ($a) {
            $a;
        });

        if ($this->session->status() > 0) {
            $this->c->outError('Couldn\'t take remote app out '.
                'of maintenance mode');
        } else {
            $this->c->out('Application is now live.', 'line', ' ✓ ');
        }

        return true;
    }
}
