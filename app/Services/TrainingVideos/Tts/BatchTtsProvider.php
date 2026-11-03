<?php

namespace App\Services\TrainingVideos\Tts;

/** AT-525 - a voice engine with a heavy start-up (Chatterbox loads a 3 GB model): the generator hands it ALL of a video's lines in one go so the model loads once. */
interface BatchTtsProvider extends TtsProvider
{
    /**
     * @param  array<int,array{text:string,out:string}>  $jobs
     * @return array<int,float> seconds of audio per job, in order
     */
    public function synthesizeMany(array $jobs): array;
}
