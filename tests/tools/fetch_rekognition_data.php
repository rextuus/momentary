<?php

declare(strict_types=1);

// Erzeugt die Rekognition-Fixtures in tests/fixtures/samples neu.
// Ruft das echte AWS Rekognition auf (kostet Geld, braucht AWS-Zugangsdaten in .env.local):
//   php tests/tools/fetch_rekognition_data.php

use App\Service\Aws\AmazonRekognitionService;

// Set environment for Symfony kernel
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

require_once __DIR__ . '/../../vendor/autoload.php';

// Boot the kernel
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();

$rekognitionService = $container->get(AmazonRekognitionService::class);

$files = [
    'people-detection' => __DIR__ . '/../fixtures/samples/people-detection.mp4',
    'face-demographics-walking' => __DIR__ . '/../fixtures/samples/face-demographics-walking.mp4',
];

$outputDir = __DIR__ . '/../fixtures/samples';

foreach ($files as $name => $videoPath) {
    echo "Processing $name...\n";
    // Extract frame
    $framePath = $outputDir . '/' . $name . '_frame.jpg';
    $cmd = "ffmpeg -i $videoPath -ss 00:00:01 -vframes 1 -y $framePath";
    exec($cmd);

    echo "Analyzing frame $framePath...\n";
    try {
        $result = array_map(static fn($face) => [
            'faceId' => $face->faceId,
            'age' => $face->age,
            'gender' => $face->gender,
            'emotion' => $face->emotion,
            'boundingBox' => $face->boundingBox,
            'candidates' => $face->candidates,
        ], $rekognitionService->processAllFacesInImage($framePath));
        $jsonPath = $outputDir . '/' . $name . '_rekognition_response.json';
        file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Saved response to $jsonPath\n";
    } catch (\Exception $e) {
        echo 'Error: ' . $e->getMessage() . "\n";
    }
}

echo "Service tested.\n";
