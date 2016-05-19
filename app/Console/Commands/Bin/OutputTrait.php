<?php

namespace App\Console\Commands\Bin;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;

trait OutputTrait
{
    protected $outputBuffer;

    protected $logTime;

    protected $logApp;

    protected $logStart = false;

    /*
        line        - Bright text
        info        - Dull text
        comment     - gold text
        question    - blue background text
        error       - red background text
     */
    protected $outputType = ['line', 'info', 'comment', 'question', 'error'];

    public function out($output = null, $outputType = 'line', $indent = ' ')
    {
        if (is_null($output)) {
            return;
        }

        if (is_array($output)) {
            foreach ($output as $line) {
                $this->$outputType($indent.$line);
            }
        } elseif (is_string($output)) {
            $this->$outputType($indent.$output);
        }
    }

    public function outError($line)
    {
        $this->out("\n ERROR: {$line}\n", 'error', "\n");
    }

    public function outWarning($line)
    {
        $this->out("\n WARNING: {$line}\n", 'error');
    }

    public function outSeparator()
    {
        $this->outputSeparator();
    }

    public function outputSeparator()
    {
        $cols = exec('tput cols');
        $separator = str_repeat('-', $cols);
        $this->out("\n{$separator}\n", 'line', '');
    }

    public function clearScreen()
    {
        $this->out("\033[2J");
    }

    protected function clearOutputBuffer()
    {
        $this->outputBuffer = [];
    }

    public function outputHeading($string)
    {
        $cols = exec('tput cols');

        $string = "_ {$string} ";

        $untaggedString = strip_tags($string);

        $len = $cols - strlen($untaggedString);

        if ($len < 0) {
            $len = 0;
        }

        $string .= str_repeat('_', $len);

        $this->out("\n{$string}\n", 'comment');
    }

    /**
     * Write a string as standard output, allow our custom styles
     *
     * @param  string  $string
     * @param  string  $style
     * @param  null|int|string  $verbosity
     * @return void
     */
    public function line($string, $style = null, $verbosity = null)
    {
        $highlight = new OutputFormatterStyle('white', 'cyan', ['bold']);
        $this->output->getFormatter()->setStyle('highlight', $highlight);

        $cyan = new OutputFormatterStyle('cyan', null, ['bold']);
        $this->output->getFormatter()->setStyle('cyan', $cyan);

        $green = new OutputFormatterStyle('green', null, ['bold']);
        $this->output->getFormatter()->setStyle('green', $green);

        $white = new OutputFormatterStyle('white', null, ['bold']);
        $this->output->getFormatter()->setStyle('white', $white);

        $styled = $style ? "<$style>$string</$style>" : $string;

        $this->output->writeln($styled, $this->parseVerbosity($verbosity));
    }
}
