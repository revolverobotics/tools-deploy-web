<?php

namespace App\Console\Commands\Bin;

use SSH;

trait DeployerRollbackTrait
{
    protected $rollbackCommit;

    protected function runRollbackCommands()
    {
        $this->c->ask('The deployment encountered errors. '.
            'We are going to attempt a rollback. Type `ok` to continue');

        $this->c->out(
            'Performing rollback on ['.$this->git->remote.'].'.
                ' Better luck next time...',
            'comment'
        );

        if ($this->dbMigrations && !is_null($this->dbBackup)) {
            $this->restoreDatabase();
            $message = "\n Errors occurred when pushing code to server. ".
                "\n\n Database was restored from backup and the remote ".
                "\n repo was rolled back to the previous commit.\n";
        } else {
            $message = "\n Errors occurred when pushing code to server. ".
                "\n\n Repo was rolled back to the previous commit.\n";
        }

        $this->rollbackRepo();

        $this->verifyRolledBackRepo();
    }

    protected function getRollbackCommit()
    {
        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            'git --git-dir='.env('REMOTE_GITDIR').
                ' --work-tree='.env('REMOTE_WORKTREE').
                ' rev-parse --verify HEAD',
        ];

        SSH::into($this->git->remote)->run($commandArray, function ($line) {
            $this->c->out(
                'Reading current commit in case of rollback...',
                'info',
                " . "
            );

            $this->rollbackCommit = trim((string)$line);

            $this->c->out(
                $this->rollbackCommit.' obtained.',
                'line',
                ' ✓ '
            );
        });
    }

    protected function restoreDatabase()
    {
        if (is_null($this->dbCredentials)) {
            throw new \Exception('No database credentials found.');
        }

        if (is_null($this->dbBackup)) {
            throw new \Exception('No database backup found.');
        }

        $dbCredentials = $this->dbCredentials;

        $commandArray = [
            'export TERM=vt100',

            // Drop database
            "mysql -u {$dbCredentials['DB_USERNAME']} ".
            "--password={$dbCredentials['DB_PASSWORD']} ".
            "-h {$dbCredentials['DB_HOST']} ".
            "-e 'DROP DATABASE {$dbCredentials['DB_DATABASE']};'",

            // Re-create database
            "mysql -u {$dbCredentials['DB_USERNAME']} ".
            "--password={$dbCredentials['DB_PASSWORD']} ".
            "-h {$dbCredentials['DB_HOST']} ".
            "-e 'CREATE DATABASE {$dbCredentials['DB_DATABASE']};'",

            // Restore from backup
            "mysql -u {$dbCredentials['DB_USERNAME']} ".
            "--password={$dbCredentials['DB_PASSWORD']} ".
            "-h {$dbCredentials['DB_HOST']} ".
            "{$dbCredentials['DB_DATABASE']} ".
            "< /var/tmp/{$this->dbBackup} --debug-check",
        ];

        $this->c->out('Restoring database from backup...', 'info', "\n . ");

        SSH::into($this->git->remote)->run($commandArray, function ($line) {
            $this->c->out($line);
        });

        $this->c->out('Done.', 'line', ' ✓ ');
    }

    protected function rollbackRepo()
    {
        $gitCommand = 'git --git-dir='.env('REMOTE_GITDIR').
            ' --work-tree='.env('REMOTE_WORKTREE');

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            $gitCommand.' reset '.$this->rollbackCommit.' --hard',
            $gitCommand.' submodule update --init --recursive',
            $gitCommand.' clean -fd'
        ];

        $this->c->out('Reverting to previous commit...', 'info', "\n . ");
        $this->c->out('');

        SSH::into($this->git->remote)->run($commandArray, function ($line) {
            $this->c->out($line);
        });

        $this->c->out('Done.', 'line', "\n ✓ ");
    }

    protected function verifyRolledBackRepo()
    {
        $gitCommand = 'git --git-dir='.env('REMOTE_GITDIR').
            ' --work-tree='.env('REMOTE_WORKTREE');

        $commandArray = [
            'export TERM=vt100',
            'cd '.env('REMOTE_WORKTREE'),
            $gitCommand.' rev-parse --verify HEAD'
        ];

        $this->c->out('Verifying rolled-back repo...', 'info', "\n . ");

        SSH::into($this->git->remote)->run($commandArray, function ($line) {
            $line = trim((string)$line);

            if (strpos($line, $this->rollbackCommit) !== false) {
                $this->c->out('Rollback verified.', 'line', ' ✓ ');
            } else {
                $this->c->outError('Rollback could not be verified');

                exit;
            }
        });
    }
}
