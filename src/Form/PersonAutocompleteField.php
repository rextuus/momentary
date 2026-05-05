<?php

namespace App\Form;

use App\Entity\Person;
use App\Repository\PersonRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
class PersonAutocompleteField extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => Person::class,
            'placeholder' => 'Search for a person...',
            'choice_label' => 'name',
            'query_builder' => function(PersonRepository $personRepository) {
                // Nur Personen anzeigen, die bereits identifiziert wurden
                return $personRepository->createQueryBuilder('p')
                    ->andWhere('p.identified = :identified')
                    ->setParameter('identified', true)
                    ->orderBy('p.name', 'ASC');
            },
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}