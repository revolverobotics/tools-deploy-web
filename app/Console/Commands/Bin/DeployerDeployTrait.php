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

    protected function checkEnvFiles()
    {
        $this->c->out(
            "Beginning server deployment on ".
            "[<cyan>{$this->git->remote}</cyan>]...",
            'comment',
            "\n "
        );
        $this->c->out('');

        $this->checkEnvFile('.env');
        $this->checkEnvFile('.env.testing');
    }

    protected function checkEnvFile($which)
    {
        $dir =
            str_replace("\\ ", " ", "{$this->c->projectRoot}/{$this->project}");

        $this->c->out(
            "Checking <white>{$which}</white> file ".
                "diff between local & remote...",
            'info',
            ' . '
        );

        // First check for file existence:
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'ls -la '.$which
        ];

        SSH::into($this->git->remote)->run($commandArray, function ($line) {
            if (strpos($line, 'cannot access') !== false) {
                $this->c->outError("Couldn't find {$which} on remote: {$line}");
                throw new \Exception('Aborting.');
            }
        });

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

        SSH::into($this->git->remote)->run(
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
                    exit;
                }
            }
        }

        $this->c->out(
            "{$which} match (or skip) confirmed by user\n",
            'line',
            ' ✓ '
        );
    }

    protected function putIntoMaintenanceMode()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'pwd',
            'php artisan down'
        ];

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);

            switch ($count) {
                case 1:
                    $this->c->out(
                        'Verifying remote directory...',
                        'info',
                        "\n . "
                    );

                    if ($line != env('REMOTE_WORKTREE')) {
                        $this->c->error(
                            'REMOTE_WORKTREE set incorrectly.'
                        );
                        exit;
                    }

                    $this->c->out('Remote directory verified.', 'line', ' ✓ ');
                    break;

                case 2:
                    $this->c->out(
                        'Placing remote app into maintenance mode...',
                        'info',
                        "\n . "
                    );

                    if ($line != 'Application is now in maintenance mode.') {
                        $this->c->error('Couldn\'t put remote app into '.
                            'maintenance mode.');
                        exit;
                    }

                    $this->c->out($line, 'line', ' ✓ ');
                    break;
            }
        });
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
                $this->c->error('Unit tests failed after performing a '.
                    'rollback.  We might be in some deep doo-doo.');
                throw new \Exception('--- RED ALERT ---');
            }
        }

        if ($this->isFlagSet('d')) {
            $this->generateDocumentation();
        }
    }

    protected function verifyCommitAndUpdateDependencies()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'git --git-dir='.env('REMOTE_GITDIR').
                ' --work-tree='.env('REMOTE_WORKTREE').
                ' rev-parse --verify HEAD',
            'composer update'
        ];

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);

            if ($count < 2) {
                $this->c->out('Verifying commit...', 'info', "\n . ");

                if ($line != $this->git->getCurrentCommitHash()) {
                    $this->c->error('Newly-pushed commit hash does not match.');
                    exit;
                }

                $this->c->out('New commit verified.', 'line', ' ✓ ');
                $this->c->out('Updating dependencies...', 'info', "\n . ");
                $this->c->out('');
            } else {
                $this->c->out($line, 'info', "\t");
            }
        });

        $this->c->out('Done.', 'line', "\n ✓ ");
    }

    protected function checkForMigrations()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan migrate:status',
        ];

        $this->c->out('Checking for migrations...', 'info', "\n . ");

        $migrationStatus = "";

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
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
    }

    protected function runUnitTests()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'vendor/phpunit/phpunit/phpunit --no-coverage'
        ];

        $this->c->out('Running unit tests...', 'info', "\n . ");

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);
            // $this->c->out('Backing up database...', 'info', "\n . ");

            $this->c->out($line, 'line', "\n");

            if (strpos($line, 'FAILURES!') !== false) {
                $this->c->error('Unit tests failed.');
                exit;
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
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'cat .env'
        ];

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
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
            'export TERM=vt100',
            "mysqldump -u {$dbCredentials['DB_USERNAME']} ".
            "--password={$dbCredentials['DB_PASSWORD']} ".
            "-h {$dbCredentials['DB_HOST']} ".
            "{$dbCredentials['DB_DATABASE']} ".
            "> /var/tmp/{$this->dbBackup}",
            'ls -l /var/tmp/ | grep "\.sql"'
        ];

        $this->c->out('Backing up database...', 'info', "\n . ");

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
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
                $this->c->error(
                    'Backup couldn\'t be found at /var/tmp/'.$this->dbBackup
                );

                throw new \Exception(
                    'Backup could not be at /var/tmp/'.$this->dbBackup
                );
            }
        });

        if ($count < 1) {
            $this->c->error('No output from backup. It may have failed.');
        }

        $this->scpBackup();
    }

    protected function scpBackup()
    {
        $this->c->out('Downloading backup to local machine...', 'info', "\n . ");

        $server = strtoupper($this->git->remote);

        passthru(
            'scp -i '.env('DEPLOY_KEY').
            ' ec2-user@'.env($server.'_HOST').
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
            $this->c->error(
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
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan migrate --force'
        ];

        $count = 0;

        $this->c->out('Running database migrations...', 'info', "\n . ");

        try {
            SSH::into($this->git->remote)->run(
                $commandArray,
                function ($line) use (&$count) {
                    $this->c->out(trim($line));
                    if (strpos($line, 'SQLSTATE') !== false) {
                        throw new \Exception('Failure.');
                    }
                }
            );
        } catch (\Exception $e) {
            $this->c->error('Exceptions found when running migrations.');
            exit;
        }
    }

    protected function generateDocumentation()
    {
        $this->c->out('Generating API Documentation...', 'info', "\n . ");

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan docs:generate'
        ];

        SSH::into($this->git->remote)->run($commandArray);

        $this->c->out('Done.', 'line', "\n ✓ ");
    }

    protected function takeOutOfMaintenanceMode()
    {
        $this->c->outputSeparator();

        $this->c->out(
            'Taking app out of maintenance mode...',
            'comment'
        );
        $this->c->out('');

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'php artisan up',
        ];

        $count = 0;

        SSH::into($this->git->remote)->run($commandArray, function ($line) use (
            &$count
        ) {
            $count++;
            $line = rtrim($line);

            switch ($count) {
                case 1:
                    $this->c->out(
                        '...',
                        'info',
                        " . "
                    );

                    if ($line != 'Application is now live.') {
                        $this->c->error('Couldn\'t take remote app out '.
                            'of maintenance mode');
                        exit;
                    }

                    $this->c->out($line, 'line', ' ✓ ');
                    break;
            }
        });
    }
}
