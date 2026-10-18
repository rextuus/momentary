<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\TranscriptionSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TranscriptionSettings>
 */
final class TranscriptionSettingsType extends AbstractType
{
    public const array MODEL_LABELS = [
        'small' => 'small',
        'medium' => 'medium',
        'large-v3-turbo' => 'large-v3-turbo',
        'large-v3' => 'large-v3',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('transcribeInPipeline', CheckboxType::class, [
            'label' => 'Jedes neue Video automatisch transkribieren',
            'required' => false,
            'help' => 'Letzter Schritt der Analyse, läuft auf dem eigenen Transkriptions-Worker. Ausgeschaltet nur per Button auf der Videoseite.',
        ]);
        $builder->add('model', ChoiceType::class, [
            'label' => 'Modell',
            'choices' => array_flip(self::MODEL_LABELS),
            'expanded' => true,
        ])->add('threads', IntegerType::class, [
            'label' => 'CPU-Threads',
            'help' => 'Auf dem ThinkCentre (4 Kerne) 3 lassen, damit die übrigen Dienste flüssig bleiben.',
            'attr' => ['min' => 1, 'max' => 32],
        ])->add('language', TextType::class, [
            'label' => 'Sprache',
            'required' => false,
            'help' => 'Sprachcode wie „de“. Leer lassen, um die Sprache je Video erkennen zu lassen.',
            'attr' => ['placeholder' => 'automatisch', 'maxlength' => 3],
        ])->add('deriveTags', CheckboxType::class, [
            'label' => 'Tags aus dem Gesprochenen ableiten',
            'required' => false,
            'help' => 'Ein Gemini-Aufruf pro Video, nur mit dem Text. Themen, genannte Namen, Orte und Anlässe werden als Tags an die Szenen gehängt.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => TranscriptionSettings::class]);
    }
}
