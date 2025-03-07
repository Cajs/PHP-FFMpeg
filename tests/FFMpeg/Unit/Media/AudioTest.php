<?php

namespace Tests\FFMpeg\Unit\Media;

use FFMpeg\Exception\RuntimeException;
use FFMpeg\Media\Audio;

class AudioTest extends AbstractStreamableTestCase
{
    public function testFiltersReturnsAudioFilters()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();

        $audio = new Audio(__FILE__, $driver, $ffprobe);
        $this->assertInstanceOf('FFMpeg\Filters\Audio\AudioFilters', $audio->filters());
    }

    public function testAddFiltersAddsAFilter()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();

        $filters = $this->getMockBuilder('FFMpeg\Filters\FiltersCollection')
            ->disableOriginalConstructor()
            ->getMock();

        $audio = new Audio(__FILE__, $driver, $ffprobe);
        $audio->setFiltersCollection($filters);

        $filter = $this->getMockBuilder('FFMpeg\Filters\Audio\AudioFilterInterface')->getMock();

        $filters->expects($this->once())
            ->method('add')
            ->with($filter);

        $audio->addFilter($filter);
    }

    public function testAddAVideoFilterThrowsException()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();

        $filters = $this->getMockBuilder('FFMpeg\Filters\FiltersCollection')
            ->disableOriginalConstructor()
            ->getMock();

        $audio = new Audio(__FILE__, $driver, $ffprobe);
        $audio->setFiltersCollection($filters);

        $filter = $this->getMockBuilder('FFMpeg\Filters\Video\VideoFilterInterface')->getMock();

        $filters->expects($this->never())
            ->method('add');

        $this->expectException('\FFMpeg\Exception\InvalidArgumentException');
        $audio->addFilter($filter);
    }

    public function testSaveWithFailure()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();
        $outputPathfile = '/target/file';

        $format = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $format->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue([]));

        $configuration = $this->getMockBuilder('Alchemy\BinaryDriver\ConfigurationInterface')->getMock();

        $driver->expects($this->any())
            ->method('getConfiguration')
            ->will($this->returnValue($configuration));

        $failure = new RuntimeException('failed to encode');
        $driver->expects($this->once())
            ->method('command')
            ->will($this->throwException($failure));

        $audio = new Audio(__FILE__, $driver, $ffprobe);
        $this->expectException('\FFMpeg\Exception\RuntimeException');
        $audio->save($format, $outputPathfile);
    }

    public function testSaveAppliesFilters()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();
        $outputPathfile = '/target/file';
        $format = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $format->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue([]));

        $configuration = $this->getMockBuilder('Alchemy\BinaryDriver\ConfigurationInterface')->getMock();

        $driver->expects($this->any())
            ->method('getConfiguration')
            ->will($this->returnValue($configuration));

        $audio = new Audio(__FILE__, $driver, $ffprobe);

        $filter = $this->getMockBuilder('FFMpeg\Filters\Audio\AudioFilterInterface')->getMock();
        $filter->expects($this->once())
            ->method('apply')
            ->with($audio, $format)
            ->will($this->returnValue(['extra-filter-command']));

        $capturedCommands = [];

        $driver->expects($this->once())
            ->method('command')
            ->with($this->isType('array'), false, $this->anything())
            ->will($this->returnCallback(function ($commands, $errors, $listeners) use (&$capturedCommands) {
                $capturedCommands[] = $commands;
            }));

        $audio->addFilter($filter);
        $audio->save($format, $outputPathfile);

        foreach ($capturedCommands as $commands) {
            $this->assertEquals('-y', $commands[0]);
            $this->assertEquals('-i', $commands[1]);
            $this->assertEquals(__FILE__, $commands[2]);
            $this->assertEquals('extra-filter-command', $commands[3]);
        }
    }

    /**
     * @dataProvider provideSaveData
     */
    public function testSaveShouldSave($threads, $expectedCommands, $expectedListeners, $format)
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();

        $configuration = $this->getMockBuilder('Alchemy\BinaryDriver\ConfigurationInterface')->getMock();

        $driver->expects($this->any())
            ->method('getConfiguration')
            ->will($this->returnValue($configuration));

        $configuration->expects($this->once())
            ->method('has')
            ->with($this->equalTo('ffmpeg.threads'))
            ->will($this->returnValue($threads));

        if ($threads) {
            $configuration->expects($this->once())
                ->method('get')
                ->with($this->equalTo('ffmpeg.threads'))
                ->will($this->returnValue(24));
        } else {
            $configuration->expects($this->never())
                ->method('get');
        }

        $capturedCommand = $capturedListeners = null;

        $driver->expects($this->once())
            ->method('command')
            ->with($this->isType('array'), false, $this->anything())
            ->will($this->returnCallback(function ($commands, $errors, $listeners) use (&$capturedCommand, &$capturedListeners) {
                $capturedCommand = $commands;
                $capturedListeners = $listeners;
            }));

        $outputPathfile = '/target/file';

        $audio = new Audio(__FILE__, $driver, $ffprobe);
        $audio->save($format, $outputPathfile);

        $this->assertEquals($expectedCommands, $capturedCommand);
        $this->assertEquals($expectedListeners, $capturedListeners);
    }

    public function provideSaveData()
    {
        $format = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $format->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue([]));
        $format->expects($this->any())
            ->method('getAudioKiloBitrate')
            ->will($this->returnValue(663));
        $format->expects($this->any())
            ->method('getAudioChannels')
            ->will($this->returnValue(5));

        $audioFormat = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $audioFormat->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue([]));
        $audioFormat->expects($this->any())
            ->method('getAudioKiloBitrate')
            ->will($this->returnValue(664));
        $audioFormat->expects($this->any())
            ->method('getAudioChannels')
            ->will($this->returnValue(5));
        $audioFormat->expects($this->any())
            ->method('getAudioCodec')
            ->will($this->returnValue('patati-patata-audio'));

        $formatExtra = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $formatExtra->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue(['extra', 'param']));
        $formatExtra->expects($this->any())
            ->method('getAudioKiloBitrate')
            ->will($this->returnValue(665));
        $formatExtra->expects($this->any())
            ->method('getAudioChannels')
            ->will($this->returnValue(5));

        $listeners = [$this->getMockBuilder('Alchemy\BinaryDriver\Listeners\ListenerInterface')->getMock()];

        $progressableFormat = $this->getMockBuilder('Tests\FFMpeg\Unit\Media\AudioProg')
            ->disableOriginalConstructor()->getMock();
        $progressableFormat->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue([]));
        $progressableFormat->expects($this->any())
            ->method('createProgressListener')
            ->will($this->returnValue($listeners));
        $progressableFormat->expects($this->any())
            ->method('getAudioKiloBitrate')
            ->will($this->returnValue(666));
        $progressableFormat->expects($this->any())
            ->method('getAudioChannels')
            ->will($this->returnValue(5));

        return [
            [false, [
                '-y', '-i', __FILE__,
                '-b:a', '663k',
                '-ac', '5',
                '/target/file',
            ], null, $format],
            [false, [
                '-y', '-i', __FILE__,
                '-acodec', 'patati-patata-audio',
                '-b:a', '664k',
                '-ac', '5',
                '/target/file',
            ], null, $audioFormat],
            [false, [
                '-y', '-i', __FILE__,
                'extra', 'param',
                '-b:a', '665k',
                '-ac', '5',
                '/target/file',
            ], null, $formatExtra],
            [true, [
                '-y', '-i', __FILE__,
                '-threads', 24,
                '-b:a', '663k',
                '-ac', '5',
                '/target/file',
            ], null, $format],
            [true, [
                '-y', '-i', __FILE__,
                'extra', 'param',
                '-threads', 24,
                '-b:a', '665k',
                '-ac', '5',
                '/target/file',
            ], null, $formatExtra],
            [false, [
                '-y', '-i', __FILE__,
                '-b:a', '666k',
                '-ac', '5',
                '/target/file',
            ], $listeners, $progressableFormat],
            [true, [
                '-y', '-i', __FILE__,
                '-threads', 24,
                '-b:a', '666k',
                '-ac', '5',
                '/target/file',
            ], $listeners, $progressableFormat],
        ];
    }

    public function testSaveShouldNotStoreCodecFiltersInTheMedia()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();

        $configuration = $this->getMockBuilder('Alchemy\BinaryDriver\ConfigurationInterface')->getMock();

        $driver->expects($this->any())
            ->method('getConfiguration')
            ->will($this->returnValue($configuration));

        $configuration->expects($this->any())
            ->method('has')
            ->with($this->equalTo('ffmpeg.threads'))
            ->will($this->returnValue(true));

        $configuration->expects($this->any())
            ->method('get')
            ->with($this->equalTo('ffmpeg.threads'))
            ->will($this->returnValue(24));

        $capturedCommands = [];

        $driver->expects($this->exactly(2))
            ->method('command')
            ->with($this->isType('array'), false, $this->anything())
            ->will($this->returnCallback(function ($commands, $errors, $listeners) use (&$capturedCommands, &$capturedListeners) {
                $capturedCommands[] = $commands;
            }));

        $outputPathfile = '/target/file';

        $format = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $format->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue(['param']));

        $audio = new Audio(__FILE__, $driver, $ffprobe);
        $audio->save($format, $outputPathfile);
        $audio->save($format, $outputPathfile);

        $expected = [
            '-y', '-i', __FILE__, 'param', '-threads', 24, '/target/file',
        ];

        foreach ($capturedCommands as $capturedCommand) {
            $this->assertEquals($expected, $capturedCommand);
        }
    }

    public function testBuildCommandWithVbr()
    {
        $driver = $this->getFFMpegDriverMock();
        $ffprobe = $this->getFFProbeMock();

        $configuration = $this->getMockBuilder('Alchemy\BinaryDriver\ConfigurationInterface')->getMock();

        $driver->expects($this->any())
            ->method('getConfiguration')
            ->will($this->returnValue($configuration));

        $format = $this->getMockBuilder('FFMpeg\Format\AudioInterface')->getMock();
        $format->expects($this->any())
            ->method('getExtraParams')
            ->will($this->returnValue(['param']));

        $format->setEnableVbrEncoding(true);
        $format->setVbrEncodingQuality(5);
        $format->method('getEnableVbrEncoding')->willReturn(true);
        $format->method('getVbrEncodingQuality')->willReturn(5);

        $audio = new Audio(__FILE__, $driver, $ffprobe);

        $reflection = new \ReflectionClass(get_class($audio));
        $method = $reflection->getMethod('buildCommand');
        $method->setAccessible(true);

        $outputPathfile = 'output/path';
        $commands = $method->invokeArgs($audio, [$format, $outputPathfile]);
        print_r($commands);

        $this->assertContains('-q:a', $commands);
        $this->assertContains('5', $commands);
    }

    public function getClassName()
    {
        return 'FFMpeg\Media\Audio';
    }
}
