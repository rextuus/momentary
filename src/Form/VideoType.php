<?php

namespace App\Form;

use App\Entity\Video;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoType extends AbstractType
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/import')]
        private string $importDir,
        #[Autowire('%env(default:app.frame_analysis_fps:FRAME_ANALYSIS_FPS)%')]
        private float $defaultFps,
        #[Autowire('%env(default:app.min_scene_length_for_refinement:MIN_SCENE_LENGTH_FOR_REFINEMENT)%')]
        private float $minSceneLengthForRefinement,
        #[Autowire('%env(default:app.refined_frame_analysis_fps:REFINED_FRAME_ANALYSIS_FPS)%')]
        private float $refinedFps
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $files = [];
        if (is_dir($this->importDir)) {
            $foundFiles = scandir($this->importDir);
            foreach ($foundFiles as $file) {
                if ($file !== '.' && $file !== '..' && !is_dir($this->importDir . '/' . $file)) {
                    $files[$file] = $file;
                }
            }
        }

        $builder
            ->add('title', TextType::class, [
                'label' => 'Titel',
                'attr' => ['class' => 'form-control'],
            ])
            ->add('sourceFile', ChoiceType::class, [
                'label' => 'Lokale Videodatei (aus public/uploads/import)',
                'choices' => $files,
                'placeholder' => '-- Datei wählen --',
                'required' => false,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('youtubeUrl', UrlType::class, [
                'label' => 'YouTube-Link (optional)',
                'required' => false,
                'attr' => ['class' => 'form-control'],
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
            ->add('mergeEmptyScenesWithLastPersonScene', \Symfony\Component\Form\Extension\Core\Type\CheckboxType::class, [
                'label' => 'Leere Szenen mit der vorherigen Personen-Szene zusammenführen',
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}
