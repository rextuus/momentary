<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FaceRecognitionSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FaceRecognitionSettings>
 */
final class FaceRecognitionSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $percent = ['html5' => true, 'scale' => 1, 'attr' => ['min' => 0, 'max' => 100, 'step' => 0.5]];

        $builder
            ->add('qualityFilter', ChoiceType::class, [
                'label' => 'Qualitätsfilter',
                'expanded' => true,
                'choices' => [
                    'Aus' => 'NONE',
                    'Niedrig' => 'LOW',
                    'Mittel' => 'MEDIUM',
                    'Hoch' => 'HIGH',
                    'Automatisch' => 'AUTO',
                ],
                'help' => 'Wie streng AWS unscharfe, verdeckte oder schlecht beleuchtete Gesichter aussortiert, bevor sie gespeichert werden.',
            ])
            ->add('maxFacesPerFrame', IntegerType::class, [
                'label' => 'Gesichter pro Frame',
                'attr' => ['min' => 1, 'max' => 100],
                'help' => 'Die größten Gesichter zuerst. Jedes Gesicht kostet einen zusätzlichen AWS-Aufruf.',
            ])
            ->add('minFaceWidth', PercentType::class, [
                'label' => 'Mindestgröße',
                'html5' => true,
                'symbol' => false,
                'scale' => 1,
                'attr' => ['min' => 0, 'max' => 20, 'step' => 0.5],
                'help' => 'Anteil an der Bildbreite. Kleinere Gesichter (Gäste weit im Hintergrund) werden verworfen.',
            ])
            ->add('candidateThreshold', NumberType::class, [
                'label' => 'Kandidaten ab',
                'help' => 'Ab hier meldet AWS überhaupt ähnliche Gesichter. Muss unter allen anderen Schwellen liegen.',
            ] + $percent)
            ->add('maxCandidates', IntegerType::class, [
                'label' => 'Kandidaten pro Gesicht',
                'attr' => ['min' => 1, 'max' => 50],
                'help' => 'Mehr Kandidaten helfen bei großen Gruppen, wenn die beste Person schon im Bild vergeben ist.',
            ])
            ->add('videoLinkThreshold', NumberType::class, [
                'label' => 'Im Video gruppieren ab',
                'help' => 'Gesichter desselben Videos werden zur selben Person zusammengefasst. Zwei Gesichter aus demselben Bild nie.',
            ] + $percent)
            ->add('suggestionThreshold', NumberType::class, [
                'label' => 'Vorschlagen ab',
                'help' => 'Eine bereits benannte Person wird am Gesicht als Vorschlag angezeigt, aber nicht automatisch zugeordnet.',
            ] + $percent)
            ->add('matchThreshold', NumberType::class, [
                'label' => 'Sofort zuordnen ab',
                'help' => 'Gilt videoübergreifend. Zu niedrig führt zu falschen Personen in anderen Videos.',
            ] + $percent)
            ->add('trackingEnabled', CheckboxType::class, [
                'label' => 'Gesichter über aufeinanderfolgende Frames verfolgen',
                'required' => false,
                'help' => 'Ein Gesicht an fast derselben Bildposition kurz danach in derselben Szene gilt als dieselbe Person, auch wenn AWS es nicht wiedererkennt.',
            ])
            ->add('trackingMinIou', PercentType::class, [
                'label' => 'Mindestüberlappung',
                'html5' => true,
                'symbol' => false,
                'scale' => 0,
                'attr' => ['min' => 0, 'max' => 100, 'step' => 5],
                'help' => 'Wie stark sich die Gesichtsboxen in beiden Frames überdecken müssen. Höher ist vorsichtiger.',
            ])
            ->add('trackingMaxGapSeconds', IntegerType::class, [
                'label' => 'Höchster Abstand',
                'attr' => ['min' => 1, 'max' => 60],
                'help' => 'Wie weit zwei Frames zeitlich auseinanderliegen dürfen.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => FaceRecognitionSettings::class]);
    }
}
