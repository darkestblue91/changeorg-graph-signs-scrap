<?php

function graphql_query(string $endpoint, string $query, array $variables = [], ?string $token = null): array
{
    $headers = [
      'Content-Type: application/json',
      'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.97 Safari/537.36',
    ];

    if ($token) {
      $headers[] = "Authorization: bearer $token";
    }

    if (false === $data = @file_get_contents($endpoint, false, stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => $headers,
          'content' => json_encode(['query' => $query, 'variables' => $variables]),
        ]
    ]))) {
        $error = error_get_last();
        throw new \ErrorException($error['message'], $error['type']);
    }

    return json_decode($data, true);
}
