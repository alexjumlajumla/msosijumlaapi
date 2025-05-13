<?php

namespace App\Services;

use Orhanerday\OpenAi\OpenAi;

class AIOrderService
{
    protected OpenAi $openAi;

    public function __construct()
    {
        $this->openAi = new OpenAi(config('services.openai.api_key'));
    }

    public function processOrderIntent(string $transcription): array
    {
        $prompt = "Extract the intent, filters, and exclusions from the following order request: \"$transcription\". Return the result as a JSON object with keys 'intent', 'filters', and 'exclusions'.";

        $response = $this->openAi->chatCompletion([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.2,
            'max_tokens' => 150,
        ]);

        $decoded = json_decode($response, true);
        $content = data_get($decoded, 'choices.0.message.content');

        return json_decode($content, true) ?? [];
    }
} 