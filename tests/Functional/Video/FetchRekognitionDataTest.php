<?php

namespace App\Tests\Functional\Video;

use App\Service\Aws\AmazonRekognitionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class FetchRekognitionDataTest extends KernelTestCase
{
    public function testFetchData(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        
        // This should work in a test environment because we can access private services
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
            $cmd = "ffmpeg -i $videoPath -ss 00:00:05 -vframes 1 -y $framePath";
            exec($cmd);
            
            echo "Analyzing frame $framePath...\n";
            try {
                // Manually call detectFaces to see what's happening
                $imageContent = file_get_contents($framePath);
                $reflection = new \ReflectionClass($rekognitionService);
                $property = $reflection->getProperty('client');
                $property->setAccessible(true);
                $client = $property->getValue($rekognitionService);
                
                $detectResponse = $client->detectFaces([
                    'Image' => ['Bytes' => $imageContent],
                    'Attributes' => ['ALL'],
                ]);
                
                if (empty($detectResponse['FaceDetails'])) {
                    echo "No faces detected!\n";
                    print_r($detectResponse->toArray());
                } else {
                    echo "Faces detected: " . count($detectResponse['FaceDetails']) . "\n";
                }

                $result = $rekognitionService->processAllFacesInImage($framePath);
                $jsonPath = $outputDir . '/' . $name . '_rekognition_response.json';
                file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                echo "Saved response to $jsonPath\n";
            } catch (\Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }
        }
    }
}
