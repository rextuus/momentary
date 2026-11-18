<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\FaceRecognitionSettings;
use App\Service\Face\Provider\InsightFaceProvider;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PercentType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Zeigt nur die Filter und Schwellen des eingestellten Anbieters (Option provider); die des anderen bleiben
 * unverändert gespeichert. Tracking und „Kandidaten pro Gesicht“ gelten für beide.
 *
 * @extends AbstractType<FaceRecognitionSettings>
 */
final class FaceRecognitionSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['provider'] === InsightFaceProvider::NAME) {
            $this->addInsightFaceFields($builder);
        } else {
            $this->addRekognitionFields($builder);
        }

        $builder->add('maxCandidates', IntegerType::class, [
            'label' => 'Kandidaten pro Gesicht',
            'attr' => ['min' => 1, 'max' => 50],
            'help' => 'Mehr Kandidaten helfen bei großen Gruppen, wenn die beste Person schon im Bild vergeben ist.',
        ])->add('trackingEnabled', CheckboxType::class, [
            'label' => 'Gesichter über aufeinanderfolgende Frames verfolgen',
            'required' => false,
            'help' => 'Ein Gesicht an fast derselben Bildposition kurz danach in derselben Szene gilt als dieselbe Person, auch wenn die Erkennung es nicht wiedererkennt.',
        ])->add('trackingMinIou', PercentType::class, [
            'label' => 'Mindestüberlappung',
            'html5' => true,
            'symbol' => false,
            'scale' => 0,
            'attr' => ['min' => 0, 'max' => 100, 'step' => 5],
            'help' => 'Wie stark sich die Gesichtsboxen in beiden Frames überdecken müssen. Höher ist vorsichtiger.',
        ])->add('trackingMaxGapSeconds', IntegerType::class, [
            'label' => 'Höchster Abstand',
            'attr' => ['min' => 1, 'max' => 60],
            'help' => 'Wie weit zwei Frames zeitlich auseinanderliegen dürfen.',
        ]);
    }

    /**
     * @param FormBuilderInterface<FaceRecognitionSettings|null> $builder
     */
    private function addInsightFaceFields(FormBuilderInterface $builder): void
    {
        $cosine = ['html5' => true, 'scale' => 2, 'attr' => ['min' => 0, 'max' => 1, 'step' => 0.01]];

        $builder
            ->add('insightFaceMinFacePx', IntegerType::class, [
                'label' => 'Mindestgröße',
                'attr' => ['min' => 0, 'max' => 200, 'step' => 2],
                'help' => 'Kürzere Seite des Gesichts im Originalbild. Kleinere Gesichter (Gäste weit im Hintergrund) werden gar nicht erst beschrieben: zu wenig Pixel für einen verlässlichen Abgleich.',
            ])
            ->add('insightFaceMaxFaces', IntegerType::class, [
                'label' => 'Gesichter pro Bild',
                'attr' => ['min' => 0, 'max' => 100],
                'help' => 'Die größten zuerst; 0 = alle. Jedes Gesicht kostet auf abyss rund 0,2 s Rechenzeit.',
            ])
            ->add('insightFaceCandidateThreshold', NumberType::class, [
                'label' => 'Kandidaten ab',
                'help' => 'Ab hier wird ein ähnliches Gesicht überhaupt gespeichert. Muss unter allen anderen Schwellen liegen.',
            ] + $cosine)
            ->add('insightFaceVideoLinkThreshold', NumberType::class, [
                'label' => 'Im Video gruppieren ab',
                'help' => 'Gesichter desselben Videos werden zur selben Person zusammengefasst. Zwei Gesichter aus demselben Bild nie.',
            ] + $cosine)
            ->add('insightFaceSuggestionThreshold', NumberType::class, [
                'label' => 'Vorschlagen ab',
                'help' => 'Eine bereits benannte Person wird am Gesicht als Vorschlag angezeigt, aber nicht automatisch zugeordnet.',
            ] + $cosine)
            ->add('insightFaceMatchThreshold', NumberType::class, [
                'label' => 'Sofort zuordnen ab',
                'help' => 'Gilt videoübergreifend. Zu niedrig führt zu falschen Personen in anderen Videos.',
            ] + $cosine)
            ->add('insightFaceReferenceMinScore', NumberType::class, [
                'label' => 'Referenz ab Detektions-Score',
                'help' => 'Nur ausreichend sichere Gesichter prägen, wie eine Person aussieht. Unsichere werden trotzdem zugeordnet.',
            ] + $cosine)
            ->add('insightFaceReferenceMinFacePx', IntegerType::class, [
                'label' => 'Referenz ab Größe',
                'attr' => ['min' => 0, 'max' => 300, 'step' => 2],
                'help' => 'Kürzere Seite des Gesichts. Kleine Gesichter werden zugeordnet, dienen aber nicht als Vergleich für andere.',
            ])
            ->add('insightFaceGenderCheck', CheckboxType::class, [
                'label' => 'Geschätztes Geschlecht beim Zuordnen beachten',
                'required' => false,
                'help' => 'Schließt Treffer mit abweichendem Geschlecht aus. Die Schätzung liegt bei Kindern und schlechten Aufnahmen oft daneben, deshalb standardmäßig aus.',
            ]);
    }

    /**
     * @param FormBuilderInterface<FaceRecognitionSettings|null> $builder
     */
    private function addRekognitionFields(FormBuilderInterface $builder): void
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
            ] + $percent);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FaceRecognitionSettings::class,
            'provider' => InsightFaceProvider::NAME,
        ]);
        $resolver->setAllowedTypes('provider', 'string');
    }
}
