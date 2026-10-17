<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Person;
use App\Service\Video\VideoFaceSwitchData;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<VideoFaceSwitchData>
 */
class VideoFaceSwitchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('target', EntityType::class, [
            'class' => Person::class,
            'placeholder' => 'Switch to?',
            'autocomplete' => true,
            'label' => false,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VideoFaceSwitchData::class,
        ]);
    }
}
