# PHP-FFMPEG

[![Latest Version on Packagist](https://img.shields.io/packagist/v/PHP-FFMpeg/PHP-FFMpeg.svg?style=flat-square)](https://packagist.org/packages/PHP-FFMpeg/PHP-FFMpeg)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
![run-tests](https://github.com/PHP-FFMpeg/PHP-FFMpeg/workflows/run-tests/badge.svg)
[![Total Downloads](https://img.shields.io/packagist/dt/PHP-FFMpeg/PHP-FFMpeg.svg?style=flat-square)](https://packagist.org/packages/PHP-FFMpeg/PHP-FFMpeg)

An Object-Oriented library to convert video/audio files with FFmpeg / AVConv.

## Your attention please

### How this library works:

This library requires a working [FFMpeg install](https://ffmpeg.org/download.html). You will need both FFMpeg and FFProbe binaries to use it.
Be sure that these binaries can be located with system PATH to get the benefit of the binary detection,
otherwise you should have to explicitly give the binaries path on load.

### Known issues:

- Using rotate and resize will produce a corrupted output when using
[libav](http://libav.org/) 0.8. The bug is fixed in version 9. This bug does not
appear in latest ffmpeg version.

## Installation

This library requires PHP 8.0 or higher. For older versions of PHP, check out the [0.x-branch](https://github.com/PHP-FFMpeg/PHP-FFMpeg/tree/0.x).

The recommended way to install PHP-FFMpeg is through [Composer](https://getcomposer.org).

```bash
$ composer require php-ffmpeg/php-ffmpeg
```

## Basic Usage

```php

require 'vendor/autoload.php';

$ffmpeg = FFMpeg\FFMpeg::create();
$video = $ffmpeg->open('video.mpg');
$video
    ->filters()
    ->resize(new FFMpeg\Coordinate\Dimension(320, 240))
    ->synchronize();
$video
    ->frame(FFMpeg\Coordinate\TimeCode::fromSeconds(10))
    ->save('frame.jpg');
$video
    ->save(new FFMpeg\Format\Video\X264(), 'export-x264.mp4')
    ->save(new FFMpeg\Format\Video\WMV(), 'export-wmv.wmv')
    ->save(new FFMpeg\Format\Video\WebM(), 'export-webm.webm');
```

## Documentation

This documentation is an introduction to discover the API. It's recommended
to browse the source code as it is self-documented.

### FFMpeg

`FFMpeg\FFMpeg` is the main object to use to manipulate medias. To build it,
use the static `FFMpeg\FFMpeg::create`:

```php
$ffmpeg = FFMpeg\FFMpeg::create();
```

FFMpeg will autodetect ffmpeg and ffprobe binaries. If you want to give binary
paths explicitly, you can pass an array as configuration. A `Psr\Logger\LoggerInterface`
can also be passed to log binary executions.

```php
$ffmpeg = FFMpeg\FFMpeg::create(array(
    'ffmpeg.binaries'  => '/opt/local/ffmpeg/bin/ffmpeg',
    'ffprobe.binaries' => '/opt/local/ffmpeg/bin/ffprobe',
    'timeout'          => 3600, // The timeout for the underlying process
    'ffmpeg.threads'   => 12,   // The number of threads that FFMpeg should use
), $logger);
```

You may pass a `temporary_directory` key to specify a path for temporary files.

```php
$ffmpeg = FFMpeg\FFMpeg::create(array(
    'temporary_directory' => '/var/ffmpeg-tmp'
), $logger);
```

### Manipulate media

`FFMpeg\FFMpeg` creates media based on URIs. URIs could be either a pointer to a
local filesystem resource, an HTTP resource or any resource supported by FFmpeg.

**Note**: To list all supported resource type of your FFmpeg build, use the
`-protocols` command:

```
ffmpeg -protocols
```

To open a resource, use the `FFMpeg\FFMpeg::open` method.

```php
$ffmpeg->open('video.mpeg');
```

Two types of media can be resolved: `FFMpeg\Media\Audio` and `FFMpeg\Media\Video`.
A third type, `FFMpeg\Media\Frame`, is available through videos.

### Video

`FFMpeg\Media\Video` can be transcoded, ie: change codec, isolate audio or
video. Frames can be extracted.

##### Transcoding

You can transcode videos using the `FFMpeg\Media\Video:save` method. You will
pass a `FFMpeg\Format\FormatInterface` for that.

Please note that audio and video bitrate are set on the format. You can disable the `-b:v` option by setting the kilo bitrate to 0.

```php
$format = new FFMpeg\Format\Video\X264();
$format->on('progress', function ($video, $format, $percentage) {
    echo "$percentage % transcoded";
});

$format
    ->setKiloBitrate(1000)
    ->setAudioChannels(2)
    ->setAudioKiloBitrate(256);

$video->save($format, 'video.avi');
```

Transcoding progress can be monitored in realtime, see Format documentation
below for more information.

##### Extracting image

You can extract a frame at any timecode using the `FFMpeg\Media\Video::frame`
method.

This code returns a `FFMpeg\Media\Frame` instance corresponding to the second 42.
You can pass any `FFMpeg\Coordinate\TimeCode` as argument, see dedicated
documentation below for more information.

```php
$frame = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds(42));
$frame->save('image.jpg');
```

If you want to extract multiple images from the video, you can use the following filter:

```php
$video
    ->filters()
    ->extractMultipleFrames(FFMpeg\Filters\Video\ExtractMultipleFramesFilter::FRAMERATE_EVERY_10SEC, '/path/to/destination/folder/')
    ->synchronize();

$video
    ->save(new FFMpeg\Format\Video\X264(), '/path/to/new/file');
```
By default, this will save the frames as `jpg` images.

You are able to override this using `setFrameFileType` to save the frames in another format:
```php
$frameFileType = 'jpg'; // either 'jpg', 'jpeg' or 'png'
$filter = new ExtractMultipleFramesFilter($frameRate, $destinationFolder);
$filter->setFrameFileType($frameFileType);

$video->addFilter($filter);
```

##### Clip

Cuts the video at a desired point. Use input seeking method. It is faster option than use filter clip.

```php
$clip = $video->clip(FFMpeg\Coordinate\TimeCode::fromSeconds(30), FFMpeg\Coordinate\TimeCode::fromSeconds(15));
$clip->save(new FFMpeg\Format\Video\X264(), 'video.avi');
```

The clip filter takes two parameters:

- `$start`, an instance of `FFMpeg\Coordinate\TimeCode`, specifies the start point of the clip
- `$duration`, optional, an instance of `FFMpeg\Coordinate\TimeCode`, specifies the duration of the clip

On clip you can apply same filters as on video. For example resizing filter.

```php
$clip = $video->clip(FFMpeg\Coordinate\TimeCode::fromSeconds(30), FFMpeg\Coordinate\TimeCode::fromSeconds(15));
$clip->filters()->resize(new FFMpeg\Coordinate\Dimension(320, 240), FFMpeg\Filters\Video\ResizeFilter::RESIZEMODE_INSET, true);
$clip->save(new FFMpeg\Format\Video\X264(), 'video.avi');
```

##### Generate a waveform

You can generate a waveform of an audio file using the `FFMpeg\Media\Audio::waveform`
method.

This code returns a `FFMpeg\Media\Waveform` instance.
You can optionally pass dimensions as the first two arguments and an array of hex string colors for ffmpeg to use for the waveform, see dedicated
documentation below for more information.

The output file MUST use the PNG extension.

```php
$waveform = $audio->waveform(640, 120, array('#00FF00'));
$waveform->save('waveform.png');
```

If you want to get a waveform from a video, convert it in an audio file first.

```php
// Open your video file
$video = $ffmpeg->open( 'video.mp4' );

// Set an audio format
$audio_format = new FFMpeg\Format\Audio\Mp3();

// Extract the audio into a new file as mp3
$video->save($audio_format, 'audio.mp3');

// Set the audio file
$audio = $ffmpeg->open( 'audio.mp3' );

// Create the waveform
$waveform = $audio->waveform();
$waveform->save( 'waveform.png' );
```

###### VBR Encoding

You can also enable VBR encoding, using `setEnableVbrEncoding()` and `setVbrEncodingQuality()` function to class 
that implements `FFMpeg\Format\FormatInterface`.


```php
$format
    ->setEnableVbrEncoding(true)
    ->setVbrEncodingQuality(5);
```
NOTE: as default settings VBR encoding disables and VBR quality equals 3

##### Filters

You can apply filters on `FFMpeg\Media\Video` with the `FFMpeg\Media\Video::addFilter`
method. Video accepts Audio and Video filters.

You can build your own filters and some are bundled in PHP-FFMpeg - they are
accessible through the `FFMpeg\Media\Video::filters` method.

Filters are chainable

```php
$video
    ->filters()
    ->resize($dimension, $mode, $useStandards)
    ->framerate($framerate, $gop)
    ->synchronize();
```

###### Rotate

Rotates a video to a given angle.

```php
$video->filters()->rotate($angle);
```

The `$angle` parameter must be one of the following constants :

- `FFMpeg\Filters\Video\RotateFilter::ROTATE_90`: 90° clockwise
- `FFMpeg\Filters\Video\RotateFilter::ROTATE_180`: 180°
- `FFMpeg\Filters\Video\RotateFilter::ROTATE_270`: 90° counterclockwise

###### Resize

Resizes a video to a given size.

```php
$video->filters()->resize($dimension, $mode, $useStandards);
```

The resize filter takes three parameters:

- `$dimension`, an instance of `FFMpeg\Coordinate\Dimension`
- `$mode`, one of the constants `FFMpeg\Filters\Video\ResizeFilter::RESIZEMODE_*` constants
- `$useStandards`, a boolean to force the use of the nearest aspect ratio standard.

If you want a video in a non-standard ratio, you can use the padding filter to resize your video in the desired size, and wrap it into black bars.

```php
$video->filters()->pad($dimension);
```

The pad filter takes one parameter:

- `$dimension`, an instance of `FFMpeg\Coordinate\Dimension`

Don't forget to save it afterwards.

```php
$video->save(new FFMpeg\Format\Video\X264(), $new_file);
```

###### Watermark

Watermark a video with a given image.

```php
$video
    ->filters()
    ->watermark($watermarkPath, array(
        'position' => 'relative',
        'bottom' => 50,
        'right' => 50,
    ));
```

The watermark filter takes two parameters:

`$watermarkPath`, the path to your watermark file.
`$coordinates`, an array defining how you want your watermark positioned. You can use relative positioning as demonstrated above or absolute as such:

```php
$video
    ->filters()
    ->watermark($watermarkPath, array(
        'position' => 'absolute',
        'x' => 1180,
        'y' => 620,
    ));
```

###### Framerate

Changes the frame rate of the video.

```php
$video->filters()->framerate($framerate, $gop);
```

The framerate filter takes two parameters:

- `$framerate`, an instance of `FFMpeg\Coordinate\FrameRate`
- `$gop`, a [GOP](https://wikipedia.org/wiki/Group_of_pictures) value (integer)

###### Synchronize

Synchronizes audio and video.

Some containers may use a delay that results in desynchronized outputs. This
filter solves this issue.

```php
$video->filters()->synchronize();
```

###### Clip

Cuts the video at a desired point.

```php
$video->filters()->clip(FFMpeg\Coordinate\TimeCode::fromSeconds(30), FFMpeg\Coordinate\TimeCode::fromSeconds(15));
```

The clip filter takes two parameters:

- `$start`, an instance of `FFMpeg\Coordinate\TimeCode`, specifies the start point of the clip
- `$duration`, optional, an instance of `FFMpeg\Coordinate\TimeCode`, specifies the duration of the clip

###### Crop

Crops the video based on a width and height(a `Point`)

```php
$video->filters()->crop(new FFMpeg\Coordinate\Point("t*100", 0, true), new FFMpeg\Coordinate\Dimension(200, 600));
```

It takes two parameters:
- `$point`, an instance of `FFMpeg\Coordinate\Point`, specifies the point to crop
- `$dimension`, an instance of `FFMpeg\Coordinate\Dimension`, specifies the dimension of the output video

### Audio

`FFMpeg\Media\Audio` can be transcoded too, ie: change codec, isolate audio or
video. Frames can be extracted.

##### Transcoding

You can transcode audios using the `FFMpeg\Media\Audio:save` method. You will
pass a `FFMpeg\Format\FormatInterface` for that.

Please note that audio kilobitrate is set on the audio format.

```php
$ffmpeg = FFMpeg\FFMpeg::create();
$audio = $ffmpeg->open('track.mp3');

$format = new FFMpeg\Format\Audio\Flac();
$format->on('progress', function ($audio, $format, $percentage) {
    echo "$percentage % transcoded";
});

$format
    ->setAudioChannels(2)
    ->setAudioKiloBitrate(256);

$audio->save($format, 'track.flac');
```

Transcoding progress can be monitored in realtime, see Format documentation
below for more information.

##### Filters

You can apply filters on `FFMpeg\Media\Audio` with the `FFMpeg\Media\Audio::addFilter`
method. It only accepts audio filters.

You can build your own filters and some are bundled in PHP-FFMpeg - they are
accessible through the `FFMpeg\Media\Audio::filters` method.

##### Clipping
Cuts the audio at a desired point.

```php
$audio->filters()->clip(FFMpeg\Coordinate\TimeCode::fromSeconds(30), FFMpeg\Coordinate\TimeCode::fromSeconds(15));
```


###### Metadata

Add metadata to audio files. Just pass an array of key=value pairs of all
metadata you would like to add. If no arguments are passed into the filter
all metadata will be removed from input file. Currently supported data is
title, artist, album, artist, composer, track, year, description, artwork

```php
$audio->filters()->addMetadata(["title" => "Some Title", "track" => 1]);

//remove all metadata and video streams from audio file
$audio->filters()->addMetadata();
```

Add artwork to the audio file
```php
$audio->filters()->addMetadata(["artwork" => "/path/to/image/file.jpg"]);
```
NOTE: at present ffmpeg (version 3.2.2) only supports artwork output for .mp3
files

###### Resample

Resamples an audio file.

```php
$audio->filters()->resample($rate);
```

The resample filter takes two parameters :

- `$rate`, a valid audio sample rate value (integer)

###### Custom

Custom filter for audio file. For example to resize album image url.

```php
$audio->filters()->simple(['-s', '500x500']);
```

#### Frame

A frame is an image at a timecode of a video; see documentation above about
frame extraction.

You can save frames using the `FFMpeg\Media\Frame::save` method.

```php
$frame->save('target.jpg');
```

This method has a second optional boolean parameter. Set it to true to get
accurate images; it takes more time to execute.

#### Gif

A gif is an animated image extracted from a sequence of the video.

You can save gif files using the `FFMpeg\Media\Gif::save` method.

```php
$video = $ffmpeg->open( '/path/to/video' );
$video
    ->gif(FFMpeg\Coordinate\TimeCode::fromSeconds(2), new FFMpeg\Coordinate\Dimension(640, 480), 3)
    ->save($new_file);
```

This method has a third optional boolean parameter, which is the duration of the animation.
If you don't set it, you will get a fixed gif image.

#### Concatenation

This feature allows you to generate one audio or video file, based on multiple sources.

There are two ways to concatenate videos, depending on the codecs of the sources.
If your sources have all been encoded with the same codec, you will want to use the `FFMpeg\Media\Concatenate::saveFromSameCodecs` which has way better performances.
If your sources have been encoded with different codecs, you will want to use the `FFMpeg\Media\Concatenate::saveFromDifferentCodecs`.

The first function will use the initial codec as the one for the generated file.
With the second function, you will be able to choose which codec you want for the generated file.

You also need to pay attention to the fact that, when using the saveFromDifferentCodecs method,
your files MUST have video and audio streams.

In both cases, you will have to provide an array of files.

To concatenate videos encoded with the same codec, do as follow:

```php
// In order to instantiate the video object, you HAVE TO pass a path to a valid video file.
// We recommend that you put there the path of any of the video you want to use in this concatenation.
$video = $ffmpeg->open( '/path/to/video' );
$video
    ->concat(array('/path/to/video1', '/path/to/video2'))
    ->saveFromSameCodecs('/path/to/new_file', TRUE);
```

The boolean parameter of the save function allows you to use the copy parameter which accelerates drastically the generation of the encoded file.

To concatenate videos encoded with the different codec, do as follow:

```php
// In order to instantiate the video object, you HAVE TO pass a path to a valid video file.
// We recommend that you put there the path of any of the video you want to use in this concatenation.
$video = $ffmpeg->open( '/path/to/video' );

$format = new FFMpeg\Format\Video\X264();
$format->setAudioCodec("libmp3lame");

$video
    ->concat(array('/path/to/video1', '/path/to/video2'))
    ->saveFromDifferentCodecs($format, '/path/to/new_file');
```

More details about concatenation in FFMPEG can be found [here](https://trac.ffmpeg.org/wiki/Concatenate), [here](https://ffmpeg.org/ffmpeg-formats.html#concat-1) and [here](https://ffmpeg.org/ffmpeg.html#Stream-copy).

### AdvancedMedia
AdvancedMedia may have multiple inputs and multiple outputs.

This class has been developed primarily to use with `-filter_complex`.

So, its `filters()` method accepts only filters that can be used inside `-filter_complex` command.
AdvancedMedia already contains some built-in filters.

#### Base usage
For example:

```php
$advancedMedia = $ffmpeg->openAdvanced(array('video_1.mp4', 'video_2.mp4'));
$advancedMedia->filters()
    ->custom('[0:v][1:v]', 'hstack', '[v]');
$advancedMedia
    ->map(array('0:a', '[v]'), new X264('aac', 'libx264'), 'output.mp4')
    ->save();
```

This code takes 2 input videos, stacks they horizontally in 1 output video and adds to this new video the audio from the first video.
(It is impossible with simple filtergraph that has only 1 input and only 1 output).


#### Complicated example
A more difficult example of possibilities of the AdvancedMedia. Consider all input videos already have the same resolution and duration. ("xstack" filter has been added in the 4.1 version of the ffmpeg).

```php
$inputs = array(
    'video_1.mp4',
    'video_2.mp4',
    'video_3.mp4',
    'video_4.mp4',
);

$advancedMedia = $ffmpeg->openAdvanced($inputs);
$advancedMedia->filters()
    ->custom('[0:v]', 'negate', '[v0negate]')
    ->custom('[1:v]', 'edgedetect', '[v1edgedetect]')
    ->custom('[2:v]', 'hflip', '[v2hflip]')
    ->custom('[3:v]', 'vflip', '[v3vflip]')
    ->xStack('[v0negate][v1edgedetect][v2hflip][v3vflip]', XStackFilter::LAYOUT_2X2, 4, '[resultv]');
$advancedMedia
    ->map(array('0:a'), new Mp3(), 'video_1.mp3')
    ->map(array('1:a'), new Flac(), 'video_2.flac')
    ->map(array('2:a'), new Wav(), 'video_3.wav')
    ->map(array('3:a'), new Aac(), 'video_4.aac')
    ->map(array('[resultv]'), new X264('aac', 'libx264'), 'output.mp4')
    ->save();
```

This code takes 4 input videos, then the negates the first video, stores result in `[v0negate]` stream, detects edges in the second video, stores result in `[v1edgedetect]` stream, horizontally flips the third video, stores result in `[v2hflip]` stream, vertically flips the fourth video, stores result in `[v3vflip]` stream, then takes this 4 generated streams ans combine them in one 2x2 collage video.
Then saves audios from the original videos into the 4 different formats and saves the generated collage video into the separate file.

As you can see, you can take multiple input sources, perform the complicated processing for them and produce multiple output files in the same time, in the one ffmpeg command.

#### Just give me a map!
You do not have to use `-filter_complex`. You can use only `-map` options. For example, just extract the audio from the video:

```php
$advancedMedia = $ffmpeg->openAdvanced(array('video.mp4'));
$advancedMedia
    ->map(array('0:a'), new Mp3(), 'output.mp3')
    ->save();
```

#### Customisation
If you need you can extra customize the result ffmpeg command of the AdvancedMedia:

```php
$advancedMedia = $ffmpeg->openAdvanced($inputs);
$advancedMedia
    ->setInitialParameters(array('the', 'params', 'that', 'will', 'be', 'added', 'before', '-i', 'part', 'of', 'the', 'command'))
    ->setAdditionalParameters(array('the', 'params', 'that', 'will', 'be', 'added', 'at', 'the', 'end', 'of', 'the', 'command'));
```

#### Formats

A format implements `FFMpeg\Format\FormatInterface`. To save to a video file,
use `FFMpeg\Format\VideoInterface`, and `FFMpeg\Format\AudioInterface` for
audio files.

A format can also extend `FFMpeg\Format\ProgressableInterface` to get realtime
information about the transcoding.

Predefined formats already provide progress information as events.

```php
$format = new FFMpeg\Format\Video\X264();
$format->on('progress', function ($video, $format, $percentage) {
    echo "$percentage % transcoded";
});

$video->save($format, 'video.avi');
```

The callback provided for the event can be any callable.

##### Add additional parameters

You can add additional parameters to your encoding requests based on your video format.

The argument of the setAdditionalParameters method is an array.

```php
$format = new FFMpeg\Format\Video\X264();
$format->setAdditionalParameters(array('foo', 'bar'));
$video->save($format, 'video.avi');
```

##### Add initial parameters

You can also add initial parameters to your encoding requests based on your video format. This can be expecially handy in overriding a default input codec in FFMpeg.

The argument of the setInitialParameters method is an array.

```php
$format = new FFMpeg\Format\Video\X264();
$format->setInitialParameters(array('-acodec', 'libopus'));
$video->save($format, 'video.avi');
```

##### Create your own format

The easiest way to create a format is to extend the abstract
`FFMpeg\Format\Video\DefaultVideo` and `FFMpeg\Format\Audio\DefaultAudio`.
and implement the following methods.

```php
class CustomWMVFormat extends FFMpeg\Format\Video\DefaultVideo
{
    public function __construct($audioCodec = 'wmav2', $videoCodec = 'wmv2')
    {
        $this
            ->setAudioCodec($audioCodec)
            ->setVideoCodec($videoCodec);
    }

    public function supportBFrames()
    {
        return false;
    }

    public function getAvailableAudioCodecs()
    {
        return array('wmav2');
    }

    public function getAvailableVideoCodecs()
    {
        return array('wmv2');
    }
}
```

#### Coordinates

FFMpeg uses many units for time and space coordinates.

- `FFMpeg\Coordinate\AspectRatio` represents an aspect ratio.
- `FFMpeg\Coordinate\Dimension` represent a dimension.
- `FFMpeg\Coordinate\FrameRate` represent a framerate.
- `FFMpeg\Coordinate\Point` represent a point. (Supports dynamic points since v0.10.0)
- `FFMpeg\Coordinate\TimeCode` represent a timecode.

### FFProbe

`FFMpeg\FFProbe` is used internally by `FFMpeg\FFMpeg` to probe medias. You can
also use it to extract media metadata.

```php
$ffprobe = FFMpeg\FFProbe::create();
$ffprobe
    ->streams('/path/to/video/mp4') // extracts streams informations
    ->videos()                      // filters video streams
    ->first()                       // returns the first video stream
    ->get('codec_name');            // returns the codec_name property
```

```php
$ffprobe = FFMpeg\FFProbe::create();
$ffprobe
    ->format('/path/to/video/mp4') // extracts file informations
    ->get('duration');             // returns the duration property
```

### Validating media files

(since 0.10.0)
You can validate media files using PHP-FFMpeg's FFProbe wrapper.

```php
$ffprobe = FFMpeg\FFProbe::create();
$ffprobe->isValid('/path/to/file/to/check'); // returns bool
```

## License

This project is licensed under the [MIT license](http://opensource.org/licenses/MIT).

Music: "Favorite Secrets" by Waylon Thornton
From the Free Music Archive
[CC BY NC SA](http://creativecommons.org/licenses/by-nc-sa/3.0/us/)

Music: "Siesta" by Jahzzar
From the Free Music Archive
[CC BY SA](https://creativecommons.org/licenses/by-sa/3.0/)
