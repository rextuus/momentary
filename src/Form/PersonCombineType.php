<?php

namespace App\Form;

use App\Entity\Person;
use App\Service\Person\Data\PersonCombineData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PersonCombineType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
//            ->add('source', EntityType::class, [
//                'class' => Person::class,
//                'disabled' => true,
//                'autocomplete' => true,
//                'data' => $options['source'],
//            ])
            ->add('target', EntityType::class, [
                'class' => Person::class,
                'placeholder' => 'Merge with?',
                'autocomplete' => true,
                'label' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PersonCombineData::class,
            'source' => null,
        ]);
    }
}
