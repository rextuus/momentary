<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Event;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Event>
 */
final class EventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('title', TextType::class, [
            'label' => 'Titel',
            'attr' => ['placeholder' => 'z. B. Mallorca 2025, Hochzeit Anna & Ben'],
        ]);
        $builder->add('startDate', DateType::class, [
            'label' => 'Von',
            'required' => false,
            'input' => 'datetime_immutable',
            'widget' => 'single_text',
        ]);
        $builder->add('endDate', DateType::class, [
            'label' => 'Bis',
            'required' => false,
            'input' => 'datetime_immutable',
            'widget' => 'single_text',
            'help' => 'Leer lassen bei eintägigen Ereignissen.',
        ]);
        $builder->add('description', TextareaType::class, [
            'label' => 'Beschreibung',
            'required' => false,
            'attr' => ['rows' => 3],
        ]);
        if ($options['collect_option']) {
            $builder->add('collectMedia', CheckboxType::class, [
                'label' => 'Alle Videos und Fotos aus diesem Zeitraum hinzufügen',
                'mapped' => false,
                'required' => false,
                'data' => true,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Event::class, 'collect_option' => false]);
        $resolver->setAllowedTypes('collect_option', 'bool');
    }
}
