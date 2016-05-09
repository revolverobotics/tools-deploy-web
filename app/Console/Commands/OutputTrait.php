<?php

namespace App\Console\Commands;

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
        $this->out(PHP_EOL.'-----------------------------------------------'
            .PHP_EOL, 'line', '');
    }

    public function clearScreen()
    {
        $this->out("\033[2J");
    }

    protected function clearOutputBuffer()
    {
        $this->outputBuffer = [];
    }

    protected function searchOutput($searchTerm)
    {

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
        $style1 = new OutputFormatterStyle('white', 'cyan', ['bold']);
        $this->output->getFormatter()->setStyle('highlight', $style1);

        $cyan = new OutputFormatterStyle('cyan', null, ['bold']);
        $this->output->getFormatter()->setStyle('cyan', $cyan);

        $styled = $style ? "<$style>$string</$style>" : $string;

        $this->output->writeln($styled, $this->parseVerbosity($verbosity));
    }
}
