<?php

/**
 * Config do package openai-php/laravel. Necessária pro façade OpenAI::chat()
 * resolver a chave — o ServiceProvider lê estes valores de config('openai.*').
 *
 * Usado pelo "segundo olhar" (faro/juiz com GPT) e pelo driver openai do JuizLlm.
 * A chave vem do .env; fail-closed: sem chave real (ou sk-noop), o driver cai
 * pro claude-cli e o segundo olhar fica inerte.
 */
return [
    'api_key' => env('OPENAI_API_KEY'),
    'organization' => env('OPENAI_ORGANIZATION'),
    'project' => env('OPENAI_PROJECT'),
    'base_uri' => env('OPENAI_BASE_URI'),
    'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 60),
];
