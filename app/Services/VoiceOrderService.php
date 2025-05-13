<?php

namespace App\Services;

use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\SpeechClient;

class VoiceOrderService
{
    protected SpeechClient $speechClient;

    public function __construct()
    {
        $this->speechClient = new SpeechClient([
            'credentials' => config('services.google.credentials'),
        ]);
    }

    public function transcribeAudio(string $audioFilePath): string
    {
        $audio = (new RecognitionAudio())
            ->setContent(file_get_contents($audioFilePath));

        $config = (new RecognitionConfig())
            ->setEncoding(RecognitionConfig\AudioEncoding::LINEAR16)
            ->setSampleRateHertz(16000)
            ->setLanguageCode('en-US');

        $response = $this->speechClient->recognize($config, $audio);

        $transcription = '';
        foreach ($response->getResults() as $result) {
            $transcription .= $result->getAlternatives()[0]->getTranscript();
        }

        return $transcription;
    }

    public function __destruct()
    {
        $this->speechClient->close();
    }
} 