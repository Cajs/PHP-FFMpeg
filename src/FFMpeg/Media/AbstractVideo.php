<?php

/*
 * This file is part of PHP-FFmpeg.
 *
 * (c) Alchemy <info@alchemy.fr>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FFMpeg\Media;

use Alchemy\BinaryDriver\Exception\ExecutionFailureException;
use FFMpeg\Exception\InvalidArgumentException;
use FFMpeg\Exception\RuntimeException;
use FFMpeg\Filters\Audio\SimpleFilter;
use FFMpeg\Filters\FilterInterface;
use FFMpeg\Filters\Video\ClipFilter;
use FFMpeg\Filters\Video\VideoFilters;
use FFMpeg\Format\AudioInterface;
use FFMpeg\Format\FormatInterface;
use FFMpeg\Format\ProgressableInterface;
use FFMpeg\Format\VideoInterface;
use Spatie\TemporaryDirectory\TemporaryDirectory;

abstract class AbstractVideo extends Audio
{
    /**
     * FileSystem Manager instance.
     *
     * @var Manager
     */
    protected $fs;

    /**
     * FileSystem Manager ID.
     *
     * @var int
     */
    protected $fsId;

    /**
     * {@inheritDoc}
     *
     * @return VideoFilters
     */
    public function filters()
    {
        return new VideoFilters($this);
    }

    /**
     * {@inheritDoc}
     *
     * @return Video
     */
    public function addFilter(FilterInterface $filter)
    {
        $this->filters->add($filter);

        return $this;
    }

    /**
     * Exports the video in the desired format, applies registered filters.
     *
     * @param string $outputPathfile
     *
     * @return Video
     *
     * @throws RuntimeException
     */
    public function save(FormatInterface $format, $outputPathfile)
    {
        $passes = $this->buildCommand($format, $outputPathfile);

        $failure = null;
        $totalPasses = $format->getPasses();

        foreach ($passes as $pass => $passCommands) {
            try {
                /** add listeners here */
                $listeners = null;

                if ($format instanceof ProgressableInterface) {
                    $filters = clone $this->filters;
                    $duration = 0;

                    // check the filters of the video, and if the video has the ClipFilter then
                    // take the new video duration and send to the
                    // FFMpeg\Format\ProgressListener\AbstractProgressListener class
                    foreach ($filters as $filter) {
                        if ($filter instanceof ClipFilter) {
                            if (null === $filter->getDuration()) {
                                continue;
                            }

                            $duration = $filter->getDuration()->toSeconds();
                            break;
                        }
                    }
                    $listeners = $format->createProgressListener($this, $this->ffprobe, $pass + 1, $totalPasses, $duration);
                }

                $this->driver->command($passCommands, false, $listeners);
            } catch (ExecutionFailureException $e) {
                $failure = $e;
                break;
            }
        }

        $this->fs->delete();

        if (null !== $failure) {
            throw new RuntimeException('Encoding failed', $failure->getCode(), $failure);
        }

        return $this;
    }

    /**
     * NOTE: This method is different to the Audio's one, because Video is using passes.
     * {@inheritDoc}
     */
    public function getFinalCommand(FormatInterface $format, $outputPathfile)
    {
        $finalCommands = [];

        foreach ($this->buildCommand($format, $outputPathfile) as $pass => $passCommands) {
            $finalCommands[] = implode(' ', $passCommands);
        }

        $this->fs->delete();

        return $finalCommands;
    }

    /**
     * **NOTE:** This creates passes instead of a single command!
     *
     * {@inheritDoc}
     *
     * @return string[][]
     */
    protected function buildCommand(FormatInterface $format, $outputPathfile)
    {
        $commands = $this->basePartOfCommand($format);

        $filters = clone $this->filters;
        $filters->add(new SimpleFilter($format->getExtraParams(), 10));

        if ($this->driver->getConfiguration()->has('ffmpeg.threads')) {
            $filters->add(new SimpleFilter(['-threads', $this->driver->getConfiguration()->get('ffmpeg.threads')]));
        }
        if ($format instanceof VideoInterface) {
            if (null !== $format->getVideoCodec()) {
                $filters->add(new SimpleFilter(['-vcodec', $format->getVideoCodec()]));
            }
        }
        if ($format instanceof AudioInterface) {
            if (null !== $format->getAudioCodec()) {
                $filters->add(new SimpleFilter(['-acodec', $format->getAudioCodec()]));
            }
        }

        foreach ($filters as $filter) {
            $commands = array_merge($commands, $filter->apply($this, $format));
        }

        if ($format instanceof VideoInterface) {
            if (0 !== $format->getKiloBitrate()) {
                $commands[] = '-b:v';
                $commands[] = $format->getKiloBitrate().'k';
            }

            $commands[] = '-refs';
            $commands[] = '6';
            $commands[] = '-coder';
            $commands[] = '1';
            $commands[] = '-sc_threshold';
            $commands[] = '40';
            $commands[] = '-flags';
            $commands[] = '+loop';
            $commands[] = '-me_range';
            $commands[] = '16';
            $commands[] = '-subq';
            $commands[] = '7';
            $commands[] = '-i_qfactor';
            $commands[] = '0.71';
            $commands[] = '-qcomp';
            $commands[] = '0.6';
            $commands[] = '-qdiff';
            $commands[] = '4';
            $commands[] = '-trellis';
            $commands[] = '1';
        }

        if ($format instanceof AudioInterface) {
            if (null !== $format->getAudioKiloBitrate()) {
                if (($format->getEnableVbrEncoding() === NULL) || !$format->getEnableVbrEncoding()) {
                    $commands[] = '-b:a';
                    $commands[] = $format->getAudioKiloBitrate() . 'k';
                } else {
                    $commands[] = '-q:a';
                    $commands[] = $format->getVbrEncodingQuality();
                }
            }
            if (null !== $format->getAudioChannels()) {
                $commands[] = '-ac';
                $commands[] = $format->getAudioChannels();
            }
        }

        // If the user passed some additional parameters
        if ($format instanceof VideoInterface) {
            if (null !== $format->getAdditionalParameters()) {
                foreach ($format->getAdditionalParameters() as $additionalParameter) {
                    $commands[] = $additionalParameter;
                }
            }
        }

        // Merge Filters into one command
        $videoFilterVars = $videoFilterProcesses = [];
        for ($i = 0; $i < count($commands); ++$i) {
            $command = $commands[$i];
            if ('-vf' === $command) {
                $commandSplits = explode(';', $commands[$i + 1]);
                if (1 == count($commandSplits)) {
                    $commandSplit = $commandSplits[0];
                    $command = trim($commandSplit);
                    if (preg_match("/^\[in\](.*?)\[out\]$/is", $command, $match)) {
                        $videoFilterProcesses[] = $match[1];
                    } else {
                        $videoFilterProcesses[] = $command;
                    }
                } else {
                    foreach ($commandSplits as $commandSplit) {
                        $command = trim($commandSplit);
                        if (preg_match("/^\[[^\]]+\](.*?)\[[^\]]+\]$/is", $command, $match)) {
                            $videoFilterProcesses[] = $match[1];
                        } else {
                            $videoFilterVars[] = $command;
                        }
                    }
                }
                unset($commands[$i]);
                unset($commands[$i + 1]);
                ++$i;
            }
        }
        $videoFilterCommands = $videoFilterVars;
        $lastInput = 'in';
        foreach ($videoFilterProcesses as $i => $process) {
            $command = '['.$lastInput.']';
            $command .= $process;
            $lastInput = 'p'.$i;
            if ($i === (count($videoFilterProcesses) - 1)) {
                $command .= '[out]';
            } else {
                $command .= '['.$lastInput.']';
            }

            $videoFilterCommands[] = $command;
        }
        $videoFilterCommand = implode(';', $videoFilterCommands);

        if ($videoFilterCommand) {
            $commands[] = '-vf';
            $commands[] = $videoFilterCommand;
        }

        $this->fsId = uniqid('ffmpeg-passes');
        $this->fs = $this->getTemporaryDirectory()->name($this->fsId)->create();
        $passPrefix = $this->fs->path(uniqid('pass-'));
        touch($passPrefix);
        $passes = [];
        $totalPasses = $format->getPasses();

        if (!$totalPasses) {
            throw new InvalidArgumentException('Pass number should be a positive value.');
        }

        for ($i = 1; $i <= $totalPasses; ++$i) {
            $pass = $commands;

            if ($totalPasses > 1) {
                $pass[] = '-pass';
                $pass[] = $i;
                $pass[] = '-passlogfile';
                $pass[] = $passPrefix;
            }

            $pass[] = $outputPathfile;

            $passes[] = $pass;
        }

        return $passes;
    }

    /**
     * Return base part of command.
     *
     * @return array
     */
    protected function basePartOfCommand(FormatInterface $format)
    {
        $commands = ['-y'];

        // If the user passed some initial parameters
        if ($format instanceof VideoInterface) {
            if (null !== $format->getInitialParameters()) {
                foreach ($format->getInitialParameters() as $initialParameter) {
                    $commands[] = $initialParameter;
                }
            }
        }

        $commands[] = '-i';
        $commands[] = $this->pathfile;

        return $commands;
    }
}
