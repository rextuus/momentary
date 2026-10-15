<?php

use App\Service\Aws\AmazonRekognitionService;
use Symfony\Component\Console\Output\ConsoleOutput;

// Set environment for Symfony kernel
putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';

require_once __DIR__ . '/../../../vendor/autoload.php';

// Boot the kernel
$kernel = new \App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();

$rekognitionService = $container->get(AmazonRekognitionService::class);

$files = [
    'people-detection' => __DIR__ . '/samples/people-detection.mp4',
    'face-demographics-walking' => __DIR__ . '/samples/face-demographics-walking.mp4',
];

$outputDir = __DIR__ . '/samples';

foreach ($files as $name => $videoPath) {
    echo "Processing $name...\n";
    // Extract frame
    $framePath = $outputDir . '/' . $name . '_frame.jpg';
    $cmd = "ffmpeg -i $videoPath -ss 00:00:01 -vframes 1 -y $framePath";
    exec($cmd);
    
    echo "Analyzing frame $framePath...\n";
    try {
        $result = $rekognitionService->processAllFacesInImage($framePath);
        $jsonPath = $outputDir . '/' . $name . '_rekognition_response.json';
        file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo "Saved response to $jsonPath\n";
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "Service tested.\n";
