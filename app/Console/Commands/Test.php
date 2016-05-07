<?php

namespace App\Console\Commands;

class Test
{
    protected $c;

    public function __construct($parentCommand)
    {
        if ($parentCommand instanceof \Illuminate\Console\Command) {
            $this->c = $parentCommand;
        } else {
            throw new \Exception('Must pass the command $this instance in.');
        }
    }

    public function ok()
    {
        $this->c->line('ok');
    }
}
