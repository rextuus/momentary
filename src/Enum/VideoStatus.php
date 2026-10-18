<?php

declare(strict_types=1);

namespace App\Enum;

enum VideoStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case CONVERTING = 'converting';
    case SCENE_DETECTION = 'scene_detection';
    case EXTRACTING_THUMBNAILS = 'extracting_thumbnails';
    case VIDEO_SPLITTING = 'video_splitting';
    case ANALYZING_FACES_INITIAL = 'analyzing_faces_initial';
    case REFINING_EXTRACTION = 'refining_extraction';
    case REFINING_SPLITTING = 'refining_splitting';
    case ANALYZING_FACES_REFINEMENT = 'analyzing_faces_refinement';
    case CONSOLIDATING_FACES = 'consolidating_faces';
    case MERGING_SCENES = 'merging_scenes';
    case TAGGING_SCENES = 'tagging_scenes';
    case CHAPTER_GENERATION = 'chapter_generation';
    case EXPORTING_JELLYFIN = 'exporting_jellyfin';
    case NAMING = 'naming';
    case COMPLETED = 'completed';
    case ERROR = 'error';

    public function isProcessing(): bool
    {
        return !in_array(
            $this,
            [
                self::PENDING,
                self::QUEUED,
                self::COMPLETED,
                self::ERROR,
            ],
            true,
        );
    }

    /**
     * Verarbeitungsschritte in Pipeline-Reihenfolge.
     *
     * @return list<self>
     */
    public static function pipelineSteps(): array
    {
        return [
            self::CONVERTING,
            self::SCENE_DETECTION,
            self::EXTRACTING_THUMBNAILS,
            self::VIDEO_SPLITTING,
            self::ANALYZING_FACES_INITIAL,
            self::REFINING_EXTRACTION,
            self::REFINING_SPLITTING,
            self::ANALYZING_FACES_REFINEMENT,
            self::CONSOLIDATING_FACES,
            self::MERGING_SCENES,
            self::TAGGING_SCENES,
            self::CHAPTER_GENERATION,
            self::NAMING,
            self::EXPORTING_JELLYFIN,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Wartet',
            self::QUEUED => 'In der Warteschlange',
            self::CONVERTING => 'Konvertierung',
            self::SCENE_DETECTION => 'Szenenerkennung',
            self::EXTRACTING_THUMBNAILS => 'Szenen-Vorschaubilder',
            self::VIDEO_SPLITTING => 'Frames extrahieren',
            self::ANALYZING_FACES_INITIAL => 'Gesichtsanalyse',
            self::REFINING_EXTRACTION => 'Verfeinerung vorbereiten',
            self::REFINING_SPLITTING => 'Verfeinerung: Frames extrahieren',
            self::ANALYZING_FACES_REFINEMENT => 'Verfeinerung: Gesichtsanalyse',
            self::CONSOLIDATING_FACES => 'Gesichter im Video abgleichen',
            self::MERGING_SCENES => 'Szenen zusammenführen',
            self::TAGGING_SCENES => 'Szenen taggen',
            self::CHAPTER_GENERATION => 'Kapitel erzeugen',
            self::NAMING => 'Titel vorschlagen',
            self::EXPORTING_JELLYFIN => 'Jellyfin-Export',
            self::COMPLETED => 'Fertig',
            self::ERROR => 'Fehler',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::CONVERTING => 'sync',
            self::SCENE_DETECTION => 'cut',
            self::EXTRACTING_THUMBNAILS => 'images',
            self::VIDEO_SPLITTING => 'film',
            self::ANALYZING_FACES_INITIAL => 'users',
            self::REFINING_EXTRACTION => 'search-plus',
            self::REFINING_SPLITTING => 'object-group',
            self::ANALYZING_FACES_REFINEMENT => 'microscope',
            self::CONSOLIDATING_FACES => 'people-arrows',
            self::MERGING_SCENES => 'compress-alt',
            self::TAGGING_SCENES => 'tags',
            self::CHAPTER_GENERATION => 'bookmark',
            self::NAMING => 'heading',
            self::EXPORTING_JELLYFIN => 'share-square',
            self::COMPLETED => 'check',
            self::ERROR => 'exclamation-triangle',
            self::PENDING, self::QUEUED => 'hourglass-half',
        };
    }
}
