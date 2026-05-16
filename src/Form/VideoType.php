<?php

namespace App\Form;

use App\Entity\Video;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class VideoType extends AbstractType
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public/uploads/import')]
        private string $importDir
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Video::class,
        ]);
    }
}
