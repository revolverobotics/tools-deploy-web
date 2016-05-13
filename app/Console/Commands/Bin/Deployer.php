<?php

namespace App\Console\Commands\Bin;

class Deployer
{
    /**
     * The parent command that uses this class
     */
    protected $c;

    public $remote;

    public $branch;

    public $version;

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }
    }

    public function push()
    {
        $branch = $this->c->
    }

    protected function getBranches()
}
