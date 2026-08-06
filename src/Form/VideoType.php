<?php

namespace App\Form;

use App\Entity\Tag;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Doctrine\ORM\EntityRepository;
use App\Entity\Video;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoType extends AbstractType
{
    public function __construct(
        #[Autowire('/var/www/html/var/uploads/app_uploads')]
        private readonly string $importDir,
        #[Autowire('%env(default:app.frame_analysis_fps:FRAME_ANALYSIS_FPS)%')]
        private readonly float $defaultFps,
        #[Autowire('%env(default:app.min_scene_length_for_refinement:MIN_SCENE_LENGTH_FOR_REFINEMENT)%')]
        private readonly float $minSceneLengthForRefinement,
        #[Autowire('%env(default:app.refined_frame_analysis_fps:REFINED_FRAME_ANALYSIS_FPS)%')]
        private readonly float $refinedFps
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $files = [];
        if (is_dir($this->importDir)) {
            $foundFiles = scandir($this->importDir);
            $allowedExtensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];

            foreach ($foundFiles as $file) {
                if ($file !== '.' && $file !== '..' && !is_dir($this->importDir . '/' . $file)) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $allowedExtensions, true)) {
                        $path = $this->importDir . DIRECTORY_SEPARATOR . $file;
                        $size = filesize($path);
                        $mtime = filemtime($path);
                        $label = sprintf('%s (%s, %s)', $file, $this->formatBytes($size), date('Y-m-d H:i', $mtime));
                        $files[$label] = $file;
                    }
                }
            }
        }

        $builder
            ->add('title', TextType::class, [
                'label' => 'Titel',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('sourceFile', ChoiceType::class, [
                'label' => 'Lokale Videodatei (aus app_uploads)',
                'choices' => $files,
                'placeholder' => '-- Datei wählen --',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('analysisFps', NumberType::class, [
                'label' => 'Standard-FPS',
                'data' => $this->defaultFps,
                'attr' => ['class' => 'form-control form-control-sm', 'step' => '0.01'],
            ])
            ->add('minSceneLengthForRefinement', NumberType::class, [
                'label' => 'Min. Szenenlänge (s)',
                'data' => $this->minSceneLengthForRefinement,
                'attr' => ['class' => 'form-control form-control-sm', 'step' => '0.1'],
            ])
            ->add('refinedAnalysisFps', NumberType::class, [
                'label' => 'Verfeinerungs-FPS',
                'data' => $this->refinedFps,
                'attr' => ['class' => 'form-control form-control-sm', 'step' => '0.01'],
            ])
            ->add('mergeEmptyScenesWithLastPersonScene', CheckboxType::class, [
                'label' => 'Leere Szenen mit der vorherigen Personen-Szene zusammenführen',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('cameraTags', EntityType::class, [
                'class' => Tag::class,
                'choice_label' => 'name',
                'multiple' => false,
                'expanded' => false,
                'label' => 'Kameramodell',
                'mapped' => false,
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('t')
                        ->join('t.category', 'c')
                        ->where('t.name IN (:tags)')
                        ->setParameter('tags', ['Smartphone', 'Camera', 'Actioncam', 'Drohne']);
                },
            ])
            ->add('formatTags', EntityType::class, [
                'class' => Tag::class,
                'choice_label' => 'name',
                'multiple' => false,
                'expanded' => false,
                'label' => 'Format',
                'mapped' => false,
                'attr' => ['class' => 'form-select'],
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('t')
                        ->join('t.category', 'c')
                        ->where('t.name IN (:tags)')
                        ->setParameter('tags', ['Hochkant', 'Querformat']);
                },
            ]);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}